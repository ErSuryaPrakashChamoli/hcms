<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Domain\Documents\Services\Documents;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Services\LeaveYear;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\TimeOfficeDetailEntries;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The leave register (§25 reports). */
class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Leave requests';

    protected static ?int $navigationSort = 10;

    /** HROne parity TX-1: date ranges of the register (filter name => [label, column]). */
    public const DATE_FILTERS = [
        'request_dates' => ['Raised', 'created_at'],
        'decision_dates' => ['Decided', 'reviewed_at'],
    ];

    /** HROne parity TX-1 (§24.6.2): cancellation states the "Cancellation" filter offers. */
    public const CANCELLATION = ['waiting' => 'Cancellation requested, waiting', 'decided' => 'Cancellation requested, decided', 'cancelled' => 'Cancelled'];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('leave.approve')) {
            return null;
        }

        $count = LeaveRequest::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'leaveType', 'requester', 'reviewer']);

        if (! auth()->user()->can('leave.view')) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id()));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (LeaveRequest $record) => $record->employee?->employee_code),
                TextColumn::make('leaveType.name')->label('Type')->badge()->color(fn (LeaveRequest $record) => $record->leaveType?->is_paid ? 'info' : 'danger'),
                TextColumn::make('from_date')->date('d M')->sortable(),
                TextColumn::make('to_date')->date('d M Y')->sortable(),
                TextColumn::make('days')->numeric(1)->sortable(),
                TextColumn::make('reason')->limit(50)->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_requested_at')->label('Cancellation requested')->dateTime()->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeaveRequest::STATUSES)->multiple(),
                SelectFilter::make('leave_type_id')->label('Type')->relationship('leaveType', 'name')->preload(),
                SelectFilter::make('department')->relationship('employee.currentPosition.department', 'name')->preload(),
                Filter::make('period')
                    ->schema([DatePicker::make('from')->native(false), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('to_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('from_date', '<=', $d))),
                // HROne parity TX-1: leave cancellations (HROne's own register), the WO-2 employee filters and raised /
                // decided ranges (HR side only; an employee's own list is unchanged).
                SelectFilter::make('cancellation')->label('Cancellation')->multiple()->options(self::CANCELLATION)
                    ->query(function (Builder $query, array $data): Builder {
                        $values = array_values(array_intersect((array) ($data['values'] ?? []), array_keys(self::CANCELLATION)));
                        if ($values === []) {
                            return $query;
                        }

                        return $query->where(fn (Builder $q) => $q
                            ->when(in_array('waiting', $values, true), fn (Builder $w) => $w->orWhere('status', 'cancel_requested'))
                            ->when(in_array('decided', $values, true), fn (Builder $w) => $w->orWhere(fn (Builder $d) => $d->whereNotNull('cancel_requested_at')->where('status', '!=', 'cancel_requested')))
                            ->when(in_array('cancelled', $values, true), fn (Builder $w) => $w->orWhere('status', 'cancelled')));
                    })
                    ->visible(fn () => TimeOfficeRegisters::hrSide('leave.view')),
                ...TimeOfficeRegisters::filters('leave.view', self::DATE_FILTERS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions(LeaveActions::forRequests())
            // HROne parity RQ-1: HR cancels several leave requests at once (bulk.run + leave.manage; each through Leaves::cancel).
            ->toolbarActions([BulkRecordActions::selected('leave_requests', ListLeaveRequests::BULK_OPERATIONS)]);
    }

    /** HROne parity TX-1: the leave request's detail (read-only) with its document and the balance left. */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Request')->columns(3)->schema([
                TextEntry::make('employee_name')->label('Employee')->state(fn (LeaveRequest $r) => trim(($r->employee?->display_name ?? '').' · '.($r->employee?->employee_code ?? ''), ' ·')),
                TextEntry::make('leaveType.name')->label('Leave type')->badge()->color('info'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => LeaveRequest::STATUSES[$state] ?? ($state === 'cancel_requested' ? 'Cancellation requested' : $state))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                    }),
                TextEntry::make('dates_span')->label('Leave dates')->state(fn (LeaveRequest $r) => $r->from_date?->format('D, d M Y').($r->to_date && ! $r->to_date->equalTo($r->from_date) ? ' – '.$r->to_date->format('D, d M Y') : '')
                    .(($r->from_session ?? 'full') !== 'full' || ($r->to_session ?? 'full') !== 'full' ? ' ('.(LeaveRequest::SESSIONS[$r->from_session] ?? $r->from_session).($r->to_date && ! $r->to_date->equalTo($r->from_date) ? ' – '.(LeaveRequest::SESSIONS[$r->to_session] ?? $r->to_session) : '').')' : '')),
                TextEntry::make('days')->label('No. of days')->numeric(1),
                TextEntry::make('remaining_balance')->label('Remaining balance')->state(fn (LeaveRequest $r) => self::remainingBalance($r))->placeholder('No balance recorded for that leave year'),
                TextEntry::make('reason')->label('Reason')->placeholder('—')->columnSpanFull(),
                TextEntry::make('document_link')->label('Supporting document')->state(fn (LeaveRequest $r) => $r->document?->title)
                    ->url(fn (LeaveRequest $r) => $r->document !== null && (auth()->user()?->can('view', $r->document) ?? false) ? app(Documents::class)->downloadUrl($r->document) : null)
                    ->placeholder('None attached'),
                TextEntry::make('created_at')->label('Raised')->dateTime(),
                TextEntry::make('requester.name')->label('Raised by')->placeholder('The employee'),
                TimeOfficeDetailEntries::workflow(),
            ]),
            Section::make('Decision')->columns(3)->schema([
                TextEntry::make('reviewer.name')->label('Decided by')->placeholder('—'),
                TextEntry::make('reviewed_at')->label('Decided on')->dateTime()->placeholder('—'),
                TextEntry::make('review_note')->label('Approver comments')->placeholder('—'),
                TextEntry::make('cancel_requested_at')->label('Cancellation requested')->dateTime()->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->placeholder('—'),
                TextEntry::make('cancellation_decided')->label('Cancellation decided')->state(fn (LeaveRequest $r) => $r->cancellation_reviewed_at
                    ? $r->cancellation_reviewed_at->format('d M Y H:i').($r->cancellation_reviewed_by ? ' by '.(User::query()->whereKey($r->cancellation_reviewed_by)->value('name') ?? '—') : '') : null)->placeholder('—'),
            ]),
        ]);
    }

    /**
     * The balance of the leave type for the leave year the request starts in, as recorded (this request's days are
     * already counted while it is pending or approved). Read only: nothing is recomputed here.
     */
    public static function remainingBalance(LeaveRequest $request): ?string
    {
        $request->loadMissing('leaveType');
        if ($request->leaveType === null || $request->from_date === null) {
            return null;
        }
        if ($request->leaveType->category === 'unpaid') {
            return 'Not counted against a balance';
        }
        $balance = LeaveBalance::query()->where('employee_id', $request->employee_id)->where('leave_type_id', $request->leave_type_id)
            ->where('period_year', app(LeaveYear::class)->periodFor($request->from_date))->first();
        if ($balance === null) {
            return null;
        }
        $available = rtrim(rtrim(number_format($balance->available(), 1), '0'), '.');

        return $available.' day(s) available'.(in_array($request->status, LeaveRequest::ACTIVE, true) ? ' after this request' : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
            'view' => ViewLeaveRequest::route('/{record}'),
        ];
    }
}
