<?php

namespace App\Enums;

/**
 * What the customer came for. The "flow" decides how money moves between the cash
 * drawer and the provider wallet when the transaction is posted to the ledger.
 */
enum TransactionType: string
{
    case CashWithdrawal = 'cash_withdrawal';
    case Transfer = 'transfer';
    case Airtime = 'airtime';
    case Data = 'data';
    case BillPayment = 'bill_payment';
    case BvnNin = 'bvn_nin';
    case CardIssuance = 'card_issuance';
    case OtherService = 'other_service';

    public function label(): string
    {
        return match ($this) {
            self::CashWithdrawal => 'Withdrawal',
            self::Transfer => 'Transfer / deposit',
            self::Airtime => 'Airtime',
            self::Data => 'Data',
            self::BillPayment => 'Bill payment',
            self::BvnNin => 'BVN / NIN service',
            self::CardIssuance => 'Card issuance',
            self::OtherService => 'Other service',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::CashWithdrawal => 'Withdraw',
            self::Transfer => 'Transfer',
            self::BillPayment => 'Bills',
            self::BvnNin => 'BVN/NIN',
            self::CardIssuance => 'Card',
            self::OtherService => 'Other',
            default => $this->label(),
        };
    }

    /**
     * cash_out: the customer's card is debited into our wallet and we hand over cash.
     * cash_in:  the customer gives cash and we send value out of our wallet.
     * service:  the customer pays a price for a service; the provider may charge us a cost.
     */
    public function flow(): string
    {
        return match ($this) {
            self::CashWithdrawal => 'cash_out',
            self::Transfer, self::Airtime, self::Data, self::BillPayment => 'cash_in',
            self::BvnNin, self::CardIssuance, self::OtherService => 'service',
        };
    }

    public function isService(): bool
    {
        return $this->flow() === 'service';
    }

    /**
     * Whether an agent may hand over cash before the provider confirms (withdrawals only).
     */
    public function canPayCashWhilePending(): bool
    {
        return $this === self::CashWithdrawal;
    }
}
