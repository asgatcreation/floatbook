<?php

use App\Actions\Expenses\Expenses;
use App\Actions\Transactions\RecordTransaction;
use App\Actions\Transactions\VoidTransaction;
use App\Enums\ExpenseCategory;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Africa/Lagos'));

    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner(['name' => 'Ade & Sons POS']);
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->agent = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->accountant = addMember($this->business, Role::Accountant);
    $this->supervisor = addMember($this->business, Role::Supervisor, [$this->ojota]);
});

function entry(object $test, Outlet $outlet, int $naira, ?Membership $by = null, TransactionType $type = TransactionType::CashWithdrawal): Transaction
{
    $by ??= $test->ownerMembership;

    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($by, [
        'idempotency_key' => (string) Str::uuid(),
        'terminal_id' => terminalAt($test->business, $outlet)->id,
        'type' => $type,
        'amount' => Money::naira($naira),
        'status' => TransactionStatus::Successful,
        'any_terminal' => true,
    ])->transaction, $by);
}

test('the report totals the days and leaves voided entries out', function () {
    entry($this, $this->ketu, 10_000);                 // charge ₦200, fee ₦50
    $voided = entry($this, $this->ketu, 50_000);
    tenancy()->runAs($this->business, fn () => app(VoidTransaction::class)->handle($voided, $this->owner, 'Twice'));

    $this->travel(1)->days();
    entry($this, $this->ojota, 20_000, $this->supervisor);

    $this->actingAs($this->owner)->get(route('reports.index', ['from' => '2026-10-16', 'to' => '2026-10-17']))
        ->assertInertia(fn ($page) => $page
            ->component('reports/Index')
            ->has('rows', 2)
            ->where('rows.0.label', 'Fri 16 Oct 2026')
            ->where('rows.0.count', 1)
            ->where('rows.0.volume', 1_000_000)
            ->where('rows.1.volume', 2_000_000)
            ->where('total.count', 2)
            ->where('total.volume', 3_000_000));
});

test('grouping by outlet, terminal, staff and type', function () {
    entry($this, $this->ketu, 10_000, $this->agent);
    entry($this, $this->ketu, 5_000, $this->agent, TransactionType::Airtime);
    entry($this, $this->ojota, 30_000, $this->supervisor);

    $url = fn (string $group) => route('reports.index', ['from' => '2026-10-16', 'to' => '2026-10-16', 'group' => $group]);

    $this->actingAs($this->owner)->get($url('outlet'))->assertInertia(fn ($page) => $page
        ->where('rows.0.label', 'Ojota')->where('rows.0.volume', 3_000_000)   // biggest first
        ->where('rows.1.label', 'Ketu Main')->where('rows.1.volume', 1_500_000));

    $this->get($url('terminal'))->assertInertia(fn ($page) => $page
        ->where('rows.0.label', 'Moniepoint POS')->where('rows.0.sub', 'Ojota'));

    $this->get($url('agent'))->assertInertia(fn ($page) => $page->has('rows', 2));

    $this->get($url('type'))->assertInertia(fn ($page) => $page
        ->where('rows.0.label', 'Withdrawal')
        ->where('rows.1.label', 'Airtime')
        ->where('rows.1.volume', 500_000));
});

test('expenses lower the profit by day and outlet, but are not split across terminals', function () {
    entry($this, $this->ketu, 10_000); // profit ₦150
    tenancy()->runAs($this->business, fn () => app(Expenses::class)->record(
        $this->ownerMembership, $this->ketu, ExpenseCategory::Fuel, Money::naira(100),
        app(Accounts::class)->cash($this->ketu), 'Diesel', (string) Str::uuid(),
    ));

    $this->actingAs($this->owner)->get(route('reports.index', ['from' => '2026-10-16', 'to' => '2026-10-16']))
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.expenses', 10_000)
            ->where('rows.0.profit', 15_000 - 10_000)
            ->where('total.profit', 5_000));

    // By terminal, the expense isn't attributed; the note on the page explains it.
    $this->get(route('reports.index', ['from' => '2026-10-16', 'to' => '2026-10-16', 'group' => 'terminal']))
        ->assertInertia(fn ($page) => $page->where('rows.0.expenses', 0)->where('rows.0.profit', 15_000));

    // Narrowed to one terminal, the total leaves expenses out too.
    $terminal = terminalAt($this->business, $this->ketu);
    $this->get(route('reports.index', ['from' => '2026-10-16', 'to' => '2026-10-16', 'terminal' => $terminal->id]))
        ->assertInertia(fn ($page) => $page->where('total.expenses', 0)->where('total.profit', 15_000));
});

test('supervisors see their outlets, agents only their own figures', function () {
    entry($this, $this->ketu, 10_000, $this->agent);
    entry($this, $this->ketu, 90_000);                 // the owner's, same outlet
    entry($this, $this->ojota, 20_000, $this->supervisor);

    $range = ['from' => '2026-10-16', 'to' => '2026-10-16'];

    $this->actingAs($this->supervisor->user)->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page->where('total.volume', 2_000_000)->has('outlets', 1));

    // An agent's figures are their own, even if they ask for someone else's.
    $this->actingAs($this->agent->user)->get(route('reports.index', [...$range, 'agent' => $this->ownerMembership->id]))
        ->assertInertia(fn ($page) => $page->where('total.volume', 1_000_000)->where('filters.agent', $this->agent->id));
});

test('accountants can export; agents cannot', function () {
    entry($this, $this->ketu, 10_000);

    $csv = $this->actingAs($this->accountant->user)
        ->get(route('reports.export', ['from' => '2026-10-16', 'to' => '2026-10-16', 'format' => 'csv']));
    $csv->assertOk()->assertDownload('ade-sons-pos-report-by-day-16-oct-2026-to-16-oct-2026.csv');

    $body = $csv->streamedContent();
    expect($body)->toContain('Day,Entries,Volume')->toContain('"Fri 16 Oct 2026",1,10000.00,200.00,0.00,50.00,0.00,150.00')->toContain('Total,1,10000.00');

    $this->actingAs($this->agent->user)
        ->get(route('reports.export', ['from' => '2026-10-16', 'to' => '2026-10-16', 'format' => 'csv']))
        ->assertForbidden();
});

test('the detailed export lists every transaction, and Excel comes back as a real file', function () {
    entry($this, $this->ketu, 10_000, $this->agent);

    $csv = $this->actingAs($this->owner)->get(route('reports.export', [
        'from' => '2026-10-16', 'to' => '2026-10-16', 'format' => 'csv', 'detail' => 1,
    ]));
    expect($csv->streamedContent())
        ->toContain('Date,Time,Outlet,Terminal,Staff,Type,Amount')
        ->toContain('2026-10-16')
        ->toContain('Ketu Main')
        ->toContain('Moniepoint POS');

    $xlsx = $this->get(route('reports.export', ['from' => '2026-10-16', 'to' => '2026-10-16', 'format' => 'xlsx']));
    $xlsx->assertOk()->assertDownload('ade-sons-pos-report-by-day-16-oct-2026-to-16-oct-2026.xlsx');
    expect(substr($xlsx->streamedContent(), 0, 2))->toBe('PK'); // a zip, which is what .xlsx is
});
