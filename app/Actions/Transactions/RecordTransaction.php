<?php

namespace App\Actions\Transactions;

use App\Actions\Reversals\ReversalCases;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Membership;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Charges\ChargeCalculator;
use App\Support\Charges\ChargeQuote;
use App\Support\Ledger\DayLocks;
use App\Support\Ledger\Ledger;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one counter transaction and posts it to the ledger, all in one database transaction.
 *
 * Idempotent: the client sends a UUID per transaction. Sending the same one again (a
 * retry after a dropped connection, or an offline queue syncing twice) returns the
 * transaction that already exists instead of creating a duplicate.
 */
class RecordTransaction
{
    public function __construct(
        private ChargeCalculator $charges,
        private TransactionPosting $posting,
        private Ledger $ledger,
        private Audit $audit,
        private ReversalCases $reversals,
        private DayLocks $days,
    ) {}

    /**
     * @param  array{
     *     idempotency_key: string, terminal_id?: ?string, outlet_id?: ?string, provider_id?: ?string, type: TransactionType,
     *     amount: Money, status: TransactionStatus, cash_paid_while_pending?: bool,
     *     customer_name?: ?string, customer_phone?: ?string, reference?: ?string, note?: ?string,
     *     source?: string, client_recorded_at?: ?CarbonImmutable,
     *     quote?: ChargeQuote, replaces_transaction_id?: string, any_terminal?: bool
     * }  $data  quote, replaces_transaction_id and any_terminal are only set internally, when an entry is moved to another terminal
     */
    public function handle(Membership $by, array $data): RecordedTransaction
    {
        $existing = Transaction::query()->where('idempotency_key', $data['idempotency_key'])->first();

        if ($existing !== null) {
            return new RecordedTransaction($existing, wasDuplicate: true);
        }

        $terminal = $this->terminal($by, $data);
        $outlet = $terminal->outlet;
        $provider = $terminal->provider;

        $type = $data['type'];
        $status = $data['status'];
        $cashPaid = $status === TransactionStatus::Pending && $type->canPayCashWhilePending() && ($data['cash_paid_while_pending'] ?? false);
        $quote = $data['quote'] ?? $this->charges->quote($type, $provider->id, $outlet->id, $data['amount']);
        $occurredAt = $this->occurredAt($data);
        $this->days->assertOpen($outlet->id, $occurredAt->setTimezone($outlet->business->timezone)->toDateString());

        try {
            $transaction = DB::transaction(function () use ($by, $data, $terminal, $outlet, $type, $status, $cashPaid, $quote, $occurredAt): Transaction {
                $transaction = Transaction::query()->create([
                    'outlet_id' => $outlet->id,
                    'provider_id' => $terminal->provider_id,
                    'terminal_id' => $terminal->id,
                    'replaces_transaction_id' => $data['replaces_transaction_id'] ?? null,
                    'membership_id' => $by->id,
                    'type' => $type,
                    'amount' => $data['amount']->kobo,
                    'charge' => $quote->charge->kobo,
                    'charge_collected_via' => $quote->chargeCollectedVia,
                    'provider_fee' => $quote->providerFee->kobo,
                    'commission' => $quote->commission->kobo,
                    'status' => $status,
                    'cash_paid_while_pending' => $cashPaid,
                    'customer_name' => $data['customer_name'] ?? null,
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? null,
                    'source' => $data['source'] ?? 'counter',
                    'idempotency_key' => $data['idempotency_key'],
                    'client_recorded_at' => $data['client_recorded_at'] ?? null,
                    'occurred_at' => $occurredAt,
                    'business_date' => $occurredAt->setTimezone($outlet->business->timezone)->toDateString(),
                ]);

                $entry = match (true) {
                    $status === TransactionStatus::Successful => $this->ledger->post(
                        'transaction', $this->posting->completionLines($transaction), $outlet->id, $transaction,
                        $type->label(), $occurredAt,
                    ),
                    $cashPaid => $this->ledger->post(
                        'pending_payout', $this->posting->pendingPayoutLines($transaction), $outlet->id, $transaction,
                        'Cash paid while '.$type->label().' was pending', $occurredAt,
                    ),
                    default => null,
                };

                if ($entry !== null && $status === TransactionStatus::Successful) {
                    $transaction->forceFill(['journal_entry_id' => $entry->id])->save();
                }

                // Pending or failed: someone must follow it up with the provider.
                $this->reversals->openFor($transaction->setRelation('outlet', $outlet));

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            // Two copies of the same request raced each other; the other one won.
            return new RecordedTransaction(
                Transaction::query()->where('idempotency_key', $data['idempotency_key'])->firstOrFail(),
                wasDuplicate: true,
            );
        }

        $this->audit->record('transaction.recorded', $transaction, after: [
            'type' => $type->value, 'amount' => $transaction->amount, 'status' => $status->value,
            'charge' => $transaction->charge, 'reference' => $transaction->reference,
        ]);

        return new RecordedTransaction($transaction, wasDuplicate: false);
    }

    /**
     * The terminal the entry goes through. Older phones (and entries already waiting in an
     * offline queue) send an outlet and provider instead; those use the member's first
     * terminal for that provider at the outlet.
     *
     * @param  array<string, mixed>  $data
     */
    private function terminal(Membership $by, array $data): Terminal
    {
        $query = Terminal::query()->active()->with(['outlet', 'provider'])
            ->whereHas('outlet', fn ($q) => $q->where('is_active', true))
            ->whereHas('provider', fn ($q) => $q->where('is_active', true));

        if (! empty($data['terminal_id'])) {
            $terminal = $query->whereKey($data['terminal_id'])->first();
        } else {
            $terminal = $query->where('outlet_id', $data['outlet_id'] ?? null)->where('provider_id', $data['provider_id'] ?? null)
                ->orderBy('name')->get()
                ->first(fn (Terminal $t) => $by->canUseTerminal($t));
        }

        if ($terminal === null) {
            throw ValidationException::withMessages(['terminal_id' => 'Choose a terminal.']);
        }

        if (! $by->coversOutlet($terminal->outlet_id)) {
            throw ValidationException::withMessages(['terminal_id' => 'You can only record transactions for your own outlets.']);
        }

        if (! ($data['any_terminal'] ?? false) && ! $by->canUseTerminal($terminal)) {
            throw ValidationException::withMessages(['terminal_id' => 'That terminal is not one of yours.']);
        }

        return $terminal;
    }

    /**
     * Entries made offline keep the time they were actually recorded on the phone, as long
     * as it is believable (not in the future, at most 3 days old). Imported statement rows
     * keep the provider's time (up to a year back). Everything else happens "now".
     *
     * @param  array<string, mixed>  $data
     */
    private function occurredAt(array $data): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $client = $data['client_recorded_at'] ?? null;
        $maxAge = match ($data['source'] ?? 'counter') {
            'offline_sync' => $now->subDays(3),
            'import' => $now->subYear(),
            // A moved entry keeps the time of the one it replaces, whenever that was.
            'correction' => $now->subYears(10),
            default => null,
        };

        if ($maxAge === null || ! $client instanceof CarbonImmutable) {
            return $now;
        }

        return $client->isAfter($now->addMinutes(2)) || $client->isBefore($maxAge) ? $now : $client;
    }
}
