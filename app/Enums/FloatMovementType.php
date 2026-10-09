<?php

namespace App\Enums;

enum FloatMovementType: string
{
    /** Money the owner already has at an outlet (or head office) when starting on FloatBook. */
    case OpeningBalance = 'opening_balance';

    /** Owner sends money from head office to an outlet's cash or wallet. */
    case TopUp = 'top_up';

    /** Money moves from one outlet to another. */
    case OutletTransfer = 'outlet_transfer';

    /** Cash is collected from an outlet back to head office (or banked). */
    case CashPickup = 'cash_pickup';

    /** Cash and wallet swap at the same outlet (e.g. cash deposited to fund the wallet). */
    case Rebalance = 'rebalance';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::TopUp => 'Top-up',
            self::OutletTransfer => 'Outlet transfer',
            self::CashPickup => 'Cash pickup',
            self::Rebalance => 'Cash ↔ wallet swap',
        };
    }
}
