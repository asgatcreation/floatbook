<?php

use App\Actions\Transactions\MoveTransaction;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Membership;
use App\Models\Provider;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->moniepoint = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());
    $this->opay = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'opay')->sole());

    // Ketu runs two Moniepoint machines, each with its own balance.
    $this->t1 = terminalAt($this->business, $this->ketu, $this->moniepoint);
    $this->actingAs($this->owner)->post(route('terminals.store', $this->ketu), [
        'name' => 'Terminal 2', 'provider_id' => $this->moniepoint->id, 'wallet' => 'new', 'terminal_code' => '2MPT0412',
    ])->assertSessionHasNoErrors();
    $this->t2 = tenancy()->runAs($this->business, fn () => Terminal::query()->where('name', 'Terminal 2')->sole());

    $this->sola = addMember($this->business, Role::Agent, [$this->ketu]);
});

function onTerminal(object $test, Terminal $terminal, int $naira = 10_000, ?Membership $by = null, array $extra = []): Transaction
{
    $by ??= $test->sola;

    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($by, [
        'idempotency_key' => (string) Str::uuid(),
        'terminal_id' => $terminal->id,
        'type' => TransactionType::CashWithdrawal,
        'amount' => Money::naira($naira),
        'status' => TransactionStatus::Successful,
        ...$extra,
    ])->transaction, $by);
}

function walletOf(object $test, Terminal $terminal): int
{
    return tenancy()->runAs($test->business, fn () => app(Accounts::class)->forTerminal($terminal->id)->fresh()->balance);
}

test('every outlet starts with one terminal per provider, each with its own balance', function () {
    $terminals = tenancy()->runAs($this->business, fn () => Terminal::query()->where('outlet_id', $this->ojota->id)->with('provider')->get());

    expect($terminals->map->label()->sort()->values()->all())->toBe(['Moniepoint POS', 'OPay POS', 'Palmpay POS'])
        ->and($terminals->pluck('outlet_wallet_id')->unique())->toHaveCount(3);
});

test('each terminal keeps its own record and balance', function () {
    onTerminal($this, $this->t1, 10_000);
    onTerminal($this, $this->t2, 5_000);

    // ₦10,000 withdrawal: the wallet gets the amount less the ₦50 provider fee.
    expect(walletOf($this, $this->t1))->toBe(995_000)
        ->and(walletOf($this, $this->t2))->toBeGreaterThan(490_000)->toBeLessThan(500_000)
        ->and(tenancy()->runAs($this->business, fn () => Transaction::query()->where('terminal_id', $this->t2->id)->count()))->toBe(1);
});

test('two terminals on the same account share one balance', function () {
    $this->actingAs($this->owner)->post(route('terminals.store', $this->ketu), [
        'name' => 'Terminal 3', 'provider_id' => $this->moniepoint->id, 'wallet' => $this->t2->outlet_wallet_id,
    ])->assertSessionHasNoErrors();
    $t3 = tenancy()->runAs($this->business, fn () => Terminal::query()->where('name', 'Terminal 3')->sole());

    onTerminal($this, $this->t2, 10_000);
    onTerminal($this, $t3, 10_000);

    expect($t3->outlet_wallet_id)->toBe($this->t2->outlet_wallet_id)
        ->and(walletOf($this, $t3))->toBe(1_990_000);

    $this->get(route('float.index'))->assertInertia(fn ($page) => $page
        ->where('outlets.0.name', 'Ketu Main')
        ->where('outlets.0.wallets', fn ($wallets) => collect($wallets)->contains(fn ($w) => collect($w['terminals'])->all() === ['Terminal 2', 'Terminal 3'])));
});

test('a balance can only be shared with a terminal of the same provider at the same outlet', function () {
    $ojotaWallet = terminalAt($this->business, $this->ojota, $this->moniepoint)->outlet_wallet_id;
    $opayWallet = terminalAt($this->business, $this->ketu, $this->opay)->outlet_wallet_id;

    foreach ([$ojotaWallet, $opayWallet] as $wallet) {
        $this->actingAs($this->owner)->post(route('terminals.store', $this->ketu), [
            'name' => 'Odd one', 'provider_id' => $this->moniepoint->id, 'wallet' => $wallet,
        ])->assertSessionHasErrors('wallet');
    }
});

test('a bank POS is added only where the owner puts it', function () {
    $this->actingAs($this->owner)->post(route('providers.store'), ['name' => 'Access Bank POS', 'color' => '#F47B20'])->assertSessionHasNoErrors();
    $access = tenancy()->runAs($this->business, fn () => Provider::query()->where('name', 'Access Bank POS')->sole());

    expect(tenancy()->runAs($this->business, fn () => Terminal::query()->where('provider_id', $access->id)->count()))->toBe(0);

    $this->post(route('terminals.store', $this->ojota), ['name' => 'Access 1', 'provider_id' => $access->id, 'wallet' => 'new'])
        ->assertSessionHasNoErrors();

    $terminals = tenancy()->runAs($this->business, fn () => Terminal::query()->where('provider_id', $access->id)->get());
    expect($terminals)->toHaveCount(1)->and($terminals->first()->outlet_id)->toBe($this->ojota->id);
});

test('staff assigned to terminals see and use only those', function () {
    $this->actingAs($this->owner)->patch(route('staff.update', $this->sola), [
        'role' => 'agent', 'outlet_ids' => [$this->ketu->id], 'terminal_ids' => [$this->t1->id, $this->t2->id],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->sola->user)->get(route('transactions.create'))->assertInertia(fn ($page) => $page
        ->has('outlets.0.terminals', 2)
        ->where('outlets.0.terminals.0.name', 'Moniepoint POS')
        ->where('outlets.0.terminals.1.name', 'Terminal 2'));

    $opay = terminalAt($this->business, $this->ketu, $this->opay);
    expect(fn () => onTerminal($this, $opay, by: $this->sola->fresh()))->toThrow(ValidationException::class, 'That terminal is not one of yours.');

    onTerminal($this, $this->t2, by: $this->sola->fresh());
    expect(tenancy()->runAs($this->business, fn () => Transaction::query()->sole()->terminal_id))->toBe($this->t2->id);
});

test('terminals outside a person\'s outlets are never assigned', function () {
    $ojotaTerminal = terminalAt($this->business, $this->ojota, $this->moniepoint);

    $this->actingAs($this->owner)->patch(route('staff.update', $this->sola), [
        'role' => 'agent', 'outlet_ids' => [$this->ketu->id], 'terminal_ids' => [$this->t1->id, $ojotaTerminal->id],
    ])->assertSessionHasNoErrors();

    expect(tenancy()->runAs($this->business, fn () => $this->sola->fresh()->terminals->pluck('id')->all()))->toBe([$this->t1->id]);
});

test('entries waiting on a phone from before terminals still sync', function () {
    $this->actingAs($this->sola->user)->postJson(route('transactions.store'), [
        'idempotency_key' => (string) Str::uuid(), 'outlet_id' => $this->ketu->id, 'provider_id' => $this->moniepoint->id,
        'type' => 'cash_withdrawal', 'amount' => 1_000_000, 'status' => 'successful',
    ])->assertCreated();

    expect(tenancy()->runAs($this->business, fn () => Transaction::query()->sole()->terminal_id))->toBe($this->t1->id);
});

test('the database refuses a terminal from another outlet', function () {
    $t = onTerminal($this, $this->t1);
    $ojotaTerminal = terminalAt($this->business, $this->ojota, $this->moniepoint);

    expect(fn () => DB::table('transactions')->where('id', $t->id)->update(['terminal_id' => $ojotaTerminal->id]))
        ->toThrow(QueryException::class);
});

test('an entry on the wrong terminal is moved, never deleted', function () {
    $this->travelTo(now()->setTime(10, 0));
    $wrong = onTerminal($this, $this->t1, 10_000, extra: ['reference' => '220944718800', 'customer_phone' => '08031234567']);
    $this->travel(2)->hours();

    $this->actingAs($this->sola->user)->post(route('transactions.move', $wrong), ['terminal_id' => $this->t2->id, 'reason' => 'Customer used Terminal 2'])
        ->assertRedirect()->assertSessionHasNoErrors();

    [$original, $moved] = tenancy()->runAs($this->business, fn () => [
        Transaction::query()->find($wrong->id),
        Transaction::query()->where('replaces_transaction_id', $wrong->id)->sole(),
    ]);

    expect($original->isVoided())->toBeTrue()
        ->and($original->void_reason)->toBe('Moved to Terminal 2 · Moniepoint: Customer used Terminal 2')
        ->and($moved->terminal_id)->toBe($this->t2->id)
        ->and($moved->amount)->toBe($original->amount)
        ->and($moved->charge)->toBe($original->charge)
        ->and($moved->reference)->toBe('220944718800')
        ->and($moved->membership_id)->toBe($this->sola->id)
        ->and($moved->occurred_at->equalTo($original->occurred_at))->toBeTrue()
        ->and(walletOf($this, $this->t1))->toBe(0)
        ->and(walletOf($this, $this->t2))->toBe(995_000)
        ->and(tenancy()->runAs($this->business, fn () => Transaction::query()->count()))->toBe(2);

    $this->get(route('transactions.show', $original))->assertInertia(fn ($page) => $page
        ->where('movedTo.id', $moved->id)->where('moveTargets', []));
});

test('moving to another provider keeps the customer\'s charge and redoes the provider fee', function () {
    $opay = terminalAt($this->business, $this->ketu, $this->opay);
    $wrong = onTerminal($this, $this->t1, 10_000);

    $moved = tenancy()->runAs($this->business, fn () => app(MoveTransaction::class)->handle($wrong, $this->ownerMembership, $opay, 'Wrong machine'));

    expect($moved->provider_id)->toBe($this->opay->id)->and($moved->charge)->toBe($wrong->charge);
});

test('agents fix their own entries on the day; after that it takes a supervisor', function () {
    $wrong = onTerminal($this, $this->t1);
    $this->travel(1)->days();

    $this->actingAs($this->sola->user)->post(route('transactions.move', $wrong), ['terminal_id' => $this->t2->id, 'reason' => 'Late fix'])
        ->assertSessionHasErrors(['terminal_id' => 'Only a supervisor or the owner can move this entry now.']);

    $supervisor = addMember($this->business, Role::Supervisor, [$this->ketu]);
    $this->actingAs($supervisor->user)->post(route('transactions.move', $wrong), ['terminal_id' => $this->t2->id, 'reason' => 'Late fix'])
        ->assertSessionHasNoErrors();
});

test('an entry can only move to another active terminal at its own outlet, and only once', function () {
    $wrong = onTerminal($this, $this->t1);
    $ojotaTerminal = terminalAt($this->business, $this->ojota, $this->moniepoint);
    $this->actingAs($this->owner);

    $this->post(route('transactions.move', $wrong), ['terminal_id' => $ojotaTerminal->id, 'reason' => 'Nope'])->assertSessionHasErrors('terminal_id');
    $this->post(route('transactions.move', $wrong), ['terminal_id' => $this->t1->id, 'reason' => 'Nope'])->assertSessionHasErrors('terminal_id');

    $this->post(route('transactions.move', $wrong), ['terminal_id' => $this->t2->id, 'reason' => 'Right one'])->assertSessionHasNoErrors();
    $this->post(route('transactions.move', $wrong), ['terminal_id' => $this->t2->id, 'reason' => 'Again'])
        ->assertSessionHasErrors(['terminal_id' => 'This entry has been voided, so it cannot be moved.']);
});

test('accountants cannot move entries', function () {
    $wrong = onTerminal($this, $this->t1);
    $accountant = addMember($this->business, Role::Accountant);

    $this->actingAs($accountant->user)->post(route('transactions.move', $wrong), ['terminal_id' => $this->t2->id, 'reason' => 'x'])
        ->assertForbidden();
});
