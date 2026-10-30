<?php

namespace App\Filament\Resources\VariablePayRequests\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\VariablePay;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\VariablePayRequests\VariablePayRequestResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\RequestChrome;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListVariablePayRequests extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = VariablePayRequestResource::class;

    public static function registerKey(): string
    {
        return 'variable_pay_requests';
    }

    /** HROne parity PO-1 (matrix 25 §25.4): HROne's Variable pay requests and cancellations registers as views of this one. */
    public static function presetViews(): array
    {
        return [
            'waiting' => ['label' => 'Waiting for approval', 'filters' => ['status' => ['values' => ['pending']]]],
            'approved' => ['label' => 'Approved, to be paid', 'filters' => ['status' => ['values' => ['approved']]]],
            'paid' => ['label' => 'Paid', 'filters' => ['status' => ['values' => ['paid']]]],
            'cancellations' => ['label' => 'Variable pay cancellations', 'filters' => ['status' => ['values' => ['cancelled']]]],
            'rejected' => ['label' => 'Rejected', 'filters' => ['status' => ['values' => ['rejected']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['variable_requests', 'variable_paid'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the variable pay dataset */
    public function registerReportFilters(): array
    {
        $state = (array) ($this->tableFilters ?? []);
        $out = TimeOfficeRegisters::reportFilters($state, ['status' => ['status', null]], ['request_dates' => 'requested_on']);
        if (filled($state['pay_month_filter']['value'] ?? null)) {
            $out[] = ['field' => 'pay_month', 'operator' => 'equals', 'value' => (string) $state['pay_month_filter']['value']];
        }

        return $out;
    }

    protected function getHeaderActions(): array
    {
        $mine = fn () => Employee::query()->where('user_id', auth()->id())->first();
        $fields = fn () => [
            Select::make('salary_component_id')->label('Pay item')->required()->native(false)
                ->options(fn () => SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->orderBy('name')->pluck('name', 'id')),
            TextInput::make('amount')->numeric()->minValue(1)->required(),
            TextInput::make('pay_month')->label('Pay in (YYYY-MM)')->default(now()->format('Y-m'))->regex('/^\d{4}-\d{2}$/')->required(),
            Textarea::make('reason')->required()->maxLength(500),
        ];
        $submit = function (Employee $employee, array $data) {
            try {
                $request = app(VariablePay::class)->request($employee, SalaryComponent::query()->findOrFail($data['salary_component_id']), (float) $data['amount'], $data['pay_month'], $data['reason']);
                RequestChrome::confirmed('variable_pay', $request, 'Sent for approval');
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
            }
        };

        return [
            // HROne parity PO-1: views, report and the payroll actions for payroll; an employee's own list keeps its actions.
            ...(PayrollRegisters::hrSide() ? [...$this->registerToolActions(), PayrollRegisters::actionsMenu()] : []),
            Action::make('request')->label('Request variable pay')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('payroll.request') && $mine() !== null)
                // HROne parity RQ-2: whose request, who approves it and the policy, around the module's own fields.
                ->schema(fn () => [RequestChrome::header(fn () => $mine()), ...$fields(), RequestChrome::route('variable_pay', fn () => $mine())])->action(fn (array $data) => $submit($mine(), $data)),
            Action::make('forEmployee')->label('For an employee')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn () => auth()->user()->can('payroll.manage') || auth()->user()->can('leave.approve'))
                ->schema(fn () => [Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required()->live(),
                    RequestChrome::header(fn (?Get $get) => RequestChrome::picked($get?->__invoke('employee_id'))), ...$fields(),
                    RequestChrome::route('variable_pay', fn (?Get $get) => RequestChrome::picked($get?->__invoke('employee_id')))])
                ->action(fn (array $data) => $submit(Employee::query()->findOrFail($data['employee_id']), $data)),
        ];
    }
}
