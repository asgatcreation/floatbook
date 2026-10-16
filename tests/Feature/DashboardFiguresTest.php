<?php

use App\Actions\Expenses\Expenses;
use App\Actions\Transactions\RecordTransaction;
use App\Actions\Transactions\VoidTransaction;
use App\Enums\ExpenseCategory;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 15:00:00', 'Africa/Lagos'));

    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->supervisor = addMember($this->business, Role::Supervisor, [$this->ojota]);
});

function sale(object $test, Outlet $outlet, int $naira, ?Membership $by = null, TransactionStatus $status = TransactionStatus::Successful): Transaction
{
    $by ??= $test->ownerMembership;

    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($by, [
        'idempotency_key' => (string) Str::uuid(),
        'terminal_id' => terminalAt($test->business, $outlet)->id,
        'type' => TransactionType::CashWithdrawal,
        'amount' => Money::naira($naira),
        'status' => $status,
    ])->transaction, $by);
}

test('profit is charges and commissions, less fees and expenses; voided and failed entries do not count', function () {
    sale($this, $this->ketu, 10_000);                       // charge ₦200, fee ₦50
    $voided = sale($this, $this->ketu, 10_000);
    sale($this, $this->ketu, 10_000, status: TransactionStatus::Failed);
    tenancy()->runAs($this->business, function () use ($voided) {
        app(VoidTransaction::class)->handle($voided, $this->owner, 'Twice');
        app(Expenses::class)->record($this->ownerMembership, $this->ketu, ExpenseCategory::Data, Money::naira(50),
            app(Accounts::class)->cash($this->ketu), null, (string) Str::uuid());
    });

    $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('view', 'business')
        ->where('totals.today.count', 1)
        ->where('totals.today.volume', 1_000_000)
        ->where('totals.today.expenses', 5_000)
        ->where('totals.today.profit', 20_000 - 5_000 - 5_000)
        ->has('week', 7)
        ->where('week.6.date', '2026-10-16')
        ->where('week.6.profit', 10_000)
        ->where('outlets.0.name', 'Ketu Main')
        ->where('outlets.0.profit', 10_000));
});

test('supervisors see only their outlets', function () {
    sale($this, $this->ketu, 10_000);
    sale($this, $this->ojota, 5_000);

    $this->actingAs($this->supervisor->user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->has('outlets', 1)->where('outlets.0.name', 'Ojota')
        ->where('totals.today.volume', 500_000)
        ->has('recent', 1));
});

test('agents see their own day, without the business\'s profit', function () {
    sale($this, $this->ketu, 10_000, $this->agent);
    sale($this, $this->ketu, 20_000); // the owner's, at the same outlet

    $this->actingAs($this->agent->user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('view', 'agent')
        ->where('totals', null)
        ->where('week', [])
        ->where('mine.count', 1)
        ->where('mine.volume', 1_000_000)
        ->where('outlets.0.profit', null)
        ->has('recent', 1));
});

test('what needs attention shows up', function () {
    // Yesterday had a sale at Ketu but nobody counted the day.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Lagos'));
    sale($this, $this->ketu, 10_000);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 15:00:00', 'Africa/Lagos'));

    // A pending withdrawal is overdue once its 72 hours pass.
    sale($this, $this->ojota, 5_000, status: TransactionStatus::Pending);
    $this->travel(73)->hours();

    // Ketu's drawer is below the alert level.
    tenancy()->runAs($this->business, fn () => app(Accounts::class)->cash($this->ketu)->forceFill(['low_balance_threshold' => 100])->save());

    $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('alerts', fn ($alerts) => collect($alerts)->pluck('text')->contains(fn ($t) => str_contains($t, '72-hour target'))
            && collect($alerts)->pluck('text')->contains(fn ($t) => str_contains($t, 'Ketu Main · Cash in drawer is low'))));
});

test('the setup checklist is for owners only', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn ($page) => $page->has('checklist', 4));
    $this->actingAs($this->agent->user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('checklist', null));
});
