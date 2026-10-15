<?php

namespace App\Actions\Reconciliation;

use App\Enums\LedgerAccountType;
use App\Enums\Permission;
use App\Enums\ReconciliationStatus;
use App\Enums\Role;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Models\Reconciliation;
use App\Models\ReconciliationWallet;
use App\Models\StaffShortage;
use App\Support\Audit;
use App\Support\Ledger\Accounts;
use App\Support\Ledger\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * End-of-day reconciliation for an outlet.
 *
 * "Expected" is what the books say the drawer and each wallet held at the end of the day:
 * the sum of every ledger line on that account up to and including that business date.
 * Approving posts the difference (counted − expected) against "Cash shortages and excess",
 * so after approval the books match what was actually in the shop.
 */
class Reconciliations
{
    /** Naira notes in circulation, largest first. Coins are entered as one amount. */
    public const NOTES = [1000, 500, 200, 100, 50, 20, 10, 5];

    public function __construct(
        private Accounts $accounts,
        private Ledger $ledger,
        private Audit $audit,
    ) {}

    /**
     * What the books say each account held at the close of the day.
     *
     * @return array{cash: int, wallets: Collection<int, array{wallet: OutletWallet, expected: int}>}
     */
    public function expected(Outlet $outlet, CarbonImmutable $date): array
    {
        $cash = $this->accounts->cash($outlet);
        $wallets = $this->walletsToCount($outlet);
        $accountIds = [$cash->id, ...$wallets->map(fn (OutletWallet $w) => $w->ledger_account_id)->all()];

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            ->where('journal_entries.business_date', '<=', $date->toDateString())
            ->groupBy('journal_lines.ledger_account_id')
            ->selectRaw('journal_lines.ledger_account_id as account_id, sum(journal_lines.amount) as total')
            ->pluck('total', 'account_id');

        return [
            'cash' => (int) ($sums[$cash->id] ?? 0),
            'wallets' => $wallets->map(fn (OutletWallet $w) => ['wallet' => $w, 'expected' => (int) ($sums[$w->ledger_account_id] ?? 0)])->values(),
        ];
    }

    /**
     * Submit a count. This closes the day for the outlet until it is sent back or reopened.
     *
     * @param  array<int|string, int>  $denominations  note value (or "coins", in kobo) => count
     * @param  array<string, int>  $walletActuals  outlet_wallet_id => kobo shown on the terminal
     */
    public function submit(Membership $by, Outlet $outlet, CarbonImmutable $date, array $denominations, array $walletActuals, ?string $note): Reconciliation
    {
        if (! $by->coversOutlet($outlet) || ! $by->allows(Permission::RecordTransactions)) {
            throw ValidationException::withMessages(['outlet_id' => 'You can only count your own outlets.']);
        }

        $today = CarbonImmutable::now($outlet->business->timezone)->startOfDay();

        if ($date->isAfter($today) || $date->isBefore($today->subDays(30))) {
            throw ValidationException::withMessages(['date' => 'Count today or one of the last 30 days.']);
        }

        return DB::transaction(function () use ($by, $outlet, $date, $denominations, $walletActuals, $note): Reconciliation {
            $existing = Reconciliation::query()->where('outlet_id', $outlet->id)
                ->where('business_date', $date->toDateString())->lockForUpdate()->first();

            if ($existing !== null && $existing->status->closesDay()) {
                throw ValidationException::withMessages(['date' => 'This day has already been counted.']);
            }

            $expected = $this->expected($outlet, $date);
            $counted = $this->countedCash($denominations);
            $cashVariance = $counted - $expected['cash'];

            $walletRows = $expected['wallets']->map(function (array $row) use ($walletActuals) {
                if (! array_key_exists($row['wallet']->id, $walletActuals)) {
                    throw ValidationException::withMessages(["wallets.{$row['wallet']->id}" => 'Enter the balance shown on the terminal.']);
                }

                $actual = (int) $walletActuals[$row['wallet']->id];

                return ['wallet' => $row['wallet'], 'expected' => $row['expected'], 'actual' => $actual, 'variance' => $actual - $row['expected']];
            });

            $balances = $cashVariance === 0 && $walletRows->every(fn (array $r) => $r['variance'] === 0);

            if (! $balances && blank($note)) {
                throw ValidationException::withMessages(['note' => "It doesn't match the books. Say what you think happened."]);
            }

            $reconciliation = $existing ?? new Reconciliation(['outlet_id' => $outlet->id, 'business_date' => $date->toDateString()]);
            $reconciliation->fill([
                'status' => ReconciliationStatus::Submitted,
                'expected_cash' => $expected['cash'],
                'counted_cash' => $counted,
                'cash_variance' => $cashVariance,
                'denominations' => $this->cleanDenominations($denominations),
                'note' => $note,
                'submitted_by' => $by->id,
                'submitted_at' => now(),
            ]);
            $reconciliation->forceFill(['decided_by' => null, 'decided_at' => null, 'decision_note' => null])->save();

            $reconciliation->wallets()->delete();

            foreach ($walletRows as $row) {
                ReconciliationWallet::query()->create([
                    'reconciliation_id' => $reconciliation->id,
                    'outlet_wallet_id' => $row['wallet']->id,
                    'expected' => $row['expected'],
                    'actual' => $row['actual'],
                    'variance' => $row['variance'],
                ]);
            }

            $this->audit->record('reconciliation.submitted', $reconciliation, after: [
                'date' => $date->toDateString(), 'expected_cash' => $expected['cash'], 'counted_cash' => $counted,
                'cash_variance' => $cashVariance, 'wallet_variance' => $walletRows->sum('variance'),
            ]);

            return $reconciliation->load('wallets');
        });
    }

    /**
     * Approve a count: post any difference to the books and, for a shortage, record who
     * is responsible for how much.
     *
     * @param  array<string, int>  $shortages  membership_id => kobo
     */
    public function approve(Membership $by, Reconciliation $reconciliation, array $shortages, ?string $note): Reconciliation
    {
        return DB::transaction(function () use ($by, $reconciliation, $shortages, $note): Reconciliation {
            $rec = Reconciliation::query()->with(['outlet.business', 'wallets.wallet'])->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            $this->authorizeDecision($by, $rec);

            // Nothing may have changed the books for this day since the count.
            $expected = $this->expected($rec->outlet, $rec->business_date);

            if ($expected['cash'] !== $rec->expected_cash
                || $rec->wallets->contains(fn (ReconciliationWallet $w) => $w->expected !== ($expected['wallets']->firstWhere('wallet.id', $w->outlet_wallet_id)['expected'] ?? $w->expected))) {
                throw ValidationException::withMessages(['note' => 'The books for this day changed after the count. Send it back for a recount.']);
            }

            $shortages = array_filter(array_map('intval', $shortages), fn (int $kobo) => $kobo > 0);
            $shortfall = max(0, -$rec->cash_variance);

            if (array_sum($shortages) > $shortfall) {
                throw ValidationException::withMessages(['shortages' => 'You can assign at most the cash shortage, '.number_format($shortfall / 100, 2).'.']);
            }

            $entry = $this->postVariance($rec);

            foreach ($shortages as $membershipId => $kobo) {
                $member = Membership::query()->whereKey((string) $membershipId)->first();

                if ($member === null) {
                    throw ValidationException::withMessages(['shortages' => 'Choose people from this business.']);
                }

                StaffShortage::query()->create([
                    'reconciliation_id' => $rec->id,
                    'membership_id' => $member->id,
                    'amount' => $kobo,
                    'status' => 'outstanding',
                ]);
            }

            $rec->forceFill([
                'status' => ReconciliationStatus::Approved,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'variance_entry_id' => $entry?->id,
            ])->save();

            $this->audit->record('reconciliation.approved', $rec, after: [
                'date' => $rec->business_date->toDateString(), 'cash_variance' => $rec->cash_variance,
                'shortages' => $shortages, 'note' => $note,
            ]);

            return $rec;
        });
    }

    /**
     * Send a count back for a recount. The day opens again.
     */
    public function sendBack(Membership $by, Reconciliation $reconciliation, string $note): Reconciliation
    {
        return DB::transaction(function () use ($by, $reconciliation, $note): Reconciliation {
            $rec = Reconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            $this->authorizeDecision($by, $rec, allowSelf: true);

            $rec->forceFill([
                'status' => ReconciliationStatus::Returned,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record('reconciliation.returned', $rec, after: ['note' => $note]);

            return $rec;
        });
    }

    /**
     * Owner only: undo an approved day. The variance entry is reversed (dated that same
     * day) and staff shortages from it are cancelled, so it can be counted again.
     */
    public function reopen(Membership $by, Reconciliation $reconciliation, string $reason): Reconciliation
    {
        if (! $by->allows(Permission::ReopenDay)) {
            throw ValidationException::withMessages(['reason' => 'Only the owner can reopen a day.']);
        }

        return DB::transaction(function () use ($by, $reconciliation, $reason): Reconciliation {
            $rec = Reconciliation::query()->with(['varianceEntry.lines', 'shortages'])->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();

            if ($rec->status !== ReconciliationStatus::Approved) {
                throw ValidationException::withMessages(['reason' => 'Only an approved day can be reopened.']);
            }

            $later = Reconciliation::query()->where('outlet_id', $rec->outlet_id)
                ->where('business_date', '>', $rec->business_date->toDateString())
                ->whereIn('status', ReconciliationStatus::closing())->exists();

            if ($later) {
                throw ValidationException::withMessages(['reason' => 'A later day has been counted. Reopen that one first.']);
            }

            if ($rec->shortages->contains('status', 'recovered')) {
                throw ValidationException::withMessages(['reason' => 'Money for a shortage on this day has already been paid back, so it cannot be reopened.']);
            }

            if ($rec->varianceEntry !== null) {
                $this->ledger->post(
                    'reconciliation_reopen',
                    $rec->varianceEntry->lines->map(fn (JournalLine $l) => [$l->ledger_account_id, -$l->amount])->all(),
                    $rec->outlet_id, $rec, 'Day reopened: '.$reason,
                    CarbonImmutable::parse($rec->varianceEntry->occurred_at), $rec->varianceEntry->id,
                );
            }

            $rec->shortages()->where('status', 'outstanding')->update(['status' => 'cancelled']);
            $rec->forceFill(['status' => ReconciliationStatus::Reopened, 'variance_entry_id' => null])->save();

            DB::table('reconciliation_reopens')->insert([
                'id' => strtolower((string) Str::ulid()),
                'business_id' => $rec->business_id,
                'reconciliation_id' => $rec->id,
                'reopened_by' => $by->id,
                'reason' => $reason,
            ]);

            $this->audit->record('reconciliation.reopened', $rec, after: ['reason' => $reason]);

            return $rec;
        });
    }

    /**
     * The staff member paid the shortage back: the cash goes into today's drawer.
     */
    public function recover(Membership $by, StaffShortage $shortage): StaffShortage
    {
        return $this->settle($by, $shortage, 'recovered');
    }

    public function writeOff(Membership $by, StaffShortage $shortage): StaffShortage
    {
        return $this->settle($by, $shortage, 'written_off');
    }

    /**
     * @return Collection<int, OutletWallet>
     */
    public function walletsToCount(Outlet $outlet): Collection
    {
        return OutletWallet::query()->where('outlet_id', $outlet->id)
            ->with(['provider', 'account', 'terminals' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->get()
            // Every balance a working terminal pays into, plus any that still hold money.
            ->filter(fn (OutletWallet $w) => $w->terminals->isNotEmpty() || $w->account->balance !== 0)
            ->sortBy(fn (OutletWallet $w) => [$w->provider->sort_order, $w->label()])
            ->values();
    }

    /**
     * @param  array<int|string, int>  $denominations  note value => count, plus "coins" in kobo
     */
    public function countedCash(array $denominations): int
    {
        $total = (int) ($denominations['coins'] ?? 0);

        foreach (self::NOTES as $note) {
            $total += max(0, (int) ($denominations[$note] ?? 0)) * $note * 100;
        }

        return $total;
    }

    /**
     * @param  array<int|string, int>  $denominations
     * @return array<int|string, int> PHP stores the note values as integer keys
     */
    private function cleanDenominations(array $denominations): array
    {
        $clean = [];

        foreach (self::NOTES as $note) {
            $clean[$note] = max(0, (int) ($denominations[$note] ?? 0));
        }

        $clean['coins'] = max(0, (int) ($denominations['coins'] ?? 0));

        return $clean;
    }

    private function authorizeDecision(Membership $by, Reconciliation $rec, bool $allowSelf = false): void
    {
        if ($rec->status !== ReconciliationStatus::Submitted) {
            throw ValidationException::withMessages(['note' => 'This count has already been decided.']);
        }

        if (! $by->allows(Permission::ApproveReconciliation) || ! $by->coversOutlet($rec->outlet_id)) {
            throw ValidationException::withMessages(['note' => 'Only a supervisor of this outlet or the owner can decide on a count.']);
        }

        // A second pair of eyes: you can't approve your own count, unless you own the business.
        if (! $allowSelf && $rec->submitted_by === $by->id && $by->role !== Role::Owner) {
            throw ValidationException::withMessages(['note' => 'Someone else has to approve your own count.']);
        }
    }

    private function postVariance(Reconciliation $rec): ?JournalEntry
    {
        $variance = $this->accounts->forOutlet($rec->outlet, LedgerAccountType::CashVariance);
        $lines = [];

        if ($rec->cash_variance !== 0) {
            $lines[] = [$this->accounts->cash($rec->outlet), $rec->cash_variance];
            $lines[] = [$variance, -$rec->cash_variance];
        }

        foreach ($rec->wallets as $w) {
            if ($w->variance !== 0) {
                $lines[] = [$w->wallet->ledger_account_id, $w->variance];
                $lines[] = [$variance, -$w->variance];
            }
        }

        if ($lines === []) {
            return null;
        }

        // Dated the last moment of the counted day, so it belongs to that day's books.
        $endOfDay = CarbonImmutable::parse($rec->business_date->toDateString(), $rec->outlet->business->timezone)->endOfDay();

        return $this->ledger->post('reconciliation', $lines, $rec->outlet_id, $rec, 'End-of-day count', $endOfDay);
    }

    private function settle(Membership $by, StaffShortage $shortage, string $status): StaffShortage
    {
        if (! $by->allows(Permission::ApproveReconciliation)) {
            throw ValidationException::withMessages(['shortage' => 'Only a supervisor or the owner can settle a shortage.']);
        }

        return DB::transaction(function () use ($by, $shortage, $status): StaffShortage {
            $fresh = StaffShortage::query()->with('reconciliation.outlet')->whereKey($shortage->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'outstanding') {
                throw ValidationException::withMessages(['shortage' => 'This shortage has already been settled.']);
            }

            if (! $by->coversOutlet($fresh->reconciliation->outlet_id)) {
                throw ValidationException::withMessages(['shortage' => 'This shortage is at an outlet you do not cover.']);
            }

            $entry = null;

            if ($status === 'recovered') {
                $outlet = $fresh->reconciliation->outlet;
                $entry = $this->ledger->post('shortage_recovered', [
                    [$this->accounts->cash($outlet), $fresh->amount],
                    [$this->accounts->forOutlet($outlet, LedgerAccountType::CashVariance), -$fresh->amount],
                ], $outlet->id, $fresh, 'Shortage paid back');
            }

            $fresh->forceFill([
                'status' => $status,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
                'recovery_entry_id' => $entry?->id,
            ])->save();

            $this->audit->record("shortage.{$status}", $fresh, after: ['amount' => $fresh->amount]);

            return $fresh;
        });
    }
}
