<?php

namespace App\Support\Ledger;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Tenancy\TenancyViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way money moves in FloatBook.
 *
 * Every posting is one journal entry whose lines sum to zero. Inside a single database
 * transaction it locks the affected accounts (always in id order, so two postings can
 * never deadlock each other), writes the entry and all its lines in one statement (the
 * database re-checks that they balance), and updates each account's cached balance.
 */
class Ledger
{
    public function __construct(private CurrentBusiness $tenancy) {}

    /**
     * @param  array<int, array{0: LedgerAccount|string, 1: int}>  $lines  [account, kobo] pairs; debit > 0, credit < 0.
     */
    public function post(
        string $type,
        array $lines,
        ?string $outletId = null,
        ?Model $source = null,
        ?string $memo = null,
        ?CarbonImmutable $occurredAt = null,
        ?string $reversesEntryId = null,
    ): JournalEntry {
        $business = $this->tenancy->businessOrFail();
        $amounts = $this->combine($lines);

        if ($amounts === []) {
            throw new UnbalancedEntry('A journal entry needs at least one non-zero line.');
        }

        if (array_sum($amounts) !== 0) {
            throw new UnbalancedEntry('Journal entry does not balance: off by '.array_sum($amounts).' kobo.');
        }

        $occurredAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($business, $type, $amounts, $outletId, $source, $memo, $occurredAt, $reversesEntryId): JournalEntry {
            $ids = array_keys($amounts);
            sort($ids);

            // Lock in a fixed order. Tenant scoping means another business's account is simply not found.
            $locked = LedgerAccount::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();

            if (count($locked) !== count($ids)) {
                throw new TenancyViolation('A journal line refers to an account outside the current business.');
            }

            $entry = JournalEntry::query()->create([
                'outlet_id' => $outletId,
                'type' => $type,
                'source_type' => $source ? class_basename($source) : null,
                'source_id' => $source ? (string) $source->getKey() : null,
                'memo' => $memo,
                'posted_by' => auth()->id(),
                'reverses_entry_id' => $reversesEntryId,
                'occurred_at' => $occurredAt,
                'business_date' => $occurredAt->setTimezone($business->timezone)->toDateString(),
            ]);

            JournalLine::query()->insert(array_map(fn (string $accountId) => [
                'business_id' => $business->id,
                'journal_entry_id' => $entry->id,
                'ledger_account_id' => $accountId,
                'amount' => $amounts[$accountId],
            ], $ids));

            foreach ($ids as $accountId) {
                LedgerAccount::query()->whereKey($accountId)->increment('balance', $amounts[$accountId]);
            }

            return $entry;
        });
    }

    /**
     * Cancel an entry's effect with an equal and opposite entry. The original stays in the books.
     */
    public function reverse(JournalEntry $entry, string $memo, ?Model $source = null): JournalEntry
    {
        $alreadyReversed = JournalEntry::query()->where('reverses_entry_id', $entry->id)->exists();

        if ($alreadyReversed) {
            throw new UnbalancedEntry('This entry has already been reversed.');
        }

        $lines = $entry->lines()->get()
            ->map(fn (JournalLine $line) => [$line->ledger_account_id, -$line->amount])
            ->all();

        return $this->post('reversal', $lines, $entry->outlet_id, $source, $memo, CarbonImmutable::now(), $entry->id);
    }

    /**
     * Sum lines per account and drop zero results.
     *
     * @param  array<int, array{0: LedgerAccount|string, 1: int}>  $lines
     * @return array<string, int>
     */
    private function combine(array $lines): array
    {
        $amounts = [];

        foreach ($lines as [$account, $amount]) {
            $id = $account instanceof LedgerAccount ? $account->id : $account;
            $amounts[$id] = ($amounts[$id] ?? 0) + $amount;
        }

        return array_filter($amounts, fn (int $amount) => $amount !== 0);
    }
}
