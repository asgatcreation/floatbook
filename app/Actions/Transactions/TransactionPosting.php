<?php

namespace App\Actions\Transactions;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;

/**
 * Turns a transaction into balanced ledger lines.
 *
 * Example — ₦10,000 withdrawal, ₦200 charge collected in cash, ₦50 provider fee:
 *   wallet +9,950 · fees +50 · cash −9,800 · charges −200   (sum 0)
 */
class TransactionPosting
{
    public function __construct(private Accounts $accounts) {}

    /**
     * Lines for a completed (successful) transaction. When cash was already paid out
     * while it was pending, the cash side was posted then, so it is taken from the
     * pending-settlement account instead of the drawer.
     *
     * @return list<array{0: LedgerAccount, 1: int}>
     */
    public function completionLines(Transaction $t, bool $cashAlreadyPaid = false): array
    {
        $cash = $this->accounts->cash($t->outlet_id);
        $wallet = $this->accounts->wallet($t->outlet_id, $t->provider_id);
        $pending = $this->accounts->forOutlet($t->outlet_id, LedgerAccountType::PendingSettlement);

        $chargeToCash = $t->charge_collected_via === 'cash' ? $t->charge : 0;
        $chargeToWallet = $t->charge - $chargeToCash;

        $lines = match ($t->type->flow()) {
            // Card debited into our wallet; cash handed to the customer.
            'cash_out' => [
                [$wallet, $t->amount],
                [$cashAlreadyPaid ? $pending : $cash, -$t->amount],
            ],
            // Customer hands over cash; value leaves our wallet.
            'cash_in' => [
                [$cash, $t->amount],
                [$wallet, -$t->amount],
            ],
            // A service: the price is the charge itself (added below); nothing else moves.
            default => [],
        };

        return [
            ...$lines,
            [$cash, $chargeToCash],
            [$wallet, $chargeToWallet],
            [$this->accounts->forOutlet($t->outlet_id, LedgerAccountType::IncomeCharges), -$t->charge],
            // Provider fee comes out of the wallet; commission goes into it.
            [$wallet, -$t->provider_fee],
            [$this->accounts->forOutlet($t->outlet_id, LedgerAccountType::ExpenseProviderFees), $t->provider_fee],
            [$wallet, $t->commission],
            [$this->accounts->forOutlet($t->outlet_id, LedgerAccountType::IncomeCommissions), -$t->commission],
        ];
    }

    /**
     * Lines for handing over cash before the provider has confirmed a withdrawal.
     *
     * @return list<array{0: LedgerAccount, 1: int}>
     */
    public function pendingPayoutLines(Transaction $t): array
    {
        return [
            [$this->accounts->forOutlet($t->outlet_id, LedgerAccountType::PendingSettlement), $t->amount],
            [$this->accounts->cash($t->outlet_id), -$t->amount],
        ];
    }
}
