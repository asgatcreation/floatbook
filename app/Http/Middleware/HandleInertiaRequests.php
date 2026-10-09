<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private CurrentBusiness $tenancy) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'tenant' => $user instanceof User ? $this->tenantProps($user) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tenantProps(User $user): ?array
    {
        $business = $this->tenancy->business();
        $membership = $this->tenancy->membership();

        if ($business === null || $membership === null) {
            return null;
        }

        return [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'is_demo' => $business->is_demo,
                'require_two_factor' => $business->require_two_factor,
            ],
            'membership' => [
                'id' => $membership->id,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'has_pin' => $membership->hasPin(),
                'outlets' => $membership->outlets->map(fn ($outlet) => ['id' => $outlet->id, 'name' => $outlet->name])->values(),
                'permissions' => array_map(
                    fn (Permission $permission) => $permission->value,
                    $membership->isActive() ? $membership->role->permissions() : [],
                ),
            ],
            'businesses' => fn () => $user->activeMemberships()->with('business:id,name')->get()
                ->map(fn (Membership $m) => ['id' => $m->business_id, 'name' => $m->business->name, 'role' => $m->role->label()])
                ->sortBy('name')->values(),
        ];
    }
}
