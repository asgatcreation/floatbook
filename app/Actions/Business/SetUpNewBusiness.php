<?php

namespace App\Actions\Business;

use App\Models\Business;
use App\Models\ChargeRule;
use App\Models\Outlet;
use App\Models\Provider;
use App\Support\Charges\StarterChargeRules;
use App\Support\Ledger\Accounts;
use App\Support\Tenancy\CurrentBusiness;

/**
 * Everything a business needs before its first transaction: the usual providers,
 * head-office and capital accounts, starter charge rules, and each outlet's accounts.
 * Safe to run again: it only adds what is missing.
 */
class SetUpNewBusiness
{
    public function __construct(private CurrentBusiness $tenancy) {}

    public function __invoke(Business $business): void
    {
        $this->tenancy->runAs($business, function (): void {
            foreach (Provider::DEFAULTS as $i => $provider) {
                Provider::query()->firstOrCreate(['slug' => $provider['slug']], [...$provider, 'sort_order' => $i]);
            }

            $accounts = app(Accounts::class);
            $accounts->headOffice();
            $accounts->ownerCapital();

            if (! ChargeRule::query()->exists()) {
                StarterChargeRules::install();
            }

            Outlet::query()->each(fn (Outlet $outlet) => $accounts->setUpOutlet($outlet));
        });
    }
}
