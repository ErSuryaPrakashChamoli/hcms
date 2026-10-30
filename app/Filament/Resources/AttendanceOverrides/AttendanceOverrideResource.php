<?php

namespace App\Filament\Resources\AttendanceOverrides;

use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Filament\Resources\AttendanceOverrides\Pages\ListAttendanceOverrides;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** HROne parity HP-1: HR's "change attendance status" and "override working hours" for a day. */
class AttendanceOverrideResource extends Resource
{
    protected static ?string $model = AttendanceOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Day overrides';

    protected static ?int $navigationSort = 62;

    /** HROne parity TX-2: attendance.override (attendance.manage also grants it). */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('attendance.override') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'creator']))
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (AttendanceOverride $r) => $r->employee?->employee_code),
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (?string $state) => $state ? config("peopleos.attendance.statuses.{$state}", $state) : null)->placeholder('As calculated'),
                TextColumn::make('worked_minutes')->label('Worked')->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 60).'h '.str_pad((string) ($state % 60), 2, '0', STR_PAD_LEFT).'m')->placeholder('As calculated'),
                TextColumn::make('reason')->limit(60)->wrap(),
                TextColumn::make('creator.name')->label('By')->placeholder('—'),
            ])
            // HROne parity TX-1: status, day and the WO-2 employee filters.
            ->filters([
                SelectFilter::make('status')->options(fn () => collect(AttendanceOverride::STATUSES)->mapWithKeys(fn ($s) => [$s => config("peopleos.attendance.statuses.{$s}", $s)])->all())->multiple(),
                ...TimeOfficeRegisters::filters('attendance.view', ['effective_dates' => ['Day', 'date']]),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([
                Action::make('remove')->label('Remove')->icon('heroicon-m-trash')->color('danger')
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(function (AttendanceOverride $record, array $data) {
                        try {
                            app(AttendanceOverrides::class)->clear($record, $data['reason']);
                            Notification::make()->success()->title('Override removed and the day recalculated')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendanceOverrides::route('/')];
    }
}
