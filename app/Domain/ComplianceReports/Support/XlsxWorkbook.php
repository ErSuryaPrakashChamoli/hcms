<?php

namespace App\Domain\ComplianceReports\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes report sheets into one .xlsx workbook: a title row, the header block (establishment, registration, period,
 * legal basis), the column headings, the rows, the totals and the notes. Amount columns use a two-decimal format.
 */
final class XlsxWorkbook
{
    /** @param  list<ReportSheet>  $sheets */
    public static function write(array $sheets, string $path): void
    {
        $options = new Options;
        foreach (array_values($sheets) as $index => $sheet) {
            $width = max(1, count($sheet->columns)) - 1;
            $options->mergeCells(0, 1, max(1, $width), 1, $index);
        }

        $writer = new Writer($options);
        $writer->setCreator('Markedge PeopleOS');
        $writer->openToFile($path);

        $title = (new Style)->setFontBold()->setFontSize(13);
        $label = (new Style)->setFontBold()->setFontColor('475569');
        $heading = (new Style)->setFontBold()->setBackgroundColor('E2E8F0')->setShouldWrapText();
        $total = (new Style)->setFontBold();
        $money = (new Style)->setFormat('#,##0.00');
        $moneyTotal = (new Style)->setFontBold()->setFormat('#,##0.00');
        $note = (new Style)->setFontItalic()->setFontColor('64748B');
        $warning = (new Style)->setFontBold()->setFontColor('B45309');

        $names = [];
        foreach (array_values($sheets) as $index => $sheet) {
            $current = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $current->setName(self::uniqueName($sheet->sheetName ?: $sheet->title, $names));

            $keys = array_keys($sheet->columns);
            $current->setColumnWidth(6, 1);
            foreach ($keys as $position => $key) {
                $current->setColumnWidth(self::width($sheet, $key), $position + 1);
            }

            $writer->addRow(Row::fromValues([$sheet->title], $title));
            foreach ($sheet->header as [$name, $value]) {
                $writer->addRow(new Row([Cell::fromValue($name, $label), Cell::fromValue((string) $value)]));
            }
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(array_values($sheet->columns), $heading));

            foreach ($sheet->rows as $row) {
                $writer->addRow(new Row(array_map(fn (string $key) => self::cell($row[$key] ?? null, in_array($key, $sheet->money, true) ? $money : null), $keys)));
            }

            if ($sheet->isEmpty()) {
                $writer->addRow(Row::fromValues([$sheet->message ?? 'No records for this period.'], $note));
            } elseif ($sheet->totals !== null) {
                $writer->addRow(new Row(array_map(fn (string $key) => self::cell($sheet->totals[$key] ?? null, in_array($key, $sheet->money, true) ? $moneyTotal : $total), $keys)));
            }

            if ($sheet->warnings !== []) {
                $writer->addRow(Row::fromValues([]));
                foreach ($sheet->warnings as $line) {
                    $writer->addRow(Row::fromValues(['Data gap: '.$line], $warning));
                }
            }
            if ($sheet->rules !== []) {
                $writer->addRow(Row::fromValues(['Calculated with: '.implode('; ', array_map(fn (array $r) => $r['label'], $sheet->rules))], $note));
            }
            if ($sheet->notes !== []) {
                $writer->addRow(Row::fromValues([]));
                foreach ($sheet->notes as $line) {
                    $writer->addRow(Row::fromValues([$line], $note));
                }
            }
        }

        $writer->close();
    }

    /** The workbook as a string (for a direct download). */
    public static function contents(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pos-report');
        try {
            self::write($sheets, $path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private static function cell(mixed $value, ?Style $style): Cell
    {
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif ($value instanceof \DateTimeInterface) {
            $value = $value->format('d-m-Y');
        } elseif (is_array($value)) {
            $value = implode(', ', $value);
        }

        return Cell::fromValue($value, $style);
    }

    private static function width(ReportSheet $sheet, string $key): float
    {
        $longest = mb_strlen((string) $sheet->columns[$key]);
        foreach (array_slice($sheet->rows, 0, 200) as $row) {
            $longest = max($longest, mb_strlen((string) (is_scalar($row[$key] ?? null) ? $row[$key] : '')));
        }

        return (float) min(48, max(6, $longest + 2));
    }

    /** Excel sheet names: at most 31 characters, none of []:*?/\, unique within the workbook. */
    private static function uniqueName(string $name, array &$taken): string
    {
        $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name)) ?: 'Sheet', 0, 28);
        $candidate = $base;
        for ($i = 2; in_array(mb_strtolower($candidate), $taken, true); $i++) {
            $candidate = mb_substr($base, 0, 27 - strlen((string) $i)).' '.$i;
        }
        $taken[] = mb_strtolower($candidate);

        return $candidate;
    }
}
