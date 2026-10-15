<?php

namespace App\Support\Ledger;

use App\Enums\ReconciliationStatus;
use App\Models\Outlet;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Once an outlet's day has been counted and submitted, its books for that day (and every
 * day before it) are closed: nothing can be recorded, voided or moved there. A supervisor
 * sending the count back, or the owner reopening it, opens it again.
 */
class DayLocks
{
    public function isClosed(string $outletId, DateTimeInterface|string $date): bool
    {
        $day = $date instanceof DateTimeInterface ? CarbonImmutable::instance($date)->toDateString() : $date;

        return Reconciliation::query()
            ->where('outlet_id', $outletId)
            ->where('business_date', '>=', $day)
            ->whereIn('status', ReconciliationStatus::closing())
            ->exists();
    }

    public function assertOpen(string $outletId, DateTimeInterface|string $date, string $field = 'date'): void
    {
        if (! $this->isClosed($outletId, $date)) {
            return;
        }

        $day = $date instanceof DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);
        $outlet = Outlet::query()->whereKey($outletId)->value('name') ?? 'This outlet';

        throw ValidationException::withMessages([
            $field => "{$outlet}'s books for {$day->format('j M')} are closed (the day has been counted). Ask a supervisor to send the count back, or the owner to reopen it.",
        ]);
    }
}
