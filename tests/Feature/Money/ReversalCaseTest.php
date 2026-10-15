<?php

use App\Actions\Reversals\ReversalCases;
use App\Actions\Transactions\RecordTransaction;
use App\Actions\Transactions\ResolvePendingTransaction;
use App\Actions\Transactions\VoidTransaction;
use App\Enums\LedgerAccountType;
use App\Enums\ReversalStatus;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Provider;
use App\Models\ReversalCase;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->outlet = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->agent = addMember($this->business, Role::Agent, [$this->outlet]);
    $this->provider = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());
});

function saveTx(object $test, TransactionStatus $status, bool $cashPaid = false, int $naira = 20_000): Transaction
{
    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($test->agent, [
        'idempotency_key' => (string) Str::uuid(), 'outlet_id' => $test->outlet->id, 'provider_id' => $test->provider->id,
        'type' => TransactionType::CashWithdrawal, 'amount' => Money::naira($naira), 'status' => $status,
        'cash_paid_while_pending' => $cashPaid, 'customer_name' => 'Chinedu O.', 'customer_phone' => '08031234567',
    ])->transaction, $test->agent);
}

function caseFor(object $test, Transaction $t): ReversalCase
{
    return tenancy()->runAs($test->business, fn () => ReversalCase::query()->where('transaction_id', $t->id)->sole());
}

function updateCase(object $test, ReversalCase $case, ?ReversalStatus $to, ?string $outcome = null, $by = null): ReversalCase
{
    $by ??= $test->ownerMembership;

    return tenancy()->runAs($test->business, fn () => app(ReversalCases::class)->update($case, $by, $to, 'note', null, $outcome), $by);
}

function cashAndPending(object $test): array
{
    return tenancy()->runAs($test->business, fn () => [
        'cash' => app(Accounts::class)->cash($test->outlet)->fresh()->balance,
        'pending' => app(Accounts::class)->forOutlet($test->outlet, LedgerAccountType::PendingSettlement)->fresh()->balance,
        'losses' => app(Accounts::class)->forOutlet($test->outlet, LedgerAccountType::ReversalLosses)->fresh()->balance,
    ]);
}

test('pending and failed transactions open a case due in 72 hours; successful ones do not', function () {
    $this->freezeTime();
    saveTx($this, TransactionStatus::Successful);
    $pending = saveTx($this, TransactionStatus::Pending);
    saveTx($this, TransactionStatus::Failed);

    $case = caseFor($this, $pending);

    expect(tenancy()->runAs($this->business, fn () => ReversalCase::query()->count()))->toBe(2)
        ->and($case->status)->toBe(ReversalStatus::Open)
        ->and($case->customer_phone)->toBe('08031234567')
        ->and($case->due_at->toDateTimeString())->toBe(now()->addHours(72)->toDateTimeString())
        ->and($case->isOverdue())->toBeFalse();

    $this->travel(73)->hours();
    expect($case->fresh()->isOverdue())->toBeTrue();
});

test('confirming the transaction closes the case and posts the money', function () {
    $t = saveTx($this, TransactionStatus::Pending, cashPaid: true);
    tenancy()->runAs($this->business, fn () => app(ResolvePendingTransaction::class)->handle($t, TransactionStatus::Successful));

    expect(caseFor($this, $t)->status)->toBe(ReversalStatus::ResolvedSuccessful)
        ->and(cashAndPending($this)['pending'])->toBe(0);
});

test('closing as "went through" from the case does the same', function () {
    $t = saveTx($this, TransactionStatus::Pending);
    updateCase($this, caseFor($this, $t), ReversalStatus::ResolvedSuccessful);

    expect($t->fresh()->status)->toBe(TransactionStatus::Successful)
        ->and($t->fresh()->journal_entry_id)->not->toBeNull()
        ->and(caseFor($this, $t)->updates()->count())->toBe(2); // opened + resolved, no duplicate line
});

test('reversed with the customer returning the cash puts it back in the drawer', function () {
    $t = saveTx($this, TransactionStatus::Pending, cashPaid: true);
    expect(cashAndPending($this))->toMatchArray(['cash' => -2_000_000, 'pending' => 2_000_000]);

    $case = updateCase($this, caseFor($this, $t), ReversalStatus::Reversed, 'cash_recovered');

    expect($case->outcome)->toBe('cash_recovered')
        ->and(cashAndPending($this))->toBe(['cash' => 0, 'pending' => 0, 'losses' => 0])
        ->and($t->fresh()->status)->toBe(TransactionStatus::Failed);
});

test('written off, the cash already paid becomes a recorded loss', function () {
    $t = saveTx($this, TransactionStatus::Pending, cashPaid: true);
    updateCase($this, caseFor($this, $t), ReversalStatus::WrittenOff);

    expect(cashAndPending($this))->toBe(['cash' => -2_000_000, 'pending' => 0, 'losses' => 2_000_000]);
});

test('closing a cash-paid case as reversed needs to know what happened to the cash', function () {
    $t = saveTx($this, TransactionStatus::Pending, cashPaid: true);

    updateCase($this, caseFor($this, $t), ReversalStatus::Reversed);
})->throws(ValidationException::class);

test('agents can add notes and escalate, but not write off', function () {
    $t = saveTx($this, TransactionStatus::Failed);
    $case = updateCase($this, caseFor($this, $t), ReversalStatus::Escalated, null, $this->agent);

    expect($case->status)->toBe(ReversalStatus::Escalated)
        ->and(fn () => updateCase($this, $case, ReversalStatus::WrittenOff, null, $this->agent))->toThrow(AuthorizationException::class);
});

test('voiding the transaction cancels its case; a settled case blocks voiding', function () {
    $a = saveTx($this, TransactionStatus::Pending);
    tenancy()->runAs($this->business, fn () => app(VoidTransaction::class)->handle($a, $this->owner, 'Wrong entry'));
    expect(caseFor($this, $a)->status)->toBe(ReversalStatus::Cancelled);

    $b = saveTx($this, TransactionStatus::Pending, cashPaid: true);
    updateCase($this, caseFor($this, $b), ReversalStatus::WrittenOff);

    expect(fn () => tenancy()->runAs($this->business, fn () => app(VoidTransaction::class)->handle($b, $this->owner, 'Oops')))
        ->toThrow(ValidationException::class);
});

test('closed cases cannot be changed again', function () {
    $t = saveTx($this, TransactionStatus::Failed);
    $case = updateCase($this, caseFor($this, $t), ReversalStatus::Reversed);

    updateCase($this, $case, ReversalStatus::Open);
})->throws(ValidationException::class);

test('the reversals page shows overdue cases first and only the agent\'s outlets', function () {
    $other = addOutlet($this->business, ['name' => 'Ojota']);
    $t = saveTx($this, TransactionStatus::Pending);
    $this->travel(80)->hours();
    saveTx($this, TransactionStatus::Failed);

    // A case at another outlet, recorded by the owner.
    tenancy()->runAs($this->business, fn () => app(RecordTransaction::class)->handle($this->ownerMembership->load('outlets'), [
        'idempotency_key' => (string) Str::uuid(), 'outlet_id' => $other->id, 'provider_id' => $this->provider->id,
        'type' => TransactionType::Transfer, 'amount' => Money::naira(5_000), 'status' => TransactionStatus::Failed,
    ]), $this->ownerMembership);

    $this->actingAs($this->agent->user)->get(route('reversals.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('cases', 2)
            ->where('cases.0.transaction_id', $t->id)->where('cases.0.overdue', true)
            ->where('counts.overdue', 1));

    $this->actingAs($this->owner)->get(route('reversals.index'))
        ->assertInertia(fn ($page) => $page->has('cases', 3));
});

test('updating a case over HTTP records the note and ticket', function () {
    $t = saveTx($this, TransactionStatus::Failed);
    $case = caseFor($this, $t);

    $this->actingAs($this->agent->user)->post(route('reversals.update', $case), [
        'status' => 'escalated', 'note' => 'Called Moniepoint support', 'provider_ticket' => 'MP-55120',
    ])->assertSessionHasNoErrors();

    $fresh = $case->fresh();
    expect($fresh->status)->toBe(ReversalStatus::Escalated)->and($fresh->provider_ticket)->toBe('MP-55120')
        ->and($fresh->updates()->pluck('note')->all())->toContain('Called Moniepoint support');
});
