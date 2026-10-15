<?php

namespace App\Support\Statements;

use DateTimeInterface;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of a CSV or Excel (.xlsx) statement into a header row plus rows
 * keyed by header. Streams the file, so large exports don't exhaust memory.
 */
class StatementReader
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function read(string $path, string $extension, int $maxRows): array
    {
        $reader = $this->readerFor($extension);
        $reader->open($path);

        $headers = null;
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_values(array_map(fn ($v) => $this->stringify($v), $row->toArray()));

                    if ($this->isBlank($values)) {
                        continue;
                    }

                    // The first non-blank row is the header row.
                    if ($headers === null) {
                        $headers = $this->uniqueHeaders($values);

                        continue;
                    }

                    if (count($rows) >= $maxRows) {
                        throw new InvalidArgumentException("This file has more than {$maxRows} rows. Split it into smaller date ranges.");
                    }

                    $assoc = [];
                    foreach ($headers as $i => $header) {
                        $assoc[$header] = $values[$i] ?? '';
                    }
                    $rows[] = $assoc;
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        if ($headers === null) {
            throw new InvalidArgumentException('The file is empty.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function readerFor(string $extension): CsvReader|XlsxReader
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => new CsvReader,
            'xlsx' => new XlsxReader,
            default => throw new InvalidArgumentException('Upload a .csv or .xlsx file.'),
        };
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_float($value) => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => trim((string) $value),
        };
    }

    /**
     * @param  list<string>  $values
     */
    private function isBlank(array $values): bool
    {
        return implode('', $values) === '';
    }

    /**
     * Empty headers become "Column 3"; repeated ones get a number, so every column has a unique name.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueHeaders(array $values): array
    {
        $seen = [];

        return array_map(function (string $header, int $i) use (&$seen) {
            $name = $header !== '' ? $header : 'Column '.($i + 1);
            $seen[$name] = ($seen[$name] ?? 0) + 1;

            return $seen[$name] > 1 ? "{$name} ({$seen[$name]})" : $name;
        }, $values, array_keys($values));
    }
}
