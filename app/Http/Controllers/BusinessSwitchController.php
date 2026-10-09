<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BusinessSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['business_id' => ['required', 'string']]);

        // Only a business the user is an active member of can be selected.
        $membership = $user->activeMemberships()->where('business_id', $validated['business_id'])->first();

        abort_if($membership === null, 404);

        $user->forceFill(['current_business_id' => $membership->business_id])->save();

        return redirect()->route('dashboard');
    }
}
