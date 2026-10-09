<?php

namespace App\Enums;

/**
 * The chart of accounts. Assets and expenses normally carry a debit (positive) balance;
 * income and owner's capital normally carry a credit (negative) balance.
 */
enum LedgerAccountType: string
{
    // Per outlet
    case CashDrawer = 'cash_drawer';
    case ProviderWallet = 'provider_wallet';
    case PendingSettlement = 'pending_settlement';
    case IncomeCharges = 'income_charges';
    case IncomeCommissions = 'income_commissions';
    case ExpenseProviderFees = 'expense_provider_fees';
    case ExpenseOperating = 'expense_operating';
    case CashVariance = 'cash_variance';

    // Per business
    case HeadOffice = 'head_office';
    case OwnerCapital = 'owner_capital';

    public function isOutletLevel(): bool
    {
        return ! in_array($this, [self::HeadOffice, self::OwnerCapital], true);
    }

    /**
     * True for accounts whose natural balance is a credit (shown as a positive number to people).
     */
    public function isCreditNormal(): bool
    {
        return in_array($this, [self::IncomeCharges, self::IncomeCommissions, self::OwnerCapital], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CashDrawer => 'Cash in drawer',
            self::ProviderWallet => 'Wallet',
            self::PendingSettlement => 'Cash paid on pending withdrawals',
            self::IncomeCharges => 'Customer charges',
            self::IncomeCommissions => 'Provider commissions',
            self::ExpenseProviderFees => 'Provider fees',
            self::ExpenseOperating => 'Operating expenses',
            self::CashVariance => 'Cash shortages and excess',
            self::HeadOffice => 'Head office',
            self::OwnerCapital => "Owner's capital",
        };
    }
}
