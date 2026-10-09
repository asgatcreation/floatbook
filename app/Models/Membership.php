<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A person's place in a business: their role, the outlets they cover and their quick-unlock PIN.
 *
 * @property string $id
 * @property string $business_id
 * @property int $user_id
 * @property Role $role
 * @property MembershipStatus $status
 * @property string|null $pin_hash
 * @property int $pin_failed_attempts
 * @property CarbonImmutable|null $last_active_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'role', 'status'])]
#[Hidden(['pin_hash'])]
class Membership extends Model
{
    use BelongsToBusiness;

    /** @use HasFactory<MembershipFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'status' => MembershipStatus::class,
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Outlet, $this>
     */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class)->withPivot('business_id');
    }

    /**
     * Assign outlets, writing business_id on the pivot so the composite foreign keys
     * prove at the database level that the outlets belong to this business.
     *
     * @param  iterable<string>  $outletIds
     */
    public function syncOutlets(iterable $outletIds): void
    {
        $this->outlets()->sync(
            collect($outletIds)->mapWithKeys(fn (string $id) => [$id => ['business_id' => $this->business_id]])->all(),
        );
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function allows(Permission $permission): bool
    {
        return $this->isActive() && $this->role->allows($permission);
    }

    /**
     * Whether this member may act on the given outlet. Owners and accountants cover every outlet.
     */
    public function coversOutlet(Outlet|string $outlet): bool
    {
        if (! $this->role->isOutletScoped()) {
            return true;
        }

        $outletId = $outlet instanceof Outlet ? $outlet->id : $outlet;

        return $this->outlets->contains('id', $outletId);
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    /**
     * @param  Builder<Membership>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active);
    }
}
