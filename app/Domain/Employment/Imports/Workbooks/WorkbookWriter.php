<?php

namespace App\Domain\Employment\Imports\Workbooks;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Identity\Models\User;
use League\Csv\Reader;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Comment\Comment;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Master uploads: the Excel files people download — the template (a Read me tab, a Columns tab and one empty tab per
 * worksheet with its header row), the sample (the same with example rows) and the error workbook (each worksheet's rows
 * that could not be imported, with their row number and what is wrong). Built from each upload's own format and examples,
 * so a workbook and the matching single upload always take the same columns.
 */
final class WorkbookWriter
{
    /** Writes the template (or sample) of a workbook for this person to a local file; returns its path. */
    public function template(string $key, ?User $user, bool $sample, string $path): string
    {
        $workbook = WorkbookCatalogue::get($key);
        $sheets = WorkbookCatalogue::sheetsFor($user, $key);
        $plan = DependencyPlanner::plan($sheets);

        $writer = new Writer;
        $writer->openToFile($path);

        // Read me.
        $this->name($writer, 'Read me');
        $bold = (new Style)->setFontBold();
        $title = (new Style)->setFontBold()->setFontSize(14);
        $wrap = (new Style)->setShouldWrapText();
        $writer->getCurrentSheet()->setColumnWidth(28, 1);
        $writer->getCurrentSheet()->setColumnWidth(70, 2);
        $writer->getCurrentSheet()->setColumnWidth(32, 3);
        $writer->getCurrentSheet()->setColumnWidth(40, 4);
        $writer->addRow(Row::fromValues([$workbook['label'].' — master upload'], $title));
        $writer->addRow(Row::fromValues([$workbook['about']], $wrap));
        $writer->addRow(Row::fromValues(['When to use it: '.$workbook['use']], $wrap));
        $writer->addRow(Row::fromValues([]));
        foreach (self::rules() as $rule) {
            $writer->addRow(Row::fromValues(['•', $rule], $wrap));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Worksheet', 'What it holds', 'Runs after', 'Columns every row needs'], $bold));
        foreach ($plan['order'] as $sheetTitle) {
            $sheet = $sheets[$sheetTitle];
            $writer->addRow(Row::fromValues([$sheetTitle, $sheet['process']['about'], implode(', ', $plan['after'][$sheetTitle]) ?: '—', implode(', ', $this->requiredLabels($sheet))], $wrap));
        }

        // Columns.
        $this->name($writer, 'Columns', $writer->addNewSheetAndMakeItCurrent());
        foreach ([24, 30, 12, 70, 24] as $i => $width) {
            $writer->getCurrentSheet()->setColumnWidth($width, $i + 1);
        }
        $writer->addRow(Row::fromValues(['Worksheet', 'Column', 'Required', 'What to enter', 'Example'], $bold));
        foreach ($sheets as $sheetTitle => $sheet) {
            foreach ($this->columns($sheet) as [$label, $required, $what, $example]) {
                $writer->addRow(Row::fromValues([$sheetTitle, $label, $required, $what, $example], $wrap));
            }
        }

        // One tab per worksheet, in workbook order.
        $headerStyle = (new Style)->setFontBold()->setBackgroundColor('E8EAF6');
        $requiredStyle = (new Style)->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('3F51B5');
        foreach ($sheets as $sheetTitle => $sheet) {
            $this->name($writer, $sheetTitle, $writer->addNewSheetAndMakeItCurrent());
            [$header, $examples] = $this->headerAndExamples($sheet);
            $columns = [];
            foreach ($this->columns($sheet) as $column) {
                $columns[$column[0]] = $column;
            }
            $cells = [];
            foreach ($header as $label) {
                $column = $columns[$label] ?? null;
                $required = $column !== null && self::isRequired($column[1]);
                $cell = Cell::fromValue($label, $required ? $requiredStyle : $headerStyle);
                if ($column !== null) {
                    $comment = new Comment;
                    $comment->width = '220pt';
                    $comment->height = '90pt';
                    $comment->addTextRun(new TextRun(($required ? 'Required. ' : 'Optional. ').$column[2].($column[3] !== '' ? ' Example: '.$column[3] : '')));
                    $cell->comment = $comment;
                }
                $cells[] = $cell;
            }
            $writer->addRow(new Row($cells));
            $writer->getCurrentSheet()->setColumnWidth(20, ...range(1, max(1, count($header))));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            if ($sample) {
                foreach ($examples as $example) {
                    $writer->addRow(Row::fromValues(array_map(fn ($v) => (string) $v, $example)));
                }
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * Writes the error workbook of a master upload: per worksheet, the rows that could not be imported (errors when
     * checked, failed when run, or still waiting) with their worksheet row, status and what to fix. Returns the path,
     * or null when every row went in.
     */
    public function errors(EmployeeImport $master, string $path): ?string
    {
        $writer = null;
        $bold = (new Style)->setFontBold()->setBackgroundColor('FDE2E1');
        $summary = WorkbookImports::problems($master);
        foreach ($master->children()->get() as $child) {
            $headers = array_values(array_filter($child->headers ?? [], fn ($h) => $h !== WorkbookImports::ROW_COLUMN));
            $query = $child->rows()->where(fn ($q) => $q->where('action', 'error')->orWhere('action', 'waiting')->orWhere('status', 'failed')->orWhereNotNull('errors'));
            if (! $query->exists()) {
                continue;
            }
            if ($writer === null) {
                $writer = new Writer;
                $writer->openToFile($path);
                $this->name($writer, 'Summary');
                $writer->addRow(Row::fromValues(['Worksheet', 'Problem'], $bold));
                foreach ($summary as [$sheet, $problem]) {
                    $writer->addRow(Row::fromValues([$sheet, $problem]));
                }
            }
            $this->name($writer, (string) $child->sheet, $writer->addNewSheetAndMakeItCurrent());
            $writer->addRow(Row::fromValues([WorkbookImports::ROW_COLUMN, 'Status', 'What to fix', ...$headers], $bold));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->getCurrentSheet()->setColumnWidth(60, 3);
            foreach ($query->orderBy('row_number')->lazy(500) as $row) {
                /** @var EmployeeImportRow $row */
                $data = (array) $row->data;
                $writer->addRow(Row::fromValues([
                    (string) ($data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1),
                    WorkbookImports::rowStatus($row),
                    WorkbookImports::rowProblem($row),
                    ...array_map(fn (string $h) => (string) ($data[$h] ?? ''), $headers),
                ]));
            }
        }
        if ($writer === null && $summary !== []) {
            $writer = new Writer;
            $writer->openToFile($path);
            $this->name($writer, 'Summary');
            $writer->addRow(Row::fromValues(['Worksheet', 'Problem'], $bold));
            foreach ($summary as [$sheet, $problem]) {
                $writer->addRow(Row::fromValues([$sheet, $problem]));
            }
        }
        $writer?->close();

        return $writer === null ? null : $path;
    }

    /** @return list<string> the rules every workbook states on its Read me tab */
    public static function rules(): array
    {
        return [
            'Fill in only the worksheets you need. An empty worksheet is left out; you may also delete it. Do not rename the tabs or the header row.',
            'Columns with a dark header are required for every row. Hover over a header for what to enter. The Columns tab lists every column.',
            'Use codes, not names, to point at other records (company code, department code, employee code). Type codes as text so Excel keeps leading zeros.',
            'Dates as YYYY-MM-DD (2026-04-01) or DD-MM-YYYY. Excel dates are read as dates.',
            'A code added in one worksheet can be used in the worksheets after it: PeopleOS works out the order itself (Read me, "Runs after").',
            'Nothing changes when you upload. Every row is checked first; you see what will be added, updated or skipped and download the rows with errors. Only then do you approve and run it.',
            'Rows that point at something an earlier worksheet adds are checked in full just before their worksheet runs. A row that fails is listed in the error workbook; the other rows still go in.',
        ];
    }

    /** @return array{0: list<string>, 1: list<list<string>>} the worksheet's header row and example rows (its process's columns and defaults) */
    private function headerAndExamples(array $sheet): array
    {
        $records = iterator_to_array(Reader::fromString(ImportCatalogue::csv($sheet['process'], true))->getRecords(), false);
        $header = array_map(fn ($h) => trim((string) $h), $records[0] ?? []);

        return [$header, array_slice($records, 1)];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> label, required, what to enter, example */
    private function columns(array $sheet): array
    {
        [$header] = $this->headerAndExamples($sheet);
        $format = ImportTypes::service($sheet['type'])->format();
        $byLabel = [];
        foreach ($format['columns'] ?? [] as $column) {
            $byLabel[(string) $column[0]] = [(string) $column[0], (string) $column[1], (string) ($column[2] ?? ''), (string) ($column[3] ?? '')];
        }
        $fields = ImportCatalogue::fields($sheet['process']);
        $defaults = $sheet['process']['defaults'] ?? [];
        $out = [];
        foreach ($header as $label) {
            $column = $byLabel[$label] ?? null;
            if ($column === null) {
                $field = array_search($label, array_map(fn ($f) => $f[0], $fields), true);
                $column = [$label, $field !== false && ($fields[$field][1] ?? false) ? 'Yes' : 'No', '', ''];
            }
            $field = array_search($label, array_map(fn ($f) => $f[0], $fields), true);
            if ($field !== false && isset($defaults[$field])) {
                $column[1] = 'No';
                $column[2] = trim($column[2].' Blank is read as “'.$defaults[$field].'”.');
            }
            $out[] = $column;
        }

        return $out;
    }

    /** @return list<string> */
    private function requiredLabels(array $sheet): array
    {
        return array_values(array_map(fn (array $c) => $c[0], array_filter($this->columns($sheet), fn (array $c) => self::isRequired($c[1]))));
    }

    private static function isRequired(string $required): bool
    {
        return str_starts_with(mb_strtolower(trim($required)), 'yes');
    }

    /** Names the current (or given) worksheet; Excel allows 31 characters and no []:*?/\ */
    private function name(Writer $writer, string $name, mixed $sheet = null): void
    {
        $clean = mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)), 0, 31);
        ($sheet ?? $writer->getCurrentSheet())->setName($clean === '' ? 'Sheet' : $clean);
    }
}
