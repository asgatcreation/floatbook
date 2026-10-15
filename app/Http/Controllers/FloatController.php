<?php

namespace App\Http\Controllers;

use App\Actions\Float\FloatMovements;
use App\Enums\FloatMovementStatus;
use App\Enums\FloatMovementType;
use App\Enums\LedgerAccountType;
use App\Enums\Role;
use App\Models\FloatMovement;
use App\Models\LedgerAccount;
use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Support\Audit;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FloatController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy, private FloatMovements $movements) {}

    public function index(Accounts $accounts): Response
    {
        $me = $this->tenancy->membershipOrFail();
        $isOwner = $me->role === Role::Owner;

        $outlets = Outlet::query()->active()->orderBy('name')->get()
            ->filter(fn (Outlet $o) => $me->coversOutlet($o))
            ->map(function (Outlet $outlet) use ($accounts) {
                $cash = $accounts->cash($outlet);
                $wallets = OutletWallet::query()->where('outlet_id', $outlet->id)->where('is_active', true)
                    ->with(['account', 'provider', 'terminals' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->get()
                    // A balance with no working terminal is hidden once it is empty.
                    ->filter(fn (OutletWallet $w) => $w->provider->is_active && ($w->terminals->isNotEmpty() || $w->account->balance !== 0))
                    ->sortBy(fn (OutletWallet $w) => $w->provider->sort_order)
                    ->map(fn (OutletWallet $w) => [
                        ...$this->presentAccount($w->account, $w->label(), $w->provider->color),
                        // Which terminals pay into this balance, e.g. "Terminal 1, Terminal 2".
                        'terminals' => $w->terminals->pluck('name')->values(),
                    ])
                    ->values();

                return [
                    'id' => $outlet->id,
                    'name' => $outlet->name,
                    'cash' => $this->presentAccount($cash, 'Cash'),
                    'wallets' => $wallets,
                    'pending_payouts' => $accounts->forOutlet($outlet, LedgerAccountType::PendingSettlement)->balance,
                ];
            })->values();

        $visible = $outlets->pluck('id');

        $movements = FloatMovement::query()
            ->with(['fromAccount:id,name,outlet_id', 'toAccount:id,name,outlet_id', 'requester:id,name', 'decider:id,name'])
            ->latest()->limit(60)->get()
            ->filter(fn (FloatMovement $m) => $isOwner
                || $visible->contains($m->fromAccount?->outlet_id) || $visible->contains($m->toAccount->outlet_id))
            ->map(fn (FloatMovement $m) => [
                'id' => $m->id,
                'type' => $m->type->value,
                'type_label' => $m->type->label(),
                'amount' => $m->amount,
                'from' => $m->type === FloatMovementType::OpeningBalance ? 'Opening balance' : $m->fromAccount?->name,
                'to' => $m->toAccount->name,
                'status' => $m->status->value,
                'reason' => $m->reason,
                'requested_by' => $m->requester?->name,
                'decided_by' => $m->decider?->name,
                'decision_note' => $m->decision_note,
                'created_at' => $m->created_at?->toIso8601String(),
                'can_approve' => $m->status === FloatMovementStatus::Requested && $this->movements->canApprove($me, $m),
                'can_cancel' => $m->status === FloatMovementStatus::Requested && $m->requested_by === $me->user_id,
            ])->values();

        // Accounts the person may pick in the "Move money" form.
        $options = $outlets->flatMap(fn (array $o) => [
            ['id' => $o['cash']['id'], 'label' => "{$o['name']} · Cash", 'kind' => 'cash', 'outlet_id' => $o['id']],
            ...collect($o['wallets'])->map(fn (array $w) => ['id' => $w['id'], 'label' => "{$o['name']} · {$w['label']} wallet", 'kind' => 'wallet', 'outlet_id' => $o['id']]),
        ])->values();

        $headOffice = $accounts->headOffice();

        return Inertia::render('float/Index', [
            'outlets' => $outlets,
            'headOffice' => $isOwner ? $this->presentAccount($headOffice, 'Head office') : null,
            'movements' => $movements,
            'accountOptions' => [
                ...$options,
                ['id' => $headOffice->id, 'label' => 'Head office', 'kind' => 'head_office', 'outlet_id' => null],
            ],
            'types' => collect(FloatMovementType::cases())
                ->reject(fn (FloatMovementType $t) => $t === FloatMovementType::OpeningBalance && ! $isOwner)
                ->map(fn (FloatMovementType $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(FloatMovementType::class)],
            'from_account_id' => ['nullable', 'string'],
            'to_account_id' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:100', 'max:10000000000'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $movement = $this->movements->request(
            $this->tenancy->membershipOrFail(),
            FloatMovementType::from($validated['type']),
            $validated['from_account_id'] ?? null,
            $validated['to_account_id'],
            Money::kobo((int) $validated['amount']),
            $validated['reason'] ?? null,
        );

        $this->toast($movement->status === FloatMovementStatus::Completed
            ? Money::kobo($movement->amount)->format(true).' moved.'
            : 'Request sent for approval.');

        return back();
    }

    public function approve(FloatMovement $movement): RedirectResponse
    {
        $this->movements->approve($this->tenancy->membershipOrFail(), $movement);
        $this->toast('Approved. '.Money::kobo($movement->amount)->format(true).' moved.');

        return back();
    }

    public function reject(Request $request, FloatMovement $movement): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        $result = $this->movements->reject($this->tenancy->membershipOrFail(), $movement, $validated['note'] ?? null);
        $this->toast($result->status === FloatMovementStatus::Cancelled ? 'Request cancelled.' : 'Request rejected.');

        return back();
    }

    /**
     * The alert level for an outlet's cash or wallet (owner only). Below it, the balance is flagged as low.
     */
    public function threshold(Request $request, LedgerAccount $account): RedirectResponse
    {
        abort_unless($this->tenancy->membershipOrFail()->role === Role::Owner, 403);
        abort_unless(in_array($account->type, [LedgerAccountType::CashDrawer, LedgerAccountType::ProviderWallet], true), 404);

        $validated = $request->validate(['low_balance_threshold' => ['nullable', 'integer', 'min:0', 'max:10000000000']]);
        $account->low_balance_threshold = $validated['low_balance_threshold'] ?? null;
        app(Audit::class)->recordChanges('float.alert_level_changed', $account);
        $account->save();

        $this->toast($account->low_balance_threshold === null
            ? 'Low-balance alert switched off.'
            : 'You will be alerted below '.Money::kobo($account->low_balance_threshold)->format(true).'.');

        return back();
    }

    /**
     * @return array{id: string, label: string, color: string|null, balance: int, low_balance_threshold: int|null, is_low: bool}
     */
    private function presentAccount(LedgerAccount $account, string $label, ?string $color = null): array
    {
        return [
            'id' => $account->id,
            'label' => $label,
            'color' => $color,
            'balance' => $account->balance,
            'low_balance_threshold' => $account->low_balance_threshold,
            'is_low' => $account->isLow(),
        ];
    }
}
