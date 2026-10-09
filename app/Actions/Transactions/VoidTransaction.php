<?php

namespace App\Actions\Transactions;

use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Audit;
use App\Support\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a transaction recorded by mistake. Its ledger entries are reversed with equal
 * and opposite entries; nothing is deleted, so the history of the mistake is kept.
 */
class VoidTransaction
{
    public function __construct(private Ledger $ledger, private Audit $audit) {}

    public function handle(Transaction $transaction, User $by, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $by, $reason): Transaction {
            $fresh = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This transaction has already been voided.']);
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

            $this->audit->record('transaction.voided', $fresh, after: [
                'reason' => $reason, 'amount' => $fresh->amount, 'type' => $fresh->type->value,
            ]);

            return $fresh;
        });
    }
}
