<?php

namespace App\Filament\Resources\AttendancePunches;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Filament\Resources\AttendancePunches\Pages\ListAttendancePunches;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/** Raw punch register (Phase 2 §6–§8): the evidence, read-only, with its processing state and a retry for failures. */
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
                TextColumn::make('employee.person.display_name')->label('Employee')->placeholder(fn (AttendancePunch $record) => 'Unknown: '.($record->payload['employee_code'] ?? '?')),
                TextColumn::make('direction')->badge()->color(fn (string $state) => match ($state) {
                    'in' => 'success', 'out' => 'warning', default => 'gray'
                }),
                TextColumn::make('source_type')->label('Source')->badge()->formatStateUsing(fn (string $state) => config("peopleos.attendance.source_types.{$state}", $state)),
                TextColumn::make('device.code')->label('Device')->placeholder('—')->toggleable(),
                TextColumn::make('processing_status')->label('Processing')->badge()->color(fn (string $state) => match ($state) {
                    'processed' => 'success', 'failed' => 'danger', 'ignored' => 'gray', default => 'info'
                }),
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
            ])
            ->defaultSort('punched_at', 'desc')
            ->recordActions([
                Action::make('retry')->label('Retry')->icon('heroicon-m-arrow-path')->color('warning')
                    ->visible(fn (AttendancePunch $record) => $record->processing_status === 'failed' && auth()->user()->can('attendance.manage'))
                    ->action(function (AttendancePunch $record) {
                        try {
                            $punch = app(PunchIngestion::class)->retry($record);
                            Notification::make()->{$punch->processing_status === 'failed' ? 'warning' : 'success'}()->title($punch->processing_status === 'failed' ? 'Still failing' : 'Punch queued for processing')->body($punch->processing_error)->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendancePunches::route('/')];
    }
}
