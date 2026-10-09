<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages inside the app need an active membership. People whose access was removed
 * (or who have none yet) are sent to a page that explains this.
 */
class EnsureBusinessMember
{
    public function __construct(private CurrentBusiness $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenancy->membership() === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'You are not an active member of any business.'], 403)
                : redirect()->route('no-business');
        }

        return $next($request);
    }
}
