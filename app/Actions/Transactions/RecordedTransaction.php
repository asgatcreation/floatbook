<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;

final readonly class RecordedTransaction
{
    public function __construct(
        public Transaction $transaction,
        public bool $wasDuplicate,
    ) {}
}
