<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Outlet;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Tenancy\StaffAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy, private StaffAccess $access, private Audit $audit) {}

    public function index(Request $request): Response
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($this->access->canViewStaff($me), 403);

        $members = Membership::query()
            ->with(['user:id,name,email,two_factor_confirmed_at', 'outlets:id,name'])
            ->get()
            ->filter(fn (Membership $m) => $this->access->canView($me, $m))
            ->sortBy(fn (Membership $m) => array_search($m->role, Role::cases(), true).'-'.mb_strtolower($m->user->name))
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->role->value,
                'role_label' => $m->role->label(),
                'status' => $m->status->value,
                'two_factor' => $m->user->two_factor_confirmed_at !== null,
                'has_pin' => $m->hasPin(),
                'last_active_at' => $m->last_active_at?->toIso8601String(),
                'outlets' => $m->outlets->map(fn (Outlet $o) => ['id' => $o->id, 'name' => $o->name])->values(),
                'is_me' => $m->id === $me->id,
                'can_manage' => $this->access->canManage($me, $m),
            ])
            ->values();

        $invitations = Invitation::query()
            ->pending()
            ->latest()
            ->get()
            ->filter(fn (Invitation $i) => $me->role === Role::Owner || $i->invited_by === $me->user_id)
            ->map(fn (Invitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'role_label' => $i->role->label(),
                'expires_at' => $i->expires_at->toIso8601String(),
            ])
            ->values();

        $outlets = Outlet::query()->active()->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Outlet $o) => $me->coversOutlet($o))
            ->values();

        return Inertia::render('staff/Index', [
            'members' => $members,
            'invitations' => $invitations,
            'outlets' => $outlets,
            'roles' => collect($me->role->assignableRoles())->map(fn (Role $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'description' => $r->description(),
                'outlet_scoped' => $r->isOutletScoped(),
            ])->values(),
        ]);
    }

    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($this->access->canManage($me, $membership), 403);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(Role::class)->only($me->role->assignableRoles())],
            'outlet_ids' => ['array'],
            'outlet_ids.*' => ['string', Rule::exists('outlets', 'id')->where('business_id', $this->tenancy->id())],
        ]);

        $role = Role::from($validated['role']);
        $outletIds = $role->isOutletScoped() ? array_values(array_unique($validated['outlet_ids'] ?? [])) : [];

        if ($role->isOutletScoped() && $outletIds === []) {
            throw ValidationException::withMessages(['outlet_ids' => 'Choose at least one outlet for this role.']);
        }

        foreach ($outletIds as $outletId) {
            abort_unless($me->coversOutlet($outletId), 403);
        }

        $this->guardLastOwner($membership, $role);

        DB::transaction(function () use ($membership, $role, $outletIds): void {
            $before = ['role' => $membership->role->value, 'outlet_ids' => $membership->outlets->pluck('id')->sort()->values()->all()];

            $membership->role = $role;
            $membership->save();
            $membership->syncOutlets($outletIds);

            $this->audit->record('membership.updated', $membership, $before, ['role' => $role->value, 'outlet_ids' => collect($outletIds)->sort()->values()->all()]);
        });

        $this->toast("{$membership->user->name}'s access updated.");

        return back();
    }

    public function suspend(Membership $membership): RedirectResponse
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($this->access->canManage($me, $membership), 403);
        $this->guardLastOwner($membership, null);

        $membership->status = MembershipStatus::Suspended;
        $this->audit->recordChanges('membership.suspended', $membership);
        $membership->save();

        // Sign the person out everywhere so the suspension takes effect immediately.
        DB::table('sessions')->where('user_id', $membership->user_id)->delete();

        $this->toast("{$membership->user->name} can no longer access this business.");

        return back();
    }

    public function reactivate(Membership $membership): RedirectResponse
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($this->access->canManage($me, $membership), 403);

        $membership->status = MembershipStatus::Active;
        $this->audit->recordChanges('membership.reactivated', $membership);
        $membership->save();

        $this->toast("{$membership->user->name} has access again.");

        return back();
    }

    /**
     * A business must always keep at least one active owner.
     */
    private function guardLastOwner(Membership $membership, ?Role $newRole): void
    {
        if ($membership->role !== Role::Owner || $newRole === Role::Owner) {
            return;
        }

        $otherOwners = Membership::query()->active()
            ->where('role', Role::Owner)
            ->whereKeyNot($membership->id)
            ->count();

        if ($otherOwners === 0) {
            throw ValidationException::withMessages(['role' => 'A business needs at least one owner. Make someone else an owner first.']);
        }
    }
}
