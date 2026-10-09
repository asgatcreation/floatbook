<?php

namespace App\Http\Controllers;

use App\Actions\Staff\InviteStaff;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Tenancy\StaffAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy, private StaffAccess $access) {}

    public function store(Request $request, InviteStaff $inviteStaff): RedirectResponse
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($this->access->canViewStaff($me), 403);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->only($me->role->assignableRoles())],
            'outlet_ids' => ['array'],
            'outlet_ids.*' => ['string', Rule::exists('outlets', 'id')->where('business_id', $this->tenancy->id())->where('is_active', true)],
        ]);

        $role = Role::from($validated['role']);
        $outletIds = array_values($validated['outlet_ids'] ?? []);

        if ($role->isOutletScoped() && $outletIds === []) {
            throw ValidationException::withMessages(['outlet_ids' => 'Choose at least one outlet for this role.']);
        }

        foreach ($outletIds as $outletId) {
            abort_unless($me->coversOutlet($outletId), 403);
        }

        $invitation = $inviteStaff->invite($me, $validated['email'], $role, $outletIds);

        $this->toast("Invitation sent to {$invitation->email}.");

        return back();
    }

    public function resend(Request $request, Invitation $invitation, InviteStaff $inviteStaff): RedirectResponse
    {
        $this->authorizeInvitation($invitation);
        abort_unless($invitation->accepted_at === null && $invitation->revoked_at === null, 422);

        /** @var User $user */
        $user = $request->user();
        $inviteStaff->resend($invitation, $user);

        $this->toast("Invitation re-sent to {$invitation->email}.");

        return back();
    }

    public function destroy(Invitation $invitation, Audit $audit): RedirectResponse
    {
        $this->authorizeInvitation($invitation);

        if ($invitation->isPending()) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $audit->record('invitation.revoked', $invitation, after: ['email' => $invitation->email]);
        }

        $this->toast('Invitation cancelled.');

        return back();
    }

    private function authorizeInvitation(Invitation $invitation): void
    {
        $me = $this->tenancy->membershipOrFail();

        abort_unless(
            $me->role === Role::Owner || ($this->access->canViewStaff($me) && $invitation->invited_by === $me->user_id),
            403,
        );
    }
}
