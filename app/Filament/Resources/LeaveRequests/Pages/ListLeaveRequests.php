<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ListLeaveRequests extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = LeaveRequestResource::class;

    /** HROne parity RQ-1 (matrix 20 §20.1.19 #4): bulk cancel for HR, on the rows ticked or on all matching. */
    public const BULK_OPERATIONS = ['leave_requests.cancel'];

    public static function registerKey(): string
    {
        return 'leave_requests';
    }

    public static function registerBulkOperations(): array
    {
        return self::BULK_OPERATIONS;
    }

    /** HROne parity TX-1: HROne's Leave requests and Leave cancellations registers as views of this register. */
    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Waiting for a decision', 'filters' => ['status' => ['values' => ['pending']]]],
            'approved' => ['label' => 'Approved leave', 'filters' => ['status' => ['values' => ['approved']]]],
            'leave_cancellations' => ['label' => 'Leave cancellations (requested, waiting or decided)', 'filters' => ['cancellation' => ['values' => ['waiting', 'decided']]]],
            'cancellations_waiting' => ['label' => 'Cancellations waiting for a decision', 'filters' => ['cancellation' => ['values' => ['waiting']]]],
            'cancelled' => ['label' => 'Cancelled leave', 'filters' => ['status' => ['values' => ['cancelled']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['pending_leave_approvals', 'leave_this_month', 'leave_availed_this_year', 'rejected_cancelled_leave', 'backdated_leave', 'lop_leave_this_month'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the leave dataset */
    public function registerReportFilters(): array
    {
        $state = (array) ($this->tableFilters ?? []);
        $out = TimeOfficeRegisters::reportFilters($state, [
            'status' => ['status', null],
            'leave_type_id' => ['leave_type', fn (string $id) => (string) (LeaveType::query()->whereKey((int) $id)->value('name') ?? '')],
        ], ['request_dates' => 'applied_on', 'decision_dates' => 'reviewed_on']);
        // The register's "Period" (leave overlapping the window) as report conditions on the leave dates.
        foreach (['from' => ['to_date', 'gte'], 'until' => ['from_date', 'lte']] as $bound => [$field, $operator]) {
            if (filled($state['period'][$bound] ?? null)) {
                $out[] = ['field' => $field, 'operator' => $operator, 'value' => Carbon::parse($state['period'][$bound])->toDateString()];
            }
        }

        return $out;
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity TX-1: views, report and uploads for the HR side; an employee's own list is unchanged.
            ...(TimeOfficeRegisters::hrSide('leave.view') ? [...$this->registerToolActions(), TimeOfficeRegisters::uploads(['leave_history'])] : []),
            Action::make('applyOnBehalf')
                ->label('Apply on behalf')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn () => auth()->user()->can('leave.manage'))
                ->schema(fn () => [
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required()->live(),
                    ...LeaveActions::applyForm(fn () => Employee::query()->find(data_get($this->mountedActions, '0.data.employee_id') ?? 0) ?? new Employee),
                ])
                ->action(fn (array $data) => LeaveActions::apply(Employee::query()->findOrFail($data['employee_id']), $data)),
        ];
    }
}
