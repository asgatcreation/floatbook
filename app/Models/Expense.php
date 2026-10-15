<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money spent to run an outlet, paid from its drawer or one of its wallets.
 *
 * @property string $id
 * @property string $business_id
 * @property string $outlet_id
 * @property string $membership_id
 * @property ExpenseCategory $category
 * @property int $amount
 * @property string $paid_from_account_id
 * @property string|null $description
 * @property string $idempotency_key
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $business_date
 * @property string|null $journal_entry_id
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property string|null $void_entry_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Outlet $outlet
 * @property-read Membership $membership
 * @property-read LedgerAccount $paidFrom
 * @property-read JournalEntry|null $journalEntry
 */
#[Fillable([
    'outlet_id', 'membership_id', 'category', 'amount', 'paid_from_account_id', 'description',
    'idempotency_key', 'occurred_at', 'business_date',
])]
class Expense extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount' => 'integer',
            'occurred_at' => 'datetime',
            'business_date' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'paid_from_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Expenses that still count (not voided).
     *
     * @param  Builder<Expense>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
