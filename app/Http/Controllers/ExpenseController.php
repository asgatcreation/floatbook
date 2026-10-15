<?php

namespace App\Http\Controllers;

use App\Actions\Expenses\Expenses;
use App\Enums\ExpenseCategory;
use App\Enums\Permission;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Models\User;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy) {}

    public function index(Request $request, Accounts $accounts): Response
    {
        $me = $this->tenancy->membershipOrFail();
        $timezone = $this->tenancy->businessOrFail()->timezone;

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('Y-m', (string) $request->query('month'), $timezone)->startOfMonth()
            : CarbonImmutable::now($timezone)->startOfMonth();

        $query = $this->visibleExpenses()
            ->whereBetween('business_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->when($request->filled('outlet'), fn ($q) => $q->where('outlet_id', $request->string('outlet')->value()));

        $byCategory = (clone $query)->counted()
            ->selectRaw('category, sum(amount) as total')->groupBy('category')->pluck('total', 'category');

        $expenses = $query->with(['outlet:id,name', 'membership.user:id,name', 'paidFrom:id,name'])
            ->latest('occurred_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Expense $e) => [
                'id' => $e->id,
                'category' => $e->category->value,
                'category_label' => $e->category->label(),
                'amount' => $e->amount,
                'description' => $e->description,
                'outlet' => $e->outlet->name,
                // Account names start with the outlet ("Ketu Main · Cash in drawer"), shown separately here.
                'paid_from' => Str::after($e->paidFrom->name, $e->outlet->name.' · '),
                'recorded_by' => $e->membership->user->name,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'voided' => $e->isVoided(),
                'void_reason' => $e->void_reason,
            ]);

        $canRecord = $me->allows(Permission::RecordTransactions);

        return Inertia::render('expenses/Index', [
            'expenses' => $expenses,
            'month' => $month->format('Y-m'),
            'outletFilter' => $request->string('outlet')->value() ?: null,
            'total' => (int) $byCategory->sum(),
            'byCategory' => collect(ExpenseCategory::cases())
                ->map(fn (ExpenseCategory $c) => ['value' => $c->value, 'label' => $c->label(), 'total' => (int) ($byCategory[$c->value] ?? 0)])
                ->filter(fn (array $c) => $c['total'] > 0)->sortByDesc('total')->values(),
            'categories' => collect(ExpenseCategory::cases())->map(fn (ExpenseCategory $c) => ['value' => $c->value, 'label' => $c->label()]),
            // Where the money can come from, per outlet: its drawer and its wallets.
            'outlets' => $this->coveredOutlets()->map(fn (Outlet $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'accounts' => $canRecord ? $this->payableAccounts($o, $accounts) : [],
            ])->values(),
            'canRecord' => $canRecord,
            'canVoid' => $me->allows(Permission::VoidTransactions),
        ]);
    }

    public function store(Request $request, Expenses $expenses): RedirectResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'outlet_id' => ['required', 'string'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000'], // kobo: ₦1 to ₦1,000,000
            'paid_from_account_id' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:160'],
        ], ['amount.min' => 'Enter an amount.', 'amount.max' => 'That is more than ₦1,000,000. Record large payments as float movements.']);

        $outlet = Outlet::query()->active()->whereKey($validated['outlet_id'])->firstOrFail();
        $paidFrom = LedgerAccount::query()->whereKey($validated['paid_from_account_id'])->firstOrFail();

        $expense = $expenses->record(
            $this->tenancy->membershipOrFail(),
            $outlet,
            ExpenseCategory::from($validated['category']),
            Money::kobo((int) $validated['amount']),
            $paidFrom,
            $validated['description'] ?? null,
            $validated['idempotency_key'],
        );

        $this->toast('Recorded '.Money::kobo($expense->amount)->format(true).' for '.mb_strtolower($expense->category->label()).'.');

        return back();
    }

    public function void(Request $request, Expense $expense, Expenses $expenses): RedirectResponse
    {
        abort_unless($this->visibleExpenses()->whereKey($expense->id)->exists(), 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        /** @var User $user */
        $user = $request->user();
        $expenses->void($expense, $user, $validated['reason']);
        $this->toast('Expense voided. The money has gone back where it came from.');

        return back();
    }

    /**
     * Supervisors and agents see their outlets' expenses; owners and accountants see all.
     *
     * @return Builder<Expense>
     */
    private function visibleExpenses(): Builder
    {
        $me = $this->tenancy->membershipOrFail();

        return Expense::query()->when(
            $me->role->isOutletScoped(),
            fn ($q) => $q->whereIn('outlet_id', $me->outlets->pluck('id')),
        );
    }

    /**
     * @return Collection<int, Outlet>
     */
    private function coveredOutlets(): Collection
    {
        $me = $this->tenancy->membershipOrFail();

        return Outlet::query()->active()->orderBy('name')->get()->filter(fn (Outlet $o) => $me->coversOutlet($o))->values();
    }

    /**
     * @return list<array{id: string, label: string, balance: int}>
     */
    private function payableAccounts(Outlet $outlet, Accounts $accounts): array
    {
        $cash = $accounts->cash($outlet);
        $wallets = OutletWallet::query()->where('outlet_id', $outlet->id)->with(['account', 'provider', 'terminals'])->get()
            ->filter(fn (OutletWallet $w) => $w->provider->is_active && $w->terminals->where('is_active', true)->isNotEmpty());

        return [
            ['id' => $cash->id, 'label' => 'Cash in drawer', 'balance' => $cash->balance],
            ...$wallets->map(fn (OutletWallet $w) => ['id' => $w->account->id, 'label' => $w->label().' wallet', 'balance' => $w->account->balance])->values()->all(),
        ];
    }
}
