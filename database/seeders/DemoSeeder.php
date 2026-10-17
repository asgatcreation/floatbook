<?php

namespace Database\Seeders;

use App\Actions\Expenses\Expenses;
use App\Actions\Float\FloatMovements;
use App\Actions\Reconciliation\Reconciliations;
use App\Actions\Reversals\ReversalCases;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\ExpenseCategory;
use App\Enums\FloatMovementType;
use App\Enums\ReversalStatus;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AnomalyFlag;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Provider;
use App\Models\Reconciliation;
use App\Models\ReversalCase;
use App\Models\Terminal;
use App\Models\User;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Ade & Sons POS": the business visitors try with one click.
 *
 * Two weeks of believable trading at two outlets, with four staff, four terminals,
 * expenses, counted days, a reversal still open and one flag to look at — enough that
 * every screen has something real in it.
 *
 * It is rebuilt from scratch each time (everything belonging to the demo business is
 * deleted first), so anything a visitor does is wiped by the nightly reset.
 */
class DemoSeeder extends Seeder
{
    public const SLUG = 'ade-and-sons-pos';

    public const PASSWORD = 'demo1234';

    /** Four people a visitor can sign in as. */
    public const PEOPLE = [
        'owner' => ['demo-owner@floatbook.app', 'Ade Balogun', Role::Owner],
        'supervisor' => ['demo-supervisor@floatbook.app', 'Chidinma Eze', Role::Supervisor],
        'agent' => ['demo-agent@floatbook.app', 'Sola Adeyemi', Role::Agent],
        'accountant' => ['demo-accountant@floatbook.app', 'Ngozi Okonkwo', Role::Accountant],
    ];

    /** How many days of trading to build. Tests use fewer to stay quick. */
    public static int $days = 14;

    public function run(CurrentBusiness $tenancy): void
    {
        // Dates are written by moving the clock, so put back whatever the caller had.
        $clock = CarbonImmutable::hasTestNow()
            ? CarbonImmutable::instance(CarbonImmutable::getTestNow())
            : null;

        try {
            $this->build($tenancy, $clock);
        } finally {
            CarbonImmutable::setTestNow($clock);
        }
    }

    private function build(CurrentBusiness $tenancy, ?CarbonImmutable $clock): void
    {
        $this->wipe();

        $business = Business::query()->create([
            'name' => 'Ade & Sons POS',
            'slug' => self::SLUG,
            'timezone' => 'Africa/Lagos',
            'closing_time' => '19:30:00',
        ]);
        $business->forceFill([
            'is_demo' => true,
            // A busy shop: only entries above ₦100,000 are worth a flag here.
            'settings' => ['large_amount' => 100_000_00],
        ])->save();

        $tenancy->runAs($business, function () use ($business, $tenancy, $clock): void {
            [$outlets, $members, $terminals] = $this->setUp($business);

            $this->openingFloat($members['owner'], $outlets, $terminals, $clock);
            $this->trade($business, $tenancy, $members, $outlets, $terminals, $clock);
        });

        $this->command->info('Demo business ready: '.self::PEOPLE['owner'][0].' / '.self::PASSWORD);
    }

    /**
     * Remove the whole demo business. Its rows cascade from `businesses`, and the demo
     * users are only ever members of it.
     */
    private function wipe(): void
    {
        $business = Business::query()->where('slug', self::SLUG)->first();

        if ($business !== null) {
            DB::transaction(function () use ($business): void {
                // The audit log and journal are append-only on purpose: deleting from them
                // needs the purge switch, which only lasts for this transaction.
                DB::statement("SET LOCAL floatbook.allow_audit_purge = 'on'");

                // Audit rows deliberately don't cascade, so they go first.
                DB::table('audit_logs')->where('business_id', $business->id)->delete();
                $business->delete();
            });
        }

        User::query()->whereIn('email', array_column(self::PEOPLE, 0))->delete();
    }

    /**
     * @return array{0: array<string, Outlet>, 1: array<string, Membership>, 2: array<string, Terminal>}
     */
    private function setUp(Business $business): array
    {
        $outlets = [
            'ketu' => Outlet::query()->create(['name' => 'Ketu Main', 'code' => 'KETU', 'address' => '12 Ikorodu Road, Ketu, Lagos']),
            'ojota' => Outlet::query()->create(['name' => 'Ojota Junction', 'code' => 'OJOTA', 'address' => 'Ojota Bus Stop, Lagos']),
        ];

        $providers = Provider::query()->get()->keyBy('slug');
        $accounts = app(Accounts::class);

        // Ketu runs two Moniepoint machines; the second shares the first's account.
        $terminals = [
            'ketu_mp1' => $accounts->ensureTerminal($outlets['ketu'], $providers['moniepoint']),
            'ketu_opay' => $accounts->ensureTerminal($outlets['ketu'], $providers['opay']),
            'ojota_mp' => $accounts->ensureTerminal($outlets['ojota'], $providers['moniepoint']),
            'ojota_palm' => $accounts->ensureTerminal($outlets['ojota'], $providers['palmpay']),
        ];
        $terminals['ketu_mp1']->forceFill(['name' => 'Terminal 1', 'terminal_code' => '2MPT4417'])->save();
        $terminals['ketu_opay']->forceFill(['name' => 'OPay counter', 'terminal_code' => 'OP88231'])->save();
        $terminals['ojota_mp']->forceFill(['name' => 'Terminal 1', 'terminal_code' => '2MPT9903'])->save();
        $terminals['ojota_palm']->forceFill(['name' => 'Palmpay counter'])->save();

        $terminals['ketu_mp2'] = Terminal::query()->create([
            'outlet_id' => $outlets['ketu']->id,
            'provider_id' => $providers['moniepoint']->id,
            'outlet_wallet_id' => $terminals['ketu_mp1']->outlet_wallet_id,
            'name' => 'Terminal 2',
            'terminal_code' => '2MPT4418',
        ]);

        $members = [];
        $outletsFor = [
            'owner' => [],
            'supervisor' => [$outlets['ketu']->id, $outlets['ojota']->id],
            'agent' => [$outlets['ketu']->id],
            'accountant' => [],
        ];

        foreach (self::PEOPLE as $key => [$email, $name, $role]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
                'current_business_id' => $business->id,
            ]);

            $members[$key] = Membership::query()->create(['user_id' => $user->id, 'role' => $role]);
            $members[$key]->syncOutlets($outletsFor[$key]);
            $members[$key]->forceFill(['last_active_at' => now()->subMinutes(random_int(3, 240))])->save();
        }

        // Sola looks after the two Moniepoint machines at Ketu.
        /** @var array<string, Membership> $members */
        $members['agent']->syncTerminals([$terminals['ketu_mp1']->id, $terminals['ketu_mp2']->id]);

        return [$outlets, $members, $terminals];
    }

    /**
     * @param  array<string, Outlet>  $outlets
     * @param  array<string, Terminal>  $terminals
     */
    private function openingFloat(Membership $owner, array $outlets, array $terminals, ?CarbonImmutable $clock): void
    {
        $accounts = app(Accounts::class);
        $float = app(FloatMovements::class);
        $start = CarbonImmutable::now('Africa/Lagos')->subDays(self::$days)->setTime(8, 0);

        // The books are append-only, so the clock is moved rather than the entries:
        // everything below is written as if it happened at the start of the fortnight.
        CarbonImmutable::setTestNow($start);

        $open = fn ($account, int $naira) => $float->request(
            $owner, FloatMovementType::OpeningBalance, null, $account->id, Money::naira($naira), 'Opening float',
        );

        $open($accounts->headOffice(), 3_500_000);
        $open($accounts->cash($outlets['ketu']), 620_000);
        $open($accounts->cash($outlets['ojota']), 410_000);
        $open($accounts->forTerminal($terminals['ketu_mp1']), 1_250_000);
        $open($accounts->forTerminal($terminals['ketu_opay']), 480_000);
        $open($accounts->forTerminal($terminals['ojota_mp']), 900_000);
        $open($accounts->forTerminal($terminals['ojota_palm']), 260_000);

        CarbonImmutable::setTestNow($clock);

        // Ketu's drawer gets a low-balance alert, so the owner sees it working.
        $accounts->cash($outlets['ketu'])->forceFill(['low_balance_threshold' => 150_000_00])->save();
    }

    /**
     * Two weeks of trading, ending with today still open.
     *
     * @param  array<string, Membership>  $members
     * @param  array<string, Outlet>  $outlets
     * @param  array<string, Terminal>  $terminals
     */
    private function trade(Business $business, CurrentBusiness $tenancy, array $members, array $outlets, array $terminals, ?CarbonImmutable $clock): void
    {
        $record = app(RecordTransaction::class);
        $expenses = app(Expenses::class);
        $accounts = app(Accounts::class);
        $today = CarbonImmutable::now('Africa/Lagos')->startOfDay();

        $counters = [
            ['member' => $members['agent'], 'terminals' => [$terminals['ketu_mp1'], $terminals['ketu_mp2'], $terminals['ketu_opay']]],
            ['member' => $members['supervisor'], 'terminals' => [$terminals['ojota_mp'], $terminals['ojota_palm']]],
        ];

        for ($back = self::$days; $back >= 0; $back--) {
            $day = $today->subDays($back);
            // Each day's work is written as if it were that day.
            CarbonImmutable::setTestNow($back === 0 ? null : $day->setTime(18, 30));

            // Sundays are quiet.
            $entries = $day->isSunday() ? random_int(3, 6) : random_int(9, 16);

            foreach ($counters as $counter) {
                foreach (range(1, $entries) as $i) {
                    $this->entry($record, $counter['member'], $counter['terminals'], $day, $back === 0);
                }
            }

            if ($back % 2 === 1) {
                $expenses->record(
                    $members['owner'],
                    $outlets[$back % 8 === 1 ? 'ketu' : 'ojota'],
                    [ExpenseCategory::Fuel, ExpenseCategory::Data, ExpenseCategory::Transport][$back % 3],
                    Money::naira([3_500, 2_000, 1_200][$back % 3]),
                    $accounts->cash($outlets[$back % 8 === 1 ? 'ketu' : 'ojota']),
                    ['Generator diesel', 'Data bundle for the terminals', 'Transport to the bank'][$back % 3],
                    (string) Str::uuid(),
                );
            }
        }

        CarbonImmutable::setTestNow($clock);

        $this->reversals($members);
        $this->reviewFlags($members);
        $this->countDays($tenancy, $business, $members, $outlets, $today, $clock);
    }

    /**
     * @param  list<Terminal>  $terminals
     */
    private function entry(RecordTransaction $record, Membership $by, array $terminals, CarbonImmutable $day, bool $isToday): void
    {
        $types = [
            [TransactionType::CashWithdrawal, [2_000, 5_000, 10_000, 20_000], 55],
            [TransactionType::Transfer, [5_000, 15_000, 30_000, 50_000], 25],
            [TransactionType::Airtime, [500, 1_000, 2_000], 10],
            [TransactionType::Data, [1_000, 2_000], 5],
            [TransactionType::BillPayment, [3_500, 8_000], 5],
        ];

        $roll = random_int(1, 100);
        $running = 0;
        [$type, $amounts] = [$types[0][0], $types[0][1]];

        foreach ($types as [$t, $values, $weight]) {
            $running += $weight;

            if ($roll <= $running) {
                [$type, $amounts] = [$t, $values];

                break;
            }
        }

        // Spread across the working day, and never later than "now" for today.
        $hour = random_int(8, $isToday ? max(9, (int) CarbonImmutable::now('Africa/Lagos')->format('G')) : 18);
        $at = $day->setTime($hour, random_int(0, 59));

        if ($at->isFuture()) {
            $at = CarbonImmutable::now('Africa/Lagos')->subMinutes(random_int(5, 90));
        }

        // Now and then one is still pending or fails.
        $status = match (true) {
            random_int(1, 40) === 1 => TransactionStatus::Pending,
            random_int(1, 60) === 1 => TransactionStatus::Failed,
            default => TransactionStatus::Successful,
        };

        $record->handle($by, [
            'idempotency_key' => (string) Str::uuid(),
            'terminal_id' => $terminals[array_rand($terminals)]->id,
            'type' => $type,
            'amount' => Money::naira($amounts[array_rand($amounts)]),
            'status' => $status,
            'cash_paid_while_pending' => $status === TransactionStatus::Pending && random_int(1, 2) === 1,
            'customer_phone' => '080'.random_int(10_000_000, 99_999_999),
            'reference' => (string) random_int(1_000_000_000, 9_999_999_999),
            'source' => 'import', // lets the entry keep its own date
            'client_recorded_at' => $at,
            'any_terminal' => true,
        ]);
    }

    /**
     * Leave the reversal tracker with something to work on: one settled, one still open.
     *
     * @param  array<string, Membership>  $members
     */
    private function reversals(array $members): void
    {
        $cases = app(ReversalCases::class);
        $open = ReversalCase::query()->with('transaction')->where('status', ReversalStatus::Open)->oldest()->get();

        // Everything older is dealt with, the way a business on top of things would.
        $settled = $open->slice(0, max(0, $open->count() - 3));

        foreach ($settled as $i => $case) {
            match ($i % 3) {
                0 => $cases->update($case, $members['supervisor'], ReversalStatus::Reversed, 'The provider reversed it; customer paid back.', 'MP-88'.(4000 + $i), 'cash_recovered'),
                1 => $cases->update($case, $members['supervisor'], ReversalStatus::ResolvedSuccessful, 'It went through after all; the customer got the value.', null),
                default => $cases->update($case, $members['supervisor'], ReversalStatus::Reversed, 'Reversed the next morning.', 'OP-55'.(2000 + $i), 'cash_recovered'),
            };
        }

        // Two still being chased, one of them escalated: the tracker has real work in it.
        if ($chasing = $open->slice(-3)->first()) {
            $cases->update($chasing, $members['supervisor'], ReversalStatus::Escalated, 'Called support twice, still waiting on them.', 'MP-884377');
        }
    }

    /**
     * Count the days up to yesterday, so today is still open for a visitor to try.
     *
     * @param  array<string, Membership>  $members
     * @param  array<string, Outlet>  $outlets
     */
    private function countDays(CurrentBusiness $tenancy, Business $business, array $members, array $outlets, CarbonImmutable $today, ?CarbonImmutable $clock): void
    {
        $reconciliations = app(Reconciliations::class);

        for ($back = self::$days; $back >= 1; $back--) {
            $day = $today->subDays($back);

            CarbonImmutable::setTestNow($day->setTime(19, 45));

            foreach ($outlets as $outlet) {
                $expected = $reconciliations->expected($outlet, $day);

                // Most days balance; one in six is a little short, which is life.
                $off = random_int(1, 6) === 1 ? -random_int(2, 15) * 100_00 : 0;
                $counted = max(0, $expected['cash'] + $off);

                $reconciliations->submit(
                    $members['supervisor'],
                    $outlet,
                    $day,
                    $this->notesFor($counted),
                    $expected['wallets']->mapWithKeys(fn (array $w) => [$w['wallet']->id => $w['expected']])->all(),
                    $off === 0 ? null : 'Short at the end of the day; still checking with the agent.',
                );

                $reconciliation = Reconciliation::query()->where('outlet_id', $outlet->id)
                    ->where('business_date', $day->toDateString())->firstOrFail();

                $reconciliations->approve(
                    $members['owner'],
                    $reconciliation,
                    $off !== 0 && random_int(1, 2) === 1 ? [$members['agent']->id => (int) abs($off)] : [],
                    $off === 0 ? null : 'Agent to make it up.',
                );
            }
        }

        CarbonImmutable::setTestNow($clock);
    }

    /**
     * Break an amount into notes the way a drawer really holds them.
     *
     * @return array<int|string, int>
     */
    private function notesFor(int $kobo): array
    {
        $naira = intdiv($kobo, 100);
        $notes = [];

        foreach (Reconciliations::NOTES as $note) {
            $notes[$note] = intdiv($naira, $note);
            $naira -= $notes[$note] * $note;
        }

        $notes['coins'] = $naira * 100 + $kobo % 100;

        return $notes;
    }

    /**
     * Most flags have already been looked at; a couple are left for the visitor.
     *
     * @param  array<string, Membership>  $members
     */
    private function reviewFlags(array $members): void
    {
        $flags = AnomalyFlag::query()->open()->oldest()->get();

        foreach ($flags->slice(0, max(0, $flags->count() - 2)) as $i => $flag) {
            $flag->forceFill([
                'status' => $i % 5 === 0 ? 'confirmed' : 'dismissed',
                'reviewed_by' => $members['owner']->id,
                'reviewed_at' => $flag->created_at?->addHours(3),
                'review_note' => $i % 5 === 0
                    ? 'Recorded twice by mistake; the second one was voided.'
                    : 'Checked the receipt with the agent. All fine.',
            ])->save();
        }
    }
}
