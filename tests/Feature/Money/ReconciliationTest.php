<?php

use App\Actions\Reconciliation\Reconciliations;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\LedgerAccountType;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Models\Membership;
use App\Models\Reconciliation;
use App\Models\StaffShortage;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use App\Support\Ledger\Ledger;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 17:00:00', 'Africa/Lagos'));

    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->supervisor = addMember($this->business, Role::Supervisor, [$this->ketu]);
    $this->terminal = terminalAt($this->business, $this->ketu);
    $this->today = '2026-10-16';

    // Opening float: ₦50,000 in the drawer, ₦100,000 on the Moniepoint terminal.
    tenancy()->runAs($this->business, function () {
        $a = app(Accounts::class);
        app(Ledger::class)->post('opening_balance', [
            [$a->cash($this->ketu), 5_000_000],
            [$a->forTerminal($this->terminal), 10_000_000],
            [$a->ownerCapital(), -15_000_000],
        ], $this->ketu->id, null, 'Opening float', CarbonImmutable::parse('2026-10-15 09:00', 'Africa/Lagos'));
    });
});

function cashOf(object $test, ?object $outlet = null): int
{
    return tenancy()->runAs($test->business, fn () => app(Accounts::class)->cash($outlet ?? $test->ketu)->fresh()->balance);
}

function withdraw(object $test, int $naira, ?Terminal $terminal = null, ?Membership $by = null): Transaction
{
    $by ??= $test->agent;

    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($by, [
        'idempotency_key' => (string) Str::uuid(),
        'terminal_id' => ($terminal ?? $test->terminal)->id,
        'type' => TransactionType::CashWithdrawal,
        'amount' => Money::naira($naira),
        'status' => TransactionStatus::Successful,
    ])->transaction, $by);
}

/** A count with this much cash and this Moniepoint balance; the other wallets as the books have them. */
function exactCount(object $test, int $cashKobo, int $walletKobo): array
{
    $notes = intdiv($cashKobo, 100_000); // ₦1,000 notes
    $wallets = tenancy()->runAs($test->business, fn () => app(Reconciliations::class)->walletsToCount($test->ketu)
        ->mapWithKeys(fn ($w) => [$w->id => $w->account->balance])->all());

    return [
        'denominations' => ['1000' => $notes, 'coins' => $cashKobo - $notes * 100_000],
        'wallets' => [...$wallets, $test->terminal->outlet_wallet_id => $walletKobo],
    ];
}

function walletOfTerminal(object $test): int
{
    return tenancy()->runAs($test->business, fn () => app(Accounts::class)->forTerminal($test->terminal)->fresh()->balance);
}

test('the count is blind: expected figures only appear after submitting', function () {
    $this->actingAs($this->agent->user)->get(route('reconciliation.show', [$this->ketu, $this->today]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('reconciliation/Show')
            ->where('counting', true)->where('reconciliation', null)
            ->has('walletsToCount', 3)
            ->missing('expected'));
});

test('a count that matches is submitted without a note and closes the day', function () {
    withdraw($this, 10_000); // drawer −₦9,800, wallet +₦9,950

    $this->actingAs($this->agent->user)
        ->post(route('reconciliation.submit', [$this->ketu, $this->today]), exactCount($this, cashOf($this), walletOfTerminal($this)))
        ->assertSessionHasNoErrors();

    $rec = tenancy()->runAs($this->business, fn () => Reconciliation::query()->with('wallets')->sole());
    expect($rec->status->value)->toBe('submitted')
        ->and($rec->expected_cash)->toBe(4_020_000)
        ->and($rec->cash_variance)->toBe(0)
        ->and($rec->balances())->toBeTrue();

    // Closed: nothing more for that day, at that outlet.
    expect(fn () => withdraw($this, 1_000))->toThrow(ValidationException::class, 'are closed');
});

test('a difference needs a reason', function () {
    $count = exactCount($this, cashOf($this) - 200_000, walletOfTerminal($this));

    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), $count)
        ->assertSessionHasErrors('note');

    $this->post(route('reconciliation.submit', [$this->ketu, $this->today]), [...$count, 'note' => 'Gave change twice'])
        ->assertSessionHasNoErrors();
});

test('approving a shortage brings the books in line and holds staff responsible', function () {
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), [
        ...exactCount($this, 4_800_000, 10_000_000), // ₦2,000 short in the drawer
        'note' => 'Not sure',
    ]);
    $rec = tenancy()->runAs($this->business, fn () => Reconciliation::query()->sole());

    $this->actingAs($this->supervisor->user)->post(route('reconciliation.approve', $rec), [
        'shortages' => [$this->agent->id => 150_000], 'note' => 'Agent to pay ₦1,500',
    ])->assertSessionHasNoErrors();

    $variance = tenancy()->runAs($this->business, fn () => app(Accounts::class)->forOutlet($this->ketu, LedgerAccountType::CashVariance)->fresh()->balance);
    expect(cashOf($this))->toBe(4_800_000)
        ->and($variance)->toBe(200_000)
        ->and($rec->fresh()->status->value)->toBe('approved');

    $shortage = tenancy()->runAs($this->business, fn () => StaffShortage::query()->sole());
    expect($shortage->amount)->toBe(150_000)->and($shortage->status)->toBe('outstanding');

    // Paid back the next day: the cash goes into that day's drawer.
    $this->travel(1)->days();
    $this->post(route('shortages.settle', $shortage), ['outcome' => 'recovered'])->assertSessionHasNoErrors();
    expect(cashOf($this))->toBe(4_950_000)->and($shortage->fresh()->status)->toBe('recovered');
});

test('more than the shortage cannot be assigned to staff', function () {
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), [
        ...exactCount($this, 4_900_000, 10_000_000), 'note' => 'Short ₦1,000',
    ]);
    $rec = tenancy()->runAs($this->business, fn () => Reconciliation::query()->sole());

    $this->actingAs($this->supervisor->user)->post(route('reconciliation.approve', $rec), ['shortages' => [$this->agent->id => 200_000]])
        ->assertSessionHasErrors('shortages');
});

test('a wallet difference is posted to that wallet', function () {
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), [
        ...exactCount($this, 5_000_000, 9_990_000), 'note' => 'Terminal shows ₦100 less',
    ]);
    $rec = tenancy()->runAs($this->business, fn () => Reconciliation::query()->sole());
    $this->actingAs($this->owner)->post(route('reconciliation.approve', $rec))->assertSessionHasNoErrors();

    expect(walletOfTerminal($this))->toBe(9_990_000)->and(cashOf($this))->toBe(5_000_000);
});

test('supervisors cannot approve their own count; the owner can', function () {
    $this->actingAs($this->supervisor->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), exactCount($this, 5_000_000, 10_000_000));
    $rec = tenancy()->runAs($this->business, fn () => Reconciliation::query()->sole());

    $this->post(route('reconciliation.approve', $rec))->assertSessionHasErrors(['note' => 'Someone else has to approve your own count.']);

    $this->actingAs($this->owner)->post(route('reconciliation.approve', $rec))->assertSessionHasNoErrors();
});

test('sending a count back opens the day again for a recount', function () {
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), [
        ...exactCount($this, 4_000_000, 10_000_000), 'note' => 'Lost count',
    ]);
    $rec = tenancy()->runAs($this->business, fn () => Reconciliation::query()->sole());

    $this->actingAs($this->supervisor->user)->post(route('reconciliation.send-back', $rec), ['note' => 'Count the ₦1,000 notes again'])
        ->assertSessionHasNoErrors();

    withdraw($this, 1_000); // the day is open again

    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), exactCount($this, cashOf($this), walletOfTerminal($this)))
        ->assertSessionHasNoErrors();
    expect($rec->fresh()->status->value)->toBe('submitted')->and($rec->fresh()->cash_variance)->toBe(0);
});

test('closing a day also closes the days before it', function () {
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), exactCount($this, 5_000_000, 10_000_000));

    // An entry from a phone that was offline yesterday can no longer land in yesterday's books.
    expect(fn () => tenancy()->runAs($this->business, fn () => app(RecordTransaction::class)->handle($this->agent, [
        'idempotency_key' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id,
        'type' => TransactionType::CashWithdrawal, 'amount' => Money::naira(1_000), 'status' => TransactionStatus::Successful,
        'source' => 'offline_sync', 'client_recorded_at' => CarbonImmutable::parse('2026-10-15 15:00', 'Africa/Lagos'),
    ])))->toThrow(ValidationException::class, 'are closed');

    // Other outlets are not affected.
    $ojota = terminalAt($this->business, $this->ojota);
    withdraw($this, 1_000, $ojota, $this->ownerMembership);
    expect(tenancy()->runAs($this->business, fn () => Transaction::query()->where('outlet_id', $this->ojota->id)->count()))->toBe(1);
});

test('entries from a closed day cannot be voided', function () {
    $t = withdraw($this, 10_000);
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), exactCount($this, cashOf($this), walletOfTerminal($this)));

    $this->actingAs($this->owner)->post(route('transactions.void', $t), ['reason' => 'Mistake'])
        ->assertSessionHasErrors('reason');
});

test('only the owner can reopen an approved day, which undoes its difference', function () {
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), [
        ...exactCount($this, 4_800_000, 10_000_000), 'note' => 'Short',
    ]);
    $rec = tenancy()->runAs($this->business, fn () => Reconciliation::query()->sole());
    $this->actingAs($this->supervisor->user)->post(route('reconciliation.approve', $rec), ['shortages' => [$this->agent->id => 200_000]]);

    $this->post(route('reconciliation.reopen', $rec), ['reason' => 'x'])->assertForbidden();

    $this->actingAs($this->owner)->post(route('reconciliation.reopen', $rec), ['reason' => 'Found the ₦2,000 in the safe'])
        ->assertSessionHasNoErrors();

    expect(cashOf($this))->toBe(5_000_000)
        ->and($rec->fresh()->status->value)->toBe('reopened')
        ->and(tenancy()->runAs($this->business, fn () => StaffShortage::query()->sole()->status))->toBe('cancelled');

    // The books still balance overall.
    expect(tenancy()->runAs($this->business, fn () => (int) LedgerAccount::query()->sum('balance')))->toBe(0);
});

test('the day list shows each outlet\'s last week', function () {
    $this->actingAs($this->agent->user)->post(route('reconciliation.submit', [$this->ketu, $this->today]), exactCount($this, 5_000_000, 10_000_000));

    $this->actingAs($this->owner)->get(route('reconciliation.index'))->assertInertia(fn ($page) => $page
        ->component('reconciliation/Index')
        ->has('outlets', 2)
        ->where('outlets.0.name', 'Ketu Main')
        ->where('outlets.0.days.0.date', '2026-10-16')
        ->where('outlets.0.days.0.status', 'submitted')
        ->where('outlets.0.days.1.status', null));
});
