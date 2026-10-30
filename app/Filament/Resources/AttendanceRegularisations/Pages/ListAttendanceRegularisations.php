<?php

namespace App\Filament\Resources\AttendanceRegularisations\Pages;

use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Support\AttendanceActions;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListAttendanceRegularisations extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AttendanceRegularisationResource::class;

    public static function registerKey(): string
    {
        return 'regularisations';
    }

    /** HROne parity TX-1: the regularisation register's views (waiting, decided, everything). */
    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Waiting for a decision', 'filters' => ['status' => ['value' => 'pending']]],
            'approved' => ['label' => 'Approved', 'filters' => ['status' => ['value' => 'approved']]],
            'rejected' => ['label' => 'Rejected', 'filters' => ['status' => ['value' => 'rejected']]],
            'all' => ['label' => 'Every regularisation', 'filters' => ['status' => ['value' => null]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['regularisations_pending', 'regularisations_this_month'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the attendance_regularisations dataset */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'status' => ['status', null],
            'type' => ['type', fn (string $v) => (string) config("peopleos.attendance.regularisation_types.{$v}", $v)],
        ], ['effective_dates' => 'date', 'request_dates' => 'applied_on', 'decision_dates' => 'reviewed_on']);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...(TimeOfficeRegisters::hrSide('attendance.view') ? $this->registerToolActions() : []),
            self::raiseOnBehalfAction(),
        ];
    }

    /**
     * HROne parity TX-1 (matrix 24 §24.8 #1): HR raises a regularisation for an employee and either approves it at once
     * (what "Regularise" on an attendance record does today) or sends it to the approver like the employee's own request.
     * The request goes through Regularisations::request, so the attendance policy's windows and rules for HR apply.
     */
    public static function raiseOnBehalfAction(): Action
    {
        return Action::make('raiseOnBehalf')
            ->label('Raise on behalf')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->authorize(fn () => auth()->user()?->can('attendance.manage') ?? false)
            ->modalHeading('Raise a regularisation on behalf of an employee')
            ->schema([
                Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required()->live(),
                // HROne parity RQ-2: whose request it is (the employee picked).
                RequestChrome::header(fn (?Get $get) => RequestChrome::picked($get?->__invoke('employee_id'))),
                DatePicker::make('date')->label('Attendance date')->native(false)->required()->maxDate(today()),
                Select::make('type')->label('Request type')->options(fn () => (array) config('peopleos.attendance.regularisation_types'))->required()->native(false),
                DateTimePicker::make('requested_in')->label('Check-in')->seconds(false)->native(false),
                DateTimePicker::make('requested_out')->label('Check-out')->seconds(false)->native(false)->after('requested_in'),
                Textarea::make('reason')->required()->maxLength(255),
                AttendanceActions::attachmentsField(),
                Radio::make('decision')->label('After raising it')->required()->default('approve')
                    ->options(['approve' => 'Approve now', 'send' => 'Send for approval'])
                    ->descriptions([
                        'approve' => 'Applied and approved by you; the day is reprocessed at once.',
                        'send' => 'Waits for the approver (the Approval Center or the regularisation workflow), like the employee\'s own request.',
                    ]),
                // HROne parity RQ-2: who approves it when sent for approval (a read-only preview).
                RequestChrome::route('attendance_regularisation', fn (?Get $get) => RequestChrome::picked($get?->__invoke('employee_id'))),
            ])
            ->action(function (array $data) {
                try {
                    $employee = Employee::query()->findOrFail($data['employee_id']);
                    $request = app(Regularisations::class)->request($employee, $data['date'], $data['type'], $data['reason'], $data['requested_in'] ?? null, $data['requested_out'] ?? null, auth()->user(), array_values((array) ($data['attachments'] ?? [])));
                    if (($data['decision'] ?? 'approve') === 'approve') {
                        app(Regularisations::class)->approve($request, 'Applied by HR');
                        Notification::make()->success()->title('Regularised and reprocessed')->send();

                        return;
                    }
                    Notification::make()->success()->title('Regularisation sent for approval')->body('Waiting for a decision on '.$request->date->format('d M Y').'.')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not raised')->body($e->getMessage())->send();
                }
            });
    }
}
