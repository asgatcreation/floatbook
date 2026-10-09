<?php

namespace App\Console\Commands;

use App\Actions\Transactions\RecordTransaction;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\Membership;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Used by the concurrency test: several copies run at the same time, each recording
 * transactions against the same outlet, to prove the row locks keep balances exact.
 */
#[Signature('floatbook:ledger-stress {business} {membership} {outlet} {provider} {count=25}')]
#[Description('Record many transactions quickly (testing only)')]
class LedgerStressTest extends Command
{
    protected $hidden = true;

    public function handle(CurrentBusiness $tenancy, RecordTransaction $record): int
    {
        if (app()->isProduction()) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $business = Business::query()->findOrFail($this->argument('business'));

        $tenancy->runAs($business, function () use ($tenancy, $record, $business): void {
            $membership = Membership::query()->findOrFail($this->argument('membership'));
            $tenancy->set($business, $membership);

            for ($i = 0; $i < (int) $this->argument('count'); $i++) {
                $record->handle($membership, [
                    'idempotency_key' => (string) Str::uuid(),
                    'outlet_id' => (string) $this->argument('outlet'),
                    'provider_id' => (string) $this->argument('provider'),
                    'type' => $i % 2 === 0 ? TransactionType::CashWithdrawal : TransactionType::Transfer,
                    'amount' => Money::naira(1_000 + $i),
                    'status' => TransactionStatus::Successful,
                    'source' => 'stress_test',
                ]);
            }
        });

        return self::SUCCESS;
    }
}
