<?php

namespace App\Support\Reports;

use App\Enums\TransactionStatus;
use App\Models\Expense;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The day's numbers per outlet, worked out the same way everywhere (dashboard, reports,
 * daily email): only successful, non-voided transactions count, and profit is
 * charges + commissions − provider fees − expenses.
 *
 * @phpstan-type FigureRow array{outlet_id: string, date: string, count: int, volume: int, charges: int, commissions: int, fees: int, expenses: int, profit: int}
 * @phpstan-type Totals array{count: int, volume: int, charges: int, commissions: int, fees: int, expenses: int, profit: int}
 */
class DailyFigures
{
    /**
     * @param  list<string>  $outletIds
     * @return Collection<int, FigureRow>
     */
    public function rows(array $outletIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($outletIds === []) {
            return collect();
        }

        $range = [$from->toDateString(), $to->toDateString()];

        $transactions = Transaction::query()
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', $range)
            ->whereNull('voided_at')
            ->where('status', TransactionStatus::Successful)
            ->groupBy('outlet_id', 'business_date')
            ->selectRaw('outlet_id, business_date, count(*) as count, sum(amount) as volume, sum(charge) as charges, sum(commission) as commissions, sum(provider_fee) as fees')
            ->toBase()
            ->get();

        $expenses = Expense::query()
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', $range)
            ->whereNull('voided_at')
            ->groupBy('outlet_id', 'business_date')
            ->selectRaw('outlet_id, business_date, sum(amount) as total')
            ->toBase()
            ->get()
            ->keyBy(fn ($e) => $e->outlet_id.'|'.CarbonImmutable::parse($e->business_date)->toDateString());

        $rows = [];

        foreach ($transactions as $t) {
            $date = CarbonImmutable::parse($t->business_date)->toDateString();
            $rows[$t->outlet_id.'|'.$date] = [
                'outlet_id' => (string) $t->outlet_id,
                'date' => $date,
                'count' => (int) $t->count,
                'volume' => (int) $t->volume,
                'charges' => (int) $t->charges,
                'commissions' => (int) $t->commissions,
                'fees' => (int) $t->fees,
                'expenses' => 0,
            ];
        }

        foreach ($expenses as $key => $e) {
            $rows[$key] ??= [
                'outlet_id' => (string) $e->outlet_id,
                'date' => CarbonImmutable::parse($e->business_date)->toDateString(),
                'count' => 0, 'volume' => 0, 'charges' => 0, 'commissions' => 0, 'fees' => 0, 'expenses' => 0,
            ];
            $rows[$key]['expenses'] = (int) $e->total;
        }

        return collect($rows)
            ->map(fn (array $r) => [...$r, 'profit' => $r['charges'] + $r['commissions'] - $r['fees'] - $r['expenses']])
            ->sortBy('date')
            ->values();
    }

    /**
     * Add up rows (for one day across outlets, or one outlet across days).
     *
     * @param  Collection<int, FigureRow>  $rows
     * @return Totals
     */
    public function total(Collection $rows): array
    {
        $sum = fn (string $key) => (int) $rows->sum($key);

        return [
            'count' => $sum('count'),
            'volume' => $sum('volume'),
            'charges' => $sum('charges'),
            'commissions' => $sum('commissions'),
            'fees' => $sum('fees'),
            'expenses' => $sum('expenses'),
            'profit' => $sum('profit'),
        ];
    }
}
