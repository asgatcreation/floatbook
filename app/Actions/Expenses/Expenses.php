<?php

namespace App\Actions\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\LedgerAccountType;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Audit;
use App\Support\Ledger\Accounts;
use App\Support\Ledger\Ledger;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records and voids outlet expenses. An expense moves money out of the drawer (or a
 * wallet) into the outlet's operating expenses, so the day's profit is net of it.
 */
class Expenses
{
    public function __construct(private Ledger $ledger, private Accounts $accounts, private Audit $audit) {}

    public function record(
        Membership $by,
        Outlet $outlet,
        ExpenseCategory $category,
        Money $amount,
        LedgerAccount $paidFrom,
        ?string $description,
        string $idempotencyKey,
    ): Expense {
        $existing = Expense::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $by->coversOutlet($outlet)) {
            throw ValidationException::withMessages(['outlet_id' => 'You can only record expenses for your own outlets.']);
        }

        $paysFromThisOutlet = $paidFrom->outlet_id === $outlet->id
            && in_array($paidFrom->type, [LedgerAccountType::CashDrawer, LedgerAccountType::ProviderWallet], true);

        if (! $paysFromThisOutlet) {
            throw ValidationException::withMessages(['paid_from_account_id' => "Pay it from {$outlet->name}'s drawer or one of its wallets."]);
        }

        $now = CarbonImmutable::now();

        $expense = DB::transaction(function () use ($by, $outlet, $category, $amount, $paidFrom, $description, $idempotencyKey, $now): Expense {
            $expense = Expense::query()->create([
                'outlet_id' => $outlet->id,
                'membership_id' => $by->id,
                'category' => $category,
                'amount' => $amount->kobo,
                'paid_from_account_id' => $paidFrom->id,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $now,
                'business_date' => $now->setTimezone($outlet->business->timezone)->toDateString(),
            ]);

            $entry = $this->ledger->post('expense', [
                [$this->accounts->forOutlet($outlet, LedgerAccountType::ExpenseOperating), $amount->kobo],
                [$paidFrom, -$amount->kobo],
            ], $outlet->id, $expense, $category->label().($description ? ": {$description}" : ''), $now);

            $expense->forceFill(['journal_entry_id' => $entry->id])->save();

            return $expense;
        });

        $this->audit->record('expense.recorded', $expense, after: [
            'category' => $category->value, 'amount' => $amount->kobo, 'description' => $description,
        ]);

        return $expense;
    }

    public function void(Expense $expense, User $by, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $by, $reason): Expense {
            $fresh = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This expense has already been voided.']);
            }

            $reversal = $fresh->journalEntry !== null
                ? $this->ledger->reverse($fresh->journalEntry, 'Voided: '.$reason, $fresh)
                : null;

            $fresh->forceFill([
                'voided_at' => now(),
                'voided_by' => $by->id,
                'void_reason' => $reason,
                'void_entry_id' => $reversal?->id,
            ])->save();

            $this->audit->record('expense.voided', $fresh, after: ['reason' => $reason, 'amount' => $fresh->amount]);

            return $fresh;
        });
    }
}
