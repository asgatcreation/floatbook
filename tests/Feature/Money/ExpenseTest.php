<?php

use App\Enums\LedgerAccountType;
use App\Enums\Role;
use App\Models\Expense;
use App\Models\Provider;
use App\Support\Ledger\Accounts;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->accountant = addMember($this->business, Role::Accountant);
});

function accountOf(object $test, object $outlet, LedgerAccountType $type): object
{
    return tenancy()->runAs($test->business, fn () => app(Accounts::class)->forOutlet($outlet, $type)->fresh());
}

function expensePayload(object $test, array $overrides = []): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'outlet_id' => $test->ketu->id,
        'category' => 'fuel',
        'amount' => 450_000, // ₦4,500
        'paid_from_account_id' => accountOf($test, $test->ketu, LedgerAccountType::CashDrawer)->id,
        'description' => 'Diesel for the generator',
        ...$overrides,
    ];
}

test('an expense comes out of the drawer and counts as an operating expense', function () {
    $this->actingAs($this->agent->user)->post(route('expenses.store'), expensePayload($this))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(accountOf($this, $this->ketu, LedgerAccountType::CashDrawer)->balance)->toBe(-450_000)
        ->and(accountOf($this, $this->ketu, LedgerAccountType::ExpenseOperating)->balance)->toBe(450_000);

    $this->get(route('expenses.index'))->assertInertia(fn ($page) => $page
        ->component('expenses/Index')
        ->where('total', 450_000)
        ->where('byCategory.0.label', 'Generator fuel')
        ->has('expenses.data', 1));
});

test('it can be paid from one of the outlet\'s wallets', function () {
    $wallet = tenancy()->runAs($this->business, function () {
        $accounts = app(Accounts::class);

        return $accounts->forTerminal($accounts->ensureTerminal($this->ketu, Provider::query()->where('slug', 'opay')->sole()));
    });

    $this->actingAs($this->owner)->post(route('expenses.store'), expensePayload($this, ['category' => 'data', 'amount' => 100_000, 'paid_from_account_id' => $wallet->id]))
        ->assertSessionHasNoErrors();

    expect($wallet->fresh()->balance)->toBe(-100_000);
});

test('it cannot be paid from another outlet\'s drawer', function () {
    $this->actingAs($this->owner)->post(route('expenses.store'), expensePayload($this, [
        'paid_from_account_id' => accountOf($this, $this->ojota, LedgerAccountType::CashDrawer)->id,
    ]))->assertSessionHasErrors('paid_from_account_id');

    expect(tenancy()->runAs($this->business, fn () => Expense::query()->count()))->toBe(0);
});

test('agents only record for their own outlets', function () {
    $this->actingAs($this->agent->user)->post(route('expenses.store'), expensePayload($this, [
        'outlet_id' => $this->ojota->id,
        'paid_from_account_id' => accountOf($this, $this->ojota, LedgerAccountType::CashDrawer)->id,
    ]))->assertSessionHasErrors('outlet_id');
});

test('sending the same expense twice records it once', function () {
    $body = expensePayload($this);
    $this->actingAs($this->agent->user)->post(route('expenses.store'), $body);
    $this->post(route('expenses.store'), $body);

    expect(tenancy()->runAs($this->business, fn () => Expense::query()->count()))->toBe(1)
        ->and(accountOf($this, $this->ketu, LedgerAccountType::CashDrawer)->balance)->toBe(-450_000);
});

test('a voided expense gives the money back and stays in the list', function () {
    $this->actingAs($this->owner)->post(route('expenses.store'), expensePayload($this));
    $expense = tenancy()->runAs($this->business, fn () => Expense::query()->sole());

    $this->post(route('expenses.void', $expense), ['reason' => 'Entered twice'])->assertSessionHasNoErrors();
    $this->post(route('expenses.void', $expense), ['reason' => 'Again'])->assertSessionHasErrors('reason');

    expect(accountOf($this, $this->ketu, LedgerAccountType::CashDrawer)->balance)->toBe(0)
        ->and(accountOf($this, $this->ketu, LedgerAccountType::ExpenseOperating)->balance)->toBe(0);

    $this->get(route('expenses.index'))->assertInertia(fn ($page) => $page
        ->where('total', 0)->where('expenses.data.0.voided', true));
});

test('agents cannot void, accountants cannot record', function () {
    $this->actingAs($this->agent->user)->post(route('expenses.store'), expensePayload($this));
    $expense = tenancy()->runAs($this->business, fn () => Expense::query()->sole());

    $this->post(route('expenses.void', $expense), ['reason' => 'Mine'])->assertForbidden();
    $this->actingAs($this->accountant->user)->post(route('expenses.store'), expensePayload($this))->assertForbidden();
    $this->get(route('expenses.index'))->assertOk()->assertInertia(fn ($page) => $page->where('canRecord', false));
});

test('agents see their own outlets\' expenses only', function () {
    $this->actingAs($this->owner)->post(route('expenses.store'), expensePayload($this, [
        'outlet_id' => $this->ojota->id,
        'paid_from_account_id' => accountOf($this, $this->ojota, LedgerAccountType::CashDrawer)->id,
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->agent->user)->get(route('expenses.index'))->assertInertia(fn ($page) => $page
        ->has('expenses.data', 0)->where('total', 0));
});
