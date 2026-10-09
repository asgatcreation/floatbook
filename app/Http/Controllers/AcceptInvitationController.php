<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\Business;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private CurrentBusiness $tenancy, private Audit $audit) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return Inertia::render('invitations/Invalid', [
                'reason' => $invitation?->status() ?? 'missing',
            ]);
        }

        /** @var Business $business */
        $business = Business::query()->findOrFail($invitation->business_id);

        return Inertia::render('invitations/Accept', [
            'token' => $token,
            'businessName' => $business->name,
            'email' => $invitation->email,
            'roleLabel' => $invitation->role->label(),
            'roleDescription' => $invitation->role->description(),
            'outlets' => Outlet::query()->withoutGlobalScopes()->where('business_id', $business->id)
                ->whereIn('id', $invitation->outlet_ids)->orderBy('name')->pluck('name'),
            'inviterName' => $invitation->inviter()->value('name'),
            'mode' => $this->mode($request, $invitation),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->find($token);

        abort_if($invitation === null || ! $invitation->isPending(), 410, 'This invitation is no longer valid.');

        return match ($this->mode($request, $invitation)) {
            'accept' => $this->acceptAsCurrentUser($request, $invitation),
            'new' => $this->acceptAsNewUser($request, $invitation),
            'login' => $this->sendToLogin($request, $invitation, $token),
            default => $this->wrongAccount(),
        };
    }

    private function acceptAsCurrentUser(Request $request, Invitation $invitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->createMembership($invitation, $user);

        return redirect()->route('dashboard');
    }

    private function acceptAsNewUser(Request $request, Invitation $invitation): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($invitation, $validated): User {
            $user = new User(['name' => $validated['name'], 'email' => $invitation->email, 'password' => $validated['password']]);
            // Opening the emailed link proves they own the address.
            $user->email_verified_at = now();
            $user->save();

            $this->createMembership($invitation, $user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        $this->toast('Welcome to FloatBook!');

        return redirect()->route('dashboard');
    }

    private function createMembership(Invitation $invitation, User $user): void
    {
        /** @var Business $business */
        $business = Business::query()->findOrFail($invitation->business_id);

        DB::transaction(fn () => $this->tenancy->runAs($business, function () use ($invitation, $user, $business): void {
            // Re-read with a lock so a double-click can't create two memberships.
            $fresh = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->isPending(), 410, 'This invitation is no longer valid.');

            $membership = Membership::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $fresh->role],
            );

            if ($membership->wasRecentlyCreated && $fresh->role->isOutletScoped()) {
                $validOutlets = Outlet::query()->whereIn('id', $fresh->outlet_ids)->pluck('id');
                $membership->syncOutlets($validOutlets);
            }

            $fresh->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['current_business_id' => $business->id])->save();

            $this->audit->record('invitation.accepted', $fresh, after: ['email' => $user->email, 'role' => $fresh->role->value], actor: $user);
            $this->audit->record('membership.created', $membership, after: ['role' => $membership->role->value, 'user' => $user->email], actor: $user);
        }));
    }

    /**
     * 'new' = no account yet, 'login' = account exists but signed out,
     * 'accept' = signed in as the invited email, 'wrong_account' = signed in as someone else.
     */
    private function mode(Request $request, Invitation $invitation): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            return strcasecmp($user->email, $invitation->email) === 0 ? 'accept' : 'wrong_account';
        }

        return User::query()->where('email', $invitation->email)->exists() ? 'login' : 'new';
    }

    private function find(string $token): ?Invitation
    {
        if (strlen($token) > 100) {
            return null;
        }

        return Invitation::query()->withoutGlobalScopes()
            ->where('token_hash', Invitation::hashToken($token))
            ->first();
    }

    private function wrongAccount(): RedirectResponse
    {
        $this->toast('You are signed in with a different email. Sign out, then open the invitation link again.', 'error');

        return back();
    }

    private function sendToLogin(Request $request, Invitation $invitation, string $token): RedirectResponse
    {
        $request->session()->put('url.intended', route('invitations.show', $token));

        return redirect()->route('login')->with('status', 'Sign in as '.$invitation->email.' to accept the invitation.');
    }
}
