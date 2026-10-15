<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Rent = 'rent';
    case Fuel = 'fuel';
    case Data = 'data';
    case Salary = 'salary';
    case Transport = 'transport';
    case Repairs = 'repairs';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Fuel => 'Generator fuel',
            self::Data => 'Data and airtime',
            self::Salary => 'Salaries',
            self::Transport => 'Transport',
            self::Repairs => 'Repairs',
            self::Other => 'Other',
        };
    }
}
