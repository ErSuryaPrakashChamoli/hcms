<?php

namespace App\Domain\Employment\Imports\Workbooks;

use DateInterval;
use DateTimeInterface;
use League\Csv\Writer;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Throwable;

/**
 * Master uploads: reads an Excel workbook (.xlsx) one worksheet at a time, streaming, and writes each worksheet with
 * data as a CSV of the staged upload pipeline: the first non-empty row is the header, blank rows are left out, and a last
 * column (WorkbookImports::ROW_COLUMN) keeps each row's number in the worksheet, so every message and the error workbook
 * point at the row the person sees in Excel. Cell values become text the uploads already read: dates as YYYY-MM-DD,
 * whole numbers without decimals, true / false as yes / no.
 */
final class WorkbookReader
{
    /**
     * @param  string  $file  a local .xlsx file
     * @param  string  $directory  a local directory the per-worksheet CSV files are written to
     * @return array<string, array{name: string, headers: list<string>, rows: int, csv: string|null, duplicate_headers: list<string>}> worksheet name => what it holds
     */
    public function read(string $file, string $directory, int $maxRows): array
    {
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_FORMAT_DATES = false;
        $reader = new Reader($options);
        try {
            $reader->open($file);
        } catch (Throwable) {
            throw new RuntimeException('The file is not an Excel workbook (.xlsx). Download the template, fill it in and upload it as .xlsx.');
        }

        $sheets = [];
        try {
            $index = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                $name = trim((string) $sheet->getName());
                $headers = null;
                $csv = null;
                $path = null;
                $rows = 0;
                $duplicates = [];
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $values = array_map(fn ($cell) => self::text($cell->getValue()), $row->getCells());
                    if (implode('', $values) === '') {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn (string $h) => preg_replace('/\s+/u', ' ', $h), $values);
                        while ($headers !== [] && end($headers) === '') {
                            array_pop($headers);
                        }
                        $counts = array_count_values(array_filter($headers, fn (string $h) => $h !== ''));
                        $duplicates = array_keys(array_filter($counts, fn (int $n) => $n > 1));

                        continue;
                    }
                    if (++$rows > $maxRows) {
                        throw new RuntimeException("Worksheet '{$name}' has more than {$maxRows} rows; split it across two workbooks.");
                    }
                    if ($csv === null) {
                        $path = $directory.'/sheet-'.(++$index).'.csv';
                        $csv = Writer::from($path, 'w');
                        $csv->insertOne([...array_map(fn (string $h, int $i) => $h === '' ? 'Column '.($i + 1) : $h, $headers, array_keys($headers)), WorkbookImports::ROW_COLUMN]);
                    }
                    $cells = array_slice(array_pad($values, count($headers), ''), 0, count($headers));
                    $csv->insertOne([...$cells, (string) $number]);
                }
                $csv = null;
                $sheets[$name] = ['name' => $name, 'headers' => $headers ?? [], 'rows' => $rows, 'csv' => $path, 'duplicate_headers' => $duplicates];
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /** A cell's value as the text an upload reads. */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value,
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s'),
            $value instanceof DateInterval => $value->format('%H:%I'),
            default => trim((string) $value),
        };
    }
}
