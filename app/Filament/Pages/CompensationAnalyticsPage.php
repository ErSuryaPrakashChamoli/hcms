<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Services\CompensationAnalytics;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Phase 11 compensation analytics: factual aggregates as of a date, within organisation scope, with
 * the small-group rule applied to every group and to the filtered population. The export of
 * individual compensation needs compensation.export and compensation.view and is audited (EXPORT).
 */
class CompensationAnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Compensation analytics';

    protected static ?string $slug = 'compensation-analytics';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.compensation-analytics';

    public ?string $asOf = null;

    public ?int $companyId = null;

    public ?int $departmentId = null;

    public ?int $locationId = null;

    public ?int $gradeId = null;

    public ?int $employmentTypeId = null;

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('compensation.analytics') ?? false) || (auth()->user()?->can('compensation.view') ?? false);
    }

    public function mount(): void
    {
        $this->asOf = now()->toDateString();
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return app(CompensationAnalytics::class)->summary(auth()->user(), ['company_id' => $this->companyId, 'department_id' => $this->departmentId, 'location_id' => $this->locationId, 'grade_id' => $this->gradeId, 'employment_type_id' => $this->employmentTypeId], $this->asOf);
    }

    /** @return array<string, array<int, string>> */
    public function getFilterOptions(): array
    {
        return [
            'companyId' => Company::query()->orderBy('name')->pluck('name', 'id')->all(),
            'departmentId' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
            'locationId' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'gradeId' => Grade::query()->orderBy('name')->pluck('name', 'id')->all(),
            'employmentTypeId' => EmploymentType::query()->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Export compensation (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => auth()->user()->can('compensation.export') && auth()->user()->can('compensation.view'))
                ->requiresConfirmation()->modalDescription('Exports the approved compensation in force on the selected date for the employees in your scope. The export is audited.')
                ->action(fn () => $this->export()),
        ];
    }

    public function export(): StreamedResponse
    {
        abort_unless(auth()->user()->can('compensation.export') && auth()->user()->can('compensation.view'), 403);
        $asOf = $this->asOf ?? now()->toDateString();
        $rows = EmployeeSalaryAssignment::query()->active()->effectiveOn($asOf)->with(['employee:id,employee_code', 'structure:id,code'])->orderBy('employee_id')->get();
        app(AuditRecorder::class)->record(AuditAction::Export, 'compensation', null, [], null, metadata: ['as_of' => $asOf, 'rows' => $rows->count(), 'export' => 'compensation_in_force']);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['employee_code', 'effective_from', 'effective_to', 'structure', 'currency', 'ctc_annual', 'variable_target_annual']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->employee?->employee_code, $r->effective_from->toDateString(), $r->effective_to?->toDateString(), $r->structure?->code, $r->currency, $r->ctc_annual, $r->variable_target_annual]);
            }
            fclose($out);
        }, 'compensation-'.$asOf.'.csv', ['Content-Type' => 'text/csv']);
    }
}
