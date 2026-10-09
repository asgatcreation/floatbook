<?php

namespace App\Models;

use App\Enums\ChargeKind;
use App\Enums\TransactionType;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How much to charge, pay or deduct for a transaction type. A rule can apply to every
 * provider and outlet, or be narrowed to one provider and/or one outlet; the most
 * specific active rule wins.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $provider_id
 * @property string|null $outlet_id
 * @property TransactionType $transaction_type
 * @property ChargeKind $kind
 * @property string $collected_via
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Provider|null $provider
 * @property-read Outlet|null $outlet
 * @property-read Collection<int, ChargeRuleTier> $tiers
 */
#[Fillable(['provider_id', 'outlet_id', 'transaction_type', 'kind', 'collected_via', 'is_active'])]
class ChargeRule extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'kind' => ChargeKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ChargeRuleTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(ChargeRuleTier::class)->orderBy('min_amount');
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Higher is more specific: outlet + provider (3) > provider (2) > outlet (1) > everything (0).
     */
    public function specificity(): int
    {
        return ($this->provider_id !== null ? 2 : 0) + ($this->outlet_id !== null ? 1 : 0);
    }
}
