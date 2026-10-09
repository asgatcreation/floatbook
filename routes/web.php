<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EmailVerificationCodeController;
use App\Http\Controllers\Auth\LockController;
use App\Http\Controllers\BusinessSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

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

    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::patch('staff/{membership}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('staff/{membership}/suspend', [StaffController::class, 'suspend'])->name('staff.suspend');
    Route::post('staff/{membership}/reactivate', [StaffController::class, 'reactivate'])->name('staff.reactivate');

    Route::post('staff/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
    Route::post('staff/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->middleware('throttle:10,1')->name('invitations.resend');
    Route::delete('staff/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('audit-log', [AuditLogController::class, 'index'])->can('view_audit_log')->name('audit-log.index');
});

require __DIR__.'/settings.php';
