<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quick-lock for shared counter devices. A locked session can only reach the unlock
 * screen. Agents and supervisors with a PIN are also locked automatically after a
 * few idle minutes, so a phone left on the counter can't be used by someone else.
 */
class EnsureUnlocked
{
    public const SESSION_LOCKED = 'floatbook.locked';

    public const SESSION_LAST_SEEN = 'floatbook.last_seen_at';

    public const DEFAULT_IDLE_MINUTES = 10;

    private const ALLOWED_ROUTES = ['unlock.*', 'logout'];

    public function __construct(private CurrentBusiness $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $membership = $this->tenancy->membership();

        if ($membership !== null && $membership->hasPin() && $membership->role->isOutletScoped()) {
            $lastSeen = (int) $session->get(self::SESSION_LAST_SEEN, 0);
            $idleMinutes = (int) ($this->tenancy->business()?->settings['idle_lock_minutes'] ?? self::DEFAULT_IDLE_MINUTES);

            if ($lastSeen > 0 && now()->getTimestamp() - $lastSeen > $idleMinutes * 60) {
                $session->put(self::SESSION_LOCKED, true);
            }
        }

        if ($session->get(self::SESSION_LOCKED) && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Locked.'], 423);
            }

            $session->put('url.intended', $request->fullUrl());

            return redirect()->route('unlock.show');
        }

        $session->put(self::SESSION_LAST_SEEN, now()->getTimestamp());

        return $next($request);
    }
}
