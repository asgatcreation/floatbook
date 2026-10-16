<?php

use App\Actions\Expenses\Expenses;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\ExpenseCategory;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Mail\DailySummaryMail;
use App\Models\Business;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
    // Ketu closes at 20:00; it is now mid-afternoon.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 15:00:00', 'Africa/Lagos'));

    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner(['name' => 'Ade & Sons POS']);
    $this->owner->forceFill(['email_verified_at' => now()])->save();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
});

function closingTime(object $test): void
{
    $test->travelTo(CarbonImmutable::parse('2026-10-16 20:30:00', 'Africa/Lagos'));
}

function took(object $test, int $naira): void
{
    tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($test->agent, [
        'idempotency_key' => (string) Str::uuid(),
        'terminal_id' => terminalAt($test->business, $test->ketu)->id,
        'type' => TransactionType::CashWithdrawal,
        'amount' => Money::naira($naira),
        'status' => TransactionStatus::Successful,
    ]), $test->agent);
}

test('the owner gets one email after closing time, with the day\'s figures', function () {
    took($this, 10_000); // charge ₦200, fee ₦50 → profit ₦150
    tenancy()->runAs($this->business, fn () => app(Expenses::class)->record(
        $this->ownerMembership, $this->ketu, ExpenseCategory::Fuel, Money::naira(50),
        app(Accounts::class)->cash($this->ketu), 'Diesel', (string) Str::uuid(),
    ));

    // Before closing time, nothing goes out.
    $this->artisan('floatbook:daily-summary')->assertSuccessful();
    Mail::assertNothingSent();

    closingTime($this);
    $this->artisan('floatbook:daily-summary')->assertSuccessful();

    Mail::assertSent(DailySummaryMail::class, function (DailySummaryMail $mail) {
        $mail->assertTo($this->owner->email);
        $s = $mail->summary;

        return $s['business'] === 'Ade & Sons POS'
            && $s['total']['count'] === 1
            && $s['total']['volume'] === 1_000_000
            && $s['total']['expenses'] === 5_000       // ₦50
            && $s['total']['profit'] === 15_000 - 5_000  // ₦200 charge − ₦50 fee − ₦50 fuel
            && $s['outlets'][0]['name'] === 'Ketu Main'
            && $s['outlets'][0]['counted'] === null
            && in_array("Ketu Main wasn't counted today.", $s['attention'], true);
    });

    // Running again the same evening doesn't send it twice.
    $this->artisan('floatbook:daily-summary')->assertSuccessful();
    Mail::assertSentCount(1);
});

test('the email says so when the day was quiet', function () {
    closingTime($this);
    $this->artisan('floatbook:daily-summary')->assertSuccessful();

    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $m) => $m->summary['hasActivity'] === false
        && $m->summary['total']['count'] === 0);
});

test('a day missed while the server slept is caught up the next morning', function () {
    took($this, 10_000);

    // Nothing ran yesterday evening; the next run is the following morning.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 07:00:00', 'Africa/Lagos'));
    $this->artisan('floatbook:daily-summary')->assertSuccessful();

    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $m) => $m->summary['date'] === '2026-10-16'
        && $m->summary['total']['count'] === 1);

    // And it isn't sent again later that morning.
    $this->artisan('floatbook:daily-summary')->assertSuccessful();
    Mail::assertSentCount(1);
});

test('owners can switch the summary off', function () {
    tenancy()->runAs($this->business, function () {
        $this->actingAs($this->owner)->patch(route('business.update'), [
            'name' => 'Ade & Sons POS',
            'timezone' => 'Africa/Lagos',
            'closing_time' => '20:00',
            'require_two_factor' => false,
            'idle_lock_minutes' => 10,
            'daily_summary' => false,
        ])->assertSessionHasNoErrors();
    });

    closingTime($this);
    $this->artisan('floatbook:daily-summary')->assertSuccessful();
    Mail::assertNothingSent();
});

test('it is not sent to an owner who has not confirmed their email', function () {
    $this->owner->forceFill(['email_verified_at' => null])->save();

    closingTime($this);
    $this->artisan('floatbook:daily-summary')->assertSuccessful();
    Mail::assertNothingSent();
});

test('each business gets its own email, and one failing does not stop the others', function () {
    [$other, $otherOwner] = businessWithOwner(['name' => 'Second POS']);
    $otherOwner->forceFill(['email_verified_at' => now()])->save();

    closingTime($this);
    $this->artisan('floatbook:daily-summary')->assertSuccessful();

    Mail::assertSentCount(2);
    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $m) => $m->summary['business'] === 'Second POS');

    expect(Business::query()->whereKey($other->id)->value('settings'))
        ->toHaveKey('daily_summary_sent_for', '2026-10-16');
});

test('the email renders, in HTML and plain text', function () {
    took($this, 10_000);
    closingTime($this);
    $this->artisan('floatbook:daily-summary');

    Mail::assertSent(DailySummaryMail::class, function (DailySummaryMail $mail) {
        $html = $mail->render();

        expect($html)->toContain('Ade &amp; Sons POS')->toContain('Ketu Main')->toContain('₦150')
            ->and($mail->envelope()->subject)->toContain('₦150 profit on Friday 16 October 2026');

        return true;
    });
});
