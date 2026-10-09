<?php

use App\Http\Controllers\Settings\BusinessSettingsController;
use App\Http\Controllers\Settings\ChargeRuleController;
use App\Http\Controllers\Settings\PinController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'unlocked'])->group(function () {
    // A GET-only redirect: Route::redirect() also registers the QUERY verb, which Wayfinder's types don't support yet.
    Route::get('settings', fn () => redirect('/settings/profile'));

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'unlocked'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::put('settings/pin', [PinController::class, 'update'])->middleware(['member', 'throttle:6,1'])->name('pin.update');
    Route::delete('settings/pin', [PinController::class, 'destroy'])->middleware('member')->name('pin.destroy');

    Route::get('settings/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])
        ->middleware('throttle:6,1')
        ->name('sessions.destroy-others');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::middleware(['member', 'can:manage_charge_rules'])->group(function () {
        Route::get('settings/charges', [ChargeRuleController::class, 'index'])->name('charges.index');
        Route::post('settings/charges', [ChargeRuleController::class, 'store'])->name('charges.store');
        Route::put('settings/charges/{rule}', [ChargeRuleController::class, 'update'])->name('charges.update');
        Route::delete('settings/charges/{rule}', [ChargeRuleController::class, 'destroy'])->name('charges.destroy');
        Route::post('settings/providers', [ChargeRuleController::class, 'storeProvider'])->name('providers.store');
        Route::patch('settings/providers/{provider}', [ChargeRuleController::class, 'updateProvider'])->name('providers.update');
    });

    Route::get('settings/business', [BusinessSettingsController::class, 'edit'])
        ->middleware(['member', 'can:manage_business'])
        ->name('business.edit');
    Route::patch('settings/business', [BusinessSettingsController::class, 'update'])
        ->middleware(['member', 'can:manage_business'])
        ->name('business.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
