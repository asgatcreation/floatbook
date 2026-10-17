<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click sign-in to the demo business, so anyone can look around without making an
 * account. It only ever signs in one of the four demo people, only while demo mode is
 * on, and only if that person really belongs to a business marked as a demo.
 */
class DemoLoginController extends Controller
{
    public function __invoke(Request $request, string $role, CurrentBusiness $tenancy): RedirectResponse
    {
        abort_unless(config('floatbook.demo.enabled'), 404);
        abort_unless(array_key_exists($role, DemoSeeder::PEOPLE), 404);

        $business = Business::query()->where('slug', DemoSeeder::SLUG)->where('is_demo', true)->first();

        if ($business === null) {
            return back()->withErrors(['demo' => 'The demo is being rebuilt. Try again in a minute.']);
        }

        $user = User::query()->where('email', DemoSeeder::PEOPLE[$role][0])->first();

        // The account must still be a member of the demo business, and nothing else.
        $belongs = $user !== null && $tenancy->runAs(
            $business,
            fn () => Membership::query()->where('user_id', $user->id)->exists(),
        );

        if (! $belongs) {
            return back()->withErrors(['demo' => 'The demo is being rebuilt. Try again in a minute.']);
        }

        /** @var User $user */
        $user->forceFill(['current_business_id' => $business->id])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
