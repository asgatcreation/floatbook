<?php

use App\Enums\Role;
use App\Models\Business;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Provider;
use App\Models\Transaction;
use App\Support\Ledger\Accounts;
use Illuminate\Support\Facades\Process;

/**
 * Real concurrency: separate PHP processes record transactions against the same outlet
 * at the same time. Without row locks, two processes would read the same balance and
 * one update would overwrite the other. With them, every kobo is accounted for.
 */
test('parallel processes posting to the same accounts never lose a kobo', function () {
    [$business, , $ownerMembership] = businessWithOwner();
    $outlet = addOutlet($business);
    $agent = addMember($business, Role::Agent, [$outlet]);
    $provider = tenancy()->runAs($business, fn () => Provider::query()->where('slug', 'moniepoint')->sole());

    $workers = 4;
    $perWorker = 15;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')];

    $results = Process::pool(function ($pool) use ($workers, $perWorker, $business, $agent, $outlet, $provider, $env) {
        foreach (range(1, $workers) as $i) {
            $pool->as("worker-{$i}")->path(base_path())->env($env)->timeout(180)
                ->command([PHP_BINARY, 'artisan', 'floatbook:ledger-stress', $business->id, $agent->id, $outlet->id, $provider->id, (string) $perWorker]);
        }
    })->start()->wait();

    foreach ($results as $name => $result) {
        expect($result->successful())->toBeTrue("{$name} failed: ".$result->errorOutput());
    }

    tenancy()->runAs($business, function () use ($workers, $perWorker, $outlet) {
        expect(Transaction::query()->where('source', 'stress_test')->count())->toBe($workers * $perWorker);

        // Every cached balance equals the true sum of its lines, and the whole ledger nets to zero.
        foreach (LedgerAccount::query()->get() as $account) {
            expect($account->balance)->toBe((int) JournalLine::query()->where('ledger_account_id', $account->id)->sum('amount'), $account->name);
        }

        expect((int) JournalLine::query()->sum('amount'))->toBe(0);

        // And the drawer matches what the transactions themselves say should have happened.
        $expectedCash = Transaction::query()->get()->sum(fn (Transaction $t) => match ($t->type->flow()) {
            'cash_out' => -$t->amount + $t->charge,
            'cash_in' => $t->amount + $t->charge,
            default => $t->charge,
        });
        expect(app(Accounts::class)->cash($outlet)->fresh()->balance)->toBe($expectedCash);
    });

    // DatabaseTruncation leaves nothing behind, but this business must not leak into other tests.
    expect(Business::query()->count())->toBe(1);
})->skip(fn () => ! function_exists('proc_open'), 'Needs proc_open to start worker processes.');
