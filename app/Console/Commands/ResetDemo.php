<?php

namespace App\Console\Commands;

use App\Models\Business;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuilds the demo business overnight, so whatever visitors did during the day is
 * wiped and tomorrow's visitors see the same tidy fortnight of trading.
 *
 * Runs every 15 minutes and does nothing until the reset time has passed. If the server
 * was asleep at that moment (the free tier sleeps), the next run catches up.
 */
class ResetDemo extends Command
{
    protected $signature = 'floatbook:reset-demo {--force : Rebuild now, whatever the time}';

    protected $description = 'Rebuild the demo business from scratch';

    private const LAST_RESET = 'floatbook.demo.last_reset';

    public function handle(): int
    {
        if (! config('floatbook.demo.enabled')) {
            $this->info('Demo mode is off; nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->isDue()) {
            return self::SUCCESS;
        }

        $this->info('Rebuilding the demo business…');
        $this->callSilently('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        Cache::forever(self::LAST_RESET, $this->today()->toDateString());
        $this->info('Demo rebuilt.');

        return self::SUCCESS;
    }

    /**
     * Due once the reset time has passed today, unless today's reset already ran.
     */
    private function isDue(): bool
    {
        $now = CarbonImmutable::now($this->timezone());
        $resetAt = $now->startOfDay()->setTimeFromTimeString((string) config('floatbook.demo.reset_at'));

        if ($now->lessThan($resetAt)) {
            return false;
        }

        return Cache::get(self::LAST_RESET) !== $now->toDateString();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return Business::query()->where('slug', DemoSeeder::SLUG)->value('timezone') ?? 'Africa/Lagos';
    }
}
