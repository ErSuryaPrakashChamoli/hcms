<?php

namespace App\Filament\Widgets;

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\ReportRunner;
use Filament\Widgets\ChartWidget;

/** Renders a report's chart series (bar / line / pie) on the report page. */
class ReportChart extends ChartWidget
{
    public ?int $reportId = null;

    protected static bool $isDiscovered = false;

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    private const PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5'];

    public function getHeading(): ?string
    {
        return $this->report()?->name;
    }

    private function report(): ?Report
    {
        return $this->reportId ? Report::query()->find($this->reportId) : null;
    }

    protected function getType(): string
    {
        $type = $this->report()?->def('visualization.type', 'bar');

        return in_array($type, ['bar', 'line', 'pie'], true) ? $type : 'bar';
    }

    protected function getData(): array
    {
        $report = $this->report();
        if ($report === null) {
            return ['datasets' => [], 'labels' => []];
        }
        $chart = app(ReportRunner::class)->run($report, auth()->user(), 50)->chart;
        $pie = $this->getType() === 'pie';
        $datasets = [];
        $i = 0;
        foreach ($chart['series'] as $name => $values) {
            $datasets[] = ['label' => $name, 'data' => $values, 'backgroundColor' => $pie ? array_map(fn ($k) => self::PALETTE[$k % count(self::PALETTE)], array_keys($values)) : self::PALETTE[$i % count(self::PALETTE)], 'borderColor' => self::PALETTE[$i % count(self::PALETTE)], 'fill' => false];
            $i++;
        }

        return ['datasets' => $datasets, 'labels' => $chart['labels']];
    }
}
