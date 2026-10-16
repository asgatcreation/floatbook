<?php

namespace App\Support\Reports;

use App\Enums\LedgerAccountType;
use App\Enums\ReconciliationStatus;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\Outlet;
use App\Models\Reconciliation;
use App\Models\ReversalCase;
use App\Models\StaffShortage;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Everything the owner's end-of-day email says: the day's money per outlet, which
 * outlets were counted, and anything that needs attention tomorrow morning.
 *
 * @phpstan-import-type Totals from DailyFigures
 */
class DailySummary
{
    public function __construct(private DailyFigures $figures) {}

    /**
     * @return array{
     *     date: string, dateLabel: string, business: string,
     *     total: Totals, yesterday: Totals,
     *     outlets: list<array{name: string, count: int, volume: int, profit: int, cash: int, wallets: int, counted: ?string, recount: bool, variance: ?int}>,
     *     attention: list<string>, hasActivity: bool
     * }
     */
    public function for(Business $business, CarbonImmutable $date): array
    {
        $outlets = Outlet::query()->active()->orderBy('name')->get();
        $ids = array_values($outlets->map(fn (Outlet $o) => $o->id)->all());

        $rows = $this->figures->rows($ids, $date, $date);
        $total = $this->figures->total($rows);
        $yesterday = $this->figures->total($this->figures->rows($ids, $date->subDay(), $date->subDay()));

        $accounts = LedgerAccount::query()->whereIn('outlet_id', $ids)
            ->whereIn('type', [LedgerAccountType::CashDrawer, LedgerAccountType::ProviderWallet])
            ->get()->groupBy('outlet_id');

        $counts = Reconciliation::query()->whereIn('outlet_id', $ids)
            ->where('business_date', $date->toDateString())->get()->keyBy('outlet_id');

        $cards = array_values($outlets->map(function (Outlet $o) use ($rows, $accounts, $counts) {
            $day = $rows->first(fn (array $r) => $r['outlet_id'] === $o->id);
            $mine = $accounts[$o->id] ?? collect();
            $count = $counts[$o->id] ?? null;

            return [
                'name' => $o->name,
                'count' => $day['count'] ?? 0,
                'volume' => $day['volume'] ?? 0,
                'profit' => $day['profit'] ?? 0,
                'cash' => (int) $mine->where('type', LedgerAccountType::CashDrawer)->sum('balance'),
                'wallets' => (int) $mine->where('type', LedgerAccountType::ProviderWallet)->sum('balance'),
                // Only a submitted or approved count closes the day; a sent-back or
                // reopened one means it still has to be counted.
                'counted' => $count?->status->closesDay() === true ? $count->status->label() : null,
                'recount' => $count !== null && ! $count->status->closesDay(),
                'variance' => $count?->status->closesDay() === true ? $count->cash_variance : null,
            ];
        })->all());

        return [
            'date' => $date->toDateString(),
            'dateLabel' => $date->format('l j F Y'),
            'business' => $business->name,
            'total' => $total,
            'yesterday' => $yesterday,
            'outlets' => $cards,
            'attention' => $this->attention($ids, $date, $cards),
            'hasActivity' => $total['count'] > 0 || $total['expenses'] > 0,
        ];
    }

    /**
     * @param  list<string>  $outletIds
     * @param  list<array{name: string, counted: ?string, recount: bool, count: int, variance: ?int}>  $cards
     * @return list<string>
     */
    private function attention(array $outletIds, CarbonImmutable $date, array $cards): array
    {
        $lines = [];

        foreach ($cards as $card) {
            if ($card['recount']) {
                $lines[] = "{$card['name']}'s count was sent back; it needs counting again.";
            } elseif ($card['counted'] === null && $card['count'] > 0) {
                $lines[] = "{$card['name']} wasn't counted today.";
            }
        }

        $waiting = Reconciliation::query()->whereIn('outlet_id', $outletIds)
            ->where('status', ReconciliationStatus::Submitted)->count();

        if ($waiting > 0) {
            $lines[] = $waiting === 1 ? '1 end-of-day count is waiting for approval.' : "{$waiting} end-of-day counts are waiting for approval.";
        }

        $overdue = ReversalCase::query()->open()->whereIn('outlet_id', $outletIds)->where('due_at', '<', $date->endOfDay())->count();

        if ($overdue > 0) {
            $lines[] = $overdue === 1 ? '1 reversal is past its target.' : "{$overdue} reversals are past their target.";
        }

        $low = LedgerAccount::query()->whereIn('outlet_id', $outletIds)
            ->whereNotNull('low_balance_threshold')->whereColumn('balance', '<', 'low_balance_threshold')
            ->pluck('name');

        foreach ($low as $name) {
            $lines[] = "{$name} is below its alert level.";
        }

        $owed = (int) StaffShortage::query()->where('status', 'outstanding')
            ->whereHas('reconciliation', fn ($q) => $q->whereIn('outlet_id', $outletIds))->sum('amount');

        if ($owed > 0) {
            $lines[] = 'Staff still owe '.Money::kobo($owed)->format(true).' from cash shortages.';
        }

        return $lines;
    }
}
