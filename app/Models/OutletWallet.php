<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An e-wallet balance an outlet holds with a provider. Each terminal pays into one wallet;
 * two terminals on the same account share a wallet.
 *
 * @property string $id
 * @property string $business_id
 * @property string $outlet_id
 * @property string $provider_id
 * @property string $ledger_account_id
 * @property string|null $name
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Outlet $outlet
 * @property-read Provider $provider
 * @property-read LedgerAccount $account
 * @property-read Collection<int, Terminal> $terminals
 */
#[Fillable(['outlet_id', 'provider_id', 'ledger_account_id', 'name', 'is_active'])]
class OutletWallet extends Model
{
    use BelongsToBusiness, HasUlids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /** "Moniepoint" for a wallet of its own, or its given name, e.g. "Moniepoint · main account". */
    public function label(): string
    {
        return $this->name ?? $this->provider->name;
    }
}
