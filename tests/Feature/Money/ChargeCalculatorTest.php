<?php

use App\Enums\ChargeKind;
use App\Enums\TransactionType;
use App\Models\ChargeRule;
use App\Models\Provider;
use App\Support\Charges\ChargeCalculator;
use App\Support\Money\Money;

beforeEach(function () {
    [$this->business] = businessWithOwner();
    $this->outlet = addOutlet($this->business);
    $this->otherOutlet = addOutlet($this->business);
    $this->moniepoint = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());
    $this->opay = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'opay')->sole());
});

function quote(object $test, TransactionType $type, int $naira, ?Provider $provider = null, ?string $outletId = null): array
{
    return tenancy()->runAs($test->business, fn () => app(ChargeCalculator::class)
        ->quote($type, ($provider ?? $test->moniepoint)->id, $outletId ?? $test->outlet->id, Money::naira($naira))
        ->toArray());
}

test('starter rules: withdrawal charge is ₦100 per started ₦5,000 and the fee is 0.5% capped at ₦100', function (int $naira, int $chargeKobo, int $feeKobo) {
    $q = quote($this, TransactionType::CashWithdrawal, $naira);

    expect($q['charge'])->toBe($chargeKobo)->and($q['provider_fee'])->toBe($feeKobo);
})->with([
    'small' => [1_000, 10_000, 500],
    'exactly one band' => [5_000, 10_000, 2_500],
    'just over a band (fee ₦25.005 rounds to ₦25.01)' => [5_001, 20_000, 2_501],
    'fee hits the cap' => [50_000, 100_000, 10_000],
]);

test('starter rules: transfer charges follow their tiers', function (int $naira, int $charge) {
    expect(quote($this, TransactionType::Transfer, $naira)['charge'])->toBe($charge * 100);
})->with([[500, 100], [10_000, 100], [10_001, 200], [50_000, 200], [50_001, 300]]);

test('airtime earns a 3% commission and no charge', function () {
    $q = quote($this, TransactionType::Airtime, 1_000);

    expect($q)->toMatchArray(['charge' => 0, 'commission' => 3_000, 'provider_fee' => 0, 'profit' => 3_000]);
});

test('for services the price the customer pays is the charge', function () {
    $q = quote($this, TransactionType::CardIssuance, 1_500);

    expect($q)->toMatchArray(['charge' => 150_000, 'provider_fee' => 100_000, 'profit' => 50_000]);
});

test('the most specific rule wins: outlet + provider over provider over everything', function () {
    tenancy()->runAs($this->business, function () {
        $opayRule = ChargeRule::query()->create(['transaction_type' => TransactionType::Transfer, 'kind' => ChargeKind::CustomerCharge, 'provider_id' => $this->opay->id]);
        $opayRule->tiers()->create(['flat' => 15_000]);

        $outletRule = ChargeRule::query()->create(['transaction_type' => TransactionType::Transfer, 'kind' => ChargeKind::CustomerCharge,
            'provider_id' => $this->opay->id, 'outlet_id' => $this->outlet->id]);
        $outletRule->tiers()->create(['flat' => 25_000]);
    });

    expect(quote($this, TransactionType::Transfer, 1_000)['charge'])->toBe(10_000)                                   // Moniepoint: starter rule
        ->and(quote($this, TransactionType::Transfer, 1_000, $this->opay, $this->otherOutlet->id)['charge'])->toBe(15_000) // OPay elsewhere
        ->and(quote($this, TransactionType::Transfer, 1_000, $this->opay)['charge'])->toBe(25_000);                      // OPay at this outlet
});

test('inactive rules are ignored', function () {
    tenancy()->runAs($this->business, fn () => ChargeRule::query()
        ->where('transaction_type', TransactionType::CashWithdrawal)->where('kind', ChargeKind::CustomerCharge)
        ->update(['is_active' => false]));

    expect(quote($this, TransactionType::CashWithdrawal, 5_000)['charge'])->toBe(0);
});
