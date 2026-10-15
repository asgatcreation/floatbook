<?php

namespace App\Models;

use App\Support\Tenancy\BusinessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an uploaded statement, normalised and matched against FloatBook.
 *
 * match_status: pending | matched | missing_in_floatbook | amount_mismatch | status_mismatch
 *               | provider_failed | duplicate | invalid | created
 *
 * @property int $id
 * @property string $business_id
 * @property string $statement_import_id
 * @property int $row_number
 * @property array<int|string, mixed> $raw
 * @property string|null $reference
 * @property int|null $amount
 * @property CarbonImmutable|null $occurred_at
 * @property string|null $type
 * @property string|null $provider_status
 * @property string $row_hash
 * @property string $match_status
 * @property string|null $problem
 * @property string|null $matched_transaction_id
 * @property-read Transaction|null $transaction
 */
#[ScopedBy(BusinessScope::class)]
class StatementImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'amount' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StatementImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class, 'statement_import_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }
}
