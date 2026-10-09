<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\OneTimeCodes;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationCode extends Notification
{
    public function __construct(public readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        // The code is only in the body, never in the subject or preview text.
        return (new MailMessage)
            ->subject('Confirm your email for '.config('app.name'))
            ->view(['mail.verification-code', 'mail.text.verification-code'], [
                'name' => $notifiable->name,
                'code' => $this->code,
                'minutes' => OneTimeCodes::VALID_MINUTES,
            ]);
    }
}
