<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\Regularisations;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
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
            Action::make('regularise')->label('Regularise')->icon('heroicon-m-pencil-square')
                ->visible(fn (AttendanceRecord $record) => ! $record->is_locked && auth()->user()->can('regularise', $record))
                ->fillForm(fn (AttendanceRecord $record) => ['requested_in' => $record->first_in, 'requested_out' => $record->last_out, 'type' => in_array('missed_punch', $record->exceptions ?? [], true) ? 'missed_punch' : null])
                ->schema([
                    Select::make('type')->options(config('peopleos.attendance.regularisation_types'))->required(),
                    DateTimePicker::make('requested_in')->label('In')->seconds(false)->native(false),
                    DateTimePicker::make('requested_out')->label('Out')->seconds(false)->native(false)->after('requested_in'),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->action(function (AttendanceRecord $record, array $data) {
                    try {
                        $request = app(Regularisations::class)->request($record->employee, $record->date, $data['type'], $data['reason'], $data['requested_in'] ?? null, $data['requested_out'] ?? null);

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
            Action::make('reprocess')->label('Reprocess')->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn (AttendanceRecord $record) => ! $record->is_locked && auth()->user()->can('attendance.manage'))
                ->action(function (AttendanceRecord $record) {
                    app(AttendanceProcessor::class)->process($record->employee, $record->date);
                    Notification::make()->success()->title('Reprocessed')->send();
                }),
        ];
    }

    /** @return array<int, Column> */
    public static function columns(bool $withEmployee = true): array
    {
        $columns = [];

        if ($withEmployee) {
            $columns[] = TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (AttendanceRecord $record) => $record->employee?->employee_code);
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
}
