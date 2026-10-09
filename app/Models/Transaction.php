<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer transaction at the counter. Amounts are kobo. The charge, fee and
 * commission are snapshots taken when it was recorded.
 *
 * @property string $id
 * @property string $business_id
 * @property string $outlet_id
 * @property string $provider_id
 * @property string $membership_id
 * @property TransactionType $type
 * @property int $amount
 * @property int $charge
 * @property string $charge_collected_via
 * @property int $provider_fee
 * @property int $commission
 * @property TransactionStatus $status
 * @property bool $cash_paid_while_pending
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $reference
 * @property string|null $note
 * @property string $source
 * @property string $idempotency_key
 * @property CarbonImmutable|null $client_recorded_at
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
 * @property-read Provider $provider
 * @property-read Membership $membership
 */
#[Fillable([
    'outlet_id', 'provider_id', 'membership_id', 'type', 'amount', 'charge', 'charge_collected_via',
    'provider_fee', 'commission', 'status', 'cash_paid_while_pending', 'customer_name', 'customer_phone',
    'reference', 'note', 'source', 'idempotency_key', 'client_recorded_at', 'occurred_at', 'business_date',
])]
class Transaction extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'charge' => 'integer',
            'provider_fee' => 'integer',
            'commission' => 'integer',
            'cash_paid_while_pending' => 'boolean',
            'client_recorded_at' => 'datetime',
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
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Profit = customer charge + provider commission − provider fee.
     */
    public function profit(): Money
    {
        return Money::kobo($this->charge + $this->commission - $this->provider_fee);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Transactions that count towards balances and reports.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNull('voided_at')->where('status', TransactionStatus::Successful);
    }
}
