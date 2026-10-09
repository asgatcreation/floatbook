<?php

namespace App\Models;

use App\Enums\FloatMovementStatus;
use App\Enums\FloatMovementType;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money moved between accounts that isn't a customer transaction: top-ups, transfers
 * between outlets, cash pickups, cash/wallet swaps and opening balances. Staff can
 * request one; it only touches the ledger once approved.
 *
 * @property string $id
 * @property string $business_id
 * @property FloatMovementType $type
 * @property string|null $from_account_id
 * @property string $to_account_id
 * @property int $amount
 * @property FloatMovementStatus $status
 * @property string|null $reason
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $journal_entry_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read LedgerAccount|null $fromAccount
 * @property-read LedgerAccount $toAccount
 * @property-read User|null $requester
 * @property-read User|null $decider
 */
#[Fillable(['type', 'from_account_id', 'to_account_id', 'amount', 'status', 'reason', 'requested_by'])]
class FloatMovement extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => FloatMovementType::class,
            'status' => FloatMovementStatus::class,
            'amount' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'to_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
