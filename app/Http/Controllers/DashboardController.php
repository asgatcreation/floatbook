<?php

namespace App\Http\Controllers;

use App\Enums\FloatMovementStatus;
use App\Enums\LedgerAccountType;
use App\Enums\Permission;
use App\Enums\ReconciliationStatus;
use App\Enums\Role;
use App\Models\FloatMovement;
use App\Models\Invitation;
use App\Models\LedgerAccount;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Reconciliation;
use App\Models\ReversalCase;
use App\Models\StaffShortage;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Reports\DailyFigures;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-import-type FigureRow from DailyFigures
 *
 * The first screen after logging in. Owners, supervisors and accountants see the whole
 * picture for their outlets (today's money, float, what needs attention, the last week);
 * agents see their own day and their outlet's balances. The page refreshes itself every
 * 30 seconds.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentBusiness $tenancy, DailyFigures $figures): Response
    {
        /** @var User $user */
        $user = $request->user();
        $business = $tenancy->businessOrFail();
        $me = $tenancy->membershipOrFail();
        $today = CarbonImmutable::now($business->timezone)->startOfDay();
        $isAgent = $me->role === Role::Agent;

        $outlets = Outlet::query()->active()->orderBy('name')->get()->filter(fn (Outlet $o) => $me->coversOutlet($o))->values();
        $ids = array_values($outlets->map(fn (Outlet $o) => $o->id)->all());
        $rows = $figures->rows($ids, $today->subDays(6), $today);

        return Inertia::render('Dashboard', [
            'greetingName' => strtok($user->name, ' ') ?: $user->name,
            'businessName' => $business->name,
            'today' => $today->toDateString(),
            'view' => $isAgent ? 'agent' : 'business',
            'checklist' => $me->role === Role::Owner ? $this->checklist($user) : null,
            'totals' => $isAgent ? null : [
                'today' => $figures->total($rows->where('date', $today->toDateString())),
                'yesterday' => $figures->total($figures->rows($ids, $today->subDay(), $today->subDay())),
            ],
            // Profit per day for the last week, oldest first.
            'week' => $isAgent ? [] : collect(range(6, 0))->map(function (int $back) use ($today, $rows, $figures) {
                $date = $today->subDays($back)->toDateString();

                return ['date' => $date, ...$figures->total($rows->where('date', $date))];
            })->values(),
            'mine' => $isAgent ? $this->myDay($me, $today) : null,
            'outlets' => $this->outletCards($outlets, $rows, $today, $isAgent),
            'alerts' => $this->alerts($me, $ids, $today),
            'recent' => Transaction::query()
                ->whereIn('outlet_id', $ids)
                ->when($isAgent, fn ($q) => $q->where('membership_id', $me->id))
                ->with(['terminal.provider:id,name,color', 'outlet:id,name', 'membership.user:id,name'])
                ->latest('occurred_at')->limit(6)->get()
                ->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'type_label' => $t->type->label(),
                    'amount' => $t->amount,
                    'profit' => $t->profit()->kobo,
                    'status' => $t->status->value,
                    'voided' => $t->isVoided(),
                    'terminal' => $t->terminal->label(),
                    'color' => $t->terminal->provider->color,
                    'outlet' => $t->outlet->name,
                    'agent' => $t->membership->user->name,
                    'occurred_at' => $t->occurred_at->toIso8601String(),
                ]),
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return list<array{key: string, label: string, done: bool}>
     */
    private function checklist(User $user): array
    {
        return [
            ['key' => 'email', 'label' => 'Confirm your email', 'done' => $user->hasVerifiedEmail()],
            ['key' => 'outlet', 'label' => 'Add your first outlet', 'done' => Outlet::query()->exists()],
            ['key' => 'staff', 'label' => 'Invite your staff', 'done' => Membership::query()->count() > 1 || Invitation::query()->exists()],
            ['key' => 'two_factor', 'label' => 'Turn on two-factor authentication', 'done' => $user->hasConfirmedTwoFactor()],
        ];
    }

    /**
     * @return array{count: int, volume: int}
     */
    private function myDay(Membership $me, CarbonImmutable $today): array
    {
        $mine = Transaction::query()->counted()->where('membership_id', $me->id)->where('business_date', $today->toDateString());

        return ['count' => (clone $mine)->count(), 'volume' => (int) $mine->sum('amount')];
    }

    /**
     * @param  Collection<int, Outlet>  $outlets
     * @param  Collection<int, FigureRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function outletCards(Collection $outlets, Collection $rows, CarbonImmutable $today, bool $isAgent): array
    {
        $accounts = LedgerAccount::query()->whereIn('outlet_id', $outlets->pluck('id'))
            ->whereIn('type', [LedgerAccountType::CashDrawer, LedgerAccountType::ProviderWallet])
            ->get()->groupBy('outlet_id');

        $counts = Reconciliation::query()->whereIn('outlet_id', $outlets->pluck('id'))
            ->where('business_date', $today->toDateString())->pluck('status', 'outlet_id');

        return array_values($outlets->map(function (Outlet $o) use ($accounts, $counts, $rows, $today, $isAgent) {
            $mine = $accounts[$o->id] ?? collect();
            $day = $rows->first(fn (array $r) => $r['outlet_id'] === $o->id && $r['date'] === $today->toDateString());
            $status = $counts[$o->id] ?? null;

            return [
                'id' => $o->id,
                'name' => $o->name,
                'cash' => (int) $mine->where('type', LedgerAccountType::CashDrawer)->sum('balance'),
                'wallets' => (int) $mine->where('type', LedgerAccountType::ProviderWallet)->sum('balance'),
                'low' => $mine->filter(fn (LedgerAccount $a) => $a->isLow())->count(),
                'count' => $day['count'] ?? 0,
                'volume' => $day['volume'] ?? 0,
                'profit' => $isAgent ? null : ($day['profit'] ?? 0),
                'day_status' => $status instanceof ReconciliationStatus ? $status->value : $status,
            ];
        })->all());
    }

    /**
     * What needs someone's attention, most urgent first.
     *
     * @param  list<string>  $outletIds
     * @return list<array{tone: string, text: string, href: string}>
     */
    private function alerts(Membership $me, array $outletIds, CarbonImmutable $today): array
    {
        $alerts = [];
        $isAgent = $me->role === Role::Agent;

        $overdue = ReversalCase::query()->open()->whereIn('outlet_id', $outletIds)->where('due_at', '<', now())->count();

        if ($overdue > 0) {
            $alerts[] = ['tone' => 'loss', 'text' => $overdue === 1 ? '1 reversal is past its 72-hour target' : "{$overdue} reversals are past their 72-hour target", 'href' => route('reversals.index')];
        }

        if ($me->allows(Permission::ApproveReconciliation)) {
            Reconciliation::query()->whereIn('outlet_id', $outletIds)->where('status', ReconciliationStatus::Submitted)
                // Your own count needs someone else, unless you own the business.
                ->when($me->role !== Role::Owner, fn ($q) => $q->where('submitted_by', '!=', $me->id))
                ->with('outlet:id,name')->orderBy('business_date')->get()
                ->each(function (Reconciliation $r) use (&$alerts) {
                    $alerts[] = ['tone' => 'pending', 'text' => "{$r->outlet->name}'s count for {$r->business_date->format('j M')} is waiting for approval", 'href' => route('reconciliation.show', [$r->outlet_id, $r->business_date->toDateString()])];
                });
        }

        // Yesterday had activity but nobody closed it.
        $yesterday = $today->subDay()->toDateString();
        $counted = Reconciliation::query()->whereIn('outlet_id', $outletIds)->where('business_date', $yesterday)
            ->whereIn('status', ReconciliationStatus::closing())->pluck('outlet_id');
        Transaction::query()->whereIn('outlet_id', $outletIds)->where('business_date', $yesterday)
            ->whereNotIn('outlet_id', $counted)->distinct()->pluck('outlet_id')
            ->each(function (string $outletId) use (&$alerts, $yesterday) {
                $name = Outlet::query()->whereKey($outletId)->value('name');
                $alerts[] = ['tone' => 'warning', 'text' => "{$name} wasn't counted at the end of yesterday", 'href' => route('reconciliation.show', [$outletId, $yesterday])];
            });

        LedgerAccount::query()->whereIn('outlet_id', $outletIds)->whereNotNull('low_balance_threshold')
            ->whereColumn('balance', '<', 'low_balance_threshold')->with('outlet:id,name')->get()
            ->each(function (LedgerAccount $a) use (&$alerts) {
                $alerts[] = ['tone' => 'warning', 'text' => "{$a->name} is low: ".Money::kobo($a->balance)->format(true), 'href' => route('float.index')];
            });

        $owed = StaffShortage::query()->where('status', 'outstanding')
            ->whereHas('reconciliation', fn ($q) => $q->whereIn('outlet_id', $outletIds))
            ->when($isAgent, fn ($q) => $q->where('membership_id', $me->id))
            ->sum('amount');

        if ($owed > 0) {
            $alerts[] = ['tone' => 'warning', 'text' => ($isAgent ? 'You owe ' : 'Staff owe ').Money::kobo((int) $owed)->format(true).' from cash shortages', 'href' => route('reconciliation.index')];
        }

        if ($me->allows(Permission::ApproveFloat)) {
            $requests = FloatMovement::query()->where('status', FloatMovementStatus::Requested)->count();

            if ($requests > 0) {
                $alerts[] = ['tone' => 'pending', 'text' => $requests === 1 ? '1 float request is waiting for approval' : "{$requests} float requests are waiting for approval", 'href' => route('float.index')];
            }
        }

        return $alerts;
    }
}
