<?php

namespace App\Models;

use App\Enums\ReconciliationStatus;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One outlet's end-of-day count: what the books expected, what was counted, and the decision.
 *
 * @property string $id
 * @property string $business_id
 * @property string $outlet_id
 * @property CarbonImmutable $business_date
 * @property ReconciliationStatus $status
 * @property int $expected_cash
 * @property int $counted_cash
 * @property int $cash_variance
 * @property array<int|string, int> $denominations
 * @property string|null $note
 * @property string $submitted_by
 * @property CarbonImmutable $submitted_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $variance_entry_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Outlet $outlet
 * @property-read Membership $submitter
 * @property-read Membership|null $decider
 * @property-read JournalEntry|null $varianceEntry
 * @property-read Collection<int, ReconciliationWallet> $wallets
 * @property-read Collection<int, StaffShortage> $shortages
 */
#[Fillable([
    'outlet_id', 'business_date', 'status', 'expected_cash', 'counted_cash', 'cash_variance', 'denominations',
    'note', 'submitted_by', 'submitted_at',
])]
class Reconciliation extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => ReconciliationStatus::class,
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'cash_variance' => 'integer',
            'denominations' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
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
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'decided_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function varianceEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'variance_entry_id');
    }

    /**
     * @return HasMany<ReconciliationWallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(ReconciliationWallet::class);
    }

    /**
     * @return HasMany<StaffShortage, $this>
     */
    public function shortages(): HasMany
    {
        return $this->hasMany(StaffShortage::class);
    }

    /** True when the drawer and every wallet matched the books exactly. */
    public function balances(): bool
    {
        return $this->cash_variance === 0 && $this->wallets->every(fn (ReconciliationWallet $w) => $w->variance === 0);
    }
}
