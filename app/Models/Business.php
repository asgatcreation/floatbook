<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A POS business: the tenant. Everything else hangs off it.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $closing_time
 * @property bool $require_two_factor
 * @property bool $is_demo
 * @property array<string, mixed> $settings
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'timezone', 'closing_time', 'require_two_factor', 'settings'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'settings' => '{}',
    ];

    protected static function booted(): void
    {
        static::creating(function (Business $business): void {
            $business->slug ??= static::uniqueSlug($business->name);
        });
    }

    protected function casts(): array
    {
        return [
            'require_two_factor' => 'boolean',
            'is_demo' => 'boolean',
            'settings' => 'array',
        ];
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    /**
     * Relations from the tenant root bypass the tenant scope on purpose: the business
     * itself is the boundary, so these are always limited to its own rows.
     *
     * @return HasMany<Outlet, $this>
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class)->withoutGlobalScopes();
    }
}
