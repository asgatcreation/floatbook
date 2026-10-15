<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\MoveTransaction;
use App\Actions\Transactions\RecordTransaction;
use App\Actions\Transactions\ResolvePendingTransaction;
use App\Actions\Transactions\VoidTransaction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\ChargeRule;
use App\Models\ChargeRuleTier;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\Accounts;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'outlet' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        $business = $this->tenancy->businessOrFail();
        $date = $filters['date'] ?? CarbonImmutable::now($business->timezone)->toDateString();

        $query = $this->visibleTransactions()
            ->whereDate('business_date', $date)
            ->when($filters['outlet'] ?? null, fn ($q, $id) => $q->where('outlet_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('reference', 'ilike', "%{$term}%")
                ->orWhere('customer_phone', 'ilike', "%{$term}%")
                ->orWhere('customer_name', 'ilike', "%{$term}%")));

        $counted = (clone $query)->counted();
        $summary = [
            'count' => (clone $query)->count(),
            'volume' => (int) (clone $counted)->sum('amount'),
            'charges' => (int) (clone $counted)->sum('charge'),
            'commissions' => (int) (clone $counted)->sum('commission'),
            'fees' => (int) (clone $counted)->sum('provider_fee'),
            'pending' => (clone $query)->where('status', TransactionStatus::Pending)->whereNull('voided_at')->count(),
        ];
        $summary['profit'] = $summary['charges'] + $summary['commissions'] - $summary['fees'];

        $transactions = $query->with(['provider:id,name,color', 'terminal.provider:id,name', 'outlet:id,name', 'membership.user:id,name'])
            ->latest('occurred_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Transaction $t) => $this->present($t));

        return Inertia::render('transactions/Index', [
            'transactions' => $transactions,
            'summary' => $summary,
            'filters' => [...$filters, 'date' => $date],
            'outlets' => $this->coveredOutlets()->map->only(['id', 'name'])->values(),
            'types' => collect(TransactionType::cases())->map(fn (TransactionType $t) => ['value' => $t->value, 'label' => $t->label()]),
            'canVoid' => $this->tenancy->membershipOrFail()->allows(Permission::VoidTransactions),
        ]);
    }

    public function create(Accounts $accounts): Response
    {
        $me = $this->tenancy->membershipOrFail();
        $me->loadMissing('terminals');

        // Only the terminals this person may record on, at the outlets they cover.
        $outlets = $this->coveredOutlets()->map(function (Outlet $outlet) use ($accounts, $me) {
            $terminals = Terminal::query()->active()->where('outlet_id', $outlet->id)
                ->whereHas('provider', fn ($q) => $q->where('is_active', true))
                ->with(['provider:id,name,color,sort_order', 'wallet.account:id,balance'])
                ->get()
                ->filter(fn (Terminal $t) => $me->canUseTerminal($t))
                ->sortBy([fn (Terminal $a, Terminal $b) => $a->provider->sort_order <=> $b->provider->sort_order, ['name', 'asc']])
                ->values();

            return [
                'id' => $outlet->id,
                'name' => $outlet->name,
                'cash' => $accounts->cash($outlet)->balance,
                'terminals' => $terminals->map(fn (Terminal $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'label' => $t->label(),
                    'code' => $t->terminal_code,
                    'provider_id' => $t->provider_id,
                    'provider' => ['name' => $t->provider->name, 'color' => $t->provider->color],
                    'wallet_id' => $t->outlet_wallet_id,
                    'wallet_label' => $t->wallet->label(),
                ]),
                // Shared by terminals on the same account.
                'wallets' => $terminals->mapWithKeys(fn (Terminal $t) => [$t->outlet_wallet_id => $t->wallet->account->balance]),
            ];
        })->filter(fn (array $o) => $o['terminals']->isNotEmpty())->values();

        return Inertia::render('transactions/Create', [
            'outlets' => $outlets,
            // Start on the terminal this person used last.
            'lastTerminalId' => Transaction::query()->where('membership_id', $me->id)->latest('occurred_at')->value('terminal_id'),
            'types' => collect(TransactionType::cases())->map(fn (TransactionType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'short' => $t->shortLabel(),
                'flow' => $t->flow(),
                'can_pay_cash_while_pending' => $t->canPayCashWhilePending(),
            ]),
            // The browser previews charges with the same rules; the server recalculates on save.
            'rules' => ChargeRule::query()->with('tiers')->where('is_active', true)->get()->map(fn (ChargeRule $r) => [
                'transaction_type' => $r->transaction_type->value,
                'kind' => $r->kind->value,
                'provider_id' => $r->provider_id,
                'outlet_id' => $r->outlet_id,
                'collected_via' => $r->collected_via,
                'tiers' => $r->tiers->map(fn (ChargeRuleTier $t) => $t->only(['min_amount', 'max_amount', 'flat', 'percent_bps', 'cap', 'step'])),
            ]),
            'recent' => Transaction::query()->where('membership_id', $me->id)
                ->with(['provider:id,name,color', 'terminal.provider:id,name'])->latest('occurred_at')->limit(5)->get()
                ->map(fn (Transaction $t) => $this->present($t)),
        ]);
    }

    public function store(Request $request, RecordTransaction $record): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'terminal_id' => ['nullable', 'string'],
            // Older phones send these instead of a terminal.
            'outlet_id' => ['required_without:terminal_id', 'nullable', 'string'],
            'provider_id' => ['required_without:terminal_id', 'nullable', 'string'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount' => ['required', 'integer', 'min:100', 'max:1000000000'], // kobo: ₦1 to ₦10,000,000
            'status' => ['required', Rule::enum(TransactionStatus::class)->except(TransactionStatus::Reversed)],
            'cash_paid_while_pending' => ['boolean'],
            'customer_name' => ['nullable', 'string', 'max:80'],
            'customer_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{7,16}$/'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:200'],
            'client_recorded_at' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(['counter', 'offline_sync'])],
        ], ['customer_phone.regex' => 'Enter a valid phone number.']);

        $result = $record->handle($this->tenancy->membershipOrFail(), [
            'idempotency_key' => $request->string('idempotency_key')->value(),
            'terminal_id' => $validated['terminal_id'] ?? null,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'provider_id' => $validated['provider_id'] ?? null,
            'type' => TransactionType::from($request->string('type')->value()),
            'status' => TransactionStatus::from($request->string('status')->value()),
            'amount' => Money::kobo($request->integer('amount')),
            'cash_paid_while_pending' => $request->boolean('cash_paid_while_pending'),
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_phone' => $validated['customer_phone'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'note' => $validated['note'] ?? null,
            'source' => $request->string('source', 'counter')->value(),
            'client_recorded_at' => $request->filled('client_recorded_at') ? CarbonImmutable::parse($request->string('client_recorded_at')->value()) : null,
        ]);

        $t = $result->transaction;

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['transaction' => $this->present($t), 'duplicate' => $result->wasDuplicate], $result->wasDuplicate ? 200 : 201);
        }

        $this->toast($result->wasDuplicate
            ? 'Already saved — this entry was not recorded twice.'
            : 'Saved '.Money::kobo($t->amount)->format(true).' '.mb_strtolower($t->type->label()).($t->status === TransactionStatus::Successful ? '' : ' as '.$t->status->value).'.');

        return back();
    }

    public function show(Transaction $transaction): Response
    {
        $this->authorizeView($transaction);
        $transaction->load(['provider:id,name,color', 'terminal.provider:id,name', 'outlet:id,name', 'membership.user:id,name', 'replacement:id,replaces_transaction_id,terminal_id', 'replacement.terminal.provider:id,name']);

        $entries = JournalEntry::query()
            ->where('source_type', 'Transaction')->where('source_id', $transaction->id)
            ->with(['lines' => fn ($q) => $q->with('account:id,name,type')])
            ->orderBy('created_at')->get()
            ->map(fn (JournalEntry $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'memo' => $e->memo,
                'created_at' => $e->created_at->toIso8601String(),
                'lines' => $e->lines->map(fn (JournalLine $l) => ['account' => $l->account->name, 'amount' => $l->amount])->values(),
            ]);

        $me = $this->tenancy->membershipOrFail();

        return Inertia::render('transactions/Show', [
            'transaction' => $this->present($transaction),
            'entries' => $entries,
            'canVoid' => $me->allows(Permission::VoidTransactions) && ! $transaction->isVoided(),
            'movedTo' => $transaction->replacement ? ['id' => $transaction->replacement->id, 'terminal' => $transaction->replacement->terminal->label()] : null,
            'moveTargets' => $this->moveTargets($transaction),
            'canResolve' => $transaction->status === TransactionStatus::Pending && ! $transaction->isVoided(),
        ]);
    }

    public function resolve(Request $request, Transaction $transaction, ResolvePendingTransaction $resolve): RedirectResponse
    {
        $this->authorizeView($transaction);
        $validated = $request->validate(['status' => ['required', Rule::in(['successful', 'failed'])]]);

        $resolved = $resolve->handle($transaction, TransactionStatus::from($validated['status']));
        $this->toast('Marked as '.$resolved->status->value.'.');

        return back();
    }

    public function void(Request $request, Transaction $transaction, VoidTransaction $void): RedirectResponse
    {
        $this->authorizeView($transaction);
        abort_unless($this->tenancy->membershipOrFail()->allows(Permission::VoidTransactions), 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        /** @var User $user */
        $user = $request->user();
        $void->handle($transaction, $user, $validated['reason']);
        $this->toast('Transaction voided. The money has been reversed.');

        return back();
    }

    public function move(Request $request, Transaction $transaction, MoveTransaction $move): RedirectResponse
    {
        $this->authorizeView($transaction);
        $validated = $request->validate([
            'terminal_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:150'],
        ]);

        $to = Terminal::query()->with('provider')->whereKey($validated['terminal_id'])->first();

        if ($to === null) {
            throw ValidationException::withMessages(['terminal_id' => 'Choose a terminal.']);
        }

        $moved = $move->handle($transaction, $this->tenancy->membershipOrFail(), $to, $validated['reason']);
        $this->toast("Moved to {$to->label()}. The wrong entry is kept, marked as moved.");

        return to_route('transactions.show', $moved);
    }

    /**
     * Terminals this person could move the entry to: same outlet, active, and (unless they
     * supervise the outlet) one of their own. Empty when the entry can't be moved.
     *
     * @return list<array{id: string, label: string}>
     */
    private function moveTargets(Transaction $transaction): array
    {
        $me = $this->tenancy->membershipOrFail();
        $supervises = $me->allows(Permission::VoidTransactions) && $me->coversOutlet($transaction->outlet_id);
        $today = CarbonImmutable::now($this->tenancy->businessOrFail()->timezone)->toDateString();
        $ownToday = $me->id === $transaction->membership_id && $transaction->business_date->toDateString() === $today;

        if ($transaction->isVoided() || (! $supervises && ! $ownToday)) {
            return [];
        }

        return array_values(Terminal::query()->active()->with('provider:id,name')
            ->where('outlet_id', $transaction->outlet_id)
            ->whereKeyNot($transaction->terminal_id)
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get()
            ->filter(fn (Terminal $t) => $supervises || $me->canUseTerminal($t))
            ->map(fn (Terminal $t) => ['id' => $t->id, 'label' => $t->label()])
            ->all());
    }

    /**
     * Agents see their own entries, supervisors their outlets, owners and accountants everything.
     *
     * @return Builder<Transaction>
     */
    private function visibleTransactions(): Builder
    {
        $me = $this->tenancy->membershipOrFail();

        return Transaction::query()
            ->when($me->role === Role::Agent, fn ($q) => $q->where('membership_id', $me->id))
            ->when($me->role === Role::Supervisor, fn ($q) => $q->whereIn('outlet_id', $me->outlets->pluck('id')));
    }

    private function authorizeView(Transaction $transaction): void
    {
        abort_unless($this->visibleTransactions()->whereKey($transaction->id)->exists(), 404);
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
     * @return array<string, mixed>
     */
    private function present(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'type' => $t->type->value,
            'type_label' => $t->type->label(),
            'flow' => $t->type->flow(),
            'amount' => $t->amount,
            'charge' => $t->charge,
            'provider_fee' => $t->provider_fee,
            'commission' => $t->commission,
            'profit' => $t->profit()->kobo,
            'status' => $t->status->value,
            'cash_paid_while_pending' => $t->cash_paid_while_pending,
            'voided' => $t->isVoided(),
            'void_reason' => $t->void_reason,
            'reference' => $t->reference,
            'customer_name' => $t->customer_name,
            'customer_phone' => $t->customer_phone,
            'note' => $t->note,
            'provider' => $t->relationLoaded('provider') ? ['name' => $t->provider->name, 'color' => $t->provider->color] : null,
            'terminal' => $t->relationLoaded('terminal') ? $t->terminal->label() : null,
            'replaces_id' => $t->replaces_transaction_id,
            'outlet' => $t->relationLoaded('outlet') ? $t->outlet->name : null,
            'agent' => $t->relationLoaded('membership') ? $t->membership->user->name : null,
            'occurred_at' => $t->occurred_at->toIso8601String(),
        ];
    }
}
