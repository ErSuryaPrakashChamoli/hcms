<?php

namespace App\Filament\Resources\AttendanceRegularisations;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendanceRegularisations\Pages\ListAttendanceRegularisations;
use App\Filament\Resources\AttendanceRegularisations\Pages\ViewAttendanceRegularisation;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeDetailEntries;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;
use UnitEnum;

class AttendanceRegularisationResource extends Resource
{
    protected static ?string $model = AttendanceRegularisation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Regularisations';

    protected static ?int $navigationSort = 60;

    /** HROne parity TX-1: date ranges of the register (filter name => [label, column]). */
    public const DATE_FILTERS = [
        'effective_dates' => ['Attendance date', 'date'],
        'request_dates' => ['Raised', 'created_at'],
        'decision_dates' => ['Decided', 'reviewed_at'],
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AttendanceRegularisation::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    /** Employees without attendance.view only ever see their own rows, whatever the table renders (server-side, not UI hiding). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'requester', 'reviewer']);

        return auth()->user()?->can('attendance.view')
            ? $query
            : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        $decide = fn (bool $approve) => function (AttendanceRegularisation $record, array $data) use ($approve) {
            try {
                $approve ? app(Regularisations::class)->approve($record, $data['note'] ?? null) : app(Regularisations::class)->reject($record, $data['note']);
                Notification::make()->success()->title($approve ? 'Approved and reprocessed' : 'Rejected')->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };

        return $table
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (AttendanceRegularisation $record) => $record->employee?->employee_code),
                TextColumn::make('date')->date('D, d M')->sortable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.attendance.regularisation_types.{$state}", $state)),
                TextColumn::make('requested_in')->label('In')->time('H:i')->placeholder('—'),
                TextColumn::make('requested_out')->label('Out')->time('H:i')->placeholder('—'),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('requester.name')->label('By')->placeholder('—')->toggleable(),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Raised')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(AttendanceRegularisation::STATUSES)->default('pending'),
                // HROne parity TX-1: request type, the WO-2 employee filters and attendance / raised / decided date ranges (HR side).
                SelectFilter::make('type')->label('Request type')->options(fn () => (array) config('peopleos.attendance.regularisation_types'))->multiple()
                    ->visible(fn () => TimeOfficeRegisters::hrSide('attendance.view')),
                ...TimeOfficeRegisters::filters('attendance.view', self::DATE_FILTERS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (AttendanceRegularisation $record) => $record->status === 'pending' && auth()->user()->can('approve', $record))
                    ->schema([Textarea::make('note')->maxLength(255)])
                    ->action($decide(true)),
                Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (AttendanceRegularisation $record) => $record->status === 'pending' && auth()->user()->can('approve', $record))
                    ->schema([Textarea::make('note')->required()->maxLength(255)])
                    ->action($decide(false)),
            ]);
    }

    /** HROne parity TX-1: the regularisation's detail (read-only) with its documents and the day as it stands now. */
    public static function infolist(Schema $schema): Schema
    {
        $time = fn (?array $snapshot, string $key) => filled($snapshot[$key] ?? null) ? Carbon::parse($snapshot[$key])->format('H:i') : null;

        return $schema->columns(1)->components([
            Section::make('Request')->columns(3)->schema([
                TextEntry::make('employee_name')->label('Employee')->state(fn (AttendanceRegularisation $r) => trim(($r->employee?->display_name ?? '').' · '.($r->employee?->employee_code ?? ''), ' ·')),
                TextEntry::make('date')->label('Attendance date')->date('D, d M Y'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => AttendanceRegularisation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                    }),
                TextEntry::make('type')->label('Request type')->formatStateUsing(fn (string $state) => config("peopleos.attendance.regularisation_types.{$state}", $state)),
                TextEntry::make('requested_in')->label('Requested check-in')->time('H:i')->placeholder('—'),
                TextEntry::make('requested_out')->label('Requested check-out')->time('H:i')->placeholder('—'),
                TextEntry::make('reason')->label('Remarks')->placeholder('—')->columnSpanFull(),
                TimeOfficeDetailEntries::attachments('regularisation'),
                TextEntry::make('created_at')->label('Raised')->dateTime(),
                TextEntry::make('requester.name')->label('Raised by')->placeholder('The employee'),
                TimeOfficeDetailEntries::workflow(),
            ]),
            TimeOfficeDetailEntries::dayStatus(fn (AttendanceRegularisation $r) => [$r->employee, $r->date, $r->date]),
            Section::make('Decision')->columns(3)->schema([
                TextEntry::make('reviewer.name')->label('Decided by')->placeholder('—'),
                TextEntry::make('reviewed_at')->label('Decided on')->dateTime()->placeholder('—'),
                TextEntry::make('review_note')->label('Approver comments')->placeholder('—'),
                TextEntry::make('before')->label('Day before approval')->state(fn (AttendanceRegularisation $r) => $r->original_snapshot === null ? null
                    : trim(config('peopleos.attendance.statuses.'.($r->original_snapshot['status'] ?? ''), $r->original_snapshot['status'] ?? '').' '.implode('–', array_filter([$time($r->original_snapshot, 'first_in'), $time($r->original_snapshot, 'last_out')]))))->placeholder('—'),
                TextEntry::make('after')->label('Day after approval')->state(fn (AttendanceRegularisation $r) => $r->resulting_snapshot === null ? null
                    : trim(config('peopleos.attendance.statuses.'.($r->resulting_snapshot['status'] ?? ''), $r->resulting_snapshot['status'] ?? '').' '.implode('–', array_filter([$time($r->resulting_snapshot, 'first_in'), $time($r->resulting_snapshot, 'last_out')]))))->placeholder('—'),
                TextEntry::make('cancelled_at')->label('Withdrawn on')->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceRegularisations::route('/'),
            'view' => ViewAttendanceRegularisation::route('/{record}'),
        ];
    }
}
