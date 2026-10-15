<?php

namespace App\Actions\Transactions;

use App\Actions\Reversals\ReversalCases;
use App\Models\JournalEntry;
use App\Models\ReversalCase;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Audit;
use App\Support\Ledger\DayLocks;
use App\Support\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a transaction recorded by mistake. Its ledger entries are reversed with equal
 * and opposite entries; nothing is deleted, so the history of the mistake is kept.
 */
class VoidTransaction
{
    public function __construct(
        private Ledger $ledger,
        private Audit $audit,
        private ReversalCases $reversals,
        private DayLocks $days,
    ) {}

    public function handle(Transaction $transaction, User $by, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $by, $reason): Transaction {
            $fresh = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This transaction has already been voided.']);
            }

            // Its day has been counted: changing it would make the count wrong.
            $this->days->assertOpen($fresh->outlet_id, $fresh->business_date, 'reason');

            $settled = ReversalCase::query()->where('transaction_id', $fresh->id)->whereNotNull('journal_entry_id')->exists();

            if ($settled) {
                throw ValidationException::withMessages(['reason' => 'Its reversal case has already been settled, so it can no longer be voided.']);
            }

            $entries = JournalEntry::query()
                ->where('source_type', 'Transaction')
                ->where('source_id', $fresh->id)
                ->whereNull('reverses_entry_id')
                ->orderBy('created_at')
                ->get();

            $lastReversal = null;

            foreach ($entries as $entry) {
                $lastReversal = $this->ledger->reverse($entry, 'Voided: '.$reason, $fresh);
            }

            $fresh->forceFill([
                'voided_at' => now(),
                'voided_by' => $by->id,
                'void_reason' => $reason,
                'void_entry_id' => $lastReversal?->id,
            ])->save();

            $this->reversals->cancelFor($fresh);

            $this->audit->record('transaction.voided', $fresh, after: [
                'reason' => $reason, 'amount' => $fresh->amount, 'type' => $fresh->type->value,
            ]);

            return $fresh;
        });
    }
}
