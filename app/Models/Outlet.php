<?php

namespace App\Models;

use App\Support\Ledger\Accounts;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical location (shop, kiosk, market stall) where agents serve customers.
 *
 * @property string $id
 * @property string $business_id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'code', 'address', 'is_active'])]
class Outlet extends Model
{
    use BelongsToBusiness;

    /** @use HasFactory<OutletFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        // Cash drawer, income/expense accounts, and a terminal and wallet per active provider.
        static::created(fn (Outlet $outlet) => app(Accounts::class)->setUpOutlet($outlet));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Membership, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class)->withPivot('business_id');
    }

    /**
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /**
     * @return HasMany<OutletWallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(OutletWallet::class);
    }

    /**
     * @param  Builder<Outlet>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
