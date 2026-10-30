<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\BankFileLayout;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity BP-13 / CA-5: bank-specific salary file formats kept as versioned data. A layout is drafted, checked,
 * activated (the code's previous active version is retired) and from then on frozen; a change is a new version. The bank
 * file renders a paying account's transfers through its layout's active version.
 *
 * Specification: format (delimited | fixed_width), delimiter (, ; | tab), quotes (as_needed, the CSV way | never),
 * column_headings (bool), columns [{field,
 * heading, width, align left|right, pad (one character), case upper|null, format, value}], header_lines and footer_lines
 * (text with {placeholders}, a format after a colon: {payment_date:d/m/Y}, {total:paise}), line_ending (lf | crlf) and
 * extension (csv | txt). Amount formats: decimal (two places, the default), whole, paise. Same bank / other bank prints the
 * format's two values split by | (default "same|other").
 */
final class BankFileLayouts
{
    private const DELIMITERS = [',' => ',', ';' => ';', '|' => '|', 'tab' => "\t"];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data  code, name, specification, notes */
    public function create(array $data, User $by): BankFileLayout
    {
        $this->authorise($by);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
            throw new RuntimeException('Give the layout a code of 2 to 32 letters, digits, - or _.');
        }
        if (BankFileLayout::query()->where('code', $code)->exists()) {
            throw new RuntimeException("Layout {$code} exists; make a new version of it instead.");
        }

        return BankFileLayout::query()->create([
            'code' => $code, 'version' => 1, 'name' => $this->name($data), 'status' => 'draft',
            'specification' => $this->validated((array) ($data['specification'] ?? [])), 'notes' => $data['notes'] ?? null, 'created_by' => $by->id,
        ]);
    }

    /** @param  array<string, mixed>  $data  name, specification, notes */
    public function updateDraft(BankFileLayout $layout, array $data, User $by): BankFileLayout
    {
        $this->authorise($by);
        if ($layout->status !== 'draft') {
            throw new RuntimeException('Only a draft layout is edited; make a new version.');
        }
        $layout->update(['name' => $this->name($data + ['name' => $layout->name]), 'specification' => $this->validated((array) ($data['specification'] ?? $layout->specification)), 'notes' => $data['notes'] ?? $layout->notes]);

        return $layout;
    }

    /** A new draft version of the code, copied from its latest version. */
    public function newVersion(BankFileLayout $from, User $by): BankFileLayout
    {
        $this->authorise($by);

        return DB::transaction(function () use ($from, $by) {
            $versions = BankFileLayout::query()->where('code', $from->code)->lockForUpdate()->get();
            if ($versions->contains(fn (BankFileLayout $l) => $l->status === 'draft')) {
                throw new RuntimeException("Layout {$from->code} already has a draft version.");
            }
            $latest = $versions->sortByDesc('version')->first();

            return BankFileLayout::query()->create([
                'code' => $from->code, 'version' => (int) $latest->version + 1, 'name' => $latest->name, 'status' => 'draft',
                'specification' => $latest->specification, 'notes' => $latest->notes, 'created_by' => $by->id,
            ]);
        });
    }

    /** Draft → active; the code's active version is retired. */
    public function activate(BankFileLayout $layout, User $by, ?string $reason = null): BankFileLayout
    {
        $this->authorise($by);

        return DB::transaction(function () use ($layout, $by, $reason) {
            $versions = BankFileLayout::query()->where('code', $layout->code)->lockForUpdate()->get();
            $current = $versions->firstWhere('id', $layout->id);
            if ($current === null || $current->status !== 'draft') {
                throw new RuntimeException('Only a draft layout is activated.');
            }
            $this->validated((array) $current->specification);
            $versions->where('status', 'active')->each(fn (BankFileLayout $l) => $l->withAuditReason("Replaced by v{$current->version}")->update(['status' => 'retired']));
            $current->withAuditReason($reason)->update(['status' => 'active', 'activated_by' => $by->id, 'activated_at' => now()]);
            $this->audit->record(AuditAction::StatusChange, 'payroll', $current, [['field' => 'status', 'before' => 'draft', 'after' => 'active']], $reason, actor: $by, metadata: ['code' => $current->code, 'version' => $current->version, 'checksum' => $current->checksum]);

            return $layout->refresh();
        });
    }

    /** The code's active version (null when none). */
    public function active(?string $code): ?BankFileLayout
    {
        return filled($code) ? BankFileLayout::query()->where('code', strtoupper((string) $code))->where('status', 'active')->orderByDesc('version')->first() : null;
    }

    /**
     * The specification, checked and normalised: unknown fields, formats or placeholders are refused here, not when a
     * file is made.
     *
     * @return array<string, mixed>
     */
    public function validated(array $spec): array
    {
        $format = (string) ($spec['format'] ?? 'delimited');
        if (! in_array($format, ['delimited', 'fixed_width'], true)) {
            throw new RuntimeException('A layout is delimited or fixed width.');
        }
        $delimiter = (string) ($spec['delimiter'] ?? ',');
        if ($format === 'delimited' && ! array_key_exists($delimiter, self::DELIMITERS)) {
            throw new RuntimeException('Separate columns with a comma, semicolon, | or tab.');
        }
        $columns = [];
        foreach (array_values((array) ($spec['columns'] ?? [])) as $i => $column) {
            $field = (string) ($column['field'] ?? '');
            if (! array_key_exists($field, BankFileLayout::FIELDS)) {
                throw new RuntimeException('Column '.($i + 1).': choose what it prints.');
            }
            $width = filled($column['width'] ?? null) ? (int) $column['width'] : null;
            if ($format === 'fixed_width' && ($width === null || $width < 1 || $width > 500)) {
                throw new RuntimeException('Column '.($i + 1).': a fixed-width column needs a width from 1 to 500.');
            }
            $pad = (string) ($column['pad'] ?? ' ');
            if (mb_strlen($pad) !== 1) {
                throw new RuntimeException('Column '.($i + 1).': pad with a single character.');
            }
            if ($field === 'amount' && filled($column['format'] ?? null) && ! in_array($column['format'], ['decimal', 'whole', 'paise'], true)) {
                throw new RuntimeException('Column '.($i + 1).': amounts are decimal, whole or paise.');
            }
            $columns[] = array_filter([
                'field' => $field, 'heading' => (string) ($column['heading'] ?? BankFileLayout::FIELDS[$field]), 'width' => $width,
                'align' => ($column['align'] ?? 'left') === 'right' ? 'right' : 'left', 'pad' => $pad,
                'case' => ($column['case'] ?? null) === 'upper' ? 'upper' : null,
                'format' => filled($column['format'] ?? null) ? (string) $column['format'] : null,
                'value' => $field === 'literal' ? (string) ($column['value'] ?? '') : null,
            ], fn ($v) => $v !== null);
        }
        if ($columns === []) {
            throw new RuntimeException('A layout needs at least one column.');
        }
        $lines = fn (mixed $value): array => array_values(array_filter(array_map(fn ($l) => rtrim((string) (is_array($l) ? ($l['line'] ?? '') : $l), "\r\n"), (array) $value), fn (string $l) => $l !== ''));
        $header = $lines($spec['header_lines'] ?? []);
        $footer = $lines($spec['footer_lines'] ?? []);
        foreach ([...$header, ...$footer] as $line) {
            preg_match_all('/\{([a-z_]+)(?::[^}]*)?\}/', $line, $m);
            foreach ($m[1] as $name) {
                if (! in_array($name, BankFileLayout::PLACEHOLDERS, true)) {
                    throw new RuntimeException("Unknown placeholder {{$name}} in a header or footer line.");
                }
            }
        }

        return [
            'format' => $format, 'delimiter' => $delimiter, 'quotes' => ($spec['quotes'] ?? 'as_needed') === 'never' ? 'never' : 'as_needed',
            'column_headings' => (bool) ($spec['column_headings'] ?? $format === 'delimited'),
            'columns' => $columns, 'header_lines' => $header, 'footer_lines' => $footer,
            'line_ending' => ($spec['line_ending'] ?? 'lf') === 'crlf' ? 'crlf' : 'lf', 'extension' => ($spec['extension'] ?? 'csv') === 'txt' ? 'txt' : 'csv',
        ];
    }

    /**
     * The file for these transfers.
     *
     * @param  list<array<string, mixed>>  $rows  sequence, employee_code, beneficiary_name, bank, ifsc, account_number, amount, narration, transfer_type
     * @param  array<string, mixed>  $context  payment_date, period, company, debit_account, debit_ifsc, debit_bank_code, batch
     */
    public function render(BankFileLayout $layout, array $rows, array $context): string
    {
        $spec = $this->validated((array) $layout->specification);
        $eol = $spec['line_ending'] === 'crlf' ? "\r\n" : "\n";
        $total = round(array_sum(array_map(fn ($r) => (float) $r['amount'], $rows)), 2);
        $context += ['count' => count($rows), 'total' => $total, 'today' => now()];
        $out = '';
        foreach ($spec['header_lines'] as $line) {
            $out .= $this->fill($line, $context).$eol;
        }
        if ($spec['format'] === 'delimited') {
            $delimiter = self::DELIMITERS[$spec['delimiter']];
            $lines = [...($spec['column_headings'] ? [array_map(fn ($c) => $c['heading'], $spec['columns'])] : []),
                ...array_map(fn (array $row) => array_map(fn ($c) => $this->cell($c, $row + $context, false), $spec['columns']), $rows)];
            if ($spec['quotes'] === 'never') {
                // Banks that take no quotes: a delimiter inside a value would split it, so it is left out.
                foreach ($lines as $fields) {
                    $out .= implode($delimiter, array_map(fn (string $v) => str_replace([$delimiter, "\r", "\n"], '', $v), $fields)).$eol;
                }
            } else {
                $handle = fopen('php://temp', 'r+');
                foreach ($lines as $fields) {
                    fputcsv($handle, $fields, $delimiter, '"', '\\', $eol);
                }
                rewind($handle);
                $out .= stream_get_contents($handle);
                fclose($handle);
            }
        } else {
            if ($spec['column_headings']) {
                $out .= implode('', array_map(fn ($c) => $this->fit($c['heading'], $c), $spec['columns'])).$eol;
            }
            foreach ($rows as $row) {
                $out .= implode('', array_map(fn ($c) => $this->cell($c, $row + $context, true), $spec['columns'])).$eol;
            }
        }
        foreach ($spec['footer_lines'] as $line) {
            $out .= $this->fill($line, $context).$eol;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $data */
    private function name(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Name the layout (up to 120 characters).');
        }

        return $name;
    }

    private function cell(array $column, array $values, bool $fixed): string
    {
        $value = match ($column['field']) {
            'literal' => (string) ($column['value'] ?? ''),
            'amount' => $this->amount((float) $values['amount'], $column['format'] ?? 'decimal'),
            'payment_date' => $values['payment_date'] instanceof CarbonInterface ? $values['payment_date']->format($column['format'] ?? 'Y-m-d') : (string) $values['payment_date'],
            'transfer_type' => explode('|', ($column['format'] ?? 'same|other').'|')[($values['transfer_type'] ?? 'other') === 'same' ? 0 : 1],
            default => (string) ($values[$column['field']] ?? ''),
        };
        if (($column['case'] ?? null) === 'upper') {
            $value = mb_strtoupper($value);
        }

        return $fixed ? $this->fit($value, $column) : (isset($column['width']) ? mb_substr($value, 0, (int) $column['width']) : $value);
    }

    private function fit(string $value, array $column): string
    {
        $width = (int) ($column['width'] ?? mb_strlen($value));
        $value = mb_substr($value, 0, $width);
        $padding = str_repeat($column['pad'] ?? ' ', max(0, $width - mb_strlen($value)));

        return ($column['align'] ?? 'left') === 'right' ? $padding.$value : $value.$padding;
    }

    private function amount(float $amount, string $format): string
    {
        return match ($format) {
            'whole' => (string) (int) round($amount),
            'paise' => (string) (int) round($amount * 100),
            default => number_format($amount, 2, '.', ''),
        };
    }

    private function fill(string $line, array $context): string
    {
        return preg_replace_callback('/\{([a-z_]+)(?::([^}]*))?\}/', function (array $m) use ($context) {
            [$name, $format] = [$m[1], $m[2] ?? null];

            return match ($name) {
                'total' => $this->amount((float) $context['total'], $format ?: 'decimal'),
                'total_whole' => $this->amount((float) $context['total'], 'whole'),
                'total_paise' => $this->amount((float) $context['total'], 'paise'),
                'payment_date', 'today' => Carbon::parse($context[$name])->format($format ?: 'Y-m-d'),
                default => (string) ($context[$name] ?? ''),
            };
        }, $line);
    }

    private function authorise(User $by): void
    {
        if (! $by->hasPermission('payroll.manage')) {
            throw new RuntimeException('Bank file layouts need payroll.manage.');
        }
    }
}
