<?php

namespace App\Actions\Transactions;

use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Charges\ChargeCalculator;
use App\Support\Charges\ChargeQuote;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moves an entry recorded on the wrong terminal to the right one.
 *
 * Nothing is deleted or edited in place: the original is voided (its ledger entries are
 * reversed) and an identical entry is recorded on the right terminal, linked to it. Both
 * stay in the history, so anyone can see what was moved, when, by whom and why.
 *
 * The moved entry keeps the original time and the charge the customer actually paid.
 * If the new terminal is with a different provider, the provider's fee and commission
 * are worked out again for that provider.
 */
class MoveTransaction
{
    public function __construct(
        private VoidTransaction $void,
        private RecordTransaction $record,
        private ChargeCalculator $charges,
        private Audit $audit,
    ) {}

    public function handle(Transaction $transaction, Membership $by, Terminal $to, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $by, $to, $reason): Transaction {
            $original = Transaction::query()->with(['terminal.provider', 'outlet.business', 'membership'])
                ->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $this->authorize($original, $by, $to);

            $to->loadMissing('provider');
            $this->void->handle($original, $by->user, "Moved to {$to->label()}: {$reason}");

            $moved = $this->record->handle($original->membership, [
                'idempotency_key' => (string) Str::uuid(),
                'terminal_id' => $to->id,
                'type' => $original->type,
                'amount' => Money::kobo($original->amount),
                'status' => $original->status,
                'cash_paid_while_pending' => $original->cash_paid_while_pending,
                'customer_name' => $original->customer_name,
                'customer_phone' => $original->customer_phone,
                'reference' => $original->reference,
                'note' => $original->note,
                'source' => 'correction',
                'client_recorded_at' => CarbonImmutable::parse($original->occurred_at),
                'quote' => $this->quoteFor($original, $to),
                'replaces_transaction_id' => $original->id,
                'any_terminal' => true,
            ])->transaction;

            $this->audit->record('transaction.moved', $moved, before: [
                'transaction_id' => $original->id, 'terminal' => $original->terminal->label(),
            ], after: [
                'transaction_id' => $moved->id, 'terminal' => $to->label(), 'reason' => $reason,
            ]);

            return $moved;
        });
    }

    private function authorize(Transaction $original, Membership $by, Terminal $to): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['terminal_id' => $message]);

        if ($original->isVoided()) {
            $fail('This entry has been voided, so it cannot be moved.');
        }

        if (! $to->is_active || $to->outlet_id !== $original->outlet_id) {
            $fail('Choose an active terminal at the same outlet.');
        }

        if ($to->id === $original->terminal_id) {
            $fail('It is already on that terminal.');
        }

        $supervises = $by->allows(Permission::VoidTransactions) && $by->coversOutlet($original->outlet_id);
        $today = CarbonImmutable::now($original->outlet->business->timezone)->toDateString();
        $ownToday = $by->id === $original->membership_id && $original->business_date->toDateString() === $today;

        // Agents can fix their own entries on the day; after that, a supervisor or owner.
        if (! $supervises && ! $ownToday) {
            $fail('Only a supervisor or the owner can move this entry now.');
        }

        if (! $supervises && ! $by->canUseTerminal($to)) {
            $fail('That terminal is not one of yours.');
        }
    }

    private function quoteFor(Transaction $original, Terminal $to): ChargeQuote
    {
        $providerFee = Money::kobo($original->provider_fee);
        $commission = Money::kobo($original->commission);

        if ($to->provider_id !== $original->provider_id) {
            $fresh = $this->charges->quote($original->type, $to->provider_id, $original->outlet_id, Money::kobo($original->amount));
            $providerFee = $fresh->providerFee;
            $commission = $fresh->commission;
        }

        return new ChargeQuote(
            charge: Money::kobo($original->charge),
            chargeCollectedVia: $original->charge_collected_via,
            providerFee: $providerFee,
            commission: $commission,
        );
    }
}
