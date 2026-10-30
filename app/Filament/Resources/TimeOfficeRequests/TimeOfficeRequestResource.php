<?php

namespace App\Filament\Resources\TimeOfficeRequests;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\TimeOfficeDetails;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Filament\Resources\TimeOfficeRequests\Pages\ListTimeOfficeRequests;
use App\Filament\Resources\TimeOfficeRequests\Pages\ViewTimeOfficeRequest;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\SendBackActions;
use App\Filament\Support\TimeOfficeDetailEntries;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
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

/**
 * HROne parity HP-1: short leave, on duty, shift change, overtime and comp-off claims, restricted holidays.
 * Employees see and raise their own; approvers decide in scope; cancelling an approved request may need approval.
 */
class TimeOfficeRequestResource extends Resource
{
    protected static ?string $model = TimeOfficeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Time-office requests';

    protected static ?int $navigationSort = 61;

    /** HROne parity TX-1: date ranges of the register (filter name => [label, column]). */
    public const DATE_FILTERS = [
        'effective_dates' => ['Day asked for', 'from_date'],
        'request_dates' => ['Raised', 'created_at'],
        'decision_dates' => ['Decided', 'reviewed_at'],
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->can('attendance.view') ? 'Attendance' : 'Me';
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('attendance.approve')) {
            return null;
        }
        $count = TimeOfficeRequest::query()->whereIn('status', ['pending', 'cancel_requested'])->count();

        return $count > 0 ? (string) $count : null;
    }

    /** Without attendance.view a person only ever sees their own requests (server-side). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'requester', 'reviewer', 'shift', 'holiday']);

        return auth()->user()?->can('attendance.view')
            ? $query
            : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (TimeOfficeRequest $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };
        $service = fn () => app(TimeOfficeRequests::class);
        $canDecide = fn (TimeOfficeRequest $r) => auth()->user()->can('approve', $r);
        $isOwnOrManaged = fn (TimeOfficeRequest $r) => auth()->user()->can('attendance.manage') || Employee::query()->whereKey($r->employee_id)->value('user_id') === auth()->id();

        return $table
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (TimeOfficeRequest $r) => $r->employee?->employee_code),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => TimeOfficeRequest::TYPES[$state] ?? $state),
                TextColumn::make('from_date')->label('Date')->date('D, d M Y')->sortable()
                    ->description(fn (TimeOfficeRequest $r) => trim(($r->to_date && ! $r->to_date->equalTo($r->from_date) ? 'to '.$r->to_date->format('d M Y').' ' : '').($r->start_time ? substr((string) $r->start_time, 0, 5).'–'.substr((string) $r->end_time, 0, 5) : ''))),
                TextColumn::make('detail')->label('Detail')->state(fn (TimeOfficeRequest $r) => match ($r->type) {
                    'shift_change' => $r->shift?->name,
                    'restricted_holiday' => $r->holiday?->name,
                    'overtime' => $r->minutes.' min',
                    'comp_off' => rtrim(rtrim(number_format((float) $r->days, 2), '0'), '.').' day(s)'.($r->expires_on ? ' · expires '.$r->expires_on->format('d M') : ''),
                    'short_leave' => $r->minutes.' min',
                    // HROne parity TX-1 (§24.2.2 #8): the weekly off worked and the day taken off instead.
                    'week_off_change' => trim(($r->shift?->name ? $r->shift->name.' · ' : '').($r->swap_date ? 'off on '.$r->swap_date->format('d M Y') : '')) ?: null,
                    default => $r->session === 'full' ? 'Full day' : str_replace('_', ' ', (string) $r->session),
                })->placeholder('—'),
                TextColumn::make('category')->label('On-duty type')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')->limit(50)->wrap()->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => TimeOfficeRequest::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Decided by')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Raised')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(TimeOfficeRequest::TYPES)->multiple(),
                SelectFilter::make('status')->options(TimeOfficeRequest::STATUSES)->multiple(),
                // HROne parity TX-1: on-duty type, shift asked for, the WO-2 employee filters and request / decision /
                // day-asked-for ranges (HR side only; an employee's own list is unchanged).
                SelectFilter::make('category')->label('On-duty type')->multiple()
                    ->options(fn () => TimeOfficeRequest::query()->where('type', 'on_duty')->whereNotNull('category')->distinct()->orderBy('category')->limit(200)->pluck('category', 'category')->all())
                    ->visible(fn () => TimeOfficeRegisters::hrSide('attendance.view')),
                SelectFilter::make('shift_id')->label('Shift asked for')->relationship('shift', 'name')->preload()
                    ->visible(fn () => TimeOfficeRegisters::hrSide('attendance.view')),
                ...TimeOfficeRegisters::filters('attendance.view', self::DATE_FILTERS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (TimeOfficeRequest $r) => $r->status === 'pending' && $canDecide($r))
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn ($r, $d) => $service()->approve($r, $d['note'] ?? null), 'Approved')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (TimeOfficeRequest $r) => $r->status === 'pending' && $canDecide($r))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])
                    ->action($run(fn ($r, $d) => $service()->reject($r, $d['note']), 'Rejected')),
                Action::make('approveCancellation')->label('Approve cancellation')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (TimeOfficeRequest $r) => $r->status === 'cancel_requested' && $canDecide($r))
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn ($r, $d) => $service()->approveCancellation($r, $d['note'] ?? null), 'Cancelled')),
                Action::make('rejectCancellation')->label('Keep request')->icon('heroicon-m-x-mark')->color('gray')
                    ->visible(fn (TimeOfficeRequest $r) => $r->status === 'cancel_requested' && $canDecide($r))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])
                    ->action($run(fn ($r, $d) => $service()->rejectCancellation($r, $d['note']), 'Request kept')),
                Action::make('cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (TimeOfficeRequest $r) => in_array($r->status, ['pending', 'approved'], true) && $isOwnOrManaged($r))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action($run(fn ($r, $d) => $service()->cancel($r, $d['reason']), 'Done')),
                // HROne setup parity P3: a request sent back to its requester is resubmitted here.
                SendBackActions::resubmit(),
            ]);
    }

    /** HROne parity TX-1: the request's detail (read-only), HROne's card "Detail" flyer. */
    public static function infolist(Schema $schema): Schema
    {
        $details = fn () => app(TimeOfficeDetails::class);

        return $schema->columns(1)->components([
            Section::make('Request')->columns(3)->schema([
                TextEntry::make('employee.person.display_name')->label('Employee')->state(fn (TimeOfficeRequest $r) => trim(($r->employee?->display_name ?? '').' · '.($r->employee?->employee_code ?? ''), ' ·')),
                TextEntry::make('type')->label('Request')->badge()->formatStateUsing(fn (string $state) => TimeOfficeRequest::TYPES[$state] ?? $state),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => TimeOfficeRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                    }),
                TextEntry::make('from_date')->label('Day asked for')->state(fn (TimeOfficeRequest $r) => $r->from_date?->format('D, d M Y').($r->to_date && ! $r->to_date->equalTo($r->from_date) ? ' – '.$r->to_date->format('D, d M Y') : '')),
                TextEntry::make('time')->label('Part of day')->visible(fn (TimeOfficeRequest $r) => in_array($r->type, ['on_duty', 'short_leave'], true))
                    ->state(fn (TimeOfficeRequest $r) => $r->start_time ? substr((string) $r->start_time, 0, 5).'–'.substr((string) $r->end_time, 0, 5).($r->minutes ? ' ('.$r->minutes.' min)' : '') : ($r->session === 'full' ? 'Full day' : str_replace('_', ' ', (string) $r->session))),
                TextEntry::make('minutes')->label('Overtime claimed')->suffix(' min')->placeholder('—')->visible(fn (TimeOfficeRequest $r) => $r->type === 'overtime'),
                TextEntry::make('category')->label('On-duty type')->placeholder('—')->visible(fn (TimeOfficeRequest $r) => $r->type === 'on_duty'),
                TextEntry::make('days')->label('Days')->numeric(2)->placeholder('—')->visible(fn (TimeOfficeRequest $r) => $r->type === 'comp_off'),
                TextEntry::make('expires_on')->label('Comp-off expires')->date('d M Y')->placeholder('—')->visible(fn (TimeOfficeRequest $r) => $r->type === 'comp_off'),
                TextEntry::make('reason')->label('Remarks')->placeholder('—')->columnSpanFull(),
                TimeOfficeDetailEntries::attachments('time-office-request'),
                TextEntry::make('created_at')->label('Raised')->dateTime(),
                TextEntry::make('requester.name')->label('Raised by')->placeholder('The employee'),
                TimeOfficeDetailEntries::workflow(),
            ]),
            Section::make('Shift')
                ->description('The shift of each day before the request and with it.')
                ->visible(fn (TimeOfficeRequest $r) => in_array($r->type, ['shift_change', 'week_off_change'], true))
                ->columns(2)
                ->schema([
                    TextEntry::make('week_off_worked')->label('Weekly off worked')->state(fn (TimeOfficeRequest $r) => $r->from_date?->format('D, d M Y'))->visible(fn (TimeOfficeRequest $r) => $r->type === 'week_off_change'),
                    TextEntry::make('swap_date')->label('Day taken off instead')->date('D, d M Y')->placeholder('—')->visible(fn (TimeOfficeRequest $r) => $r->type === 'week_off_change'),
                    RepeatableEntry::make('shift_rows')->hiddenLabel()->columnSpanFull()
                        ->state(fn (TimeOfficeRequest $r) => array_map(fn (array $row) => ['date' => Carbon::parse($row['date'])->format('D, d M Y')] + $row, $details()->shiftChanges($r)))
                        ->columns(3)
                        ->schema([
                            TextEntry::make('date')->label('Day'),
                            TextEntry::make('earlier')->label('Earlier shift'),
                            TextEntry::make('new')->label('New shift')->weight('bold'),
                        ]),
                ]),
            Section::make('Restricted holiday')->columns(2)
                ->visible(fn (TimeOfficeRequest $r) => $r->type === 'restricted_holiday')
                ->schema([
                    TextEntry::make('holiday.name')->label('Holiday')->placeholder('—'),
                    TextEntry::make('quota')->label('Chosen this year (pending or approved)')->state(function (TimeOfficeRequest $r) {
                        $r->loadMissing(['employee', 'holiday.calendar']);
                        $usage = $r->employee && $r->holiday ? app(TimeOfficeRequests::class)->restrictedHolidayQuota($r->employee, $r->holiday) : null;

                        return $usage === null ? null : "{$usage['taken']} of {$usage['quota']} for {$usage['year']}";
                    })->placeholder('No limit on the calendar'),
                ]),
            Section::make('Leave taken off the balance')->columns(2)
                ->description('Short leave beyond the monthly limit takes leave off the balance when the policy says so.')
                ->visible(fn (TimeOfficeRequest $r) => $r->type === 'short_leave')
                ->schema([
                    TextEntry::make('debited_leave')->label('Leave debited')->state(fn (TimeOfficeRequest $r) => self::debitedLeave($r))->placeholder('None (within the monthly limit)'),
                ]),
            TimeOfficeDetailEntries::dayStatus(fn (TimeOfficeRequest $r) => [$r->employee, $r->from_date, $r->to_date])
                ->visible(fn (TimeOfficeRequest $r) => in_array($r->type, ['on_duty', 'short_leave', 'overtime', 'comp_off', 'restricted_holiday'], true)),
            Section::make('Decision')->columns(3)->schema([
                TextEntry::make('reviewer.name')->label('Decided by')->placeholder('—'),
                TextEntry::make('reviewed_at')->label('Decided on')->dateTime()->placeholder('—'),
                TextEntry::make('review_note')->label('Approver comments')->placeholder('—'),
                TextEntry::make('cancel_requested_at')->label('Cancellation requested')->dateTime()->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->placeholder('—'),
                TextEntry::make('cancelled_at')->label('Cancelled on')->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    /** The leave a short leave beyond the monthly limit took off the balance (ledger entries keyed sl-{id}). */
    public static function debitedLeave(TimeOfficeRequest $request): ?string
    {
        $entries = LeaveLedgerEntry::query()->with('leaveType')->where('employee_id', $request->employee_id)
            ->whereIn('accrual_key', ["sl-{$request->id}", "sl-{$request->id}-back"])->orderBy('id')->get();
        if ($entries->isEmpty()) {
            return null;
        }

        return $entries->map(fn (LeaveLedgerEntry $e) => sprintf('%s %s day(s)%s', $e->leaveType?->name ?? 'Leave', rtrim(rtrim(number_format(abs((float) $e->days), 2), '0'), '.'), (float) $e->days > 0 ? ' given back' : ''))->implode(' · ');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimeOfficeRequests::route('/'),
            'view' => ViewTimeOfficeRequest::route('/{record}'),
        ];
    }
}
