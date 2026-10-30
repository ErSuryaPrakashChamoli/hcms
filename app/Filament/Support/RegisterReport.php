<?php

namespace App\Filament\Support;

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\ReportLibraryPage;
use App\Filament\Resources\Reports\ReportResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * HROne parity WO-1 (the Records / Reports tabs of every Workforce page): a "Report" action on a register that adds the
 * matching report-library template to the person's reports — carrying the register's filters that the report's dataset
 * can use — and opens it. There is no second report engine: the report runs, exports and schedules like any other, and
 * the dataset's own access rules and masking apply.
 */
final class RegisterReport
{
    /** Single-choice organisation filters of the Employees register => dataset field (names, not ids). */
    private const ORGANISATION_SELECTS = ['company' => Company::class, 'department' => Department::class, 'location' => Location::class, 'designation' => Designation::class, 'grade' => Grade::class];

    /** @param  list<string>  $templateKeys */
    public static function action(array $templateKeys): Action
    {
        return Action::make('registerReport')
            ->label('Report')
            ->icon(Heroicon::OutlinedDocumentChartBar)
            ->color('gray')
            ->visible(fn () => self::templates($templateKeys) !== [])
            ->modalHeading('Report on this register')
            ->modalDescription('Adds a ready-made report from the report library to your reports, with the filters of this register that the report can use, and opens it.')
            ->modalSubmitActionLabel('Create and open')
            ->fillForm(fn () => ['template' => array_key_first(self::templates($templateKeys)), 'with_filters' => true])
            ->schema(fn () => [
                Select::make('template')->label('Report')->options(self::templates($templateKeys))->required()->native(false),
                Toggle::make('with_filters')->label('Use the filters applied on this register'),
            ])
            ->action(function (array $data, $livewire) use ($templateKeys) {
                $key = (string) ($data['template'] ?? '');
                if (! array_key_exists($key, self::templates($templateKeys))) {
                    return;
                }
                // HROne parity TX-1: a register may translate its own filters (labels, date ranges) for its datasets.
                $filters = ($data['with_filters'] ?? true)
                    ? (method_exists($livewire, 'registerReportFilters') ? $livewire->registerReportFilters() : self::filtersFrom((array) ($livewire->tableFilters ?? [])))
                    : [];
                try {
                    $report = app(ReportLibrary::class)->create($key, auth()->user(), filters: $filters);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Report created')->send();
                $livewire->redirect(ReportResource::getUrl('view', ['record' => $report]));
            });
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string> template key => name, for the templates the signed-in person may use
     */
    public static function templates(array $keys): array
    {
        $user = auth()->user();
        if ($user === null || ! ReportLibraryPage::canAccess()) {
            return [];
        }
        $all = collect(app(ReportLibrary::class)->all())->keyBy('key');
        $allowed = app(DatasetRegistry::class)->availableTo($user);
        $out = [];
        foreach ($keys as $key) {
            $template = $all->get($key);
            if ($template !== null && isset($allowed[$template['dataset']])) {
                $out[$key] = $template['name'];
            }
        }

        return $out;
    }

    /**
     * The register's filters as report conditions (the dataset keeps only fields it has, see ReportLibrary::create).
     *
     * @param  array<string, mixed>  $state  the register's table filter state
     * @return list<array{field: string, operator: string, value: string|null}>
     */
    public static function filtersFrom(array $state): array
    {
        $out = [];
        $in = function (string $field, array $values, string $operator = 'in') use (&$out) {
            $values = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $values), fn ($v) => $v !== ''));
            if ($values !== []) {
                $out[] = ['field' => $field, 'operator' => $operator, 'value' => implode(',', $values)];
            }
        };
        $values = fn (?array $filter) => (array) ($filter['values'] ?? (isset($filter['value']) && filled($filter['value']) ? [$filter['value']] : []));

        $in('lifecycle_state', $values($state['lifecycle_state'] ?? null));
        $in('status', $values($state['status'] ?? null));
        foreach (self::ORGANISATION_SELECTS as $filter => $model) {
            $ids = $values($state[$filter] ?? null);
            if ($ids !== []) {
                $in($filter, $model::query()->whereKey($ids)->pluck('name')->all());
            }
        }
        foreach (EmployeeFilters::ORGANISATION as $column => [, $model]) {
            $ids = array_values(array_filter(array_map('intval', (array) ($state['organisation'][$column] ?? []))));
            if ($ids !== []) {
                $ids = $column === 'department_id' ? EmployeeFilters::withSubDepartments($ids) : $ids;
                $in(str_replace('_id', '', $column), $model::query()->whereKey($ids)->pluck('name')->all());
            }
        }
        $in('employee_code', EmployeeFilters::parseCodes($state['employee_codes']['codes'] ?? null));
        $in('employee_code', EmployeeFilters::parseCodes($state['employee_codes']['exclude_codes'] ?? null), 'not_in');
        // HROne parity TX-1: the employee status / joining group of the time-office registers (EmployeeFilters::workforce).
        $states = array_values(array_filter((array) ($state['workforce']['lifecycle_state'] ?? [])));
        $in('lifecycle_state', $states);
        $in('employee_status', $states);
        foreach (['joined_from' => 'gte', 'joined_until' => 'lte'] as $bound => $operator) {
            if (filled($state['workforce'][$bound] ?? null)) {
                $out[] = ['field' => 'joining_date', 'operator' => $operator, 'value' => Carbon::parse($state['workforce'][$bound])->toDateString()];
            }
        }
        foreach (['joined' => 'joining_date'] as $filter => $field) {
            foreach (['from' => 'gte', 'until' => 'lte'] as $bound => $operator) {
                if (filled($state[$filter][$bound] ?? null)) {
                    $out[] = ['field' => $field, 'operator' => $operator, 'value' => Carbon::parse($state[$filter][$bound])->toDateString()];
                }
            }
        }

        return $out;
    }
}
