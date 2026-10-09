<?php

use App\Actions\Transactions\RecordTransaction;
use App\Actions\Transactions\ResolvePendingTransaction;
use App\Actions\Transactions\VoidTransaction;
use App\Enums\LedgerAccountType;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\ChargeRule;
use App\Models\ChargeRuleTier;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Membership;
use App\Models\Provider;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->outlet = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->agent = addMember($this->business, Role::Agent, [$this->outlet]);
    $this->provider = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());
});

/**
 * Record a transaction as the given member and return it.
 */
function record(object $test, TransactionType $type, int $naira, TransactionStatus $status = TransactionStatus::Successful, array $extra = [], ?Membership $by = null): Transaction
{
    $by ??= $test->agent;

    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($by, [
        'idempotency_key' => (string) Str::uuid(),
        'outlet_id' => $test->outlet->id,
        'provider_id' => $test->provider->id,
        'type' => $type,
        'amount' => Money::naira($naira),
        'status' => $status,
        ...$extra,
    ])->transaction, $by);
}

/**
 * @return array{cash: int, wallet: int, charges: int, commissions: int, fees: int, pending: int}
 */
function balances(object $test): array
{
    return tenancy()->runAs($test->business, function () use ($test) {
        $a = app(Accounts::class);
        $o = $test->outlet;

        return [
            'cash' => $a->cash($o)->fresh()->balance,
            'wallet' => $a->wallet($o, $test->provider)->fresh()->balance,
            'charges' => -$a->forOutlet($o, LedgerAccountType::IncomeCharges)->fresh()->balance,
            'commissions' => -$a->forOutlet($o, LedgerAccountType::IncomeCommissions)->fresh()->balance,
            'fees' => $a->forOutlet($o, LedgerAccountType::ExpenseProviderFees)->fresh()->balance,
            'pending' => $a->forOutlet($o, LedgerAccountType::PendingSettlement)->fresh()->balance,
        ];
    });
}

test('a ₦10,000 withdrawal: wallet +₦9,950, cash −₦9,800, profit ₦150', function () {
    $t = record($this, TransactionType::CashWithdrawal, 10_000);

    expect($t->charge)->toBe(20_000)->and($t->provider_fee)->toBe(5_000)->and($t->profit()->kobo)->toBe(15_000)
        ->and(balances($this))->toBe(['cash' => -980_000, 'wallet' => 995_000, 'charges' => 20_000, 'commissions' => 0, 'fees' => 5_000, 'pending' => 0]);
});

test('a ₦10,000 transfer: cash +₦10,100, wallet −₦10,020', function () {
    record($this, TransactionType::Transfer, 10_000);

    expect(balances($this))->toMatchArray(['cash' => 1_010_000, 'wallet' => -1_002_000, 'charges' => 10_000, 'fees' => 2_000]);
});

test('₦1,000 airtime: cash +₦1,000, wallet −₦970 after the 3% commission', function () {
    record($this, TransactionType::Airtime, 1_000);

    expect(balances($this))->toMatchArray(['cash' => 100_000, 'wallet' => -97_000, 'commissions' => 3_000]);
});

test('a ₦1,500 card issuance: cash +₦1,500, wallet −₦1,000 provider cost', function () {
    record($this, TransactionType::CardIssuance, 1_500);

    expect(balances($this))->toMatchArray(['cash' => 150_000, 'wallet' => -100_000, 'charges' => 150_000, 'fees' => 100_000]);
});

test('a charge collected through the card goes to the wallet instead of the drawer', function () {
    tenancy()->runAs($this->business, fn () => ChargeRule::query()
        ->where('transaction_type', TransactionType::CashWithdrawal)->where('kind', 'customer_charge')
        ->update(['collected_via' => 'wallet']));

    record($this, TransactionType::CashWithdrawal, 5_000);

    expect(balances($this))->toMatchArray(['cash' => -500_000, 'wallet' => 500_000 - 2_500 + 10_000]);
});

test('every transaction type posts a balanced entry and keeps the whole ledger at zero', function (TransactionType $type) {
    record($this, $type, 7_350);

    $total = tenancy()->runAs($this->business, fn () => (int) JournalLine::query()->sum('amount'));
    $accountsTotal = tenancy()->runAs($this->business, fn () => (int) LedgerAccount::query()->sum('balance'));

    expect($total)->toBe(0)->and($accountsTotal)->toBe(0);
})->with(TransactionType::cases());

test('sending the same idempotency key twice records only one transaction', function () {
    $key = (string) Str::uuid();
    $payload = ['idempotency_key' => $key, 'outlet_id' => $this->outlet->id, 'provider_id' => $this->provider->id,
        'type' => TransactionType::Transfer, 'amount' => Money::naira(5_000), 'status' => TransactionStatus::Successful];

    $first = tenancy()->runAs($this->business, fn () => app(RecordTransaction::class)->handle($this->agent, $payload), $this->agent);
    $second = tenancy()->runAs($this->business, fn () => app(RecordTransaction::class)->handle($this->agent, $payload), $this->agent);

    expect($second->wasDuplicate)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and(tenancy()->runAs($this->business, fn () => Transaction::query()->count()))->toBe(1)
        ->and(balances($this)['cash'])->toBe(510_000);
});

test('pending and failed transactions do not move money', function () {
    record($this, TransactionType::Transfer, 5_000, TransactionStatus::Pending);
    record($this, TransactionType::CashWithdrawal, 5_000, TransactionStatus::Failed);

    expect(balances($this))->toBe(['cash' => 0, 'wallet' => 0, 'charges' => 0, 'commissions' => 0, 'fees' => 0, 'pending' => 0]);
});

test('cash paid on a pending withdrawal is tracked, then settled when it succeeds', function () {
    $t = record($this, TransactionType::CashWithdrawal, 10_000, TransactionStatus::Pending, ['cash_paid_while_pending' => true]);

    expect(balances($this))->toMatchArray(['cash' => -1_000_000, 'pending' => 1_000_000, 'wallet' => 0]);

    tenancy()->runAs($this->business, fn () => app(ResolvePendingTransaction::class)->handle($t, TransactionStatus::Successful));

    // Same end result as a withdrawal that succeeded straight away.
    expect(balances($this))->toBe(['cash' => -980_000, 'wallet' => 995_000, 'charges' => 20_000, 'commissions' => 0, 'fees' => 5_000, 'pending' => 0]);
});

test('a pending transaction that fails posts nothing more', function () {
    $t = record($this, TransactionType::Transfer, 3_000, TransactionStatus::Pending);
    $resolved = tenancy()->runAs($this->business, fn () => app(ResolvePendingTransaction::class)->handle($t, TransactionStatus::Failed));

    expect($resolved->status)->toBe(TransactionStatus::Failed)->and(balances($this)['cash'])->toBe(0)
        ->and(fn () => tenancy()->runAs($this->business, fn () => app(ResolvePendingTransaction::class)->handle($t, TransactionStatus::Successful)))
        ->toThrow(ValidationException::class);
});

test('voiding reverses the money but keeps the record', function () {
    $t = record($this, TransactionType::CashWithdrawal, 10_000);
    tenancy()->runAs($this->business, fn () => app(VoidTransaction::class)->handle($t, $this->owner, 'Entered twice'));

    expect(balances($this))->toBe(['cash' => 0, 'wallet' => 0, 'charges' => 0, 'commissions' => 0, 'fees' => 0, 'pending' => 0])
        ->and($t->fresh()->voided_at)->not->toBeNull()
        ->and(fn () => tenancy()->runAs($this->business, fn () => app(VoidTransaction::class)->handle($t, $this->owner, 'Again')))
        ->toThrow(ValidationException::class);
});

test('rule changes never rewrite past transactions', function () {
    $t = record($this, TransactionType::Transfer, 5_000);
    tenancy()->runAs($this->business, fn () => ChargeRuleTier::query()->update(['flat' => 99_900]));

    expect($t->fresh()->charge)->toBe(10_000);
});

test('agents cannot record for an outlet they do not work at', function () {
    $other = addOutlet($this->business);

    tenancy()->runAs($this->business, fn () => app(RecordTransaction::class)->handle($this->agent, [
        'idempotency_key' => (string) Str::uuid(), 'outlet_id' => $other->id, 'provider_id' => $this->provider->id,
        'type' => TransactionType::Transfer, 'amount' => Money::naira(1_000), 'status' => TransactionStatus::Successful,
    ]), $this->agent);
})->throws(ValidationException::class);
