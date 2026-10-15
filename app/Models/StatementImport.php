<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provider statement (CSV or Excel export) uploaded to check against what was
 * recorded at the counter. The file itself is never kept: its rows are stored here.
 *
 * @property string $id
 * @property string $business_id
 * @property string $outlet_id
 * @property string $provider_id
 * @property string $terminal_id
 * @property string $original_filename
 * @property string $status
 * @property list<string> $headers
 * @property array<string, string|null>|null $column_mapping
 * @property int $row_count
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Outlet $outlet
 * @property-read Provider $provider
 * @property-read Terminal $terminal
 * @property-read User|null $uploader
 */
#[Fillable(['outlet_id', 'provider_id', 'terminal_id', 'original_filename', 'status', 'headers', 'column_mapping', 'row_count', 'uploaded_by'])]
class StatementImport extends Model
{
    use BelongsToBusiness, HasUlids;

    public const MAX_ROWS = 5_000;

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'column_mapping' => 'array',
            'row_count' => 'integer',
            'committed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StatementImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(StatementImportRow::class)->orderBy('row_number');
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
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
