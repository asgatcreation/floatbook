<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note or status change in a reversal case's history.
 *
 * @property int $id
 * @property string $reversal_case_id
 * @property int|null $user_id
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'from_status', 'to_status', 'note'])]
class ReversalCaseUpdate extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
