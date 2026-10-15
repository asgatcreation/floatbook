<?php

namespace App\Http\Controllers;

use App\Actions\Reversals\ReversalCases;
use App\Enums\Permission;
use App\Enums\ReversalStatus;
use App\Models\ReversalCase;
use App\Models\ReversalCaseUpdate;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReversalController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy) {}

    public function index(Request $request): Response
    {
        $view = $request->validate(['view' => ['nullable', Rule::in(['open', 'closed'])]])['view'] ?? 'open';

        $base = $this->visibleCases();

        $cases = (clone $base)
            ->when($view === 'open', fn ($q) => $q->open()->orderBy('due_at'), fn ($q) => $q->whereNotIn('status', ['open', 'escalated'])->latest('closed_at'))
            ->with(['transaction.provider:id,name,color', 'outlet:id,name', 'updates.user:id,name'])
            ->limit(100)
            ->get()
            ->map(fn (ReversalCase $c) => [
                'id' => $c->id,
                'transaction_id' => $c->transaction_id,
                'type_label' => $c->transaction->type->label(),
                'amount' => $c->amount,
                'customer_name' => $c->customer_name,
                'customer_phone' => $c->customer_phone,
                'provider' => $c->transaction->provider->only(['name', 'color']),
                'outlet' => $c->outlet->name,
                'reference' => $c->transaction->reference,
                'cash_paid' => $c->transaction->cash_paid_while_pending,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'provider_ticket' => $c->provider_ticket,
                'outcome' => $c->outcome,
                'opened_at' => $c->created_at?->toIso8601String(),
                'due_at' => $c->due_at->toIso8601String(),
                'closed_at' => $c->closed_at?->toIso8601String(),
                'overdue' => $c->isOverdue(),
                'updates' => $c->updates->map(fn (ReversalCaseUpdate $u) => [
                    'by' => $u->user->name ?? 'FloatBook',
                    'to_status' => $u->to_status !== null ? ReversalStatus::from($u->to_status)->label() : null,
                    'note' => $u->note,
                    'at' => $u->created_at->toIso8601String(),
                ])->values(),
            ])
            // Overdue cases first, then the soonest due.
            ->sortBy(fn (array $c) => ($c['overdue'] ? '0' : '1').$c['due_at'])
            ->values();

        return Inertia::render('reversals/Index', [
            'view' => $view,
            'cases' => $view === 'open' ? $cases : $cases->sortByDesc('closed_at')->values(),
            'counts' => [
                'open' => (clone $base)->open()->count(),
                'overdue' => (clone $base)->open()->where('due_at', '<', now())->count(),
                'owed' => (int) (clone $base)->open()->sum('amount'),
            ],
            'canWriteOff' => $this->tenancy->membershipOrFail()->allows(Permission::VoidTransactions),
        ]);
    }

    public function update(Request $request, ReversalCase $case, ReversalCases $cases): RedirectResponse
    {
        abort_unless($this->visibleCases()->whereKey($case->id)->exists(), 404);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ReversalStatus::class)->except(ReversalStatus::Cancelled)],
            'note' => ['nullable', 'string', 'max:500'],
            'provider_ticket' => ['nullable', 'string', 'max:60'],
            'cash_outcome' => ['nullable', Rule::in(['cash_recovered', 'written_off'])],
        ]);

        $updated = $cases->update(
            $case,
            $this->tenancy->membershipOrFail(),
            isset($validated['status']) ? ReversalStatus::from($validated['status']) : null,
            $validated['note'] ?? null,
            $validated['provider_ticket'] ?? null,
            $validated['cash_outcome'] ?? null,
        );

        $this->toast($updated->status->isClosed() ? 'Case closed: '.mb_strtolower($updated->status->label()).'.' : 'Case updated.');

        return back();
    }

    /**
     * Supervisors and agents see cases at their outlets; owners and accountants see all.
     *
     * @return Builder<ReversalCase>
     */
    private function visibleCases(): Builder
    {
        $me = $this->tenancy->membershipOrFail();

        return ReversalCase::query()
            ->when($me->role->isOutletScoped(), fn ($q) => $q->whereIn('outlet_id', $me->outlets->pluck('id')));
    }
}
