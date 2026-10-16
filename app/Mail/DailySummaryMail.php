<?php

namespace App\Mail;

use App\Support\Money\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The owner's end-of-day email: what the business took today, outlet by outlet, and
 * anything waiting on them tomorrow.
 */
class DailySummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary  from DailySummary::for()
     */
    public function __construct(public readonly array $summary) {}

    public function envelope(): Envelope
    {
        $profit = Money::kobo((int) $this->summary['total']['profit'])->format(true);

        return new Envelope(subject: "{$this->summary['business']}: {$profit} profit on {$this->summary['dateLabel']}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.daily-summary',
            text: 'mail.text.daily-summary',
            with: $this->summary,
        );
    }
}
