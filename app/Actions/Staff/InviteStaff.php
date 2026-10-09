<?php

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Mail\StaffInvitation;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteStaff
{
    public function __construct(private CurrentBusiness $tenancy, private Audit $audit) {}

    /**
     * @param  list<string>  $outletIds
     */
    public function invite(Membership $inviter, string $email, Role $role, array $outletIds): Invitation
    {
        $email = Str::lower(trim($email));

        $alreadyMember = Membership::query()
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'This person is already part of your business.']);
        }

        // A new invitation replaces any earlier pending one for the same email.
        Invitation::query()->pending()->where('email', $email)->get()
            ->each(fn (Invitation $old) => $old->forceFill(['revoked_at' => now()])->save());

        $token = Str::random(48);

        $invitation = Invitation::create([
            'email' => $email,
            'role' => $role,
            'outlet_ids' => $role->isOutletScoped() ? array_values(array_unique($outletIds)) : [],
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $inviter->user_id,
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ]);

        $this->send($invitation, $inviter->user, $token);
        $this->audit->record('invitation.sent', $invitation, after: ['email' => $email, 'role' => $role->value, 'outlet_ids' => $invitation->outlet_ids]);

        return $invitation;
    }

    /**
     * Sends the invitation again with a fresh token and expiry (the old link stops working).
     */
    public function resend(Invitation $invitation, User $sender): void
    {
        $token = Str::random(48);

        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ])->save();

        $this->send($invitation, $sender, $token);
        $this->audit->record('invitation.resent', $invitation, after: ['email' => $invitation->email]);
    }

    private function send(Invitation $invitation, User $inviter, string $token): void
    {
        Mail::to($invitation->email)->send(
            new StaffInvitation($invitation, $this->tenancy->businessOrFail(), $inviter->name, $token),
        );
    }
}
