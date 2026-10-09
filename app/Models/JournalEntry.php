<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A balanced set of ledger lines describing one money event. Written only by App\Support\Ledger\Ledger.
 * Append-only: a mistake is corrected with a reversing entry, never an edit.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $outlet_id
 * @property string $type
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $memo
 * @property int|null $posted_by
 * @property string|null $reverses_entry_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $created_at
 */
class JournalEntry extends Model
{
    use BelongsToBusiness, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal entries cannot be changed. Post a reversing entry instead.'));
        static::deleting(fn () => throw new LogicException('Journal entries cannot be deleted. Post a reversing entry instead.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'business_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
