<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Datasets\Dataset;
use App\Domain\Analytics\Models\Report;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Services\FormulaEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Dataset → Fields → Filters → Calculated fields → Grouping/aggregation → Sort → Visualization (§84).
 * Filters, grouping and calculations run in memory on the mapped rows (bounded by analytics.max_rows),
 * so every dataset behaves the same regardless of how its values are derived.
 */
final class ReportRunner
{
    public function __construct(private readonly DatasetRegistry $datasets, private readonly FormulaEngine $formulas) {}

    public function run(Report $report, ?User $user = null, ?int $limit = null): ReportResult
    {
        return $this->execute($report->dataset, $report->definition ?? [], $user, $limit);
    }

    /** @param  array<string, mixed>  $definition */
    public function execute(string $datasetKey, array $definition, ?User $user = null, ?int $limit = null): ReportResult
    {
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Analytics, 'analytics.report.run');
        $dataset = $this->datasets->get($datasetKey);
        if ($user && ! $dataset->allowedFor($user)) {
            throw new RuntimeException('You cannot report on the '.$dataset->label().' dataset.');
        }

        $available = $dataset->fieldsFor($user);
        $selected = array_values(array_filter($definition['fields'] ?? array_keys($available), fn ($f) => isset($available[$f])));
        $calculated = collect($definition['calculated'] ?? [])->filter(fn ($c) => ! empty($c['key']) && ! empty($c['formula']))->keyBy(fn ($c) => strtolower($c['key']));
        $filters = collect($definition['filters'] ?? [])->filter(fn ($f) => ! empty($f['field']) && ! empty($f['operator']));
        $needed = collect($selected)->merge($filters->pluck('field'))->merge([$definition['group_by'] ?? null])->merge(collect($definition['aggregations'] ?? [])->pluck('field'))->filter()->unique()->filter(fn ($f) => isset($available[$f]))->values();

        // Phase 14: rows are streamed and filtered first, then capped. The cap used to apply before the
        // filters and silently dropped matching rows on large tenants; now a capped run says so.
        $max = (int) config('peopleos.analytics.max_rows', 10000);
        $rows = collect();
        $truncated = false;
        foreach ($dataset->query()->lazy(500) as $model) {
            $row = [];
            foreach ($needed as $field) {
                $row[$field] = $dataset->value($field, $model);
            }
            foreach ($calculated as $key => $calc) {
                try {
                    $row[$key] = round($this->formulas->evaluate($calc['formula'], fn (string $name) => isset($row[$name]) && is_numeric($row[$name]) ? (float) $row[$name] : null), 2);
                } catch (RuntimeException) {
                    $row[$key] = null;
                }
            }
            if (! $filters->every(fn ($f) => $this->matches($row[$f['field']] ?? null, $f['operator'], $f['value'] ?? null, $available[$f['field']]['type'] ?? 'string'))) {
                continue;
            }
            if ($rows->count() >= $max) {
                $truncated = true;
                break;
            }
            $rows->push($row);
        }

        $columns = [];
        foreach ($selected as $f) {
            $columns[$f] = $available[$f]['label'];
        }
        foreach ($calculated as $key => $calc) {
            $columns[$key] = $calc['label'] ?? $key;
        }

        $grouped = false;
        $groupBy = $definition['group_by'] ?? null;
        if ($groupBy && (isset($available[$groupBy]) || $calculated->has($groupBy))) {
            $grouped = true;
            $aggregations = collect($definition['aggregations'] ?? [])->filter(fn ($a) => ! empty($a['fn']) && ($a['fn'] === 'count' || ! empty($a['field'])));
            if ($aggregations->isEmpty()) {
                $aggregations = collect([['fn' => 'count']]);
            }
            $columns = [$groupBy => $available[$groupBy]['label'] ?? $calculated[$groupBy]['label'] ?? $groupBy];
            foreach ($aggregations as $agg) {
                $columns[$this->aggKey($agg)] = $agg['label'] ?? ($agg['fn'] === 'count' ? 'Count' : ucfirst($agg['fn']).' of '.($available[$agg['field']]['label'] ?? $calculated[$agg['field']]['label'] ?? $agg['field']));
            }
            $rows = $rows->groupBy(fn ($row) => (string) ($row[$groupBy] ?? ''))->map(function (Collection $group, $key) use ($groupBy, $aggregations) {
                $out = [$groupBy => $key === '' ? null : $key];
                foreach ($aggregations as $agg) {
                    $values = $agg['fn'] === 'count' ? $group : $group->pluck($agg['field'])->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (float) $v);
                    $out[$this->aggKey($agg)] = match ($agg['fn']) {
                        'count' => $group->count(),
                        'sum' => round($values->sum(), 2),
                        'avg' => $values->isEmpty() ? null : round($values->avg(), 2),
                        'min' => $values->isEmpty() ? null : $values->min(),
                        'max' => $values->isEmpty() ? null : $values->max(),
                        default => null,
                    };
                }

                return $out;
            })->values();
        } else {
            $rows = $rows->map(fn ($row) => array_intersect_key($row, $columns));
        }

        $sort = $definition['sort'] ?? null;
        if (! empty($sort['field']) && isset($columns[$sort['field']])) {
            $rows = (($sort['dir'] ?? 'asc') === 'desc' ? $rows->sortByDesc($sort['field']) : $rows->sortBy($sort['field']))->values();
        }

        $total = $rows->count();
        $limit ??= (int) ($definition['limit'] ?? 0);
        if ($limit > 0) {
            $rows = $rows->take($limit)->values();
        }

        return new ReportResult($columns, $rows->all(), $total, $grouped, $this->chart($columns, $rows, $definition['visualization'] ?? [], $grouped, $groupBy), $this->kpi($rows, $columns, $definition['visualization'] ?? []), $truncated);
    }

    private function aggKey(array $agg): string
    {
        return $agg['fn'] === 'count' ? 'count' : $agg['fn'].'_'.$agg['field'];
    }

    private function matches(mixed $value, string $operator, mixed $expected, string $type): bool
    {
        $isDate = $type === 'date';
        $v = $isDate && $value ? Carbon::parse($value)->startOfDay() : $value;

        return match ($operator) {
            'equals' => (string) $value === (string) $expected || ($type === 'boolean' && (bool) $value === filter_var($expected, FILTER_VALIDATE_BOOLEAN)),
            'not_equals' => (string) $value !== (string) $expected,
            'in' => in_array((string) $value, array_map('trim', is_array($expected) ? $expected : explode(',', (string) $expected)), true),
            'contains' => $value !== null && str_contains(strtolower((string) $value), strtolower((string) $expected)),
            'gt' => $value !== null && ($isDate ? $v->gt(Carbon::parse($expected)) : (float) $value > (float) $expected),
            'gte' => $value !== null && ($isDate ? $v->gte(Carbon::parse($expected)) : (float) $value >= (float) $expected),
            'lt' => $value !== null && ($isDate ? $v->lt(Carbon::parse($expected)) : (float) $value < (float) $expected),
            'lte' => $value !== null && ($isDate ? $v->lte(Carbon::parse($expected)) : (float) $value <= (float) $expected),
            'between' => (function () use ($value, $expected, $isDate, $v) {
                [$a, $b] = array_pad(array_map('trim', explode(',', (string) $expected)), 2, null);

                return $value !== null && $a !== null && $b !== null && ($isDate ? $v->betweenIncluded(Carbon::parse($a), Carbon::parse($b)) : ((float) $value >= (float) $a && (float) $value <= (float) $b));
            })(),
            'is_empty' => $value === null || $value === '' || $value === [],
            'not_empty' => ! ($value === null || $value === '' || $value === []),
            'last_days' => $value !== null && Carbon::parse($value)->gte(now()->subDays((int) $expected)->startOfDay()),
            'this_month' => $value !== null && Carbon::parse($value)->isSameMonth(now()),
            'this_year' => $value !== null && Carbon::parse($value)->isSameYear(now()),
            default => true,
        };
    }

    /** @return array{labels: array<int, string>, series: array<string, array<int, float>>} */
    private function chart(array $columns, Collection $rows, array $viz, bool $grouped, ?string $groupBy): array
    {
        $type = $viz['type'] ?? 'table';
        if (! in_array($type, ['bar', 'line', 'pie'], true) || $rows->isEmpty()) {
            return ['labels' => [], 'series' => []];
        }
        $x = $viz['x'] ?? ($grouped ? $groupBy : array_key_first($columns));
        $numeric = array_keys(array_filter($columns, fn ($label, $key) => $key !== $x && $rows->first()[$key] !== null && is_numeric($rows->first()[$key]), ARRAY_FILTER_USE_BOTH));
        $y = ! empty($viz['y']) && isset($columns[$viz['y']]) ? [$viz['y']] : ($type === 'pie' ? array_slice($numeric, 0, 1) : $numeric);
        $labels = $rows->pluck($x)->map(fn ($v) => (string) ($v ?? '—'))->all();
        $series = [];
        foreach ($y as $key) {
            $series[$columns[$key]] = $rows->pluck($key)->map(fn ($v) => (float) $v)->all();
        }

        return ['labels' => $labels, 'series' => $series];
    }

    private function kpi(Collection $rows, array $columns, array $viz): ?float
    {
        if (($viz['type'] ?? null) !== 'kpi' || $rows->isEmpty()) {
            return null;
        }
        $key = ! empty($viz['y']) && isset($columns[$viz['y']]) ? $viz['y'] : null;
        if ($key === null) {
            foreach (array_keys($columns) as $k) {
                if (is_numeric($rows->first()[$k] ?? null)) {
                    $key = $k;
                    break;
                }
            }
        }

        return $key === null ? (float) $rows->count() : round((float) $rows->sum(fn ($r) => (float) ($r[$key] ?? 0)), 2);
    }
}
