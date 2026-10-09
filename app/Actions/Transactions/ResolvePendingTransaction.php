<?php

namespace App\Actions\Transactions;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A pending transaction gets its final answer from the provider.
 *
 * Successful: the full transaction is posted now (taking any cash already paid out from
 * the pending-settlement account). Failed: nothing more is posted; if cash was already
 * handed over it stays in pending settlement until the reversal is followed up.
 */
class ResolvePendingTransaction
{
    public function __construct(
        private TransactionPosting $posting,
        private Ledger $ledger,
        private Audit $audit,
    ) {}

    public function handle(Transaction $transaction, TransactionStatus $outcome): Transaction
    {
        if (! in_array($outcome, [TransactionStatus::Successful, TransactionStatus::Failed], true)) {
            throw ValidationException::withMessages(['status' => 'A pending transaction can only become successful or failed.']);
        }

        return DB::transaction(function () use ($transaction, $outcome): Transaction {
            $fresh = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== TransactionStatus::Pending || $fresh->isVoided()) {
                throw ValidationException::withMessages(['status' => 'This transaction is no longer pending.']);
            }

            if ($outcome === TransactionStatus::Successful) {
                $entry = $this->ledger->post(
                    'transaction', $this->posting->completionLines($fresh, $fresh->cash_paid_while_pending),
                    $fresh->outlet_id, $fresh, $fresh->type->label().' confirmed',
                );
                $fresh->journal_entry_id = $entry->id;
            }

            $fresh->status = $outcome;
            $fresh->save();

            $this->audit->record('transaction.resolved', $fresh,
                ['status' => TransactionStatus::Pending->value], ['status' => $outcome->value]);

            return $fresh;
        });
    }
}
