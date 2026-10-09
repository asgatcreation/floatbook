<?php

use App\Support\Money\Money;

test('typed amounts are parsed into exact kobo', function (string $input, int $kobo) {
    expect(Money::parse($input)->kobo)->toBe($kobo);
})->with([
    ['5000', 500_000],
    ['5,000', 500_000],
    ['₦12,500.50', 1_250_050],
    ['0.1', 10],
    ['0.01', 1],
    ['  250  ', 25_000],
]);

test('ambiguous or invalid input is rejected rather than guessed', function (string $input) {
    Money::parse($input);
})->throws(InvalidArgumentException::class)->with(['', 'abc', '1.005', '12,5,00.1.2', '1e5']);

test('percentages use basis points and round half away from zero', function () {
    expect(Money::naira(10_000)->percent(50)->kobo)->toBe(5_000)       // 0.5% of ₦10,000 = ₦50
        ->and(Money::kobo(101)->percent(50)->kobo)->toBe(1)             // 0.505 kobo -> 1
        ->and(Money::kobo(99)->percent(50)->kobo)->toBe(0)              // 0.495 kobo -> 0
        ->and(Money::kobo(-101)->percent(50)->kobo)->toBe(-1);
});

test('started steps are counted correctly', function () {
    $step = Money::naira(5_000);

    expect(Money::naira(1)->stepsOf($step))->toBe(1)
        ->and(Money::naira(5_000)->stepsOf($step))->toBe(1)
        ->and(Money::kobo(500_001)->stepsOf($step))->toBe(2)
        ->and(Money::naira(20_000)->stepsOf($step))->toBe(4);
});

test('amounts are formatted as naira with a real minus sign', function () {
    expect(Money::kobo(1_250_050)->format())->toBe('₦12,500.50')
        ->and(Money::naira(5_000)->format(trimZeroKobo: true))->toBe('₦5,000')
        ->and(Money::kobo(-98_000)->format(true))->toBe('−₦980');
});

test('money is immutable', function () {
    $a = Money::naira(100);
    $b = $a->plus(Money::naira(50));

    expect($a->kobo)->toBe(10_000)->and($b->kobo)->toBe(15_000);
});
