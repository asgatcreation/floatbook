<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\DeviceName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Where you're signed in": lists the user's active sessions and can sign out the others.
 */
class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session) => [
                'device' => DeviceName::fromUserAgent((string) $session->user_agent),
                'ip_address' => $session->ip_address,
                'is_current' => $session->id === $currentId,
                'last_active' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
            ]);

        return Inertia::render('settings/Sessions', ['sessions' => $sessions]);
    }

    public function destroyOthers(Request $request, Audit $audit): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password']]);

        /** @var User $user */
        $user = $request->user();

        Auth::logoutOtherDevices($request->string('password')->value());

        $removed = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $audit->record('user.signed_out_other_devices', $user, meta: ['sessions' => $removed], business: $user->currentBusiness);

        $this->toast('Signed out of all other devices.');

        return back();
    }
}
