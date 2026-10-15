<?php

namespace App\Http\Controllers;

use App\Actions\Reconciliation\Reconciliations;
use App\Enums\Permission;
use App\Enums\ReconciliationStatus;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Models\Reconciliation;
use App\Models\ReconciliationWallet;
use App\Models\StaffShortage;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    private const DAYS_SHOWN = 7;

    public function __construct(private CurrentBusiness $tenancy, private Reconciliations $reconciliations) {}

    public function index(): Response
    {
        $me = $this->tenancy->membershipOrFail();
        $today = $this->today();
        $outlets = $this->coveredOutlets();
        $days = collect(range(0, self::DAYS_SHOWN - 1))->map(fn (int $i) => $today->subDays($i));

        $recs = Reconciliation::query()
            ->whereIn('outlet_id', $outlets->pluck('id'))
            ->where('business_date', '>=', $days->last()->toDateString())
            ->with('wallets')
            ->get()
            ->keyBy(fn (Reconciliation $r) => $r->outlet_id.'|'.$r->business_date->toDateString());

        $shortages = StaffShortage::query()->where('status', 'outstanding')
            ->with(['membership.user:id,name', 'reconciliation.outlet:id,name'])
            ->get()
            ->filter(fn (StaffShortage $s) => $me->coversOutlet($s->reconciliation->outlet_id))
            ->filter(fn (StaffShortage $s) => $me->role !== Role::Agent || $s->membership_id === $me->id)
            ->map(fn (StaffShortage $s) => [
                'id' => $s->id,
                'person' => $s->membership->user->name,
                'outlet' => $s->reconciliation->outlet->name,
                'date' => $s->reconciliation->business_date->toDateString(),
                'amount' => $s->amount,
            ])->values();

        return Inertia::render('reconciliation/Index', [
            'today' => $today->toDateString(),
            'days' => $days->map(fn (CarbonImmutable $d) => $d->toDateString()),
            'outlets' => $outlets->map(fn (Outlet $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'days' => $days->map(function (CarbonImmutable $d) use ($recs, $o) {
                    $rec = $recs[$o->id.'|'.$d->toDateString()] ?? null;

                    return [
                        'date' => $d->toDateString(),
                        'status' => $rec?->status->value,
                        'cash_variance' => $rec?->cash_variance,
                        'balances' => $rec?->balances(),
                    ];
                })->values(),
            ])->values(),
            'shortages' => $shortages,
            'canSettle' => $me->allows(Permission::ApproveReconciliation),
        ]);
    }

    public function show(Outlet $outlet, string $date): Response
    {
        $me = $this->tenancy->membershipOrFail();
        abort_unless($me->coversOutlet($outlet), 404);
        $day = $this->parseDay($date);

        $rec = Reconciliation::query()->where('outlet_id', $outlet->id)->where('business_date', $day->toDateString())
            ->with(['wallets.wallet.provider', 'wallets.wallet.terminals', 'submitter.user:id,name', 'decider.user:id,name', 'shortages.membership.user:id,name'])
            ->first();

        $counting = $rec === null || ! $rec->status->closesDay();

        return Inertia::render('reconciliation/Show', [
            'outlet' => ['id' => $outlet->id, 'name' => $outlet->name],
            'date' => $day->toDateString(),
            'isToday' => $day->equalTo($this->today()),
            'notes' => Reconciliations::NOTES,
            'counting' => $counting,
            'canCount' => $counting && $me->allows(Permission::RecordTransactions),
            // The count is blind: expected figures only appear once it has been submitted.
            'walletsToCount' => $counting ? $this->reconciliations->walletsToCount($outlet)->map(fn (OutletWallet $w) => [
                'id' => $w->id,
                'label' => $w->label(),
                'color' => $w->provider->color,
                'terminals' => $w->terminals->pluck('name')->values(),
            ])->values() : [],
            'reconciliation' => $rec ? $this->present($rec) : null,
            'staff' => $rec?->status === ReconciliationStatus::Submitted ? $this->staffAt($outlet) : [],
            'canDecide' => $rec?->status === ReconciliationStatus::Submitted
                && $me->allows(Permission::ApproveReconciliation)
                && ($rec->submitted_by !== $me->id || $me->role === Role::Owner),
            'canSendBack' => $rec?->status === ReconciliationStatus::Submitted && $me->allows(Permission::ApproveReconciliation),
            'canReopen' => $rec?->status === ReconciliationStatus::Approved && $me->allows(Permission::ReopenDay),
            'canSettle' => $me->allows(Permission::ApproveReconciliation),
        ]);
    }

    public function submit(Request $request, Outlet $outlet, string $date): RedirectResponse
    {
        $validated = $request->validate([
            'denominations' => ['required', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000000'],
            'wallets' => ['array'],
            'wallets.*' => ['required', 'integer', 'min:-100000000000', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $rec = $this->reconciliations->submit(
            $this->tenancy->membershipOrFail(),
            $outlet,
            $this->parseDay($date),
            $validated['denominations'],
            $validated['wallets'] ?? [],
            $validated['note'] ?? null,
        );

        $rec->load('wallets');
        $this->toast($rec->balances()
            ? 'Counted. Everything matches the books.'
            : 'Counted and sent for approval. The difference is shown below.');

        return to_route('reconciliation.show', [$outlet, $date]);
    }

    public function approve(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $validated = $request->validate([
            'shortages' => ['array'],
            'shortages.*' => ['integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $this->reconciliations->approve($this->tenancy->membershipOrFail(), $reconciliation, $validated['shortages'] ?? [], $validated['note'] ?? null);
        $this->toast('Day approved and closed.');

        return back();
    }

    public function sendBack(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);
        $this->reconciliations->sendBack($this->tenancy->membershipOrFail(), $reconciliation, $validated['note']);
        $this->toast('Sent back for a recount. The day is open again.');

        return back();
    }

    public function reopen(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->reconciliations->reopen($this->tenancy->membershipOrFail(), $reconciliation, $validated['reason']);
        $this->toast('Day reopened. Count it again when ready.');

        return back();
    }

    public function settleShortage(Request $request, StaffShortage $shortage): RedirectResponse
    {
        $validated = $request->validate(['outcome' => ['required', 'in:recovered,written_off']]);
        $me = $this->tenancy->membershipOrFail();

        $validated['outcome'] === 'recovered'
            ? $this->reconciliations->recover($me, $shortage)
            : $this->reconciliations->writeOff($me, $shortage);

        $this->toast($validated['outcome'] === 'recovered'
            ? 'Marked as paid back. '.Money::kobo($shortage->amount)->format(true).' added to the drawer.'
            : 'Shortage written off.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Reconciliation $rec): array
    {
        return [
            'id' => $rec->id,
            'status' => $rec->status->value,
            'status_label' => $rec->status->label(),
            'expected_cash' => $rec->expected_cash,
            'counted_cash' => $rec->counted_cash,
            'cash_variance' => $rec->cash_variance,
            'denominations' => $rec->denominations,
            'note' => $rec->note,
            'balances' => $rec->balances(),
            'submitted_by' => $rec->submitter->user->name,
            'submitted_at' => $rec->submitted_at->toIso8601String(),
            'decided_by' => $rec->decider?->user->name,
            'decided_at' => $rec->decided_at?->toIso8601String(),
            'decision_note' => $rec->decision_note,
            'wallets' => $rec->wallets->map(fn (ReconciliationWallet $w) => [
                'id' => $w->outlet_wallet_id,
                'label' => $w->wallet->label(),
                'color' => $w->wallet->provider->color,
                'terminals' => $w->wallet->terminals->pluck('name')->values(),
                'expected' => $w->expected,
                'actual' => $w->actual,
                'variance' => $w->variance,
            ])->values(),
            'shortages' => $rec->shortages->where('status', '!=', 'cancelled')->map(fn (StaffShortage $s) => [
                'id' => $s->id, 'person' => $s->membership->user->name, 'amount' => $s->amount, 'status' => $s->status,
            ])->values(),
        ];
    }

    /**
     * People who could be held responsible for a shortage: staff working at the outlet.
     *
     * @return list<array{id: string, name: string, role: string}>
     */
    private function staffAt(Outlet $outlet): array
    {
        return array_values(Membership::query()->active()->with(['user:id,name', 'outlets:id'])->get()
            ->filter(fn (Membership $m) => $m->role->isOutletScoped() && $m->coversOutlet($outlet))
            ->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->label()])
            ->sortBy('name')->all());
    }

    /**
     * @return Collection<int, Outlet>
     */
    private function coveredOutlets(): Collection
    {
        $me = $this->tenancy->membershipOrFail();

        return Outlet::query()->active()->orderBy('name')->get()->filter(fn (Outlet $o) => $me->coversOutlet($o))->values();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tenancy->businessOrFail()->timezone)->startOfDay();
    }

    private function parseDay(string $date): CarbonImmutable
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);

        return CarbonImmutable::parse($date, $this->tenancy->businessOrFail()->timezone)->startOfDay();
    }
}
