<?php

namespace App\Actions\Transactions;

use App\Enums\ReversalStatus;
use App\Enums\TransactionStatus;
use App\Models\ReversalCase;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A pending (or failed) transaction gets its final answer from the provider.
 *
 * Successful: the full transaction is posted now (taking any cash already paid out from
 * the pending-settlement account) and its reversal case closes. Failed: nothing more is
 * posted; if cash was handed over it stays in pending settlement until the reversal
 * case is settled. A failed transaction can still turn out successful later, as long
 * as its reversal case wasn't already closed as reversed or written off.
 */
class ResolvePendingTransaction
{
    public function __construct(
        private TransactionPosting $posting,
        private Ledger $ledger,
        private Audit $audit,
    ) {}

    public function handle(Transaction $transaction, TransactionStatus $outcome, bool $closeCase = true): Transaction
    {
        if (! in_array($outcome, [TransactionStatus::Successful, TransactionStatus::Failed], true)) {
            throw ValidationException::withMessages(['status' => 'A transaction can only be marked successful or failed.']);
        }

        return DB::transaction(function () use ($transaction, $outcome, $closeCase): Transaction {
            $fresh = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $from = $fresh->status;

            $allowed = ! $fresh->isVoided() && (
                $from === TransactionStatus::Pending
                || ($from === TransactionStatus::Failed && $outcome === TransactionStatus::Successful)
            );

            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'This transaction can no longer be changed.']);
            }

            $case = ReversalCase::query()->where('transaction_id', $fresh->id)->first();

            if ($case !== null && in_array($case->status, [ReversalStatus::Reversed, ReversalStatus::WrittenOff], true)) {
                throw ValidationException::withMessages(['status' => 'Its reversal case was already closed as '.$case->status->label().'.']);
            }

            if ($outcome === TransactionStatus::Successful) {
                $entry = $this->ledger->post(
                    'transaction', $this->posting->completionLines($fresh, $fresh->cash_paid_while_pending),
                    $fresh->outlet_id, $fresh, $fresh->type->label().' confirmed',
                );
                $fresh->journal_entry_id = $entry->id;

                if ($closeCase && $case !== null && ! $case->status->isClosed()) {
                    $caseFrom = $case->status;
                    $case->forceFill(['status' => ReversalStatus::ResolvedSuccessful, 'closed_at' => now(), 'closed_by' => auth()->id()])->save();
                    $case->updates()->create([
                        'user_id' => auth()->id(),
                        'from_status' => $caseFrom->value,
                        'to_status' => ReversalStatus::ResolvedSuccessful->value,
                        'note' => 'The transaction was confirmed as successful.',
                    ]);
                }
            }

            $fresh->status = $outcome;
            $fresh->save();

            $this->audit->record('transaction.resolved', $fresh, ['status' => $from->value], ['status' => $outcome->value]);

            return $fresh;
        });
    }
}
