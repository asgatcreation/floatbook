<?php

namespace App\Support\Anomalies;

use App\Enums\AnomalyKind;
use App\Models\AnomalyFlag;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Raises a flag when something looks worth checking. Never an accusation and never a
 * blocker: an agent's entry is always saved, and a person decides what the flag means.
 *
 * The checks are deliberately cheap (a count or two) because they run while someone is
 * standing at the counter. If one fails, the failure is reported and the entry stands.
 */
class AnomalyChecks
{
    /** Anything at or above this is "unusually large", unless the business sets its own. */
    public const DEFAULT_LARGE_AMOUNT = 50_000_00;

    /** Entries this long after closing time are flagged. */
    private const AFTER_HOURS_GRACE_MINUTES = 90;

    private const VOIDS_WINDOW_DAYS = 7;

    private const VOIDS_BEFORE_FLAG = 3;

    private const SHORTAGE_WINDOW_DAYS = 30;

    private const SHORTAGES_BEFORE_FLAG = 2;

    public function forTransaction(Transaction $transaction): void
    {
        $this->quietly(function () use ($transaction): void {
            $this->duplicateReference($transaction);
            $this->largeAmount($transaction);
            $this->afterHours($transaction);
        });
    }

    public function forVoid(Transaction $transaction): void
    {
        $this->quietly(function () use ($transaction): void {
            $membership = $transaction->membership;
            $since = CarbonImmutable::now()->subDays(self::VOIDS_WINDOW_DAYS);

            $voids = Transaction::query()
                ->where('membership_id', $membership->id)
                ->whereNotNull('voided_at')
                ->where('voided_at', '>=', $since)
                ->count();

            if ($voids < self::VOIDS_BEFORE_FLAG) {
                return;
            }

            $this->raise(
                AnomalyKind::FrequentVoids,
                "{$membership->user->name} has voided {$voids} entries in the last ".self::VOIDS_WINDOW_DAYS.' days',
                subject: $membership,
                outletId: $transaction->outlet_id,
                membershipId: $membership->id,
                details: ['voids' => $voids, 'days' => self::VOIDS_WINDOW_DAYS],
                severity: $voids >= self::VOIDS_BEFORE_FLAG * 2 ? 'serious' : 'watch',
                // One flag per person per week, not one per void.
                scope: $membership->id.'|'.CarbonImmutable::now()->startOfWeek()->toDateString(),
            );
        });
    }

    public function forReconciliation(Reconciliation $reconciliation): void
    {
        $this->quietly(function () use ($reconciliation): void {
            if ($reconciliation->cash_variance >= 0) {
                return;
            }

            $since = $reconciliation->business_date->subDays(self::SHORTAGE_WINDOW_DAYS)->toDateString();

            $shortages = Reconciliation::query()
                ->where('outlet_id', $reconciliation->outlet_id)
                ->where('business_date', '>=', $since)
                ->where('business_date', '<=', $reconciliation->business_date->toDateString())
                ->where('cash_variance', '<', 0)
                ->count();

            if ($shortages < self::SHORTAGES_BEFORE_FLAG) {
                return;
            }

            $total = (int) Reconciliation::query()
                ->where('outlet_id', $reconciliation->outlet_id)
                ->where('business_date', '>=', $since)
                ->where('cash_variance', '<', 0)
                ->sum('cash_variance');

            $outlet = $reconciliation->outlet->name;

            $this->raise(
                AnomalyKind::RepeatedShortage,
                "{$outlet} has come up short {$shortages} times in ".self::SHORTAGE_WINDOW_DAYS.' days, '.Money::kobo(abs($total))->format(true).' in total',
                subject: $reconciliation,
                outletId: $reconciliation->outlet_id,
                details: ['times' => $shortages, 'total' => abs($total), 'days' => self::SHORTAGE_WINDOW_DAYS],
                severity: $shortages >= self::SHORTAGES_BEFORE_FLAG + 2 ? 'serious' : 'watch',
            );
        });
    }

    private function duplicateReference(Transaction $transaction): void
    {
        if (blank($transaction->reference)) {
            return;
        }

        $twin = Transaction::query()
            ->where('reference', $transaction->reference)
            ->whereKeyNot($transaction->id)
            ->whereNull('voided_at')
            ->where('business_date', '>=', $transaction->business_date->subDays(7)->toDateString())
            ->first();

        if ($twin === null) {
            return;
        }

        $this->raise(
            AnomalyKind::DuplicateReference,
            "Reference {$transaction->reference} was used twice",
            subject: $transaction,
            outletId: $transaction->outlet_id,
            membershipId: $transaction->membership_id,
            details: ['reference' => $transaction->reference, 'other_transaction_id' => $twin->id, 'amount' => $transaction->amount],
            severity: $twin->amount === $transaction->amount ? 'serious' : 'watch',
        );
    }

    private function largeAmount(Transaction $transaction): void
    {
        $limit = (int) ($transaction->outlet->business->settings['large_amount'] ?? self::DEFAULT_LARGE_AMOUNT);

        if ($limit <= 0 || $transaction->amount < $limit) {
            return;
        }

        $this->raise(
            AnomalyKind::LargeAmount,
            Money::kobo($transaction->amount)->format(true).' '.mb_strtolower($transaction->type->label()).' at '.$transaction->outlet->name,
            subject: $transaction,
            outletId: $transaction->outlet_id,
            membershipId: $transaction->membership_id,
            details: ['amount' => $transaction->amount, 'limit' => $limit],
            severity: $transaction->amount >= $limit * 2 ? 'serious' : 'watch',
        );
    }

    private function afterHours(Transaction $transaction): void
    {
        $business = $transaction->outlet->business;
        $at = $transaction->occurred_at->setTimezone($business->timezone);
        $closing = $at->startOfDay()->setTimeFromTimeString($business->closing_time)->addMinutes(self::AFTER_HOURS_GRACE_MINUTES);
        $opening = $at->startOfDay()->addHours(5); // before 5am is the small hours

        if ($at->lessThan($closing) && $at->greaterThan($opening)) {
            return;
        }

        $this->raise(
            AnomalyKind::AfterHours,
            Money::kobo($transaction->amount)->format(true).' recorded at '.$at->format('g:i a').', outside working hours',
            subject: $transaction,
            outletId: $transaction->outlet_id,
            membershipId: $transaction->membership_id,
            details: ['at' => $at->toIso8601String(), 'closing_time' => substr($business->closing_time, 0, 5)],
        );
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  string|null  $scope  overrides the subject id when one flag should cover a period
     */
    private function raise(
        AnomalyKind $kind,
        string $summary,
        ?Model $subject = null,
        ?string $outletId = null,
        ?string $membershipId = null,
        array $details = [],
        string $severity = 'watch',
        ?string $scope = null,
    ): void {
        $identity = [
            'kind' => $kind,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $scope ?? ($subject !== null ? (string) $subject->getKey() : null),
        ];

        $flag = AnomalyFlag::query()->where($identity)->first();

        // Already judged by a person: leave their decision alone.
        if ($flag !== null && $flag->status !== 'open') {
            return;
        }

        ($flag ?? new AnomalyFlag($identity))->fill([
            ...$identity,
            'outlet_id' => $outletId,
            'membership_id' => $membershipId,
            'summary' => $summary,
            'details' => $details,
            'severity' => $severity,
        ])->save();
    }

    /**
     * A check must never stop someone from working: if it breaks, report it and move on.
     */
    private function quietly(callable $check): void
    {
        try {
            $check();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
