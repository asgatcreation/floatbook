<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * A 4–6 digit PIN to unlock a locked session quickly on a shared counter device.
 * It is per business, so a person working for two businesses can use two PINs.
 */
class PinController extends Controller
{
    /** PINs that are too easy to guess. */
    private const WEAK = ['0000', '1111', '1234', '12345', '123456', '2222', '4321', '9999', '0123', '1212', '000000', '111111', '654321'];

    public function __construct(private CurrentBusiness $tenancy, private Audit $audit) {}

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed', 'not_in:'.implode(',', self::WEAK)],
        ], [
            'pin.regex' => 'Your PIN must be 4 to 6 digits.',
            'pin.not_in' => 'That PIN is too easy to guess. Choose another.',
        ]);

        $membership = $this->tenancy->membershipOrFail();
        $hadPin = $membership->hasPin();

        $membership->forceFill([
            'pin_hash' => Hash::make($request->string('pin')->value()),
            'pin_failed_attempts' => 0,
        ])->save();

        $this->audit->record($hadPin ? 'pin.changed' : 'pin.set', $membership);

        $this->toast('Your PIN is saved.');

        return back();
    }

    public function destroy(): RedirectResponse
    {
        $membership = $this->tenancy->membershipOrFail();
        $membership->forceFill(['pin_hash' => null, 'pin_failed_attempts' => 0])->save();
        $this->audit->record('pin.removed', $membership);

        $this->toast('Your PIN has been removed.');

        return back();
    }
}
