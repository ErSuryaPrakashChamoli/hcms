<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Domain\Analytics\Services\ReportExports;
use App\Domain\Analytics\Services\ReportResult;
use App\Domain\Analytics\Services\ReportRunner;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\ServiceDeskActions;
use App\Filament\Widgets\ReportChart;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** Runs the report on open: table (first 500 rows), chart when the visualization asks for one, export. */
class ViewReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ReportResource::class;

    protected string $view = 'filament.pages.view-report';

    public ?string $error = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()->can('view', $this->record), 403);
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getResult(): ?ReportResult
    {
        try {
            return app(ReportRunner::class)->run($this->record, auth()->user(), 500);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return null;
        }
    }

    public function hasChart(): bool
    {
        return in_array($this->record->def('visualization.type'), ['bar', 'line', 'pie'], true);
    }

    protected function getHeaderWidgets(): array
    {
        return $this->hasChart() ? [ReportChart::class] : [];
    }

    public function getWidgetData(): array
    {
        return ['reportId' => $this->record->id];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Export CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('primary')
                ->visible(fn () => auth()->user()->can('analytics.export') || auth()->user()->can('analytics.manage'))
                ->action(function () {
                    $run = app(ReportExports::class)->export($this->record, auth()->user());
                    if ($run->status !== 'completed') {
                        ServiceDeskActions::run(fn () => throw new RuntimeException($run->error ?? 'Export failed'), '');

                        return null;
                    }
                    $contents = app(ReportExports::class)->contents($run);

                    return response()->streamDownload(fn () => print ($contents), basename($run->path), ['Content-Type' => 'text/csv']);
                }),
            EditAction::make()->visible(fn () => auth()->user()->can('update', $this->record)),
        ];
    }
}
