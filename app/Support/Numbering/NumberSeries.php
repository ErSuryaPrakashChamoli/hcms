<?php

namespace App\Support\Numbering;

use App\Domain\Configuration\Models\CodeSeries;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity (Forms & templates → Code series): document numbers by code type, shaped per tenant.
 *
 * - Code types (types()): config peopleos.numbering.types (loan, letter, exit case, alumni request, ticket) plus one
 *   optional `letter.<type>` per letter type, which falls back to `letter`. Each has a built-in format, the one used
 *   before code series existed, so a tenant without series issues exactly the same numbers as before.
 * - Resolution (definition()): the active series of the company, else the active organisation-wide series (company
 *   null), else the fallback code type's, else the built-in format.
 * - Counting: one locked number_sequences row per rendered pattern (prefix and suffix with their {YYYY} {YY} {MM} {FY}
 *   filled in), created at the highest number already used in that pattern. Every series that renders the same pattern
 *   shares the counter, so two series can never hand out the same number, and a period token in the pattern restarts
 *   numbering when the period changes. A number already taken (typed, imported) is skipped. Counters for the built-in
 *   formats are the same rows the Phase 12 sequences use (e.g. TKT-2026), so ticket numbers simply continue.
 * - Call prepare() before opening the transaction that calls next() (the counter row is created in its own statement,
 *   as NumberSequences does); next() creates it itself when it is missing.
 */
final class NumberSeries
{
    public const TOKENS = ['{YYYY}', '{YY}', '{MM}', '{FY}'];

    private const PART = '/^[A-Za-z0-9_\-\/]*$/';

    private const MAX_LENGTH = 32;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Every code type: label, module, applicable_on (company | enterprise), model and column holding the numbers,
     * built-in prefix and digits, or `fallback` (letter types).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function types(): array
    {
        $types = [];
        foreach ((array) config('peopleos.numbering.types', []) as $key => $type) {
            if (isset($type['prefix_from']) && filled($code = config($type['prefix_from']))) {
                $type['prefix'] = $code.'-{YYYY}-';
            }
            $types[$key] = $type + ['suffix' => '', 'digits' => 5];
            if ($key === 'letter') {
                foreach ((array) config('peopleos.letters.types', []) as $letterType => $label) {
                    $types["letter.{$letterType}"] = ['label' => "{$label} letter code", 'module' => $type['module'], 'applicable_on' => $type['applicable_on'],
                        'model' => $type['model'], 'column' => $type['column'], 'fallback' => 'letter'];
                }
            }
        }

        return $types;
    }

    /** @return array<string, mixed> */
    public static function type(string $key): array
    {
        return self::types()[$key] ?? throw new RuntimeException("Unknown code type [{$key}].");
    }

    /** The company of an employee's current position (null when none), for company-level series. */
    public static function companyOf(?Employee $employee): ?int
    {
        if ($employee === null) {
            return null;
        }
        $id = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())
            ->effectiveOn()->orderByDesc('effective_from')->orderByDesc('id')->value('company_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The format in force for a code type and company: prefix, suffix, digits, start and the series it comes from (null:
     * the built-in format).
     *
     * @return array{prefix: string, suffix: string, digits: int, start: int, series: ?CodeSeries}
     */
    public function definition(string $key, ?int $companyId = null): array
    {
        $type = self::type($key);
        $rows = CodeSeries::query()->where('key', $key)->where('status', ActiveStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('company_id')->when($companyId !== null && $type['applicable_on'] === 'company', fn ($q) => $q->orWhere('company_id', $companyId)))
            ->get();
        $series = $rows->firstWhere('company_id', $companyId) ?? $rows->firstWhere('company_id', null);
        if ($series !== null) {
            return ['prefix' => (string) $series->prefix, 'suffix' => (string) $series->suffix, 'digits' => (int) $series->digits, 'start' => max(1, (int) $series->start_number), 'series' => $series];
        }
        if (isset($type['fallback'])) {
            return $this->definition($type['fallback'], $companyId);
        }

        return ['prefix' => (string) $type['prefix'], 'suffix' => (string) $type['suffix'], 'digits' => (int) $type['digits'], 'start' => 1, 'series' => null];
    }

    /** Creates the counter for the pattern in force now, outside the caller's transaction. */
    public function prepare(string $key, ?int $companyId = null): void
    {
        $this->ensure(self::type($key), $this->definition($key, $companyId), now());
    }

    /** The next number for a code type and company, taken under a lock on its counter (inside the caller's transaction). */
    public function next(string $key, ?int $companyId = null): string
    {
        $type = self::type($key);
        $definition = $this->definition($key, $companyId);
        $now = now();

        return DB::transaction(function () use ($type, $definition, $now) {
            $sequence = $this->counterKey($definition, $now);
            $row = NumberSequence::query()->where('sequence', $sequence)->lockForUpdate()->first();
            if ($row === null) {
                $this->ensure($type, $definition, $now);
                $row = NumberSequence::query()->where('sequence', $sequence)->lockForUpdate()->firstOrFail();
            }
            $number = $this->firstFree($type, $definition, $now, max($row->last_value + 1, $definition['start']));
            $row->update(['last_value' => $number]);

            return $this->format($definition, $now, $number);
        });
    }

    /** What next() would issue now, without taking it. */
    public function preview(string $key, ?int $companyId = null): string
    {
        return $this->previewOf(self::type($key), $this->definition($key, $companyId));
    }

    /** The first number a series being set up would issue (it continues after the highest number in its pattern). */
    public function previewSeries(CodeSeries $series): string
    {
        return $this->previewOf(self::type((string) $series->key), ['prefix' => (string) $series->prefix, 'suffix' => (string) $series->suffix,
            'digits' => max(1, (int) $series->digits), 'start' => max(1, (int) $series->start_number), 'series' => $series]);
    }

    /** The pattern people read: LN-{YYYY}-##### with the built-in format when no series is set. */
    public function patternOf(string $key, ?int $companyId = null): string
    {
        $definition = $this->definition($key, $companyId);

        return $definition['prefix'].str_repeat('#', max(1, $definition['digits'])).$definition['suffix'];
    }

    /** When numbering restarts, from the period tokens in the pattern. */
    public static function restarts(string $prefix, string $suffix): string
    {
        $pattern = $prefix.$suffix;
        $year = str_contains($pattern, '{YYYY}') || str_contains($pattern, '{YY}');

        return match (true) {
            str_contains($pattern, '{MM}') && ($year || str_contains($pattern, '{FY}')) => 'Every month',
            str_contains($pattern, '{FY}') => 'Every financial year',
            $year => 'Every year',
            default => 'Never',
        };
    }

    /**
     * Whether a code type is set up: yes (an organisation-wide series, or one for every company of a company-level type),
     * partial (some companies), fallback (a letter type using the letter series) or default (the built-in format).
     */
    public function configured(string $key): string
    {
        $type = self::type($key);
        $rows = CodeSeries::query()->where('key', $key)->where('status', ActiveStatus::Active->value)->pluck('company_id');
        if ($rows->contains(null)) {
            return 'yes';
        }
        if ($rows->isNotEmpty() && $type['applicable_on'] === 'company') {
            return Company::query()->whereNotIn('id', $rows->filter()->all())->exists() ? 'partial' : 'yes';
        }

        return isset($type['fallback']) ? 'fallback' : 'default';
    }

    /**
     * Every code type with its module, applicability, status and the pattern used for the organisation.
     *
     * @return list<array{key: string, label: string, module: string, applicable_on: string, configured: string, pattern: string, next: string}>
     */
    public function overview(): array
    {
        $rows = [];
        foreach (self::types() as $key => $type) {
            $rows[] = ['key' => $key, 'label' => $type['label'], 'module' => $type['module'], 'applicable_on' => $type['applicable_on'],
                'configured' => $this->configured($key), 'pattern' => $this->patternOf($key), 'next' => $this->preview($key)];
        }

        return $rows;
    }

    /** @return array{0: string, 1: string}|null the field and the reason a series cannot be saved */
    public function problemWith(CodeSeries $series): ?array
    {
        $type = self::types()[$series->key] ?? null;
        if ($type === null) {
            return ['key', 'Choose a code type.'];
        }
        if ($series->company_id !== null && $type['applicable_on'] !== 'company') {
            return ['company_id', "{$type['label']} is numbered for the whole organisation only."];
        }
        foreach (['prefix' => 24, 'suffix' => 16] as $field => $max) {
            $value = (string) $series->{$field};
            if (mb_strlen($value) > $max || ! preg_match(self::PART, str_replace(self::TOKENS, '', $value))) {
                return [$field, "Use at most {$max} characters: letters, digits, - _ / and {YYYY} {YY} {MM} {FY}."];
            }
        }
        $digits = (int) $series->digits;
        if ($digits < 1 || $digits > 10) {
            return ['digits', 'Use between 1 and 10 digits.'];
        }
        if ((int) $series->start_number < 1) {
            return ['start_number', 'Numbering starts at 1 or more.'];
        }
        // The longest the numbers can get: {FY} renders as 2026-27.
        $sample = now()->setDate(2026, 12, 1);
        if (mb_strlen($this->render((string) $series->prefix, $sample).$this->render((string) $series->suffix, $sample)) + $digits > self::MAX_LENGTH) {
            return ['suffix', 'Prefix, digits and suffix together must fit in '.self::MAX_LENGTH.' characters.'];
        }
        $duplicate = CodeSeries::query()->where('key', $series->key)
            ->when($series->company_id === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $series->company_id))
            ->when($series->exists, fn ($q) => $q->whereKeyNot($series->getKey()))->exists();
        if ($duplicate) {
            return ['company_id', $series->company_id === null ? 'There is already an organisation-wide series for this code type. Edit that one instead.' : 'This company already has a series for this code type. Edit that one instead.'];
        }

        return null;
    }

    public function render(string $part, CarbonInterface $at): string
    {
        $fyStart = $at->month >= 4 ? $at->year : $at->year - 1;

        return strtr($part, ['{YYYY}' => $at->format('Y'), '{YY}' => $at->format('y'), '{MM}' => $at->format('m'), '{FY}' => $fyStart.'-'.substr((string) ($fyStart + 1), -2)]);
    }

    /** @param array{prefix: string, suffix: string, digits: int} $definition */
    private function format(array $definition, CarbonInterface $at, int $number): string
    {
        return $this->render($definition['prefix'], $at).str_pad((string) $number, max(1, $definition['digits']), '0', STR_PAD_LEFT).$this->render($definition['suffix'], $at);
    }

    /** The counter row for the pattern in force: the rendered prefix (trailing dashes dropped) and suffix, upper-cased. */
    private function counterKey(array $definition, CarbonInterface $at): string
    {
        $prefix = strtoupper(rtrim($this->render($definition['prefix'], $at), '-'));
        $suffix = strtoupper($this->render($definition['suffix'], $at));
        $key = $suffix === '' ? $prefix : $prefix.'#'.$suffix;
        if ($key === '') {
            $key = '#';
        }

        return strlen($key) > 32 ? 'H#'.substr(hash('sha256', $key), 0, 30) : $key;
    }

    /** @param array<string, mixed> $type */
    private function ensure(array $type, array $definition, CarbonInterface $at): void
    {
        $tenantId = $this->tenants->id() ?? throw new RuntimeException('Numbers are issued inside a tenant.');
        $sequence = $this->counterKey($definition, $at);
        if (NumberSequence::query()->where('sequence', $sequence)->exists()) {
            return;
        }
        $seed = $this->highestInPattern($type, $this->render($definition['prefix'], $at), $this->render($definition['suffix'], $at));
        NumberSequence::query()->insertOrIgnore(['tenant_id' => $tenantId, 'sequence' => $sequence, 'last_value' => $seed, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $type */
    private function previewOf(array $type, array $definition): string
    {
        $now = now();
        $last = NumberSequence::query()->where('sequence', $this->counterKey($definition, $now))->value('last_value');
        $last ??= $this->highestInPattern($type, $this->render($definition['prefix'], $now), $this->render($definition['suffix'], $now));

        return $this->format($definition, $now, $this->firstFree($type, $definition, $now, max((int) $last + 1, $definition['start'])));
    }

    /** @param array<string, mixed> $type */
    private function firstFree(array $type, array $definition, CarbonInterface $at, int $from): int
    {
        $number = $from;
        // Skip numbers already used (created directly, imported, or under an earlier counter of the same pattern).
        while ($this->query($type)->where($type['column'], $this->format($definition, $at, $number))->exists()) {
            $number++;
        }

        return $number;
    }

    /**
     * The highest number already used between this rendered prefix and suffix (0 when none), compared without case.
     *
     * @param  array<string, mixed>  $type
     */
    private function highestInPattern(array $type, string $prefix, string $suffix): int
    {
        $head = mb_strtolower($prefix);
        $tail = mb_strtolower($suffix);

        return (int) ($this->query($type)->where($type['column'], 'like', $prefix.'%'.$suffix)->pluck($type['column'])
            ->map(function (string $value) use ($head, $tail): int {
                $lower = mb_strtolower($value);
                $number = substr($value, strlen($head), strlen($value) - strlen($head) - strlen($tail));

                return str_starts_with($lower, $head) && str_ends_with($lower, $tail) && $number !== '' && ctype_digit($number) && strlen($number) <= 18 ? (int) $number : 0;
            })->max() ?? 0);
    }

    /** The numbered records of the tenant, whatever the caller's organisation scope. */
    private function query(array $type)
    {
        $model = $type['model'];

        return $model::query()->withoutGlobalScope(AccessScope::class);
    }
}
