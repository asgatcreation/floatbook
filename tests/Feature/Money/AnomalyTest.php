<?php

use App\Actions\Transactions\RecordTransaction;
use App\Actions\Transactions\VoidTransaction;
use App\Enums\AnomalyKind;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AnomalyFlag;
use App\Models\Membership;
use App\Models\Transaction;
use App\Support\Anomalies\AnomalyChecks;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

beforeEach(function () {
    // Mid-afternoon, well inside working hours (Ketu closes at 20:00).
    $this->travelTo(CarbonImmutable::parse('2026-10-16 14:00:00', 'Africa/Lagos'));

    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->supervisor = addMember($this->business, Role::Supervisor, [$this->ketu]);
    $this->accountant = addMember($this->business, Role::Accountant);
});

function entryAt(object $test, int $naira, array $extra = [], ?Membership $by = null, ?object $outlet = null): Transaction
{
    $by ??= $test->agent;
    $outlet ??= $test->ketu;

    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($by, [
        'idempotency_key' => (string) Str::uuid(),
        'terminal_id' => terminalAt($test->business, $outlet)->id,
        'type' => TransactionType::CashWithdrawal,
        'amount' => Money::naira($naira),
        'status' => TransactionStatus::Successful,
        'any_terminal' => true,
        ...$extra,
    ])->transaction, $by);
}

function flags(object $test): Collection
{
    return tenancy()->runAs($test->business, fn () => AnomalyFlag::query()->get());
}

test('the same reference twice is flagged, and the entry is still saved', function () {
    entryAt($this, 5_000, ['reference' => '220944718800']);
    $second = entryAt($this, 5_000, ['reference' => '220944718800']);

    $flag = flags($this)->sole();

    expect($flag->kind)->toBe(AnomalyKind::DuplicateReference)
        ->and($flag->severity)->toBe('serious')          // same amount too
        ->and($flag->subject_id)->toBe($second->id)
        ->and($flag->summary)->toContain('220944718800')
        ->and($second->exists)->toBeTrue()
        ->and(tenancy()->runAs($this->business, fn () => Transaction::query()->count()))->toBe(2);
});

test('a different reference, or an older one, is not flagged', function () {
    entryAt($this, 5_000, ['reference' => 'AAA111']);
    entryAt($this, 5_000, ['reference' => 'BBB222']);
    entryAt($this, 5_000); // no reference at all

    expect(flags($this))->toHaveCount(0);
});

test('an unusually large amount is flagged', function () {
    entryAt($this, 40_000);  // under the ₦50,000 default
    expect(flags($this))->toHaveCount(0);

    entryAt($this, 60_000);
    $flag = flags($this)->sole();
    expect($flag->kind)->toBe(AnomalyKind::LargeAmount)->and($flag->severity)->toBe('watch');

    // Twice the limit is treated as serious.
    entryAt($this, 150_000);
    expect(flags($this)->where('severity', 'serious'))->toHaveCount(1);
});

test('each business sets its own idea of large', function () {
    tenancy()->runAs($this->business, fn () => $this->business->forceFill([
        'settings' => [...$this->business->settings, 'large_amount' => 10_000_00],
    ])->save());

    entryAt($this, 20_000);

    expect(flags($this)->sole()->kind)->toBe(AnomalyKind::LargeAmount);
});

test('an entry long after closing time is flagged', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 22:30:00', 'Africa/Lagos'));
    entryAt($this, 5_000);

    $flag = flags($this)->sole();
    expect($flag->kind)->toBe(AnomalyKind::AfterHours)->and($flag->summary)->toContain('10:30 pm');
});

test('a little after closing is fine; the small hours are not', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 20:45:00', 'Africa/Lagos')); // inside the grace
    entryAt($this, 5_000);
    expect(flags($this))->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 03:00:00', 'Africa/Lagos'));
    entryAt($this, 5_000);
    expect(flags($this)->sole()->kind)->toBe(AnomalyKind::AfterHours);
});

test('someone voiding often is flagged once, not once per void', function () {
    $voids = collect(range(1, 3))->map(fn () => entryAt($this, 5_000));

    tenancy()->runAs($this->business, function () use ($voids) {
        foreach ($voids as $t) {
            app(VoidTransaction::class)->handle($t, $this->agent->user, 'Mistake');
        }
    });

    $flag = flags($this)->where('kind', AnomalyKind::FrequentVoids)->sole();
    expect($flag->membership_id)->toBe($this->agent->id)
        ->and($flag->summary)->toContain('3 entries');
});

test('a judged flag is not raised again by a later check', function () {
    entryAt($this, 60_000);
    $flag = flags($this)->sole();

    $this->actingAs($this->supervisor->user)
        ->post(route('flags.review', $flag), ['status' => 'dismissed', 'note' => 'Big customer, receipt checked'])
        ->assertSessionHasNoErrors();

    // The same transaction is checked again (as happens on a re-run).
    tenancy()->runAs($this->business, fn () => app(AnomalyChecks::class)
        ->forTransaction(Transaction::query()->whereKey($flag->subject_id)->firstOrFail()));

    $fresh = $flag->fresh();
    expect($fresh->status)->toBe('dismissed')
        ->and($fresh->review_note)->toBe('Big customer, receipt checked')
        ->and(flags($this))->toHaveCount(1);
});

test('supervisors judge flags for their outlets; agents cannot see them at all', function () {
    entryAt($this, 60_000);
    entryAt($this, 60_000, outlet: $this->ojota, by: $this->ownerMembership);

    // The supervisor covers Ketu only.
    $this->actingAs($this->supervisor->user)->get(route('flags.index'))
        ->assertInertia(fn ($page) => $page->component('anomalies/Index')->has('flags.data', 1)->where('canReview', true));

    // The accountant sees both but cannot judge.
    $this->actingAs($this->accountant->user)->get(route('flags.index'))
        ->assertInertia(fn ($page) => $page->has('flags.data', 2)->where('canReview', false));

    $this->actingAs($this->agent->user)->get(route('flags.index'))->assertForbidden();
});

test('a confirmed flag can be looked at again', function () {
    entryAt($this, 60_000);
    $flag = flags($this)->sole();

    $this->actingAs($this->owner)->post(route('flags.review', $flag), ['status' => 'confirmed']);
    expect($flag->fresh()->status)->toBe('confirmed');

    $this->post(route('flags.review', $flag), ['status' => 'open']);
    expect($flag->fresh()->status)->toBe('open')->and($flag->fresh()->reviewed_by)->toBeNull();
});

test('the sidebar count and the dashboard show open flags', function () {
    entryAt($this, 60_000);

    $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('tenant.open_flags', 1)
        ->where('alerts', fn ($alerts) => collect($alerts)->pluck('text')->contains('1 flag to look at')));

    // Agents never see the count.
    $this->actingAs($this->agent->user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('tenant.open_flags', 0));
});
