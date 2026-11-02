<?php

namespace App\Filament\Resources\LeaveEncashments;

use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Support\PayMonth;
use App\Filament\Resources\LeaveEncashments\Pages\ListLeaveEncashments;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeRegisters;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

class LeaveEncashmentResource extends Resource
{
    protected static ?string $model = LeaveEncashment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Encashments';

    protected static ?int $navigationSort = 30;

    /** HROne parity TX-1: date ranges of the register (filter name => [label, column]). */
    public const DATE_FILTERS = [
        'request_dates' => ['Raised', 'created_at'],
        'decision_dates' => ['Decided', 'reviewed_at'],
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('leave.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'leaveType', 'reviewer', 'requester']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (LeaveEncashment $record) => $record->employee?->employee_code),
                TextColumn::make('leaveType.name')->label('Type')->badge(),
                TextColumn::make('period_year')->label('Year'),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                self::filesColumn(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved', 'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                // HROne parity PO-8: who raised it (HR for the employee, or an HR batch) and the pay month that pays it.
                TextColumn::make('requester.name')->label('Raised by')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (LeaveEncashment $record) => $record->leave_encashment_batch_id !== null ? 'Batch #'.$record->leave_encashment_batch_id : null),
                TextColumn::make('pay_month')->label('Pay month')->state(fn (LeaveEncashment $record) => PayMonth::label($record->pay_month))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Raised')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_reason')->label('Cancellation reason')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeaveEncashment::STATUSES),
                // HROne parity TX-1: leave type, the WO-2 employee filters and raised / decided ranges.
                SelectFilter::make('leave_type_id')->label('Leave type')->relationship('leaveType', 'name')->preload(),
                ...TimeOfficeRegisters::filters('leave.view', self::DATE_FILTERS),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                    ->authorize(fn () => auth()->user()->can('leave.manage'))
                    ->visible(fn (LeaveEncashment $record) => $record->status === 'pending')
                    ->schema([
                        Textarea::make('note')->maxLength(255),
                        // HROne parity PO-8 (TX-8): the payroll month that pays it; blank = as asked, else the next run.
                        Select::make('pay_month')->label('Pay month')->options(fn () => PayMonth::options())->native(false)
                            ->placeholder(fn (LeaveEncashment $record) => $record->pay_month !== null ? PayMonth::label($record->pay_month).' (as asked)' : 'The next pay run'),
                    ])
                    ->action(function (LeaveEncashment $record, array $data) {
                        try {
                            app(LeaveAdjustments::class)->approveEncashment($record, $data['note'] ?? null, $data['pay_month'] ?? null);
                            Notification::make()->success()->title('Encashment approved')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->authorize(fn () => auth()->user()->can('leave.manage'))
                    ->visible(fn (LeaveEncashment $record) => $record->status === 'pending')
                    ->schema([Textarea::make('note')->required()->maxLength(255)])
                    ->action(fn (LeaveEncashment $record, array $data) => app(LeaveAdjustments::class)->rejectEncashment($record, $data['note'])),
                // HP-1: HR cancels a pending or approved, unpaid encashment (the days go back to the balance).
                Action::make('cancel')->label('Cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->authorize(fn () => auth()->user()->can('leave.manage'))
                    ->visible(fn (LeaveEncashment $record) => in_array($record->status, ['pending', 'approved'], true))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (LeaveEncashment $record, array $data) {
                        try {
                            app(LeaveAdjustments::class)->cancelEncashment($record, $data['reason']);
                            Notification::make()->success()->title('Encashment cancelled')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /**
     * HROne parity RQ-4 (§20.1.18 Encashment #5, #6): the request's supporting files, each behind a signed link that checks
     * the request's own access rules (ParityFileController, type `leave-encashment`).
     */
    public static function filesColumn(): TextColumn
    {
        return TextColumn::make('files')->label('Files')->html()->toggleable()->placeholder('—')
            ->state(function (LeaveEncashment $record) {
                // Indexes as ParityFileController reads them (the list's own positions).
                $paths = collect(array_values((array) $record->getAttribute('attachments')))->filter(fn ($p) => is_string($p) && $p !== '');

                return $paths->isEmpty() ? null : new HtmlString($paths->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'
                    .e(ParityFileController::url('leave-encashment', $record, $i)).'">'.e(basename($path)).'</a>')->implode('<br>'));
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveEncashments::route('/')];
    }
}
