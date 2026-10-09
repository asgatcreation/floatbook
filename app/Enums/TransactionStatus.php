<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Successful = 'successful';
    case Pending = 'pending';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
