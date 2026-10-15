<?php

namespace App\Models;

use App\Enums\ReversalStatus;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Follow-up for a failed or pending transaction: the customer was debited (or paid cash)
 * but the money didn't arrive. Opened automatically; closed when the provider answers.
 *
 * @property string $id
 * @property string $business_id
 * @property string $transaction_id
 * @property string $outlet_id
 * @property int $amount
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $provider_ticket
 * @property ReversalStatus $status
 * @property string|null $outcome
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property string|null $journal_entry_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Transaction $transaction
 * @property-read Outlet $outlet
 */
#[Fillable(['transaction_id', 'outlet_id', 'amount', 'customer_name', 'customer_phone', 'provider_ticket', 'status', 'due_at'])]
class ReversalCase extends Model
{
    use BelongsToBusiness, HasUlids;

    /** How long a provider normally takes before a case counts as overdue. */
    public const DEFAULT_SLA_HOURS = 72;

    protected function casts(): array
    {
        return [
            'status' => ReversalStatus::class,
            'amount' => 'integer',
            'due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return HasMany<ReversalCaseUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(ReversalCaseUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    public function isOverdue(): bool
    {
        return ! $this->status->isClosed() && $this->due_at->isPast();
    }

    /**
     * @param  Builder<ReversalCase>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [ReversalStatus::Open, ReversalStatus::Escalated]);
    }
}
