<?php

namespace App\Http\Controllers;

use App\Enums\AnomalyKind;
use App\Enums\Permission;
use App\Models\AnomalyFlag;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flags FloatBook raised for someone to look at. Owners, supervisors and accountants
 * see them; a supervisor or owner says whether each one was fine or not.
 */
class AnomalyController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy) {}

    public function index(Request $request): Response
    {
        $me = $this->tenancy->membershipOrFail();
        // Owners and supervisors (who judge them) and accountants (who audit); not agents.
        abort_unless($me->allows(Permission::ApproveReconciliation) || $me->allows(Permission::ViewAuditLog), 403);

        $status = in_array($request->query('status'), ['open', 'dismissed', 'confirmed'], true)
            ? (string) $request->query('status')
            : 'open';

        $flags = $this->visible()
            ->where('status', $status)
            ->with(['outlet:id,name', 'membership.user:id,name', 'reviewer.user:id,name'])
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AnomalyFlag $f) => [
                'id' => $f->id,
                'kind' => $f->kind->value,
                'kind_label' => $f->kind->label(),
                'hint' => $f->kind->hint(),
                'severity' => $f->severity,
                'summary' => $f->summary,
                'details' => $f->details,
                'outlet' => $f->outlet?->name,
                'person' => $f->membership?->user->name,
                'subject_type' => $f->subject_type,
                'subject_id' => $f->subject_id,
                'status' => $f->status,
                'reviewed_by' => $f->reviewer?->user->name,
                'review_note' => $f->review_note,
                'created_at' => $f->created_at?->toIso8601String(),
            ]);

        return Inertia::render('anomalies/Index', [
            'flags' => $flags,
            'status' => $status,
            'counts' => [
                'open' => (clone $this->visible())->where('status', 'open')->count(),
                'dismissed' => (clone $this->visible())->where('status', 'dismissed')->count(),
                'confirmed' => (clone $this->visible())->where('status', 'confirmed')->count(),
            ],
            'kinds' => collect(AnomalyKind::cases())->map(fn (AnomalyKind $k) => ['value' => $k->value, 'label' => $k->label()]),
            'canReview' => $me->allows(Permission::ApproveReconciliation),
        ]);
    }

    public function review(Request $request, AnomalyFlag $flag): RedirectResponse
    {
        abort_unless($this->tenancy->membershipOrFail()->allows(Permission::ApproveReconciliation), 403);
        abort_unless($this->visible()->whereKey($flag->id)->exists(), 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['dismissed', 'confirmed', 'open'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $flag->forceFill([
            'status' => $validated['status'],
            'reviewed_by' => $validated['status'] === 'open' ? null : $this->tenancy->membershipOrFail()->id,
            'reviewed_at' => $validated['status'] === 'open' ? null : now(),
            'review_note' => $validated['note'] ?? null,
        ])->save();

        $this->toast(match ($validated['status']) {
            'dismissed' => 'Marked as fine.',
            'confirmed' => 'Marked as a real problem. It stays on the record.',
            default => 'Reopened.',
        });

        return back();
    }

    /**
     * Supervisors see flags for their outlets; owners and accountants see all.
     *
     * @return Builder<AnomalyFlag>
     */
    private function visible(): Builder
    {
        $me = $this->tenancy->membershipOrFail();

        return AnomalyFlag::query()->when(
            $me->role->isOutletScoped(),
            fn (Builder $q) => $q->whereIn('outlet_id', $me->outlets->pluck('id'))->orWhereNull('outlet_id'),
        );
    }
}
