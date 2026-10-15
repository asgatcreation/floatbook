<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a cash shortage that a staff member is responsible for.
 *
 * @property string $id
 * @property string $business_id
 * @property string $reconciliation_id
 * @property string $membership_id
 * @property int $amount
 * @property string $status outstanding | recovered | written_off | cancelled
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $recovery_entry_id
 * @property-read Reconciliation $reconciliation
 * @property-read Membership $membership
 */
#[Fillable(['reconciliation_id', 'membership_id', 'amount', 'status'])]
class StaffShortage extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Reconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
