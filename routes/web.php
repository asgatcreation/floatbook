<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\AnomalyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EmailVerificationCodeController;
use App\Http\Controllers\Auth\LockController;
use App\Http\Controllers\BusinessSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FloatController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReversalController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StatementImportController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\TransactionController;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    // The landing page offers one-click demo sign-ins only when demo mode is on.
    'demoRoles' => config('floatbook.demo.enabled')
        ? collect(DemoSeeder::PEOPLE)->map(fn (array $person, string $key) => [
            'key' => $key,
            'name' => $person[1],
            'role' => $person[2]->label(),
            'description' => $person[2]->description(),
        ])->values()
        : [],
]))->name('home');

// Privacy and Terms, readable without an account.
Route::get('privacy', fn () => Inertia::render('legal/Legal', [
    'page' => 'privacy', 'updated' => config('floatbook.legal.updated'), 'contact' => config('floatbook.legal.contact'),
]))->name('privacy');
Route::get('terms', fn () => Inertia::render('legal/Legal', [
    'page' => 'terms', 'updated' => config('floatbook.legal.updated'), 'contact' => config('floatbook.legal.contact'),
]))->name('terms');
// One-click sign-in to the demo business (only when demo mode is on).
Route::post('demo/{role}', DemoLoginController::class)->middleware(['guest', 'throttle:10,1'])->name('demo.login');

// Invitation links work whether or not the person is signed in.
Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [AcceptInvitationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('invitations.accept');

Route::middleware('auth')->group(function () {
    Route::post('email/verify-code', [EmailVerificationCodeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('verification.code');

    Route::inertia('no-business', 'NoBusiness')->name('no-business');
    Route::post('business/switch', BusinessSwitchController::class)->name('business.switch');

    Route::post('lock', [LockController::class, 'lock'])->name('lock');
    Route::get('unlock', [LockController::class, 'show'])->name('unlock.show');
    Route::post('unlock', [LockController::class, 'unlock'])->middleware('throttle:10,1')->name('unlock.store');
});

Route::middleware(['auth', 'verified', 'member', 'unlocked', 'two-factor.required'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('outlets', [OutletController::class, 'index'])->can('manage_outlets')->name('outlets.index');
    Route::post('outlets', [OutletController::class, 'store'])->can('manage_outlets')->name('outlets.store');
    Route::patch('outlets/{outlet}', [OutletController::class, 'update'])->can('manage_outlets')->name('outlets.update');
    Route::post('outlets/{outlet}/terminals', [TerminalController::class, 'store'])->can('manage_outlets')->name('terminals.store');
    Route::patch('terminals/{terminal}', [TerminalController::class, 'update'])->can('manage_outlets')->name('terminals.update');

    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::patch('staff/{membership}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('staff/{membership}/suspend', [StaffController::class, 'suspend'])->name('staff.suspend');
    Route::post('staff/{membership}/reactivate', [StaffController::class, 'reactivate'])->name('staff.reactivate');

    Route::post('staff/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
    Route::post('staff/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->middleware('throttle:10,1')->name('invitations.resend');
    Route::delete('staff/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('audit-log', [AuditLogController::class, 'index'])->can('view_audit_log')->name('audit-log.index');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/new', [TransactionController::class, 'create'])->can('record_transactions')->name('transactions.create');
    Route::post('transactions', [TransactionController::class, 'store'])->can('record_transactions')->middleware('throttle:120,1')->name('transactions.store');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::post('transactions/{transaction}/resolve', [TransactionController::class, 'resolve'])->can('record_transactions')->name('transactions.resolve');
    Route::post('transactions/{transaction}/void', [TransactionController::class, 'void'])->can('void_transactions')->name('transactions.void');
    Route::post('transactions/{transaction}/move', [TransactionController::class, 'move'])->can('record_transactions')->name('transactions.move');

    Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::get('reconciliation/{outlet}/{date}', [ReconciliationController::class, 'show'])->where('date', '\d{4}-\d{2}-\d{2}')->name('reconciliation.show');
    Route::post('reconciliation/{outlet}/{date}', [ReconciliationController::class, 'submit'])->where('date', '\d{4}-\d{2}-\d{2}')->can('record_transactions')->name('reconciliation.submit');
    Route::post('reconciliations/{reconciliation}/approve', [ReconciliationController::class, 'approve'])->can('approve_reconciliation')->name('reconciliation.approve');
    Route::post('reconciliations/{reconciliation}/send-back', [ReconciliationController::class, 'sendBack'])->can('approve_reconciliation')->name('reconciliation.send-back');
    Route::post('reconciliations/{reconciliation}/reopen', [ReconciliationController::class, 'reopen'])->can('reopen_day')->name('reconciliation.reopen');
    Route::post('shortages/{shortage}/settle', [ReconciliationController::class, 'settleShortage'])->can('approve_reconciliation')->name('shortages.settle');

    Route::get('flags', [AnomalyController::class, 'index'])->name('flags.index');
    Route::post('flags/{flag}/review', [AnomalyController::class, 'review'])->name('flags.review');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses', [ExpenseController::class, 'store'])->can('record_transactions')->name('expenses.store');
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->can('void_transactions')->name('expenses.void');

    Route::middleware('can:import_statements')->group(function () {
        Route::get('imports', [StatementImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [StatementImportController::class, 'store'])->middleware('throttle:20,1')->name('imports.store');
        Route::get('imports/{import}', [StatementImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{import}/map', [StatementImportController::class, 'map'])->name('imports.map');
        Route::post('imports/{import}/commit', [StatementImportController::class, 'commit'])->name('imports.commit');
    });

    Route::get('reversals', [ReversalController::class, 'index'])->name('reversals.index');
    Route::post('reversals/{case}', [ReversalController::class, 'update'])->middleware('throttle:60,1')->name('reversals.update');

    Route::get('float', [FloatController::class, 'index'])->name('float.index');
    Route::post('float/movements', [FloatController::class, 'store'])->name('float.store');
    Route::post('float/movements/{movement}/approve', [FloatController::class, 'approve'])->name('float.approve');
    Route::post('float/movements/{movement}/reject', [FloatController::class, 'reject'])->name('float.reject');
    Route::patch('float/accounts/{account}/threshold', [FloatController::class, 'threshold'])->name('float.threshold');
});

require __DIR__.'/settings.php';
