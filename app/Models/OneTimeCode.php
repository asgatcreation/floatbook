<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short numeric code sent by email (for example to verify an address). Only a hash is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 */
#[Fillable(['user_id', 'purpose', 'code_hash', 'expires_at'])]
#[Hidden(['code_hash'])]
class OneTimeCode extends Model
{
    public const PURPOSE_VERIFY_EMAIL = 'verify_email';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
