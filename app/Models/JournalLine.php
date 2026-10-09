<?php

namespace App\Models;

use App\Support\Tenancy\BusinessScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit (positive) or credit (negative) line of a journal entry, in kobo.
 *
 * @property int $id
 * @property string $business_id
 * @property string $journal_entry_id
 * @property string $ledger_account_id
 * @property int $amount
 * @property-read LedgerAccount $account
 */
#[ScopedBy(BusinessScope::class)]
class JournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
