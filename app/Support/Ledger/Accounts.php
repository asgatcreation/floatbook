<?php

namespace App\Support\Ledger;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Models\Provider;
use App\Support\Tenancy\CurrentBusiness;

/**
 * Finds (or creates, the first time) the ledger accounts of the current business.
 * Accounts are normally created when a business, outlet or provider is set up; creating
 * on demand keeps older data working if a new account type is added later.
 */
class Accounts
{
    /** @var array<string, LedgerAccount> */
    private array $cache = [];

    public function __construct(private CurrentBusiness $tenancy) {}

    public function forOutlet(Outlet|string $outlet, LedgerAccountType $type): LedgerAccount
    {
        if (! $type->isOutletLevel() || $type === LedgerAccountType::ProviderWallet) {
            throw new \InvalidArgumentException("{$type->value} is not a single per-outlet account.");
        }

        $outlet = $outlet instanceof Outlet ? $outlet : Outlet::query()->findOrFail($outlet);

        return $this->find($type, $outlet->id, null, "{$outlet->name} · {$type->label()}");
    }

    public function cash(Outlet|string $outlet): LedgerAccount
    {
        return $this->forOutlet($outlet, LedgerAccountType::CashDrawer);
    }

    public function wallet(Outlet|string $outlet, Provider|string $provider): LedgerAccount
    {
        $outletId = $outlet instanceof Outlet ? $outlet->id : $outlet;
        $providerId = $provider instanceof Provider ? $provider->id : $provider;

        $wallet = OutletWallet::query()->with('account')
            ->where('outlet_id', $outletId)->where('provider_id', $providerId)->first();

        if ($wallet !== null) {
            return $wallet->account;
        }

        $outlet = $outlet instanceof Outlet ? $outlet : Outlet::query()->findOrFail($outletId);
        $provider = $provider instanceof Provider ? $provider : Provider::query()->findOrFail($providerId);

        $account = $this->find(LedgerAccountType::ProviderWallet, $outlet->id, $provider->id, "{$outlet->name} · {$provider->name} wallet");

        OutletWallet::query()->create([
            'outlet_id' => $outlet->id,
            'provider_id' => $provider->id,
            'ledger_account_id' => $account->id,
        ]);

        return $account;
    }

    public function headOffice(): LedgerAccount
    {
        return $this->find(LedgerAccountType::HeadOffice, null, null, LedgerAccountType::HeadOffice->label());
    }

    public function ownerCapital(): LedgerAccount
    {
        return $this->find(LedgerAccountType::OwnerCapital, null, null, LedgerAccountType::OwnerCapital->label());
    }

    /**
     * Create every account an outlet needs, including a wallet per active provider.
     */
    public function setUpOutlet(Outlet $outlet): void
    {
        foreach (LedgerAccountType::cases() as $type) {
            if ($type->isOutletLevel() && $type !== LedgerAccountType::ProviderWallet) {
                $this->forOutlet($outlet, $type);
            }
        }

        foreach (Provider::query()->active()->get() as $provider) {
            $this->wallet($outlet, $provider);
        }
    }

    private function find(LedgerAccountType $type, ?string $outletId, ?string $providerId, string $name): LedgerAccount
    {
        $key = implode('|', [$this->tenancy->id(), $type->value, $outletId, $providerId]);

        return $this->cache[$key] ??= LedgerAccount::query()->firstOrCreate(
            ['type' => $type, 'outlet_id' => $outletId, 'provider_id' => $providerId],
            ['name' => $name],
        );
    }
}
