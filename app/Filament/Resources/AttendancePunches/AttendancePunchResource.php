<?php

namespace App\Filament\Resources\AttendancePunches;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Services\PunchCorrections;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendancePunches\Pages\ListAttendancePunches;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeRegisters;
use App\Livewire\Attendance\DayDrawer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;
use UnitEnum;

/**
 * Raw punch register (Phase 2 §6–§8): the evidence, read-only, with its processing state and a retry for failures.
 * HROne parity TX-7 (D-TX5): "Exclude" / "Restore" with a reason (PunchCorrections — never deleted; the day is recalculated)
 * and "Day" opening the HR day drawer.
 */
class AttendancePunchResource extends Resource
{
    protected static ?string $model = AttendancePunch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Punches';

    protected static ?int $navigationSort = 12;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'device']);
    }

    public static function table(Table $table): Table
    {
        $canSeePayload = fn () => auth()->user()?->can('attendance.manage') ?? false;

        return $table
            ->columns([
                TextColumn::make('punched_at')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('employee.employee_code')->label('Code')->placeholder('—')->searchable(),
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->placeholder(fn (AttendancePunch $record) => 'Unknown: '.($record->payload['employee_code'] ?? '?')),
                TextColumn::make('direction')->badge()->color(fn (string $state) => match ($state) {
                    'in' => 'success', 'out' => 'warning', default => 'gray'
                }),
                TextColumn::make('source_type')->label('Source')->badge()->formatStateUsing(fn (string $state) => config("peopleos.attendance.source_types.{$state}", $state)),
                TextColumn::make('device.code')->label('Device')->placeholder('—')->toggleable(),
                TextColumn::make('processing_status')->label('Processing')->badge()->color(fn (string $state) => match ($state) {
                    'processed' => 'success', 'failed' => 'danger', 'ignored' => 'gray', 'excluded' => 'warning', default => 'info'
                }),
                // HROne parity TX-7: why a punch was excluded.
                TextColumn::make('exclusion_reason')->label('Excluded because')->wrap()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('processing_error')->label('Error')->wrap()->placeholder('—')->toggleable(),
                TextColumn::make('source_timezone')->label('Source TZ')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_id')->label('External id')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payload')->label('Payload')->visible($canSeePayload)->state(fn (AttendancePunch $record) => $record->payload ? json_encode($record->payload) : null)->limit(60)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('processing_status')->label('Processing')->options(array_combine(AttendancePunch::STATUSES, array_map('ucfirst', AttendancePunch::STATUSES))),
                SelectFilter::make('source_type')->label('Source')->options(config('peopleos.attendance.source_types')),
                Filter::make('date')->schema([DatePicker::make('from')->native(false), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('punched_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('punched_at', '<=', $d))),
                // HROne parity TX-1: the WO-2 employee filters.
                ...TimeOfficeRegisters::filters('attendance.view'),
            ])
            ->defaultSort('punched_at', 'desc')
            ->recordActions([
                Action::make('retry')->label('Retry')->icon('heroicon-m-arrow-path')->color('warning')
                    ->visible(fn (AttendancePunch $record) => $record->processing_status === 'failed' && auth()->user()->can('punch', $record))
                    ->action(function (AttendancePunch $record) {
                        try {
                            $punch = app(PunchIngestion::class)->retry($record);
                            Notification::make()->{$punch->processing_status === 'failed' ? 'warning' : 'success'}()->title($punch->processing_status === 'failed' ? 'Still failing' : 'Punch queued for processing')->body($punch->processing_error)->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
                ...self::correctionActions(),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * HROne parity TX-7: "Day" (the drawer for the day the punch counts on), "Exclude" and "Restore" with a reason — for
     * holders of attendance.punch in scope, or managers of the employee with the team option; never one's own punches.
     *
     * @return list<Action>
     */
    public static function correctionActions(): array
    {
        $may = fn (AttendancePunch $record): bool => $record->employee_id !== null && $record->employee !== null && app(PunchCorrections::class)->mayChange(auth()->user(), $record->employee);
        $run = function (string $what, AttendancePunch $record, array $data): void {
            try {
                $corrections = app(PunchCorrections::class);
                $what === 'exclude' ? $corrections->exclude($record, (string) ($data['reason'] ?? ''), auth()->user()) : $corrections->restore($record, (string) ($data['reason'] ?? ''), auth()->user());
                Notification::make()->success()->title($what === 'exclude' ? 'Punch excluded and the day recalculated' : 'Punch restored and the day recalculated')->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();
            }
        };

        return [
            Action::make('day')->label('Day')->icon('heroicon-m-queue-list')->color('gray')
                ->visible(fn (AttendancePunch $record) => $record->employee_id !== null && DayDrawer::available())
                ->action(function (AttendancePunch $record, $livewire) {
                    $employee = Employee::query()->find($record->employee_id);
                    if ($employee !== null) {
                        $livewire->dispatch('pos-day-drawer-open', employee: (int) $employee->id, date: app(PunchCorrections::class)->daysOf($employee, $record->punched_at)[0]->toDateString());
                    }
                }),
            Action::make('exclude')->label('Exclude')->icon('heroicon-m-no-symbol')->color('danger')
                ->visible(fn (AttendancePunch $record) => ! in_array($record->processing_status, AttendancePunch::NOT_COUNTED, true) && $may($record))
                ->modalHeading('Exclude this punch')
                ->modalDescription('The punch is kept as evidence (nothing is deleted, the device data stays) and the day is recalculated without it. It can be restored.')
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                ->action(fn (AttendancePunch $record, array $data) => $run('exclude', $record, $data)),
            Action::make('restore')->label('Restore')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                ->visible(fn (AttendancePunch $record) => $record->processing_status === 'excluded' && $may($record))
                ->modalHeading('Restore this punch')
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                ->action(fn (AttendancePunch $record, array $data) => $run('restore', $record, $data)),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendancePunches::route('/')];
    }
}
