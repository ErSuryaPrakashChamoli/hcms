<?php

namespace App\Filament\Resources\ExpenseReports;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\ExpenseReports\Pages\ListExpenseReports;
use App\Filament\Resources\ExpenseReports\Pages\ViewExpenseReport;
use App\Filament\Resources\ExpenseReports\RelationManagers\LinesRelationManager;
use App\Filament\Resources\ExpenseReports\RelationManagers\PartsRelationManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity EX-C: expense reports — the employee's own under Me (My expense reports), everyone's in scope under
 * Expense & travel for approvers, payers and viewers. A report is built from unreported expenses, submitted (inside the
 * submission windows), decided part by part (line by line, on the report), returned, cancelled; an approved report is
 * paid outside payroll (recorded on Pay expenses), with payroll or in the final settlement (EX-E). Payment details —
 * including a payroll it is scheduled for — are shown to the employee when expense.payment.show_to_employee is on.
 */
class ExpenseReportResource extends Resource
{
    protected static ?string $model = ExpenseReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $modelLabel = 'expense report';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function oversees(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('expense.view') || $user->can('expense.approve') || $user->can('expense.pay'));
    }

    public static function getNavigationGroup(): ?string
    {
        return static::oversees() ? 'Expense & travel' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return static::oversees() ? 'Expense reports' : 'My expense reports';
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('expense.approve')) {
            return null;
        }
        $count = ExpenseReport::query()->where('status', 'submitted')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person']);

        return static::oversees() ? $query : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    /** Whether the viewer sees how the report was paid (the employee only when the organisation shows it). */
    public static function showsPayment(ExpenseReport $report): bool
    {
        return static::oversees() || (bool) app(SettingsRepository::class)->get('expense.payment.show_to_employee', true);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (ExpenseReport $r) => $r->currency;

        return $schema->components([
            Section::make('Report')->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.person.display_name')->label('Employee'),
                TextEntry::make('title')->columnSpan(2),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseReport::STATUSES[$state] ?? $state),
                TextEntry::make('period_from')->label('From')->date()->placeholder('—'),
                TextEntry::make('period_to')->label('To')->date()->placeholder('—'),
                TextEntry::make('submitted_at')->label('Submitted')->dateTime()->placeholder('Not yet'),
                TextEntry::make('total_claimed')->label('Claimed')->money($money),
                TextEntry::make('total_approved')->label('Approved')->money($money),
                TextEntry::make('advance_adjusted')->label('Advances settled')->money($money),
                TextEntry::make('net_payable')->label('Net payable')->money($money),
                TextEntry::make('return_note')->label('Returned with')->visible(fn (ExpenseReport $r) => $r->status === 'returned')->columnSpanFull(),
                TextEntry::make('cancel_reason')->label('Cancelled because')->visible(fn (ExpenseReport $r) => $r->status === 'cancelled')->columnSpanFull(),
            ]),
            Section::make('Payment')->columns(4)->visible(fn (ExpenseReport $r) => ($r->paid_on !== null || $r->isScheduledForPayroll()) && static::showsPayment($r))->schema([
                TextEntry::make('payment_status')->label('Payment')->state(fn (ExpenseReport $r) => $r->paymentStatus()),
                TextEntry::make('payment_scheduled_at')->label('Scheduled for payroll on')->date()->visible(fn (ExpenseReport $r) => $r->paid_on === null),
                TextEntry::make('paid_on')->date()->visible(fn (ExpenseReport $r) => $r->paid_on !== null),
                TextEntry::make('payment_reference')->label('Reference')->visible(fn (ExpenseReport $r) => $r->paid_on !== null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('employee.person.display_name')->label('Employee')->visible(fn () => static::oversees()),
                TextColumn::make('title')->limit(40),
                TextColumn::make('period_from')->label('From')->date()->placeholder('—'),
                TextColumn::make('period_to')->label('To')->date()->placeholder('—'),
                TextColumn::make('total_claimed')->label('Claimed')->numeric(2),
                TextColumn::make('total_approved')->label('Approved')->numeric(2),
                TextColumn::make('net_payable')->label('Net payable')->numeric(2),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseReport::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved', 'paid' => 'success', 'partly_approved', 'submitted' => 'info', 'rejected', 'cancelled' => 'gray', default => 'warning',
                    }),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(ExpenseReport::STATUSES)->multiple()])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('submit')->icon('heroicon-m-paper-airplane')->color('success')->requiresConfirmation()
                    ->visible(fn (ExpenseReport $r) => $r->isEditable() && auth()->user()->can('update', $r))
                    ->action(fn (ExpenseReport $record) => static::run(fn () => app(ExpenseReports::class)->submit($record), 'Report submitted for approval')),
            ]);
    }

    /** Runs a report action and tells the person how it went. */
    public static function run(\Closure $call, string $done): void
    {
        try {
            $call();
            Notification::make()->success()->title($done)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class, PartsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenseReports::route('/'), 'view' => ViewExpenseReport::route('/{record}')];
    }

    /** The cancel action of the report page. */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('Cancel report')->icon('heroicon-m-x-circle')->color('gray')
            ->visible(fn (ExpenseReport $record) => in_array($record->status, ['draft', 'returned', 'submitted'], true) && auth()->user()->can('update', $record))
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(fn (ExpenseReport $record, array $data) => static::run(fn () => app(ExpenseReports::class)->cancel($record, $data['reason']), 'Report cancelled'));
    }
}
