<?php

namespace App\Domain\Analytics\Services;

/** Rows + columns + optional chart series produced by the runner. */
final class ReportResult
{
    /**
     * @param  array<string, string>  $columns  key => label
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{labels: array<int, string>, series: array<string, array<int, float>>}  $chart
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $total,
        public readonly bool $grouped,
        public readonly array $chart = ['labels' => [], 'series' => []],
        public readonly ?float $kpi = null,
        // Phase 14: true when more rows matched the filters than the per-run cap; never silent.
        public readonly bool $truncated = false,
    ) {}
}
