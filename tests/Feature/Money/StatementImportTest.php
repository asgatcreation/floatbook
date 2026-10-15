<?php

use App\Actions\Transactions\RecordTransaction;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Provider;
use App\Models\StatementImport;
use App\Models\StatementImportRow;
use App\Models\Transaction;
use App\Support\Money\Money;
use App\Support\Statements\StatementValues;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->outlet = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->agent = addMember($this->business, Role::Agent, [$this->outlet]);
    $this->provider = tenancy()->runAs($this->business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());
    $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', 'Africa/Lagos'));
});

function recordAt(object $test, string $lagosTime, int $naira, ?string $reference = null, TransactionType $type = TransactionType::CashWithdrawal): Transaction
{
    $test->travelTo(CarbonImmutable::parse($lagosTime, 'Africa/Lagos'));

    return tenancy()->runAs($test->business, fn () => app(RecordTransaction::class)->handle($test->agent, [
        'idempotency_key' => (string) Str::uuid(), 'outlet_id' => $test->outlet->id, 'provider_id' => $test->provider->id,
        'type' => $type, 'amount' => Money::naira($naira), 'status' => TransactionStatus::Successful, 'reference' => $reference,
    ])->transaction, $test->agent);
}

function statementFile(string $csv, string $name = 'moniepoint-14-oct.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $csv);
}

const SAMPLE_STATEMENT = <<<'CSV'
Transaction Date,Transaction Reference,Transaction Type,Amount,Status
14/10/2026 09:12,220944718800,Cash Withdrawal,"10,000.00",Successful
14/10/2026 10:30,220944718801,Cash Withdrawal,"5,000.00",Successful
14/10/2026 11:45,220944718802,Transfer,"25,000.00",Successful
14/10/2026 11:45,220944718802,Transfer,"25,000.00",Successful
14/10/2026 12:05,220944718803,Cash Withdrawal,"8,000.00",Failed
14/10/2026 13:00,220944718804,Airtime,abc,Successful
14/10/2026 14:20,,Airtime,"1,000.00",Successful
CSV;

test('uploading a statement suggests the column mapping', function () {
    $this->actingAs($this->owner)->post(route('imports.store'), [
        'terminal_id' => terminalAt($this->business, $this->outlet, $this->provider)->id, 'file' => statementFile(SAMPLE_STATEMENT),
    ])->assertRedirect();

    $import = tenancy()->runAs($this->business, fn () => StatementImport::query()->sole());

    expect($import->row_count)->toBe(7)
        ->and($import->column_mapping)->toEqual([
            'date' => 'Transaction Date', 'amount' => 'Amount', 'reference' => 'Transaction Reference',
            'type' => 'Transaction Type', 'status' => 'Status',
        ]);
});

test('every row is matched, flagged or listed as missing', function () {
    recordAt($this, '2026-10-14 09:12', 10_000, '220944718800');      // same reference
    recordAt($this, '2026-10-14 10:36', 5_200, '220944718801');       // same reference, different amount
    recordAt($this, '2026-10-14 14:25', 1_000, null, TransactionType::Airtime); // no reference: amount + time
    recordAt($this, '2026-10-14 13:30', 3_000, 'NOT-ON-STATEMENT');    // only in FloatBook, within the statement's hours

    $this->actingAs($this->owner)->post(route('imports.store'), [
        'terminal_id' => terminalAt($this->business, $this->outlet, $this->provider)->id, 'file' => statementFile(SAMPLE_STATEMENT),
    ]);
    $import = tenancy()->runAs($this->business, fn () => StatementImport::query()->sole());

    $this->post(route('imports.map', $import), ['mapping' => $import->column_mapping])->assertRedirect();

    $statuses = tenancy()->runAs($this->business, fn () => StatementImportRow::query()->orderBy('row_number')->pluck('match_status')->all());

    expect($statuses)->toBe([
        'matched',               // ₦10,000 by reference
        'amount_mismatch',       // ₦5,000 vs ₦5,200
        'missing_in_floatbook',  // ₦25,000 transfer never recorded
        'duplicate',             // same line twice
        'provider_failed',       // provider says failed, nothing recorded: fine
        'invalid',               // "abc" is not an amount
        'matched',               // ₦1,000 airtime by amount and time
    ]);

    $this->get(route('imports.show', $import))->assertInertia(fn ($page) => $page
        ->has('notOnStatement', 1)->where('notOnStatement.0.reference', 'NOT-ON-STATEMENT'));
});

test('adding the missing rows records them once, at the statement time', function () {
    $this->actingAs($this->owner)->post(route('imports.store'), [
        'terminal_id' => terminalAt($this->business, $this->outlet, $this->provider)->id, 'file' => statementFile(SAMPLE_STATEMENT),
    ]);
    $import = tenancy()->runAs($this->business, fn () => StatementImport::query()->sole());
    $this->post(route('imports.map', $import), ['mapping' => $import->column_mapping]);

    $missing = tenancy()->runAs($this->business, fn () => StatementImportRow::query()->where('match_status', 'missing_in_floatbook')->pluck('id')->all());
    $this->post(route('imports.commit', $import), ['row_ids' => $missing, 'default_type' => 'transfer'])->assertRedirect();

    // Nothing was recorded at the counter in this test, so every valid, non-duplicate,
    // non-failed row is missing: ₦10,000, ₦5,000, ₦25,000 and ₦1,000.
    expect(count($missing))->toBe(4)
        ->and(tenancy()->runAs($this->business, fn () => Transaction::query()->count()))->toBe(4);

    $t = tenancy()->runAs($this->business, fn () => Transaction::query()->where('amount', 2_500_000)->sole());
    expect($t->amount)->toBe(2_500_000)->and($t->type)->toBe(TransactionType::Transfer)->and($t->source)->toBe('import')
        ->and($t->occurred_at->timezone('Africa/Lagos')->format('Y-m-d H:i'))->toBe('2026-10-14 11:45')
        ->and($t->charge)->toBe(20_000);

    // The same statement uploaded again: its rows are now duplicates or matches, nothing new to add.
    $this->post(route('imports.store'), [
        'terminal_id' => terminalAt($this->business, $this->outlet, $this->provider)->id, 'file' => statementFile(SAMPLE_STATEMENT),
    ]);
    $second = tenancy()->runAs($this->business, fn () => StatementImport::query()->latest()->first());
    $this->post(route('imports.map', $second), ['mapping' => $second->column_mapping]);

    expect(tenancy()->runAs($this->business, fn () => $second->rows()->where('match_status', 'missing_in_floatbook')->count()))->toBe(0);
});

test('Excel files are read too', function () {
    $path = tempnam(sys_get_temp_dir(), 'fb').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Date', 'RRN', 'Amount']));
    $writer->addRow(Row::fromValues([new DateTimeImmutable('2026-10-14 09:12:00'), '1234', 10000]));
    $writer->close();

    $this->actingAs($this->owner)->post(route('imports.store'), [
        'terminal_id' => terminalAt($this->business, $this->outlet, $this->provider)->id,
        'file' => new UploadedFile($path, 'statement.xlsx', null, null, true),
    ])->assertRedirect();

    $import = tenancy()->runAs($this->business, fn () => StatementImport::query()->sole());
    $this->post(route('imports.map', $import), ['mapping' => $import->column_mapping]);

    $row = tenancy()->runAs($this->business, fn () => StatementImportRow::query()->sole());
    expect($row->amount)->toBe(1_000_000)->and($row->reference)->toBe('1234')
        ->and($row->occurred_at->timezone('Africa/Lagos')->format('H:i'))->toBe('09:12');
});

test('bad files are explained, not crashed on', function (string $name, string $content, string $error) {
    $this->actingAs($this->owner)->post(route('imports.store'), [
        'terminal_id' => terminalAt($this->business, $this->outlet, $this->provider)->id, 'file' => statementFile($content, $name),
    ])->assertSessionHasErrors(['file' => $error]);
})->with([
    'header only' => ['empty.csv', "Date,Amount\n", 'The file has a header row but no transactions.'],
    'wrong type' => ['photo.pdf', '%PDF-1.4', 'Upload a .csv or .xlsx file.'],
]);

test('agents cannot import; supervisors only for their outlets', function () {
    $other = addOutlet($this->business, ['name' => 'Ojota']);
    $supervisor = addMember($this->business, Role::Supervisor, [$this->outlet]);

    $this->actingAs($this->agent->user)->get(route('imports.index'))->assertForbidden();

    $this->actingAs($supervisor->user)->post(route('imports.store'), [
        'terminal_id' => terminalAt($this->business, $other, $this->provider)->id, 'file' => statementFile(SAMPLE_STATEMENT),
    ])->assertForbidden();
});

test('amounts and dates are read the Nigerian way', function () {
    $v = new StatementValues;

    expect($v->amount('₦10,000.00'))->toBe(1_000_000)
        ->and($v->amount('(2,500.50)'))->toBe(250_050)
        ->and($v->amount('-300'))->toBe(30_000)
        ->and($v->amount('10.005'))->toBeNull()
        ->and($v->date('03/04/2026 14:05', 'Africa/Lagos')?->timezone('Africa/Lagos')->format('j F H:i'))->toBe('3 April 14:05')
        ->and($v->date('31/12/2026', 'Africa/Lagos'))->not->toBeNull()
        ->and($v->date('12/31/2026', 'Africa/Lagos'))->toBeNull()
        ->and($v->type('DSTV payment'))->toBe(TransactionType::BillPayment)
        ->and($v->type('POS Cash Withdrawal'))->toBe(TransactionType::CashWithdrawal)
        ->and($v->isFailed('Reversed'))->toBeTrue();
});
