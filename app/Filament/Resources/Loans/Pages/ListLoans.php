<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Loans\Services\Loans;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Loans\LoanResource;
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

class ListLoans extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = LoanResource::class;

    public static function registerKey(): string
    {
        return 'loans';
    }

    /** HROne parity PO-1 (matrix 25 §25.5): HROne's Loans (dispense) and Loan requests registers as views of this one. */
    public static function presetViews(): array
    {
        return [
            'requests' => ['label' => 'Loan requests waiting for approval', 'filters' => ['status' => ['values' => ['pending']]]],
            'to_disburse' => ['label' => 'Approved, to disburse', 'filters' => ['status' => ['values' => ['approved']]]],
            'recovering' => ['label' => 'Loans being recovered', 'filters' => ['status' => ['values' => ['active']]]],
            'closed' => ['label' => 'Closed', 'filters' => ['status' => ['values' => ['closed']]]],
            'declined' => ['label' => 'Rejected or cancelled', 'filters' => ['status' => ['values' => ['rejected', 'cancelled']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['loan_requests', 'loan_summary', 'loans_outstanding', 'loan_ledger'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the loan datasets */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), ['status' => ['status', null]], ['request_dates' => 'requested_on']);
    }

    protected function getHeaderActions(): array
    {
        $mine = fn () => Employee::query()->where('user_id', auth()->id())->first();
        $fields = fn () => [
            Select::make('loan_type')->label('Type')->options(config('peopleos.loans.types'))->required()->native(false),
            TextInput::make('principal')->label('Amount')->numeric()->minValue(1)->required(),
            TextInput::make('installments')->label('Repay in (months)')->numeric()->minValue(1)->maxValue(120)->required(),
            TextInput::make('first_due_month')->label('Recovery from (YYYY-MM)')->default(now()->addMonthNoOverflow()->format('Y-m'))->regex('/^\d{4}-\d{2}$/')->required(),
            // HROne setup parity BP-9: the loan policy may ask for more than one guarantor.
            Select::make('guarantor_ids')->label('Guarantors')->options(fn () => CreateEmployee::managerOptions())->searchable()->multiple()->placeholder('—'),
            Textarea::make('reason')->required()->maxLength(500),
        ];
        $submit = function (Employee $employee, array $data) {
            try {
                $guarantors = Employee::query()->whereIn('id', array_map('intval', (array) ($data['guarantor_ids'] ?? [])))->get()->sortBy(fn (Employee $g) => array_search($g->id, array_map('intval', (array) $data['guarantor_ids']), true))->values();
                $loan = app(Loans::class)->request($employee, $data['loan_type'], (float) $data['principal'], (int) $data['installments'], $data['reason'], auth()->user(),
                    $guarantors->first(), $data['first_due_month'], $guarantors->slice(1)->values()->all());
                RequestChrome::confirmed('loan', $loan, "Loan {$loan->number} sent for approval", 'About '.number_format((float) $loan->emi, 2).' a month.'.($loan->exceptionSummary() !== null ? ' Beyond the loan policy: the approver decides.' : ''));
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
            }
        };

        return [
            // HROne parity PO-1: views, report and the payroll actions for payroll; an employee's own list keeps its actions.
            ...(PayrollRegisters::hrSide() ? [...$this->registerToolActions(), PayrollRegisters::actionsMenu()] : []),
            Action::make('requestLoan')->label('Request a loan or advance')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('payroll.request') && $mine() !== null)
                // HROne parity RQ-2: whose request, who approves it and the policy, around the module's own fields.
                ->schema(fn () => [RequestChrome::header(fn () => $mine()), ...$fields(), RequestChrome::route('loan', fn () => $mine())])->action(fn (array $data) => $submit($mine(), $data)),
            Action::make('onBehalf')->label('Raise on behalf')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->authorize(fn () => auth()->user()->can('payroll.manage'))
                ->schema(fn () => [Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required()->live(),
                    RequestChrome::header(fn (?Get $get) => RequestChrome::picked($get?->__invoke('employee_id'))), ...$fields(),
                    RequestChrome::route('loan', fn (?Get $get) => RequestChrome::picked($get?->__invoke('employee_id')))])
                ->action(fn (array $data) => $submit(Employee::query()->findOrFail($data['employee_id']), $data)),
        ];
    }
}
