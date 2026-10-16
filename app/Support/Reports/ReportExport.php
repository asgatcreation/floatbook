<?php

namespace App\Support\Reports;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns report rows into a downloadable CSV or Excel file.
 *
 * Money is written in naira with two decimals, because that is what people paste into
 * their own spreadsheets; FloatBook keeps kobo internally.
 */
class ReportExport
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows  built before streaming: the stream runs after the request
     */
    public function download(string $format, string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $name = Str::slug($filename).($format === 'xlsx' ? '.xlsx' : '.csv');

        return $format === 'xlsx'
            ? $this->xlsx($name, $headers, $rows)
            : $this->csv($name, $headers, $rows);
    }

    public static function naira(int $kobo): string
    {
        return number_format($kobo / 100, 2, '.', '');
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    private function csv(string $name, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('Could not open the download stream.');
            }

            // Excel on Windows needs the byte-order mark to read ₦ and accented names.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    private function xlsx(string $name, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($headers));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
