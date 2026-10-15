<?php

namespace App\Support\Ledger;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Models\Provider;
use App\Models\Terminal;
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

    /**
     * The ledger account behind a terminal: the wallet it pays into.
     */
    public function forTerminal(Terminal|string $terminal): LedgerAccount
    {
        $walletId = $terminal instanceof Terminal
            ? $terminal->outlet_wallet_id
            : Terminal::query()->whereKey($terminal)->value('outlet_wallet_id');

        return $this->cache['wallet|'.$walletId] ??= OutletWallet::query()->with('account')->whereKey($walletId)->firstOrFail()->account;
    }

    /**
     * A new wallet (with its own ledger account) at an outlet with a provider.
     */
    public function openWallet(Outlet $outlet, Provider $provider, ?string $name = null): OutletWallet
    {
        $account = LedgerAccount::query()->create([
            'type' => LedgerAccountType::ProviderWallet,
            'outlet_id' => $outlet->id,
            'provider_id' => $provider->id,
            'name' => "{$outlet->name} · ".($name ?? "{$provider->name} wallet"),
        ]);

        return OutletWallet::query()->create([
            'outlet_id' => $outlet->id,
            'provider_id' => $provider->id,
            'ledger_account_id' => $account->id,
            'name' => $name,
        ]);
    }

    /**
     * Make sure the outlet has at least one terminal for the provider; the first one gets
     * its own wallet and is named after the provider ("Moniepoint POS").
     */
    public function ensureTerminal(Outlet $outlet, Provider $provider): Terminal
    {
        $existing = Terminal::query()->where('outlet_id', $outlet->id)->where('provider_id', $provider->id)->orderBy('created_at')->first();

        if ($existing !== null) {
            return $existing;
        }

        $wallet = $this->openWallet($outlet, $provider);

        return Terminal::query()->create([
            'outlet_id' => $outlet->id,
            'provider_id' => $provider->id,
            'outlet_wallet_id' => $wallet->id,
            'name' => "{$provider->name} POS",
        ]);
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
     * Create every account an outlet needs, and a terminal (with its wallet) per active provider.
     */
    public function setUpOutlet(Outlet $outlet): void
    {
        foreach (LedgerAccountType::cases() as $type) {
            if ($type->isOutletLevel() && $type !== LedgerAccountType::ProviderWallet) {
                $this->forOutlet($outlet, $type);
            }
        }

        foreach (Provider::query()->active()->get() as $provider) {
            $this->ensureTerminal($outlet, $provider);
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
