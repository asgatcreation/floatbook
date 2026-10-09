<?php

namespace App\Models;

use App\Enums\LedgerAccountType;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account in the business's chart of accounts, e.g. "Ketu Main · Cash in drawer".
 * `balance` is a cache of the sum of its journal lines, kept in step under row locks.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $outlet_id
 * @property string|null $provider_id
 * @property LedgerAccountType $type
 * @property string $name
 * @property int $balance
 * @property int|null $low_balance_threshold
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Outlet|null $outlet
 * @property-read Provider|null $provider
 */
#[Fillable(['outlet_id', 'provider_id', 'type', 'name', 'low_balance_threshold'])]
class LedgerAccount extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => LedgerAccountType::class,
            'balance' => 'integer',
            'low_balance_threshold' => 'integer',
        ];
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
     * The balance as a person would read it (income and capital shown as positive).
     */
    public function displayBalance(): Money
    {
        return Money::kobo($this->type->isCreditNormal() ? -$this->balance : $this->balance);
    }

    public function isLow(): bool
    {
        return $this->low_balance_threshold !== null && $this->balance < $this->low_balance_threshold;
    }
}
