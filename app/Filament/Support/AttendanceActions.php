<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Experience\BulkOperations\ChangeAttendanceStatusOperation;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AttendanceCalendar;
use App\Livewire\Attendance\DayDrawer;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use RuntimeException;

/** Row actions shared by the records list, the exception centre and the Employee 360. */
final class AttendanceActions
{
    /** @return array<int, Action> */
    public static function forRecords(): array
    {
        return [
            // HROne parity TX-7 (§24.5.2 #3, §24.5.1 #3): the day drawer and the employee's month, for the HR side (attendance.view)
            // on days the viewer may see; an employee's own list is unchanged.
            Action::make('day')->label('Day')->icon('heroicon-m-queue-list')->color('gray')
                ->visible(fn (AttendanceRecord $record) => DayDrawer::available() && auth()->user()->can('view', $record))
                ->action(fn (AttendanceRecord $record, $livewire) => $livewire->dispatch('pos-day-drawer-open', employee: (int) $record->employee_id, date: $record->date->toDateString())),
            Action::make('calendar')->label('Calendar')->icon('heroicon-m-calendar-days')->color('gray')
                ->visible(fn (AttendanceRecord $record) => TimeOfficeRegisters::hrSide('attendance.view') && AttendanceCalendar::canAccess() && auth()->user()->can('view', $record))
                ->url(fn (AttendanceRecord $record) => AttendanceCalendar::getUrl(['employee' => $record->employee_id, 'month' => $record->date->format('Y-m')])),
            Action::make('regularise')->label('Regularise')->icon('heroicon-m-pencil-square')
                ->visible(fn (AttendanceRecord $record) => ! $record->is_locked && auth()->user()->can('regularise', $record))
                ->fillForm(fn (AttendanceRecord $record) => ['requested_in' => $record->first_in, 'requested_out' => $record->last_out, 'type' => in_array('missed_punch', $record->exceptions ?? [], true) ? 'missed_punch' : null])
                ->schema([
                    Select::make('type')->options(config('peopleos.attendance.regularisation_types'))->required(),
                    DateTimePicker::make('requested_in')->label('In')->seconds(false)->native(false),
                    DateTimePicker::make('requested_out')->label('Out')->seconds(false)->native(false)->after('requested_in'),
                    Textarea::make('reason')->required()->maxLength(255),
                    self::attachmentsField(),
                ])
                ->action(function (AttendanceRecord $record, array $data) {
                    try {
                        $request = app(Regularisations::class)->request($record->employee, $record->date, $data['type'], $data['reason'], $data['requested_in'] ?? null, $data['requested_out'] ?? null, null, array_values((array) ($data['attachments'] ?? [])));

                        if (auth()->user()->can('attendance.manage')) {
                            app(Regularisations::class)->approve($request, 'Applied by HR');
                            Notification::make()->success()->title('Regularised and reprocessed')->send();
                        } else {
                            Notification::make()->success()->title('Regularisation requested')->send();
                        }
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                    }
                }),
            // HROne parity TX-6 (§24.5.1 #4 "Edit"): the day's status for the whole day or each half (and worked minutes) — the
            // "Change attendance status" form for one day, through AttendanceOverrides::setRange (attendance.override on the
            // record in scope; never one's own day; locked days only with corrections after lock).
            Action::make('editStatus')->label('Edit')->icon('heroicon-m-adjustments-horizontal')->color('gray')
                ->visible(fn (AttendanceRecord $record) => self::canEditStatus($record))
                ->modalHeading(fn (AttendanceRecord $record) => 'Edit attendance · '.$record->employee?->auditLabel().' · '.$record->date->format('D j M Y'))
                ->modalDescription(fn (AttendanceRecord $record) => 'Now: '.$record->statusLabel().($record->is_half_day_leave ? ' (with half-day leave)' : '').'. The day is recalculated when saved; approved full-day leave keeps the day.')
                ->modalSubmitActionLabel('Save')
                ->fillForm(fn (AttendanceRecord $record) => ChangeAttendanceStatusOperation::defaultsFor($record))
                ->schema([...ChangeAttendanceStatusOperation::statusFields(true), Textarea::make('reason')->required()->maxLength(500)])
                ->action(function (AttendanceRecord $record, array $data) {
                    try {
                        [$status, $first, $second] = ChangeAttendanceStatusOperation::statusesFrom($data);
                        $result = app(AttendanceOverrides::class)->setRange([$record->employee], $record->date, $record->date, $status, $first, $second, (string) ($data['reason'] ?? ''), auth()->user(),
                            filled($data['worked_minutes'] ?? null) ? (int) $data['worked_minutes'] : null);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

                        return;
                    }
                    if ($result['done'] === []) {
                        Notification::make()->danger()->title('Not saved')->body($result['refused'][0]['reason'] ?? 'Nothing was changed.')->send();

                        return;
                    }
                    Notification::make()->success()->title('Day recalculated: '.$record->refresh()->statusLabel())->body($result['done'][0]['message'])->send();
                }),
            Action::make('approveOvertime')->label('Approve OT')->icon('heroicon-m-clock')->color('warning')
                ->visible(fn (AttendanceRecord $record) => $record->overtime_minutes > 0 && ! $record->is_locked && auth()->user()->can('approve', $record))
                ->fillForm(fn (AttendanceRecord $record) => ['minutes' => $record->overtime_minutes])
                ->schema([
                    TextInput::make('minutes')->numeric()->minValue(0)->required()->helperText(fn (AttendanceRecord $record) => "Recorded: {$record->overtime_minutes} minutes"),
                    Textarea::make('note')->maxLength(255),
                ])
                ->action(function (AttendanceRecord $record, array $data) {
                    try {
                        app(Regularisations::class)->approveOvertime($record, (int) $data['minutes'], $data['note'] ?? null);
                        Notification::make()->success()->title('Overtime approved')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                    }
                }),
            Action::make('rejectOvertime')->label('Reject OT')->icon('heroicon-m-x-circle')->color('danger')
                ->visible(fn (AttendanceRecord $record) => $record->overtime_minutes > 0 && $record->overtime_status !== 'rejected' && ! $record->is_locked && auth()->user()->can('approve', $record))
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(function (AttendanceRecord $record, array $data) {
                    try {
                        app(Regularisations::class)->rejectOvertime($record, $data['reason']);
                        Notification::make()->success()->title('Overtime rejected')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                    }
                }),
            // HROne parity TX-2: attendance.process on the record (attendance.manage also grants it).
            Action::make('reprocess')->label('Reprocess')->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn (AttendanceRecord $record) => ! $record->is_locked && auth()->user()->can('process', $record))
                ->action(function (AttendanceRecord $record) {
                    app(AttendanceProcessor::class)->process($record->employee, $record->date);
                    Notification::make()->success()->title('Reprocessed')->send();
                }),
        ];
    }

    /** HROne parity TX-6: "Edit" is offered on days in the viewer's override scope, not their own, and not locked (unless corrections after lock are allowed). */
    public static function canEditStatus(AttendanceRecord $record): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->can('override', $record)) {
            return false;
        }
        if ($record->employee?->user_id !== null && (int) $record->employee->user_id === (int) $user->id) {
            return false;
        }

        return ! $record->is_locked || (bool) app(SettingsRepository::class)->get('attendance.allow_corrections_after_lock', false);
    }

    /** @return array<int, Column> */
    public static function columns(bool $withEmployee = true): array
    {
        $columns = [];

        if ($withEmployee) {
            // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
            $columns[] = TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (AttendanceRecord $record) => $record->employee?->employee_code);
        }

        return [
            ...$columns,
            TextColumn::make('date')->date('D, d M')->sortable(),
            TextColumn::make('shift.name')->label('Shift')->placeholder('—'),
            TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.attendance.statuses.{$state}", $state))->color(fn (string $state) => match ($state) {
                'present', 'wfh', 'on_duty' => 'success', 'half_day', 'incomplete' => 'warning', 'absent' => 'danger', default => 'gray',
            }),
            TextColumn::make('first_in')->label('In')->time('H:i')->placeholder('—'),
            TextColumn::make('last_out')->label('Out')->time('H:i')->placeholder('—'),
            TextColumn::make('worked_minutes')->label('Worked')->formatStateUsing(fn (int $state) => $state ? sprintf('%d:%02d', intdiv($state, 60), $state % 60) : '—'),
            TextColumn::make('late_minutes')->label('Late')->suffix(' m')->placeholder('—')->formatStateUsing(fn ($state) => $state ?: null)->color('danger'),
            TextColumn::make('overtime_minutes')->label('OT')->formatStateUsing(fn (int $state, AttendanceRecord $record) => $state ? "{$record->overtime_approved_minutes}/{$state} m" : '—'),
            TextColumn::make('exceptions')->badge()->color('danger')->formatStateUsing(fn (string $state) => config("peopleos.attendance.exception_types.{$state}", $state))->placeholder('—'),
            IconColumn::make('is_regularised')->label('Reg.')->boolean()->toggleable(),
        ];
    }

    /** HROne setup parity (TO-3): documents supporting a regularisation (required when the attendance policy says so). */
    public static function attachmentsField(): FileUpload
    {
        return FileUpload::make('attachments')->label('Supporting documents')->multiple()->maxFiles(5)->disk(config('peopleos.documents.disk', 'local'))
            ->directory('regularisations')->visibility('private')->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
            ->maxSize((int) config('peopleos.documents.max_kb', 10240));
    }
}
