<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Reports\ReportExport;
use App\Support\Reports\TransactionReport;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @phpstan-import-type Filters from TransactionReport
 *
 * Reports: what was recorded over a date range, grouped by day, outlet, terminal, agent
 * or type, with a CSV or Excel download of the same figures (or every transaction).
 */
class ReportController extends Controller
{
    private const MAX_DAYS = 400;

    public function __construct(private CurrentBusiness $tenancy, private TransactionReport $report) {}

    public function index(Request $request): Response
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($me->allows(Permission::ViewReports), 403);

        $filters = $this->filters($request);
        $rows = $this->report->rows($filters);

        return Inertia::render('reports/Index', [
            'filters' => [
                'from' => $filters['from']->toDateString(),
                'to' => $filters['to']->toDateString(),
                'group' => $filters['group'],
                'outlet' => $filters['outlet'],
                'terminal' => $filters['terminal'],
                'agent' => $filters['agent'],
                'type' => $filters['type'],
            ],
            'rows' => $rows,
            'total' => $this->report->total($filters),
            'groups' => [
                ['value' => 'day', 'label' => 'Day'],
                ['value' => 'outlet', 'label' => 'Outlet'],
                ['value' => 'terminal', 'label' => 'Terminal'],
                ['value' => 'agent', 'label' => 'Staff'],
                ['value' => 'type', 'label' => 'Type'],
            ],
            'outlets' => $this->coveredOutlets()->map(fn (Outlet $o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'terminals' => Terminal::query()->with('provider:id,name')->whereIn('outlet_id', $filters['outlet_ids'])
                ->orderBy('name')->get()
                ->map(fn (Terminal $t) => ['id' => $t->id, 'outlet_id' => $t->outlet_id, 'label' => $t->label()])->values(),
            'staff' => $me->role === Role::Agent ? [] : Membership::query()->with('user:id,name')->get()
                ->filter(fn (Membership $m) => ! $m->role->isOutletScoped() || $m->outlets->pluck('id')->intersect($filters['outlet_ids'])->isNotEmpty())
                ->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user->name])
                ->sortBy('name')->values(),
            'types' => collect(TransactionType::cases())->map(fn (TransactionType $t) => ['value' => $t->value, 'label' => $t->label()]),
            'canExport' => $me->allows(Permission::ExportReports),
        ]);
    }

    public function export(Request $request, ReportExport $export): StreamedResponse
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($me->allows(Permission::ExportReports), 403);

        $validated = $request->validate([
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'detail' => ['boolean'],
        ]);

        $filters = $this->filters($request);
        $range = $filters['from']->format('j M Y').' to '.$filters['to']->format('j M Y');
        $business = $this->tenancy->businessOrFail();

        if ($request->boolean('detail')) {
            return $export->download(
                $validated['format'],
                "{$business->name} transactions {$range}",
                ['Date', 'Time', 'Outlet', 'Terminal', 'Staff', 'Type', 'Amount', 'Charge', 'Provider fee', 'Commission', 'Profit', 'Reference', 'Customer', 'Note'],
                iterator_to_array($this->detailRows($filters), false),
            );
        }

        $group = ucfirst($filters['group'] === 'agent' ? 'staff' : $filters['group']);

        return $export->download(
            $validated['format'],
            "{$business->name} report by {$group} {$range}",
            [$group, 'Entries', 'Volume', 'Charges', 'Commissions', 'Provider fees', 'Expenses', 'Profit'],
            iterator_to_array($this->summaryRows($filters), false),
        );
    }

    /**
     * @param  Filters  $filters
     *                            Built before the download starts: the stream runs after the request has finished,
     *                            when the current business (and so the tenant scope) is no longer set.
     * @return iterable<int, list<string|int|float|null>>
     */
    private function summaryRows(array $filters): iterable
    {
        foreach ($this->report->rows($filters) as $row) {
            yield [
                $row['sub'] ? "{$row['label']} ({$row['sub']})" : $row['label'],
                $row['count'],
                ReportExport::naira($row['volume']),
                ReportExport::naira($row['charges']),
                ReportExport::naira($row['commissions']),
                ReportExport::naira($row['fees']),
                ReportExport::naira($row['expenses']),
                ReportExport::naira($row['profit']),
            ];
        }

        $total = $this->report->total($filters);

        yield [
            'Total',
            $total['count'],
            ReportExport::naira($total['volume']),
            ReportExport::naira($total['charges']),
            ReportExport::naira($total['commissions']),
            ReportExport::naira($total['fees']),
            ReportExport::naira($total['expenses']),
            ReportExport::naira($total['profit']),
        ];
    }

    /**
     * @param  Filters  $filters
     * @return iterable<int, list<string|int|float|null>>
     */
    private function detailRows(array $filters): iterable
    {
        $timezone = $this->tenancy->businessOrFail()->timezone;

        foreach ($this->report->detail($filters)->lazy(500) as $t) {
            $at = $t->occurred_at->setTimezone($timezone);

            yield [
                $at->toDateString(),
                $at->format('H:i'),
                $t->outlet->name,
                $t->terminal->label(),
                $t->membership->user->name,
                $t->type->label(),
                ReportExport::naira($t->amount),
                ReportExport::naira($t->charge),
                ReportExport::naira($t->provider_fee),
                ReportExport::naira($t->commission),
                ReportExport::naira($t->profit()->kobo),
                $t->reference,
                $t->customer_name ?? $t->customer_phone,
                $t->note,
            ];
        }
    }

    /**
     * @return Filters
     */
    private function filters(Request $request): array
    {
        $me = $this->tenancy->membershipOrFail();
        $timezone = $this->tenancy->businessOrFail()->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $to = $this->date($request->query('to'), $timezone) ?? $today;
        $from = $this->date($request->query('from'), $timezone) ?? $to->startOfMonth();

        if ($from->isAfter($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        $outlets = $this->coveredOutlets();
        $outlet = $request->string('outlet')->value() ?: null;
        $outlet = $outlets->contains('id', $outlet) ? $outlet : null;

        $ids = array_values($outlets->map(fn (Outlet $o) => $o->id)->all());
        $terminal = $request->string('terminal')->value() ?: null;
        $terminal = $terminal !== null && Terminal::query()->whereKey($terminal)->whereIn('outlet_id', $ids)->exists() ? $terminal : null;

        // Agents only ever see their own figures.
        $agent = $me->role === Role::Agent ? $me->id : ($request->string('agent')->value() ?: null);
        $agent = $agent !== null && Membership::query()->whereKey($agent)->exists() ? $agent : null;

        $type = $request->string('type')->value() ?: null;
        $type = TransactionType::tryFrom((string) $type)?->value;

        return [
            'from' => $from,
            'to' => $to,
            'outlet_ids' => $ids,
            'outlet' => $outlet,
            'terminal' => $terminal,
            'agent' => $agent,
            'type' => $type,
            'group' => in_array($request->query('group'), TransactionReport::GROUPS, true) ? (string) $request->query('group') : 'day',
        ];
    }

    private function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::parse($value, $timezone)->startOfDay()
            : null;
    }

    /**
     * @return Collection<int, Outlet>
     */
    private function coveredOutlets(): Collection
    {
        $me = $this->tenancy->membershipOrFail();

        return Outlet::query()->orderBy('name')->get()->filter(fn (Outlet $o) => $me->coversOutlet($o))->values();
    }
}
