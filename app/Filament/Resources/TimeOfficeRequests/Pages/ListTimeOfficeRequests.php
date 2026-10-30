<?php

namespace App\Filament\Resources\TimeOfficeRequests\Pages;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeActions;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ListTimeOfficeRequests extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = TimeOfficeRequestResource::class;

    public static function registerKey(): string
    {
        return 'time_office_requests';
    }

    /**
     * HROne parity TX-1: HROne's separate request registers (Shift requests, On duty, Short leave, Restricted holidays,
     * Overtime, Comp off and their cancellations) as views of this one register.
     */
    public static function presetViews(): array
    {
        $cancellations = ['cancel_requested', 'cancelled'];
        $view = fn (string $label, array $types, ?array $statuses = null) => ['label' => $label, 'filters' => array_filter(['type' => ['values' => $types], 'status' => $statuses !== null ? ['values' => $statuses] : null])];

        return [
            'pending' => ['label' => 'Waiting for a decision', 'filters' => ['status' => ['values' => ['pending']]]],
            'cancellations' => ['label' => 'Cancellations waiting for a decision', 'filters' => ['status' => ['values' => ['cancel_requested']]]],
            'shift_requests' => $view('Shift requests (shift and week-off changes)', ['shift_change', 'week_off_change']),
            'on_duty' => $view('On duty requests', ['on_duty']),
            'on_duty_cancellations' => $view('On duty cancellations', ['on_duty'], $cancellations),
            'short_leave' => $view('Short leave requests', ['short_leave']),
            'short_leave_cancellations' => $view('Short leave cancellations', ['short_leave'], $cancellations),
            'restricted_holidays' => $view('Restricted holidays', ['restricted_holiday']),
            'restricted_holiday_cancellations' => $view('Restricted holiday cancellations', ['restricted_holiday'], $cancellations),
            'overtime' => $view('Overtime requests', ['overtime']),
            'comp_off' => $view('Comp off requests', ['comp_off']),
        ];
    }

    public static function registerReports(): array
    {
        return ['time_office_pending', 'shift_change_requests', 'on_duty_requests', 'short_leave_requests', 'restricted_holiday_requests', 'overtime_claims', 'comp_off_requests', 'attendance_reconciliation'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the time_office_requests dataset */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'status' => ['status', fn (string $v) => TimeOfficeRequest::STATUSES[$v] ?? $v],
            'type' => ['type', fn (string $v) => TimeOfficeRequest::TYPES[$v] ?? $v],
        ], ['effective_dates' => 'from_date', 'request_dates' => 'applied_on', 'decision_dates' => 'reviewed_on']);
    }

    protected function getHeaderActions(): array
    {
        $mine = fn () => Employee::query()->where('user_id', auth()->id())->first();

        return [
            // HROne parity TX-1: views, report and uploads for the HR side; an employee's own list keeps its two actions.
            ...(TimeOfficeRegisters::hrSide('attendance.view') ? [...$this->registerToolActions(), TimeOfficeRegisters::uploads(['overtime'])] : []),
            Action::make('newRequest')
                ->label('New request')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('attendance.regularise') && $mine() !== null)
                // HROne parity RQ-1: "New request" opens this form with the kind chosen (?actionArguments[type]=…).
                ->mountUsing(fn (?Schema $schema, array $arguments) => self::fillType($schema, $arguments))
                ->schema(fn () => TimeOfficeActions::form($mine))
                ->action(fn (array $data) => TimeOfficeActions::submit($mine(), $data)),
            Action::make('onBehalf')
                ->label('Raise on behalf')
                ->icon(Heroicon::OutlinedUserPlus)
                ->color('gray')
                ->authorize(fn () => auth()->user()->can('attendance.manage'))
                ->mountUsing(fn (?Schema $schema, array $arguments) => self::fillType($schema, $arguments))
                ->schema(fn () => [
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required()->live(),
                    ...TimeOfficeActions::form(fn () => Employee::query()->find(data_get($this->mountedActions, '0.data.employee_id') ?? 0)),
                ])
                ->action(fn (array $data) => TimeOfficeActions::submit(Employee::query()->findOrFail($data['employee_id']), $data)),
        ];
    }

    /**
     * The form's usual defaults, with the request kind chosen when the link names a known one (HROne parity RQ-1: the
     * request launcher opens "New request" / "Raise on behalf" on a kind). Without an argument this is Filament's default.
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function fillType(?Schema $schema, array $arguments): void
    {
        $schema?->fill();
        $type = is_string($arguments['type'] ?? null) ? $arguments['type'] : null;
        if ($schema !== null && $type !== null && array_key_exists($type, TimeOfficeRequest::TYPES)) {
            $schema->fill([...$schema->getRawState(), 'type' => $type]);
        }
    }
}
