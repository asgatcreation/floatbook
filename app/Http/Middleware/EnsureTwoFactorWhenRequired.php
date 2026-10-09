<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the owner has made two-factor authentication compulsory, members it applies to
 * can only reach the security settings (to turn it on) until they have done so.
 */
class EnsureTwoFactorWhenRequired
{
    /** Routes that must stay reachable so the member can set up 2FA or leave. */
    private const ALLOWED_ROUTES = ['security.edit', 'two-factor.*', 'password.confirm*', 'logout', 'no-business'];

    public function __construct(private CurrentBusiness $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $business = $this->tenancy->business();
        $membership = $this->tenancy->membership();
        $user = $request->user();

        if (
            $business !== null && $membership !== null && $user instanceof User
            && $business->require_two_factor
            && $membership->role->canBeRequiredToUseTwoFactor()
            && ! $user->hasConfirmedTwoFactor()
            && ! $request->routeIs(...self::ALLOWED_ROUTES)
        ) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => "{$business->name} requires two-factor authentication. Turn it on to continue."]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
