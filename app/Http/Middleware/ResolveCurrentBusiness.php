<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which business the signed-in user is acting for on this request, from their
 * active memberships, and makes it the current tenant.
 */
class ResolveCurrentBusiness
{
    public function __construct(private CurrentBusiness $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->resolve($user);
        }

        try {
            return $next($request);
        } finally {
            $this->tenancy->clear();
        }
    }

    private function resolve(User $user): void
    {
        /** @var Membership|null $membership */
        $membership = $user->activeMemberships()
            ->with(['business', 'outlets' => fn ($query) => $query->withoutGlobalScopes()->orderBy('name')])
            ->orderByRaw('business_id = ? desc', [$user->current_business_id ?? ''])
            ->orderBy('created_at')
            ->first();

        if ($membership === null) {
            return;
        }

        if ($user->current_business_id !== $membership->business_id) {
            $user->forceFill(['current_business_id' => $membership->business_id])->saveQuietly();
        }

        $this->tenancy->set($membership->business, $membership);

        if ($membership->last_active_at === null || $membership->last_active_at->lt(now()->subMinutes(5))) {
            $membership->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }
}
