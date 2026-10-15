<?php

namespace App\Support\Statements;

use Illuminate\Support\Str;

/**
 * Suggests a column mapping by comparing header names with known names for each field.
 *
 * The name lists cover the column titles commonly seen in Nigerian agent-banking exports.
 * They are a convenience only: the person importing always confirms the mapping, so a
 * provider changing its export layout never silently breaks an import.
 */
class HeaderMatchingAdapter implements StatementAdapter
{
    /** @var array<string, list<string>> */
    private const COMMON = [
        'date' => ['date', 'transaction date', 'transaction time', 'date time', 'datetime', 'created at', 'created', 'time', 'value date', 'posted date'],
        'amount' => ['amount', 'transaction amount', 'amount (ngn)', 'amount ngn', 'value', 'debit amount', 'credit amount', 'txn amount'],
        'reference' => ['reference', 'rrn', 'ref', 'transaction reference', 'transaction ref', 'reference number', 'session id', 'stan', 'transaction id', 'txn ref'],
        'type' => ['type', 'transaction type', 'description', 'narration', 'category', 'service', 'details'],
        'status' => ['status', 'transaction status', 'state', 'response', 'response message'],
    ];

    /**
     * @param  array<string, list<string>>  $extra  provider-specific names, tried first
     */
    public function __construct(private array $extra = []) {}

    public function suggestMapping(array $headers): array
    {
        $normalised = array_map(fn (string $h) => $this->normalise($h), $headers);
        $taken = [];
        $mapping = [];

        foreach (self::FIELDS as $field) {
            $mapping[$field] = null;
            $candidates = [...($this->extra[$field] ?? []), ...self::COMMON[$field]];

            foreach ($candidates as $candidate) {
                $index = array_search($this->normalise($candidate), $normalised, true);

                if ($index !== false && ! in_array($index, $taken, true)) {
                    $mapping[$field] = $headers[$index];
                    $taken[] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalise(string $header): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(str_replace(['_', '-', '.'], ' ', $header))) ?? '');
    }
}
