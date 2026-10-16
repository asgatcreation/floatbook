<?php

namespace App\Support\Reports;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Terminal;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Totals over a date range, grouped the way the reader asked for: by day, outlet,
 * terminal, agent or transaction type.
 *
 * Only successful, non-voided transactions count, the same rule the dashboard uses.
 * Expenses belong to an outlet and a day, not to a terminal or a type, so they are
 * added to the groupings where they make sense and left out (shown as zero) elsewhere.
 *
 * @phpstan-type Filters array{from: CarbonImmutable, to: CarbonImmutable, outlet_ids: list<string>, outlet: ?string, terminal: ?string, agent: ?string, type: ?string, group: string}
 * @phpstan-type ReportRow array{key: string, label: string, sub: ?string, count: int, volume: int, charges: int, commissions: int, fees: int, expenses: int, profit: int}
 */
class TransactionReport
{
    public const GROUPS = ['day', 'outlet', 'terminal', 'agent', 'type'];

    /** Groupings where an expense can be attributed to the row. */
    private const EXPENSE_GROUPS = ['day', 'outlet'];

    /**
     * @param  Filters  $filters
     * @return Collection<int, ReportRow>
     */
    public function rows(array $filters): Collection
    {
        $group = in_array($filters['group'], self::GROUPS, true) ? $filters['group'] : 'day';
        $column = match ($group) {
            'day' => 'business_date',
            'outlet' => 'outlet_id',
            'terminal' => 'terminal_id',
            'agent' => 'membership_id',
            default => 'type',
        };

        $totals = $this->transactions($filters)
            ->groupBy($column)
            ->selectRaw("{$column} as group_key, count(*) as count, sum(amount) as volume, sum(charge) as charges, sum(commission) as commissions, sum(provider_fee) as fees")
            ->toBase()
            ->get()
            ->keyBy(fn ($r) => (string) $r->group_key);

        $expenses = in_array($group, self::EXPENSE_GROUPS, true)
            ? $this->expenses($filters)
                ->groupBy($column === 'business_date' ? 'business_date' : 'outlet_id')
                ->selectRaw(($column === 'business_date' ? 'business_date' : 'outlet_id').' as group_key, sum(amount) as total')
                ->toBase()->get()
                ->mapWithKeys(fn ($r) => [$this->keyOf((string) $r->group_key, $group) => (int) $r->total])
            : collect();

        $keys = $totals->keys()->map(fn ($k) => $this->keyOf((string) $k, $group))
            ->merge($expenses->keys())->map(fn ($k) => (string) $k)->unique()->values();
        $names = $this->labels($group, array_values($keys->all()));

        return $keys->map(function (string $key) use ($totals, $expenses, $names, $group) {
            $t = $totals->first(fn ($r) => $this->keyOf((string) $r->group_key, $group) === $key);
            $charges = (int) ($t->charges ?? 0);
            $commissions = (int) ($t->commissions ?? 0);
            $fees = (int) ($t->fees ?? 0);
            $spent = (int) ($expenses[$key] ?? 0);

            return [
                'key' => $key,
                'label' => $names[$key]['label'] ?? $key,
                'sub' => $names[$key]['sub'] ?? null,
                'count' => (int) ($t->count ?? 0),
                'volume' => (int) ($t->volume ?? 0),
                'charges' => $charges,
                'commissions' => $commissions,
                'fees' => $fees,
                'expenses' => $spent,
                'profit' => $charges + $commissions - $fees - $spent,
            ];
        })
            ->sortBy(fn (array $r) => $group === 'day' ? $r['key'] : -$r['volume'])
            ->values();
    }

    /**
     * @param  Filters  $filters
     * @return array{count: int, volume: int, charges: int, commissions: int, fees: int, expenses: int, profit: int}
     */
    public function total(array $filters): array
    {
        /** @var object{count: int, volume: ?int, charges: ?int, commissions: ?int, fees: ?int} $t */
        $t = $this->transactions($filters)
            ->selectRaw('count(*) as count, sum(amount) as volume, sum(charge) as charges, sum(commission) as commissions, sum(provider_fee) as fees')
            ->toBase()->first();

        // Expenses only count when the report isn't narrowed to a terminal, agent or type.
        $narrowed = $filters['terminal'] !== null || $filters['agent'] !== null || $filters['type'] !== null;
        $spent = $narrowed ? 0 : (int) $this->expenses($filters)->sum('amount');
        $charges = (int) ($t->charges ?? 0);
        $commissions = (int) ($t->commissions ?? 0);
        $fees = (int) ($t->fees ?? 0);

        return [
            'count' => (int) $t->count,
            'volume' => (int) ($t->volume ?? 0),
            'charges' => $charges,
            'commissions' => $commissions,
            'fees' => $fees,
            'expenses' => $spent,
            'profit' => $charges + $commissions - $fees - $spent,
        ];
    }

    /**
     * Every transaction behind the report, for the detailed export.
     *
     * @param  Filters  $filters
     * @return Builder<Transaction>
     */
    public function detail(array $filters): Builder
    {
        return $this->transactions($filters)
            ->with(['outlet:id,name', 'terminal.provider:id,name', 'membership.user:id,name'])
            ->orderBy('occurred_at');
    }

    /**
     * @param  Filters  $filters
     * @return Builder<Transaction>
     */
    private function transactions(array $filters): Builder
    {
        return Transaction::query()
            ->whereIn('outlet_id', $filters['outlet_ids'])
            ->whereBetween('business_date', [$filters['from']->toDateString(), $filters['to']->toDateString()])
            ->whereNull('voided_at')
            ->where('status', TransactionStatus::Successful)
            ->when($filters['outlet'], fn (Builder $q, string $id) => $q->where('outlet_id', $id))
            ->when($filters['terminal'], fn (Builder $q, string $id) => $q->where('terminal_id', $id))
            ->when($filters['agent'], fn (Builder $q, string $id) => $q->where('membership_id', $id))
            ->when($filters['type'], fn (Builder $q, string $type) => $q->where('type', $type));
    }

    /**
     * @param  Filters  $filters
     * @return Builder<Expense>
     */
    private function expenses(array $filters): Builder
    {
        return Expense::query()
            ->whereIn('outlet_id', $filters['outlet_ids'])
            ->whereBetween('business_date', [$filters['from']->toDateString(), $filters['to']->toDateString()])
            ->whereNull('voided_at')
            ->when($filters['outlet'], fn (Builder $q, string $id) => $q->where('outlet_id', $id));
    }

    /** Dates come back from the database with a time; keep just the date. */
    private function keyOf(string $raw, string $group): string
    {
        return $group === 'day' ? CarbonImmutable::parse($raw)->toDateString() : $raw;
    }

    /**
     * Readable names for the grouped ids.
     *
     * @param  list<string>  $keys
     * @return array<string, array{label: string, sub: ?string}>
     */
    private function labels(string $group, array $keys): array
    {
        $rows = match ($group) {
            'day' => collect($keys)->mapWithKeys(fn (string $d) => [$d => [
                'label' => CarbonImmutable::parse($d)->format('D j M Y'), 'sub' => null,
            ]]),
            'outlet' => Outlet::query()->whereIn('id', $keys)->get()
                ->mapWithKeys(fn (Outlet $o) => [$o->id => ['label' => $o->name, 'sub' => $o->code]]),
            'terminal' => Terminal::query()->whereIn('id', $keys)->with(['provider:id,name', 'outlet:id,name'])->get()
                ->mapWithKeys(fn (Terminal $t) => [$t->id => ['label' => $t->label(), 'sub' => $t->outlet->name]]),
            'agent' => Membership::query()->whereIn('id', $keys)->with('user:id,name')->get()
                ->mapWithKeys(fn (Membership $m) => [$m->id => ['label' => $m->user->name, 'sub' => $m->role->label()]]),
            default => collect($keys)->mapWithKeys(fn (string $t) => [$t => [
                'label' => TransactionType::from($t)->label(), 'sub' => null,
            ]]),
        };

        return $rows->all();
    }
}
