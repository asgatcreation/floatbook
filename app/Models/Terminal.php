<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A POS terminal at an outlet, e.g. "Terminal 1", a Moniepoint machine. Money it takes
 * lands in its wallet, which it may share with another terminal on the same account.
 *
 * @property string $id
 * @property string $business_id
 * @property string $outlet_id
 * @property string $provider_id
 * @property string $outlet_wallet_id
 * @property string $name
 * @property string|null $terminal_code
 * @property string|null $serial_number
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Outlet $outlet
 * @property-read Provider $provider
 * @property-read OutletWallet $wallet
 */
#[Fillable(['outlet_id', 'provider_id', 'outlet_wallet_id', 'name', 'terminal_code', 'serial_number', 'is_active'])]
class Terminal extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
     * @return BelongsTo<OutletWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(OutletWallet::class, 'outlet_wallet_id');
    }

    /**
     * Staff assigned to this terminal.
     *
     * @return BelongsToMany<Membership, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class)->withPivot('business_id');
    }

    /** "Terminal 1 · Moniepoint", or just "Moniepoint POS" when the name already says it. */
    public function label(): string
    {
        return str_contains(mb_strtolower($this->name), mb_strtolower($this->provider->name))
            ? $this->name
            : $this->name.' · '.$this->provider->name;
    }

    /**
     * @param  Builder<Terminal>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
