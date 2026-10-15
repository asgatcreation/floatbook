<?php

use App\Enums\ChargeKind;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\ChargeRule;
use App\Models\Provider;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->otherAgent = addMember($this->business, Role::Agent, [$this->ojota]);
    $this->accountant = addMember($this->business, Role::Accountant);
    $this->provider = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());
});

function payload(object $test, array $overrides = []): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'outlet_id' => $test->ketu->id,
        'provider_id' => $test->provider->id,
        'type' => 'cash_withdrawal',
        'amount' => 1_000_000,
        'status' => 'successful',
        ...$overrides,
    ];
}

test('an agent can open the entry screen and save a transaction', function () {
    $this->actingAs($this->agent->user)->get(route('transactions.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('transactions/Create')
            ->has('outlets', 1)->where('outlets.0.name', 'Ketu Main')
            ->has('outlets.0.terminals', 3)->where('outlets.0.terminals.0.label', 'Moniepoint POS')->has('rules'));

    $this->post(route('transactions.store'), payload($this, ['reference' => '2209447188']))->assertRedirect()->assertSessionHasNoErrors();

    $t = tenancy()->runAs($this->business, fn () => Transaction::query()->sole());
    expect($t->charge)->toBe(20_000)->and($t->reference)->toBe('2209447188');
});

test('posting the same request twice (a double tap or retry) saves it once', function () {
    $body = payload($this);
    $this->actingAs($this->agent->user);

    $this->postJson(route('transactions.store'), $body)->assertCreated()->assertJsonPath('duplicate', false);
    $this->postJson(route('transactions.store'), $body)->assertOk()->assertJsonPath('duplicate', true);

    expect(tenancy()->runAs($this->business, fn () => Transaction::query()->count()))->toBe(1);
});

test('the server ignores any charge the browser might send and uses its own rules', function () {
    $this->actingAs($this->agent->user)->post(route('transactions.store'), payload($this, ['charge' => 1, 'provider_fee' => 0]));

    expect(tenancy()->runAs($this->business, fn () => Transaction::query()->value('charge')))->toBe(20_000);
});

test('invalid amounts and statuses are refused', function (array $override, string $field) {
    $this->actingAs($this->agent->user)->post(route('transactions.store'), payload($this, $override))->assertSessionHasErrors($field);
})->with([
    'zero' => [['amount' => 0], 'amount'],
    'decimal naira' => [['amount' => 10.5], 'amount'],
    'too big' => [['amount' => 2_000_000_000], 'amount'],
    'reversed status' => [['status' => 'reversed'], 'status'],
    'bad phone' => [['customer_phone' => 'call me'], 'customer_phone'],
    'not a uuid' => [['idempotency_key' => 'abc'], 'idempotency_key'],
]);

test('accountants can look but not record', function () {
    $this->actingAs($this->accountant->user);

    $this->get(route('transactions.index'))->assertOk();
    $this->get(route('transactions.create'))->assertForbidden();
    $this->post(route('transactions.store'), payload($this))->assertForbidden();
});

test('agents only see their own entries; owners see everything', function () {
    $this->actingAs($this->agent->user)->post(route('transactions.store'), payload($this));
    $this->actingAs($this->otherAgent->user)->post(route('transactions.store'), payload($this, ['outlet_id' => $this->ojota->id]));

    $this->actingAs($this->agent->user)->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 1)->where('summary.count', 1));

    $theirs = tenancy()->runAs($this->business, fn () => Transaction::query()->where('outlet_id', $this->ojota->id)->sole());
    $this->get(route('transactions.show', $theirs))->assertNotFound();

    $this->actingAs($this->owner)->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 2)->where('summary.volume', 2_000_000));
});

test('only owners and supervisors can void, and a reason is required', function () {
    $this->actingAs($this->agent->user)->post(route('transactions.store'), payload($this));
    $t = tenancy()->runAs($this->business, fn () => Transaction::query()->sole());

    $this->post(route('transactions.void', $t), ['reason' => 'Oops'])->assertForbidden();

    $this->actingAs($this->owner)->post(route('transactions.void', $t), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('transactions.void', $t), ['reason' => 'Entered twice'])->assertSessionHasNoErrors();

    expect($t->fresh()->voided_at)->not->toBeNull();
    $this->get(route('transactions.show', $t))->assertInertia(fn ($page) => $page->has('entries', 2)->where('canVoid', false));
});

test('the transaction page shows the balanced ledger lines', function () {
    $this->actingAs($this->agent->user)->post(route('transactions.store'), payload($this));
    $t = tenancy()->runAs($this->business, fn () => Transaction::query()->sole());

    $this->get(route('transactions.show', $t))->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.0.lines', fn ($lines) => collect($lines)->sum('amount') === 0));
});

test('the float page shows balances, and agents only see their outlets', function () {
    $this->actingAs($this->owner)->post(route('float.store'), [
        'type' => 'opening_balance', 'to_account_id' => tenancy()->runAs($this->business, fn () => app(Accounts::class)->cash($this->ketu)->id),
        'amount' => 50_000_000,
    ])->assertSessionHasNoErrors();

    $this->get(route('float.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('outlets', 2)->where('outlets.0.cash.balance', 50_000_000)->has('headOffice'));

    $this->actingAs($this->agent->user)->get(route('float.index'))
        ->assertInertia(fn ($page) => $page->has('outlets', 1)->where('headOffice', null)
            ->where('types', fn ($types) => ! collect($types)->contains('value', 'opening_balance')));
});

test('only the owner can set low-balance alerts', function () {
    $cash = tenancy()->runAs($this->business, fn () => app(Accounts::class)->cash($this->ketu));

    $this->actingAs($this->agent->user)->patch(route('float.threshold', $cash), ['low_balance_threshold' => 100])->assertForbidden();
    $this->actingAs($this->owner)->patch(route('float.threshold', $cash), ['low_balance_threshold' => 15_000_000])->assertRedirect();

    expect($cash->fresh()->low_balance_threshold)->toBe(15_000_000);
});

test('owners can edit charge rules; overlapping bands are refused', function () {
    $rule = tenancy()->runAs($this->business, fn () => ChargeRule::query()
        ->where('transaction_type', TransactionType::Transfer)->where('kind', ChargeKind::CustomerCharge)->sole());

    $this->actingAs($this->agent->user)->get(route('charges.index'))->assertForbidden();

    $this->actingAs($this->owner)->put(route('charges.update', $rule), ['tiers' => [
        ['min_amount' => 0, 'max_amount' => 1_000_000, 'flat' => 10_000, 'percent_bps' => 0],
        ['min_amount' => 900_000, 'max_amount' => null, 'flat' => 20_000, 'percent_bps' => 0],
    ]])->assertSessionHasErrors('tiers');

    $this->put(route('charges.update', $rule), ['tiers' => [
        ['min_amount' => 0, 'max_amount' => null, 'flat' => 15_000, 'percent_bps' => 0],
    ]])->assertSessionHasNoErrors();

    $this->actingAs($this->agent->user)->post(route('transactions.store'), payload($this, ['type' => 'transfer']));
    expect(tenancy()->runAs($this->business, fn () => Transaction::query()->value('charge')))->toBe(15_000);
});

test('entries synced from an offline phone keep the time they were recorded', function () {
    $this->freezeTime();
    $this->actingAs($this->agent->user);

    $twoHoursAgo = now()->subHours(2);
    $this->postJson(route('transactions.store'), payload($this, ['source' => 'offline_sync', 'client_recorded_at' => $twoHoursAgo->toIso8601String()]))->assertCreated();
    $this->postJson(route('transactions.store'), payload($this, ['source' => 'offline_sync', 'client_recorded_at' => now()->subDays(5)->toIso8601String()]))->assertCreated();
    $this->postJson(route('transactions.store'), payload($this, ['source' => 'counter', 'client_recorded_at' => $twoHoursAgo->toIso8601String()]))->assertCreated();

    $times = tenancy()->runAs($this->business, fn () => Transaction::query()->orderBy('created_at')->orderBy('id')->get()
        ->map(fn ($t) => [$t->source, $t->occurred_at->toDateTimeString()])->all());

    expect($times)->toContain(['offline_sync', $twoHoursAgo->toDateTimeString()])   // believable: kept
        ->toContain(['offline_sync', now()->toDateTimeString()])                       // 5 days old: not trusted
        ->toContain(['counter', now()->toDateTimeString()]);                           // counter entries use server time
});

test('an unknown source is refused', function () {
    $this->actingAs($this->agent->user)->post(route('transactions.store'), payload($this, ['source' => 'import']))
        ->assertSessionHasErrors('source');
});
