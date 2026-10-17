<?php

namespace App\Models;

use App\Enums\AnomalyKind;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something worth a second look. Raised by FloatBook, judged by a person.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $outlet_id
 * @property string|null $membership_id
 * @property AnomalyKind $kind
 * @property string $severity
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $summary
 * @property array<string, mixed> $details
 * @property string $status
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Outlet|null $outlet
 * @property-read Membership|null $membership
 * @property-read Membership|null $reviewer
 */
#[Fillable([
    'outlet_id', 'membership_id', 'kind', 'severity', 'subject_type', 'subject_id', 'summary', 'details', 'status',
])]
class AnomalyFlag extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return ['kind' => AnomalyKind::class, 'details' => 'array', 'reviewed_at' => 'datetime'];
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
     * @return BelongsTo<Membership, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'reviewed_by');
    }

    /**
     * @param  Builder<AnomalyFlag>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }
}
