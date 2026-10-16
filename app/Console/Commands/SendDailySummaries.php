<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Mail\DailySummaryMail;
use App\Models\Business;
use App\Models\Membership;
use App\Support\Reports\DailySummary;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails each owner their day once the outlet has closed.
 *
 * Runs every 15 minutes. A business is sent its summary only after its own closing time
 * has passed in its own time zone, and the last date sent is remembered, so a business
 * gets exactly one email a day. If the server was asleep (the free hosting tier sleeps),
 * the next run catches up instead of skipping the day.
 */
class SendDailySummaries extends Command
{
    protected $signature = 'floatbook:daily-summary
                            {--business= : Only this business (its slug or id)}
                            {--date= : The business date to send, YYYY-MM-DD}
                            {--force : Send again even if it has already gone out}';

    protected $description = "Email each owner their business's end-of-day summary";

    public function handle(CurrentBusiness $tenancy, DailySummary $summaries): int
    {
        $only = $this->option('business');
        $sent = 0;

        $businesses = Business::query()
            ->when(is_string($only) && $only !== '', fn ($q) => $q->where('slug', $only)->orWhere('id', $only))
            ->get();

        foreach ($businesses as $business) {
            [$date, $catchingUp] = $this->dateFor($business);

            if ($date === null) {
                continue; // not closed yet today, and nothing older is owed
            }

            if (! $this->option('force') && ($business->settings['daily_summary_sent_for'] ?? null) === $date->toDateString()) {
                continue;
            }

            try {
                $sent += $tenancy->runAs($business, fn () => $this->send($business, $date, $catchingUp, $summaries));
            } catch (Throwable $e) {
                // One business's problem must not stop the others.
                report($e);
                $this->error("{$business->name}: {$e->getMessage()}");
            }
        }

        $this->info("Sent {$sent} ".($sent === 1 ? 'summary' : 'summaries').'.');

        return self::SUCCESS;
    }

    private function send(Business $business, CarbonImmutable $date, bool $catchingUp, DailySummary $summaries): int
    {
        if (! ($business->settings['daily_summary'] ?? true)) {
            $this->remember($business, $date);

            return 0;
        }

        $owners = Membership::query()->active()->where('role', Role::Owner)->with('user')->get()
            ->filter(fn (Membership $m) => $m->user->hasVerifiedEmail());

        if ($owners->isEmpty()) {
            $this->remember($business, $date);

            return 0;
        }

        $summary = $summaries->for($business, $date);

        // Catching up a day nobody worked: no email, and stop looking at it.
        if ($catchingUp && ! $summary['hasActivity']) {
            $this->remember($business, $date);

            return 0;
        }

        foreach ($owners as $owner) {
            Mail::to($owner->user->email)->send(new DailySummaryMail($summary));
        }

        $this->remember($business, $date);
        $this->line("{$business->name}: sent for {$date->toDateString()}.");

        return $owners->count();
    }

    /**
     * The business date that is due, and whether this is a catch-up: today once closing
     * time has passed, otherwise yesterday if that one never went out (the server may
     * have been asleep). Null when nothing is owed yet.
     *
     * @return array{0: ?CarbonImmutable, 1: bool}
     */
    private function dateFor(Business $business): array
    {
        if ($this->option('date') !== null) {
            return [CarbonImmutable::parse((string) $this->option('date'), $business->timezone)->startOfDay(), false];
        }

        $now = CarbonImmutable::now($business->timezone);
        $closing = $now->startOfDay()->setTimeFromTimeString($business->closing_time);
        $today = $now->startOfDay();
        $lastSent = $business->settings['daily_summary_sent_for'] ?? null;

        if ($now->greaterThanOrEqualTo($closing)) {
            return [$today, false];
        }

        $yesterday = $today->subDay();

        return $lastSent === $yesterday->toDateString() ? [null, false] : [$yesterday, true];
    }

    private function remember(Business $business, CarbonImmutable $date): void
    {
        $business->forceFill([
            'settings' => [...$business->settings, 'daily_summary_sent_for' => $date->toDateString()],
        ])->save();
    }
}
