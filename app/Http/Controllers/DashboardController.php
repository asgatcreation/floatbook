<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * For now the dashboard greets the member and, for owners, shows a setup checklist.
 * The live money dashboard arrives with transactions and reconciliation.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentBusiness $tenancy): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = $tenancy->businessOrFail();
        $membership = $tenancy->membershipOrFail();

        $checklist = null;

        if ($membership->role === Role::Owner) {
            $checklist = [
                ['key' => 'email', 'label' => 'Confirm your email', 'done' => $user->hasVerifiedEmail()],
                ['key' => 'outlet', 'label' => 'Add your first outlet', 'done' => Outlet::query()->exists()],
                ['key' => 'staff', 'label' => 'Invite your staff', 'done' => Membership::query()->count() > 1 || Invitation::query()->exists()],
                ['key' => 'two_factor', 'label' => 'Turn on two-factor authentication', 'done' => $user->hasConfirmedTwoFactor()],
            ];
        }

        return Inertia::render('Dashboard', [
            'greetingName' => strtok($user->name, ' ') ?: $user->name,
            'businessName' => $business->name,
            'checklist' => $checklist,
        ]);
    }
}
