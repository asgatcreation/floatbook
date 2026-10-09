<?php

namespace Database\Seeders;

use App\Actions\Float\FloatMovements;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\FloatMovementType;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\FloatMovement;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Provider;
use App\Models\User;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local development only: a small business with one account per role, opening balances
 * and a few transactions, so every screen can be tried quickly.
 *
 *   php artisan db:seed --class=LocalTestAccountsSeeder
 *
 * Every account uses the password "password" (see README → Local test accounts).
 * Never run this in production.
 */
class LocalTestAccountsSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(CurrentBusiness $tenancy): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LocalTestAccountsSeeder must never run in production.');
        }

        $business = Business::query()->firstOrCreate(['slug' => 'local-test-pos'], ['name' => 'Local Test POS']);

        $tenancy->runAs($business, function () use ($business, $tenancy): void {
            $ketu = Outlet::query()->firstOrCreate(['code' => 'KETU'], ['name' => 'Ketu Main', 'address' => '12 Ikorodu Road, Ketu, Lagos']);
            $ojota = Outlet::query()->firstOrCreate(['code' => 'OJOTA'], ['name' => 'Ojota', 'address' => 'Ojota Bus Stop, Lagos']);

            $people = [
                'owner' => ['owner@floatbook.test', 'Adewale Owner', Role::Owner, []],
                'supervisor' => ['supervisor@floatbook.test', 'Halima Supervisor', Role::Supervisor, [$ketu->id, $ojota->id]],
                'agent' => ['agent@floatbook.test', 'Tunde Agent', Role::Agent, [$ketu->id]],
                'accountant' => ['accountant@floatbook.test', 'Grace Accountant', Role::Accountant, []],
            ];

            $members = [];

            foreach ($people as $key => [$email, $name, $role, $outletIds]) {
                $user = User::query()->firstOrNew(['email' => $email]);
                $user->forceFill([
                    'name' => $name,
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'current_business_id' => $business->id,
                ])->save();

                $members[$key] = Membership::query()->firstOrCreate(['user_id' => $user->id], ['role' => $role]);
                $members[$key]->syncOutlets($outletIds);
            }

            if (FloatMovement::query()->exists()) {
                return; // Balances and sample transactions were already added on an earlier run.
            }

            $owner = ($members['owner'] ?? throw new RuntimeException('Owner account missing.'))->load('outlets');
            $tenancy->set($business, $owner);
            $accounts = app(Accounts::class);
            $float = app(FloatMovements::class);
            $providers = Provider::query()->active()->get()->keyBy('slug');

            $open = fn ($account, int $naira) => $float->request($owner, FloatMovementType::OpeningBalance, null, $account->id, Money::naira($naira), 'Starting balance');

            $open($accounts->headOffice(), 2_000_000);

            foreach ([$ketu, $ojota] as $outlet) {
                $open($accounts->cash($outlet), 450_000);
                $open($accounts->wallet($outlet, $providers['moniepoint']), 800_000);
                $open($accounts->wallet($outlet, $providers['opay']), 350_000);
            }

            $agent = ($members['agent'] ?? throw new RuntimeException('Agent account missing.'))->load('outlets');
            $tenancy->set($business, $agent);
            $record = app(RecordTransaction::class);

            $samples = [
                [TransactionType::CashWithdrawal, 10_000, 'moniepoint', TransactionStatus::Successful],
                [TransactionType::Transfer, 25_000, 'moniepoint', TransactionStatus::Successful],
                [TransactionType::Airtime, 1_000, 'opay', TransactionStatus::Successful],
                [TransactionType::CashWithdrawal, 40_000, 'opay', TransactionStatus::Successful],
                [TransactionType::BillPayment, 8_500, 'moniepoint', TransactionStatus::Successful],
                [TransactionType::CashWithdrawal, 20_000, 'moniepoint', TransactionStatus::Pending],
                [TransactionType::Data, 2_000, 'opay', TransactionStatus::Successful],
                [TransactionType::Transfer, 60_000, 'opay', TransactionStatus::Successful],
            ];

            foreach ($samples as [$type, $naira, $provider, $status]) {
                $record->handle($agent, [
                    'idempotency_key' => (string) Str::uuid(),
                    'outlet_id' => $ketu->id,
                    'provider_id' => $providers[$provider]->id,
                    'type' => $type,
                    'amount' => Money::naira($naira),
                    'status' => $status,
                    'customer_phone' => '080'.random_int(10_000_000, 99_999_999),
                    'reference' => (string) random_int(1_000_000_000, 9_999_999_999),
                ]);
            }
        });
    }
}
