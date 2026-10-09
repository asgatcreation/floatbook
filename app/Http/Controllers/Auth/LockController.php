<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUnlocked;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lock and unlock a shared counter device. Unlocking uses the member's PIN, or their
 * password if they haven't set one. Five wrong PINs sign the person out completely.
 */
class LockController extends Controller
{
    public const MAX_PIN_ATTEMPTS = 5;

    public function __construct(private CurrentBusiness $tenancy) {}

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->put(EnsureUnlocked::SESSION_LOCKED, true);

        return redirect()->route('unlock.show');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->get(EnsureUnlocked::SESSION_LOCKED)) {
            return redirect()->route('dashboard');
        }

        /** @var User $user */
        $user = $request->user();
        $membership = $this->tenancy->membership();

        return Inertia::render('auth/Unlock', [
            'name' => $user->name,
            'usesPin' => $membership?->hasPin() ?? false,
        ]);
    }

    public function unlock(Request $request, Audit $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->tenancy->membership();
        $usesPin = $membership?->hasPin() ?? false;

        $validated = $request->validate([
            'secret' => $usesPin ? ['required', 'string', 'regex:/^\d{4,6}$/'] : ['required', 'string'],
        ], ['secret.regex' => 'Enter your 4 to 6 digit PIN.']);

        $correct = $membership !== null && $usesPin
            ? Hash::check($validated['secret'], (string) $membership->pin_hash)
            : Hash::check($validated['secret'], $user->password);

        if (! $correct) {
            if ($membership !== null && $usesPin) {
                $membership->increment('pin_failed_attempts');

                if ($membership->pin_failed_attempts >= self::MAX_PIN_ATTEMPTS) {
                    $membership->forceFill(['pin_failed_attempts' => 0])->save();
                    $audit->record('pin.locked_out', $membership, meta: ['attempts' => self::MAX_PIN_ATTEMPTS]);

                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->with('status', 'Too many wrong PIN attempts. Sign in with your password.');
                }
            }

            return back()->withErrors(['secret' => $usesPin ? 'Wrong PIN.' : 'Wrong password.']);
        }

        if ($membership !== null && $membership->pin_failed_attempts > 0) {
            $membership->forceFill(['pin_failed_attempts' => 0])->save();
        }

        $request->session()->forget(EnsureUnlocked::SESSION_LOCKED);
        $request->session()->put(EnsureUnlocked::SESSION_LAST_SEEN, now()->getTimestamp());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
