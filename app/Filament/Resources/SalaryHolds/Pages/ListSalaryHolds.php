<?php

namespace App\Filament\Resources\SalaryHolds\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Services\SalaryHolds;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\SalaryHolds\SalaryHoldResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListSalaryHolds extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = SalaryHoldResource::class;

    public static function registerKey(): string
    {
        return 'salary_holds';
    }

    /** HROne parity PO-1 (matrix 25 §25.6.6): holds and releases as views. */
    public static function presetViews(): array
    {
        return [
            'held' => ['label' => 'Held', 'filters' => ['status' => ['value' => 'held']]],
            'released' => ['label' => 'Released', 'filters' => ['status' => ['value' => 'released']]],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            PayrollRegisters::actionsMenu(),
            Action::make('hold')->label('Hold salary')->icon(Heroicon::OutlinedPauseCircle)
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    TextInput::make('pay_month')->label('Payroll month (YYYY-MM)')->default(now()->format('Y-m'))->regex('/^\d{4}-\d{2}$/')->required(),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(SalaryHolds::class)->hold(Employee::query()->findOrFail($data['employee_id']), $data['pay_month'], $data['reason']);
                        Notification::make()->success()->title('Salary held')->body('The employee stays out of that month\'s bank file until released.')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not held')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
