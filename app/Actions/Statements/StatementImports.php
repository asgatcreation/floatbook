<?php

namespace App\Actions\Statements;

use App\Actions\Transactions\RecordTransaction;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\Provider;
use App\Models\StatementImport;
use App\Models\StatementImportRow;
use App\Models\Transaction;
use App\Support\Audit;
use App\Support\Money\Money;
use App\Support\Statements\StatementAdapter;
use App\Support\Statements\StatementAdapters;
use App\Support\Statements\StatementReader;
use App\Support\Statements\StatementValues;
use App\Support\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Upload → map columns → match against FloatBook → add what's missing.
 *
 * Matching for each statement row (same outlet and provider):
 *   1. same reference           → matched (or amount_mismatch if the amounts differ)
 *   2. same amount within 15 min → matched
 *   3. otherwise                → missing_in_floatbook
 * Rows already seen in an earlier import, or twice in this file, are duplicates.
 */
class StatementImports
{
    private const TIME_WINDOW_MINUTES = 15;

    public function __construct(
        private CurrentBusiness $tenancy,
        private StatementReader $reader,
        private StatementValues $values,
        private StatementAdapters $adapters,
        private Audit $audit,
    ) {}

    public function upload(Membership $by, Outlet $outlet, Provider $provider, UploadedFile $file): StatementImport
    {
        try {
            $data = $this->reader->read($file->getRealPath(), $file->getClientOriginalExtension(), StatementImport::MAX_ROWS);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => "We couldn't read that file. Export it again as CSV or Excel (.xlsx)."]);
        }

        if ($data['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'The file has a header row but no transactions.']);
        }

        return DB::transaction(function () use ($by, $outlet, $provider, $file, $data): StatementImport {
            $import = StatementImport::query()->create([
                'outlet_id' => $outlet->id,
                'provider_id' => $provider->id,
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 200),
                'status' => 'uploaded',
                'headers' => $data['headers'],
                'column_mapping' => $this->adapter($provider)->suggestMapping($data['headers']),
                'row_count' => count($data['rows']),
                'uploaded_by' => $by->user_id,
            ]);

            foreach (array_chunk($data['rows'], 500, true) as $chunk) {
                StatementImportRow::query()->insert(array_map(fn (array $raw, int $i) => [
                    'business_id' => $import->business_id,
                    'statement_import_id' => $import->id,
                    'row_number' => $i + 2, // spreadsheet row number (row 1 is the header)
                    'raw' => json_encode($raw),
                    'row_hash' => hash('sha256', $import->id.$i),
                ], $chunk, array_keys($chunk)));
            }

            $this->audit->record('statement.uploaded', $import, after: [
                'file' => $import->original_filename, 'rows' => $import->row_count, 'outlet' => $outlet->name, 'provider' => $provider->name,
            ]);

            return $import;
        });
    }

    /**
     * Save the column mapping, then read and match every row.
     *
     * @param  array<string, string|null>  $mapping
     */
    public function analyse(StatementImport $import, array $mapping): StatementImport
    {
        if ($import->status === 'committed') {
            throw ValidationException::withMessages(['mapping' => 'This statement has already been imported.']);
        }

        foreach (StatementAdapter::REQUIRED as $field) {
            if (empty($mapping[$field]) || ! in_array($mapping[$field], $import->headers, true)) {
                throw ValidationException::withMessages(["mapping.{$field}" => 'Choose the column that holds the '.$field.'.']);
            }
        }

        $timezone = $this->tenancy->businessOrFail()->timezone;
        $rows = $import->rows()->get();

        // Everything this outlet/provider recorded around the statement's dates, loaded once.
        $parsed = $rows->map(fn (StatementImportRow $row) => $this->parse($row, $mapping, $timezone));
        $times = $parsed->pluck('occurred_at')->filter();
        $candidates = $times->isEmpty() ? collect() : Transaction::query()
            ->where('outlet_id', $import->outlet_id)
            ->where('provider_id', $import->provider_id)
            ->whereNull('voided_at')
            ->whereBetween('occurred_at', [$times->min()->subDay(), $times->max()->addDay()])
            ->get();

        $earlierHashes = StatementImportRow::query()
            ->where('statement_import_id', '!=', $import->id)
            ->whereIn('match_status', ['matched', 'missing_in_floatbook', 'amount_mismatch', 'status_mismatch', 'provider_failed', 'created'])
            ->whereHas('import', fn ($q) => $q->where('status', 'committed'))
            ->pluck('row_hash')
            ->flip();

        DB::transaction(function () use ($import, $rows, $parsed, $candidates, $earlierHashes, $mapping): void {
            $usedTransactions = [];
            $seenHashes = [];

            foreach ($rows as $i => $row) {
                $values = $parsed[$i];
                $result = $this->match($values, $candidates, $usedTransactions, $seenHashes, $earlierHashes);

                if ($result['transaction_id'] !== null) {
                    $usedTransactions[$result['transaction_id']] = true;
                }

                $seenHashes[$values['hash']] = true;

                $row->forceFill([
                    'reference' => $values['reference'],
                    'amount' => $values['amount'],
                    'occurred_at' => $values['occurred_at'],
                    'type' => $values['type']?->value,
                    'provider_status' => $values['status'],
                    'row_hash' => $values['hash'],
                    'match_status' => $result['status'],
                    'problem' => $result['problem'],
                    'matched_transaction_id' => $result['transaction_id'],
                ])->save();
            }

            $import->forceFill(['column_mapping' => $mapping, 'status' => 'mapped'])->save();
        });

        return $import;
    }

    /**
     * Record the chosen "missing" rows as transactions. Each row's idempotency key comes
     * from the row itself, so importing the same row twice can never double it.
     *
     * @param  list<int>  $rowIds
     */
    public function commit(Membership $by, StatementImport $import, array $rowIds, TransactionType $defaultType, RecordTransaction $record): int
    {
        if ($import->status !== 'mapped') {
            throw ValidationException::withMessages(['rows' => 'Check the statement against FloatBook first.']);
        }

        $created = 0;

        DB::transaction(function () use ($by, $import, $rowIds, $defaultType, $record, &$created): void {
            $rows = $import->rows()->whereIn('id', $rowIds)->where('match_status', 'missing_in_floatbook')->get();

            foreach ($rows as $row) {
                $result = $record->handle($by, [
                    'idempotency_key' => $this->uuidFromHash($row->row_hash),
                    'outlet_id' => $import->outlet_id,
                    'provider_id' => $import->provider_id,
                    'type' => $row->type !== null ? TransactionType::from($row->type) : $defaultType,
                    'amount' => Money::kobo((int) $row->amount),
                    'status' => TransactionStatus::Successful,
                    'reference' => $row->reference,
                    'note' => 'Imported from '.$import->original_filename.' (row '.$row->row_number.')',
                    'source' => 'import',
                    'client_recorded_at' => $row->occurred_at,
                ]);

                $row->forceFill(['match_status' => 'created', 'matched_transaction_id' => $result->transaction->id])->save();
                $created++;
            }

            $import->forceFill(['status' => 'committed', 'committed_at' => now()])->save();

            $this->audit->record('statement.committed', $import, after: ['created' => $created, 'file' => $import->original_filename]);
        });

        return $created;
    }

    /**
     * Transactions recorded at the counter for the statement's period that the statement doesn't mention.
     *
     * @return Collection<int, Transaction>
     */
    public function missingFromStatement(StatementImport $import): Collection
    {
        $rows = $import->rows()->whereNotNull('occurred_at')->get(['occurred_at', 'matched_transaction_id']);

        if ($rows->isEmpty()) {
            return collect();
        }

        return Transaction::query()
            ->where('outlet_id', $import->outlet_id)
            ->where('provider_id', $import->provider_id)
            ->whereNull('voided_at')
            ->where('status', TransactionStatus::Successful)
            ->whereBetween('occurred_at', [$rows->min('occurred_at'), $rows->max('occurred_at')])
            ->whereNotIn('id', $rows->pluck('matched_transaction_id')->filter()->all())
            ->orderBy('occurred_at')
            ->get()
            ->values();
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return array{reference: ?string, amount: ?int, occurred_at: ?CarbonImmutable, type: ?TransactionType, status: ?string, hash: string, problem: ?string}
     */
    private function parse(StatementImportRow $row, array $mapping, string $timezone): array
    {
        $cell = fn (string $field): string => isset($mapping[$field]) ? trim((string) ($row->raw[$mapping[$field]] ?? '')) : '';

        $reference = $cell('reference') !== '' ? mb_substr($cell('reference'), 0, 80) : null;
        $amount = $this->values->amount($cell('amount'));
        $occurredAt = $this->values->date($cell('date'), $timezone);
        $status = $cell('status') !== '' ? mb_substr($cell('status'), 0, 40) : null;

        $problem = match (true) {
            $amount === null => 'Amount "'.$cell('amount').'" could not be read',
            $occurredAt === null => 'Date "'.$cell('date').'" could not be read',
            default => null,
        };

        $import = $row->statement_import_id;
        $hash = hash('sha256', implode('|', [
            $this->tenancy->id(), $this->importScope($import), $reference ?? '', $amount ?? '', $occurredAt?->format('Y-m-d H:i') ?? '',
        ]));

        return [
            'reference' => $reference,
            'amount' => $amount,
            'occurred_at' => $occurredAt,
            'type' => $this->values->type($cell('type')),
            'status' => $status,
            'hash' => $hash,
            'problem' => $problem,
        ];
    }

    /**
     * @param  array{reference: ?string, amount: ?int, occurred_at: ?CarbonImmutable, status: ?string, hash: string, problem: ?string}  $row
     * @param  Collection<int, Transaction>  $candidates
     * @param  array<string, bool>  $used
     * @param  array<string, bool>  $seen
     * @param  Collection<string, int>  $earlier
     * @return array{status: string, problem: ?string, transaction_id: ?string}
     */
    private function match(array $row, Collection $candidates, array $used, array $seen, Collection $earlier): array
    {
        if ($row['problem'] !== null) {
            return ['status' => 'invalid', 'problem' => $row['problem'], 'transaction_id' => null];
        }

        if (isset($seen[$row['hash']])) {
            return ['status' => 'duplicate', 'problem' => 'Appears more than once in this file', 'transaction_id' => null];
        }

        if ($earlier->has($row['hash'])) {
            return ['status' => 'duplicate', 'problem' => 'Already imported from an earlier statement', 'transaction_id' => null];
        }

        $available = $candidates->reject(fn (Transaction $t) => isset($used[$t->id]));

        $byReference = $row['reference'] !== null
            ? $available->first(fn (Transaction $t) => $t->reference !== null && strcasecmp($t->reference, $row['reference']) === 0)
            : null;

        $byAmountAndTime = $byReference ?? $available
            ->filter(fn (Transaction $t) => $t->amount === $row['amount']
                && abs($t->occurred_at->diffInMinutes($row['occurred_at'])) <= self::TIME_WINDOW_MINUTES)
            ->sortBy(fn (Transaction $t) => abs($t->occurred_at->diffInSeconds($row['occurred_at'])))
            ->first();

        $match = $byReference ?? $byAmountAndTime;
        $providerFailed = $row['status'] !== null && $this->values->isFailed($row['status']);

        if ($match === null) {
            return $providerFailed
                ? ['status' => 'provider_failed', 'problem' => 'The provider marked this as '.$row['status'], 'transaction_id' => null]
                : ['status' => 'missing_in_floatbook', 'problem' => null, 'transaction_id' => null];
        }

        if ($match->amount !== $row['amount']) {
            return ['status' => 'amount_mismatch', 'transaction_id' => $match->id,
                'problem' => 'FloatBook has '.Money::kobo($match->amount)->format(true).', the statement has '.Money::kobo((int) $row['amount'])->format(true)];
        }

        if ($providerFailed && $match->status === TransactionStatus::Successful) {
            return ['status' => 'status_mismatch', 'transaction_id' => $match->id,
                'problem' => 'Recorded as successful, but the provider says '.$row['status']];
        }

        return ['status' => 'matched', 'problem' => null, 'transaction_id' => $match->id];
    }

    /**
     * Rows from the same outlet and provider share a duplicate-detection scope across imports.
     */
    private function importScope(string $importId): string
    {
        static $scopes = [];

        return $scopes[$importId] ??= (function () use ($importId) {
            $import = StatementImport::query()->find($importId);

            return $import ? $import->outlet_id.'|'.$import->provider_id : $importId;
        })();
    }

    private function adapter(Provider $provider): StatementAdapter
    {
        return $this->adapters->for($provider->slug);
    }

    private function uuidFromHash(string $hash): string
    {
        $hex = substr($hash, 0, 32);

        return sprintf('%s-%s-5%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), '8'.substr($hex, 17, 3), substr($hex, 20, 12));
    }
}
