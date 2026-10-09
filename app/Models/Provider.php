<?php

namespace App\Models;

use App\Support\Ledger\Accounts;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment provider whose terminal or app the business uses (Moniepoint, OPay, Palmpay...).
 * FloatBook has no live connection to providers; this only labels wallets and rules.
 *
 * @property string $id
 * @property string $business_id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'slug', 'color', 'is_active', 'sort_order'])]
class Provider extends Model
{
    use BelongsToBusiness, HasUlids;

    /** Common Nigerian agent-banking providers, offered to every new business. */
    public const DEFAULTS = [
        ['name' => 'Moniepoint', 'slug' => 'moniepoint', 'color' => '#2D6BEA'],
        ['name' => 'OPay', 'slug' => 'opay', 'color' => '#14A37F'],
        ['name' => 'Palmpay', 'slug' => 'palmpay', 'color' => '#7A4BE0'],
    ];

    protected static function booted(): void
    {
        // Every outlet gets a wallet for a newly added provider.
        static::created(function (Provider $provider): void {
            $accounts = app(Accounts::class);
            Outlet::query()->each(fn (Outlet $outlet) => $accounts->wallet($outlet, $provider));
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<OutletWallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(OutletWallet::class);
    }

    /**
     * @param  Builder<Provider>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
