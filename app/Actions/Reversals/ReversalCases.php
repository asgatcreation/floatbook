<?php

namespace App\Actions\Reversals;

use App\Actions\Transactions\ResolvePendingTransaction;
use App\Enums\LedgerAccountType;
use App\Enums\Permission;
use App\Enums\ReversalStatus;
use App\Enums\TransactionStatus;
use App\Models\Membership;
use App\Models\ReversalCase;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Ledger\Accounts;
use App\Support\Ledger\Ledger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Follow-up of failed and pending transactions.
 *
 * A case opens automatically when a transaction is saved as pending or failed, with a
 * deadline based on how long providers usually take. Closing it as "reversed" or
 * "written off" settles any cash already handed to the customer: either they brought it
 * back (cash recovered) or it is booked as a loss.
 */
class ReversalCases
{
    public function __construct(
        private Ledger $ledger,
        private Accounts $accounts,
        private ResolvePendingTransaction $resolve,
        private Audit $audit,
    ) {}

    public function openFor(Transaction $transaction): ?ReversalCase
    {
        if (! in_array($transaction->status, [TransactionStatus::Pending, TransactionStatus::Failed], true)
            || $transaction->isVoided()
            || ReversalCase::query()->where('transaction_id', $transaction->id)->exists()) {
            return null;
        }

        $slaHours = (int) ($transaction->outlet->business->settings['reversal_sla_hours'] ?? ReversalCase::DEFAULT_SLA_HOURS);

        $case = ReversalCase::query()->create([
            'transaction_id' => $transaction->id,
            'outlet_id' => $transaction->outlet_id,
            'amount' => $transaction->amount,
            'customer_name' => $transaction->customer_name,
            'customer_phone' => $transaction->customer_phone,
            'status' => ReversalStatus::Open,
            'due_at' => $transaction->occurred_at->addHours($slaHours),
        ]);

        $case->updates()->create([
            'to_status' => ReversalStatus::Open->value,
            'note' => 'Opened automatically: the transaction was saved as '.$transaction->status->value.'.',
        ]);

        return $case;
    }

    /**
     * @param  'cash_recovered'|'written_off'|null  $cashOutcome  required when closing a case where cash was already paid out
     */
    public function update(
        ReversalCase $case,
        Membership $by,
        ?ReversalStatus $to,
        ?string $note,
        ?string $ticket = null,
        ?string $cashOutcome = null,
    ): ReversalCase {
        if ($case->status->isClosed()) {
            throw ValidationException::withMessages(['status' => 'This case is already closed.']);
        }

        if ($to === ReversalStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'Cases are cancelled by voiding the transaction.']);
        }

        if ($to === ReversalStatus::WrittenOff && ! $by->allows(Permission::VoidTransactions)) {
            throw new AuthorizationException('Only an owner or supervisor can write a case off.');
        }

        return DB::transaction(function () use ($case, $by, $to, $note, $ticket, $cashOutcome): ReversalCase {
            $fresh = ReversalCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $transaction = Transaction::query()->findOrFail($fresh->transaction_id);
            $from = $fresh->status;

            if ($ticket !== null && $ticket !== '') {
                $fresh->provider_ticket = $ticket;
            }

            if ($to !== null && $to !== $from) {
                match ($to) {
                    ReversalStatus::ResolvedSuccessful => $this->resolve->handle($transaction, TransactionStatus::Successful, closeCase: false),
                    ReversalStatus::Reversed, ReversalStatus::WrittenOff => $this->settleClosedWithoutSuccess($fresh, $transaction, $to, $cashOutcome),
                    default => null,
                };

                $fresh->status = $to;

                if ($to->isClosed()) {
                    $fresh->closed_at = now();
                    $fresh->closed_by = $by->user_id;
                }
            }

            $fresh->save();

            if ($note !== null && $note !== '' || $to !== $from) {
                $fresh->updates()->create([
                    'user_id' => $by->user_id,
                    'from_status' => $to !== null && $to !== $from ? $from->value : null,
                    'to_status' => $to !== null && $to !== $from ? $to->value : null,
                    'note' => $note,
                ]);
            }

            $this->audit->record('reversal.updated', $fresh, ['status' => $from->value], [
                'status' => $fresh->status->value, 'outcome' => $fresh->outcome, 'ticket' => $fresh->provider_ticket,
            ]);

            return $fresh;
        });
    }

    /**
     * When a voided transaction had a case, the case simply closes.
     */
    public function cancelFor(Transaction $transaction): void
    {
        $case = ReversalCase::query()->where('transaction_id', $transaction->id)->open()->first();

        if ($case === null) {
            return;
        }

        $case->forceFill(['status' => ReversalStatus::Cancelled, 'closed_at' => now()])->save();
        $case->updates()->create([
            'to_status' => ReversalStatus::Cancelled->value,
            'note' => 'Closed because the transaction was voided.',
        ]);
    }

    private function settleClosedWithoutSuccess(ReversalCase $case, Transaction $transaction, ReversalStatus $to, ?string $cashOutcome): void
    {
        if ($transaction->status === TransactionStatus::Pending) {
            $transaction->forceFill(['status' => TransactionStatus::Failed])->save();
        }

        if (! $transaction->cash_paid_while_pending) {
            return;
        }

        $outcome = $to === ReversalStatus::WrittenOff ? 'written_off' : $cashOutcome;

        if (! in_array($outcome, ['cash_recovered', 'written_off'], true)) {
            throw ValidationException::withMessages([
                'cash_outcome' => 'Cash was already given to this customer. Say whether they returned it or it is lost.',
            ]);
        }

        $pending = $this->accounts->forOutlet($transaction->outlet_id, LedgerAccountType::PendingSettlement);
        $target = $outcome === 'cash_recovered'
            ? $this->accounts->cash($transaction->outlet_id)
            : $this->accounts->forOutlet($transaction->outlet_id, LedgerAccountType::ReversalLosses);

        $entry = $this->ledger->post(
            'reversal_settlement',
            [[$target, $transaction->amount], [$pending, -$transaction->amount]],
            $transaction->outlet_id,
            $case,
            $outcome === 'cash_recovered' ? 'Customer returned the cash' : 'Cash paid out could not be recovered',
        );

        $case->outcome = $outcome;
        $case->journal_entry_id = $entry->id;
    }
}
