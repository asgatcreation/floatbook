<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An emailed invitation to join a business. Only a SHA-256 hash of the token is stored,
 * so a database leak cannot be used to accept invitations.
 *
 * @property string $id
 * @property string $business_id
 * @property string $email
 * @property Role $role
 * @property list<string> $outlet_ids
 * @property string $token_hash
 * @property int|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $inviter
 */
#[Fillable(['email', 'role', 'outlet_ids', 'token_hash', 'invited_by', 'expires_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    use BelongsToBusiness;

    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUlids;

    public const VALID_DAYS = 7;

    protected $attributes = [
        'outlet_ids' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'outlet_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /**
     * @param  Builder<Invitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
