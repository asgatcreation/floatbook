<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\Invitation;
use App\Models\Outlet;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitation extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $token  The plain invitation token (only its hash is stored).
     */
    public function __construct(
        public readonly Invitation $invitation,
        public readonly Business $business,
        public readonly string $inviterName,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been invited to {$this->business->name} on ".config('app.name'));
    }

    public function content(): Content
    {
        $outletNames = Outlet::query()->withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->whereIn('id', $this->invitation->outlet_ids)
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');

        return new Content(
            view: 'mail.invitation',
            text: 'mail.text.invitation',
            with: [
                'businessName' => $this->business->name,
                'inviterName' => $this->inviterName,
                'roleLabel' => $this->invitation->role->label(),
                'roleDescription' => $this->invitation->role->description(),
                'outletNames' => $outletNames,
                'url' => route('invitations.show', $this->token),
                'expiresOn' => $this->invitation->expires_at->timezone($this->business->timezone)->format('j F Y'),
            ],
        );
    }
}
