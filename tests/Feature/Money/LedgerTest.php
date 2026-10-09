<?php

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\Ledger\Accounts;
use App\Support\Ledger\Ledger;
use App\Support\Ledger\UnbalancedEntry;
use App\Support\Tenancy\TenancyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->business] = businessWithOwner();
    $this->outlet = addOutlet($this->business);
});

function inBusiness(object $test, Closure $callback): mixed
{
    return tenancy()->runAs($test->business, $callback);
}

test('a balanced entry updates every account balance', function () {
    inBusiness($this, function () {
        $accounts = app(Accounts::class);
        $cash = $accounts->cash($this->outlet);
        $head = $accounts->headOffice();

        app(Ledger::class)->post('test', [[$cash, 50_000], [$head, -50_000]]);

        expect($cash->fresh()->balance)->toBe(50_000)->and($head->fresh()->balance)->toBe(-50_000);
    });
});

test('an unbalanced entry is refused before anything is written', function () {
    inBusiness($this, function () {
        $accounts = app(Accounts::class);

        expect(fn () => app(Ledger::class)->post('test', [[$accounts->cash($this->outlet), 100], [$accounts->headOffice(), -99]]))
            ->toThrow(UnbalancedEntry::class);
        expect(JournalEntry::query()->count())->toBe(0);
    });
});

test('the database itself rejects lines that do not balance, even if the app is bypassed', function () {
    inBusiness($this, function () {
        $accounts = app(Accounts::class);
        $entry = JournalEntry::query()->create(['type' => 'test', 'occurred_at' => now(), 'business_date' => today()]);

        DB::table('journal_lines')->insert([
            ['business_id' => $this->business->id, 'journal_entry_id' => $entry->id, 'ledger_account_id' => $accounts->cash($this->outlet)->id, 'amount' => 100],
            ['business_id' => $this->business->id, 'journal_entry_id' => $entry->id, 'ledger_account_id' => $accounts->headOffice()->id, 'amount' => -90],
        ]);
    });
})->throws(QueryException::class, 'does not balance');

test('journal entries and lines can never be edited or deleted', function () {
    inBusiness($this, function () {
        $accounts = app(Accounts::class);
        $entry = app(Ledger::class)->post('test', [[$accounts->cash($this->outlet), 100], [$accounts->headOffice(), -100]]);

        expect(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['amount' => 5]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('journal_entries')->where('id', $entry->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class);
    });
});

test('a reversal cancels the effect but keeps the original', function () {
    inBusiness($this, function () {
        $accounts = app(Accounts::class);
        $cash = $accounts->cash($this->outlet);
        $entry = app(Ledger::class)->post('test', [[$cash, 7_500], [$accounts->headOffice(), -7_500]]);

        app(Ledger::class)->reverse($entry, 'Mistake');

        expect($cash->fresh()->balance)->toBe(0)
            ->and(JournalEntry::query()->count())->toBe(2)
            ->and(fn () => app(Ledger::class)->reverse($entry, 'Twice'))->toThrow(UnbalancedEntry::class);
    });
});

test('lines for the same account are combined and zero lines dropped', function () {
    inBusiness($this, function () {
        $accounts = app(Accounts::class);
        $entry = app(Ledger::class)->post('test', [
            [$accounts->cash($this->outlet), 300], [$accounts->cash($this->outlet), -100],
            [$accounts->headOffice(), -200], [$accounts->ownerCapital(), 0],
        ]);

        expect($entry->lines()->count())->toBe(2);
    });
});

test('an account from another business cannot be posted to', function () {
    [$other] = businessWithOwner();
    $foreign = tenancy()->runAs($other, fn () => app(Accounts::class)->headOffice());

    inBusiness($this, fn () => app(Ledger::class)->post('test', [
        [app(Accounts::class)->headOffice(), 100], [$foreign->id, -100],
    ]));
})->throws(TenancyViolation::class);

test('cached balances always equal the sum of journal lines', function () {
    inBusiness($this, function () {
        $accounts = app(Accounts::class);
        $ledger = app(Ledger::class);
        $cash = $accounts->cash($this->outlet);
        $head = $accounts->headOffice();

        foreach ([12_345, -2_000, 999, -10_344] as $amount) {
            $ledger->post('test', [[$cash, $amount], [$head, -$amount]]);
        }

        foreach (LedgerAccount::query()->get() as $account) {
            expect($account->balance)->toBe((int) JournalLine::query()->where('ledger_account_id', $account->id)->sum('amount'));
        }
    });
});

test('a business created with only a name can post straight away (defaults are complete in memory)', function () {
    $business = Business::query()->create(['name' => 'Fresh POS']);

    expect($business->timezone)->toBe('Africa/Lagos');

    tenancy()->runAs($business, function () {
        $accounts = app(Accounts::class);
        $entry = app(Ledger::class)->post('test', [[$accounts->headOffice(), 100], [$accounts->ownerCapital(), -100]]);

        expect($entry->business_date->toDateString())->toBe(now('Africa/Lagos')->toDateString());
    });
});
