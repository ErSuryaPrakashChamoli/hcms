<?php

namespace App\Filament\Resources\StatutoryOverrides\Pages;

use App\Domain\Compliance\Models\StatutoryOverride;
use App\Domain\Compliance\Services\StatutoryOverrides;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\StatutoryOverrides\StatutoryOverrideResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListStatutoryOverrides extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = StatutoryOverrideResource::class;

    public static function registerKey(): string
    {
        return 'statutory_overrides';
    }

    public static function registerReports(): array
    {
        return ['statutory_override_audit'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the dataset (labels) */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'status' => ['status', fn (string $v) => StatutoryOverride::STATUSES[$v] ?? $v],
            'code' => ['line', fn (string $v) => StatutoryOverride::CODES[$v][0] ?? $v],
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity PO-1: views, report and the register's upload.
            ...$this->registerToolActions(),
            PayrollRegisters::uploads(['stat_overrides']),
            Action::make('override')->label('Override an amount')->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->visible(fn () => auth()->user()->can('create', StatutoryOverride::class))
                ->modalDescription('The engine still calculates the line; the run then uses this amount (0 removes the line). Recalculate the run after saving.')
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    TextInput::make('pay_month')->label('Payroll month (YYYY-MM)')->default(now()->format('Y-m'))->regex('/^\d{4}-\d{2}$/')->required(),
                    Select::make('code')->label('Line')->options(collect(StatutoryOverride::CODES)->map(fn ($c) => $c[0])->all())->required(),
                    TextInput::make('amount')->numeric()->minValue(0)->required(),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        $employee = Employee::query()->with('currentPosition')->findOrFail($data['employee_id']);
                        $company = $employee->currentPosition?->company ?? throw new RuntimeException('The employee has no current position, so their company is not known.');
                        [$year, $month] = array_map('intval', explode('-', $data['pay_month']));
                        app(StatutoryOverrides::class)->set($employee, PayrollPeriod::for($company, $year, $month), $data['code'], (float) $data['amount'], $data['reason'], auth()->user());
                        Notification::make()->success()->title('Override saved')->body('Recalculate the run for it to take effect.')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
