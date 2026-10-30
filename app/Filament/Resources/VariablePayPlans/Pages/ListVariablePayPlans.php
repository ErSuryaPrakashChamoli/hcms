<?php

namespace App\Filament\Resources\VariablePayPlans\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\VariablePayPlans;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\VariablePayPlans\VariablePayPlanResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListVariablePayPlans extends PeopleListRecords
{
    protected static string $resource = VariablePayPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('plan')->label('Plan variable pay')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('payroll.manage'))
                ->modalDescription('On the day set in Setup → Payroll, the employee\'s manager is asked for each month\'s payout; what they enter is approved and paid like any variable pay.')
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    Select::make('salary_component_id')->label('Pay item')->required()->native(false)
                        ->options(fn () => SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('name')->label('Plan name')->maxLength(120)->placeholder('e.g. Quarterly sales incentive'),
                    ...VariablePayPlanResource::scheduleField(),
                    Textarea::make('note')->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(VariablePayPlans::class)->create(Employee::query()->findOrFail($data['employee_id']), SalaryComponent::query()->findOrFail($data['salary_component_id']),
                            array_values((array) $data['schedule']), $data['name'] ?? null, auth()->user(), $data['note'] ?? null);
                        Notification::make()->success()->title('Variable pay planned')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }
}
