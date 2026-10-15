<?php

use App\Actions\Float\FloatMovements;
use App\Enums\FloatMovementStatus;
use App\Enums\FloatMovementType;
use App\Enums\Role;
use App\Models\FloatMovement;
use App\Models\LedgerAccount;
use App\Models\Membership;
use App\Models\Provider;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->yaba = addOutlet($this->business, ['name' => 'Yaba']);
    $this->supervisor = addMember($this->business, Role::Supervisor, [$this->ketu, $this->ojota]);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->provider = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());
});

function acct(object $test, string $what, ?object $outlet = null): string
{
    return tenancy()->runAs($test->business, function () use ($test, $what, $outlet) {
        $a = app(Accounts::class);

        return match ($what) {
            'cash' => $a->cash($outlet)->id,
            'wallet' => $a->forTerminal($a->ensureTerminal($outlet, $test->provider))->id,
            'head' => $a->headOffice()->id,
        };
    });
}

function balanceOf(object $test, string $accountId): int
{
    return tenancy()->runAs($test->business, fn () => LedgerAccount::query()->findOrFail($accountId)->balance);
}

function move(object $test, Membership $by, FloatMovementType $type, ?string $from, string $to, int $naira): FloatMovement
{
    return tenancy()->runAs($test->business, fn () => app(FloatMovements::class)->request($by, $type, $from, $to, Money::naira($naira), 'test'), $by);
}

test('the owner can set opening balances, which come from owner\'s capital', function () {
    $m = move($this, $this->ownerMembership, FloatMovementType::OpeningBalance, null, acct($this, 'cash', $this->ketu), 250_000);

    expect($m->status)->toBe(FloatMovementStatus::Completed)
        ->and(balanceOf($this, acct($this, 'cash', $this->ketu)))->toBe(25_000_000);
});

test('an owner top-up from head office completes straight away', function () {
    move($this, $this->ownerMembership, FloatMovementType::TopUp, acct($this, 'head'), acct($this, 'wallet', $this->ketu), 100_000);

    expect(balanceOf($this, acct($this, 'wallet', $this->ketu)))->toBe(10_000_000)
        ->and(balanceOf($this, acct($this, 'head')))->toBe(-10_000_000);
});

test('an agent\'s request waits for approval and moves nothing until approved', function () {
    $m = move($this, $this->agent, FloatMovementType::TopUp, acct($this, 'head'), acct($this, 'cash', $this->ketu), 50_000);

    expect($m->status)->toBe(FloatMovementStatus::Requested)
        ->and(balanceOf($this, acct($this, 'cash', $this->ketu)))->toBe(0);

    tenancy()->runAs($this->business, fn () => app(FloatMovements::class)->approve($this->ownerMembership, $m), $this->ownerMembership);

    expect($m->fresh()->status)->toBe(FloatMovementStatus::Completed)
        ->and(balanceOf($this, acct($this, 'cash', $this->ketu)))->toBe(5_000_000);
});

test('a supervisor approves transfers between their outlets but not head-office top-ups', function () {
    $transfer = move($this, $this->agent, FloatMovementType::OutletTransfer, acct($this, 'cash', $this->ojota), acct($this, 'cash', $this->ketu), 20_000);
    $topUp = move($this, $this->agent, FloatMovementType::TopUp, acct($this, 'head'), acct($this, 'cash', $this->ketu), 20_000);

    tenancy()->runAs($this->business, fn () => app(FloatMovements::class)->approve($this->supervisor, $transfer), $this->supervisor);

    expect($transfer->fresh()->status)->toBe(FloatMovementStatus::Completed)
        ->and(fn () => tenancy()->runAs($this->business, fn () => app(FloatMovements::class)->approve($this->supervisor, $topUp), $this->supervisor))
        ->toThrow(AuthorizationException::class);
});

test('an agent cannot move float for an outlet they do not work at', function () {
    move($this, $this->agent, FloatMovementType::OutletTransfer, acct($this, 'cash', $this->ojota), acct($this, 'cash', $this->yaba), 1_000);
})->throws(AuthorizationException::class);

test('only the owner can set opening balances', function () {
    move($this, $this->supervisor, FloatMovementType::OpeningBalance, null, acct($this, 'cash', $this->ketu), 1_000);
})->throws(AuthorizationException::class);

test('movements must use accounts that make sense for their type', function (string $type, string $from, string $to) {
    $accounts = ['head' => acct($this, 'head'), 'ketu_cash' => acct($this, 'cash', $this->ketu),
        'ketu_wallet' => acct($this, 'wallet', $this->ketu), 'ojota_cash' => acct($this, 'cash', $this->ojota)];

    move($this, $this->ownerMembership, FloatMovementType::from($type), $accounts[$from], $accounts[$to], 1_000);
})->throws(ValidationException::class)->with([
    'pickup into a wallet' => ['cash_pickup', 'ketu_cash', 'ketu_wallet'],
    'rebalance across outlets' => ['rebalance', 'ketu_cash', 'ojota_cash'],
    'transfer within one outlet' => ['outlet_transfer', 'ketu_cash', 'ketu_wallet'],
    'top-up not from head office' => ['top_up', 'ojota_cash', 'ketu_cash'],
    'same account' => ['rebalance', 'ketu_cash', 'ketu_cash'],
]);

test('a rejected request never touches the ledger, and requesters can cancel their own', function () {
    $a = move($this, $this->agent, FloatMovementType::Rebalance, acct($this, 'cash', $this->ketu), acct($this, 'wallet', $this->ketu), 10_000);
    $b = move($this, $this->agent, FloatMovementType::Rebalance, acct($this, 'cash', $this->ketu), acct($this, 'wallet', $this->ketu), 5_000);

    tenancy()->runAs($this->business, fn () => app(FloatMovements::class)->reject($this->supervisor, $a, 'Not today'), $this->supervisor);
    tenancy()->runAs($this->business, fn () => app(FloatMovements::class)->reject($this->agent, $b, null), $this->agent);

    expect($a->fresh()->status)->toBe(FloatMovementStatus::Rejected)
        ->and($b->fresh()->status)->toBe(FloatMovementStatus::Cancelled)
        ->and(balanceOf($this, acct($this, 'wallet', $this->ketu)))->toBe(0)
        ->and(fn () => tenancy()->runAs($this->business, fn () => app(FloatMovements::class)->approve($this->ownerMembership, $a), $this->ownerMembership))
        ->toThrow(ValidationException::class);
});
