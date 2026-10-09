<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Support\Audit;
use App\Support\OneTimeCodeResult;
use App\Support\OneTimeCodes;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationCodeController extends Controller
{
    public function store(Request $request, OneTimeCodes $codes, Audit $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\s*\d{6}\s*$/'],
        ], [
            'code.regex' => 'Enter the 6-digit code from the email.',
        ]);

        $result = $codes->verify($user, OneTimeCode::PURPOSE_VERIFY_EMAIL, $validated['code']);

        if ($result !== OneTimeCodeResult::Valid) {
            return back()->withErrors(['code' => $result->message()]);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));
        $audit->record('user.email_verified', $user, after: ['email' => $user->email], business: $user->currentBusiness, actor: $user);

        $this->toast('Email confirmed. Welcome to FloatBook!');

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
