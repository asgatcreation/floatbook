<?php

namespace App\Actions\Transactions;

use App\Actions\Reversals\ReversalCases;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Provider;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Charges\ChargeCalculator;
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
    ) {}

    /**
     * @param  array{
     *     idempotency_key: string, outlet_id: string, provider_id: string, type: TransactionType,
     *     amount: Money, status: TransactionStatus, cash_paid_while_pending?: bool,
     *     customer_name?: ?string, customer_phone?: ?string, reference?: ?string, note?: ?string,
     *     source?: string, client_recorded_at?: ?CarbonImmutable
     * }  $data
     */
    public function handle(Membership $by, array $data): RecordedTransaction
    {
        $existing = Transaction::query()->where('idempotency_key', $data['idempotency_key'])->first();

        if ($existing !== null) {
            return new RecordedTransaction($existing, wasDuplicate: true);
        }

        $outlet = Outlet::query()->active()->find($data['outlet_id']);
        $provider = Provider::query()->where('is_active', true)->find($data['provider_id']);

        if ($outlet === null || ! $by->coversOutlet($outlet)) {
            throw ValidationException::withMessages(['outlet_id' => 'You can only record transactions for your own outlets.']);
        }

        if ($provider === null) {
            throw ValidationException::withMessages(['provider_id' => 'Choose a provider.']);
        }

        $type = $data['type'];
        $status = $data['status'];
        $cashPaid = $status === TransactionStatus::Pending && $type->canPayCashWhilePending() && ($data['cash_paid_while_pending'] ?? false);
        $quote = $this->charges->quote($type, $provider->id, $outlet->id, $data['amount']);
        $occurredAt = $this->occurredAt($data);

        try {
            $transaction = DB::transaction(function () use ($by, $data, $outlet, $provider, $type, $status, $cashPaid, $quote, $occurredAt): Transaction {
                $transaction = Transaction::query()->create([
                    'outlet_id' => $outlet->id,
                    'provider_id' => $provider->id,
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
     * Entries made offline keep the time they were actually recorded on the phone, as long
     * as it is believable: not in the future and no more than 3 days old. Otherwise "now".
     *
     * @param  array<string, mixed>  $data
     */
    private function occurredAt(array $data): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $client = $data['client_recorded_at'] ?? null;

        if (($data['source'] ?? 'counter') !== 'offline_sync' || ! $client instanceof CarbonImmutable) {
            return $now;
        }

        return $client->isAfter($now->addMinutes(2)) || $client->isBefore($now->subDays(3)) ? $now : $client;
    }
}
