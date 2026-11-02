<?php

namespace App\Filament\Resources\ExpenseReports;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseDocumentDownload;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseDocumentDownloads;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ExpenseReports\Pages\ListExpenseReports;
use App\Filament\Resources\ExpenseReports\Pages\ViewExpenseReport;
use App\Filament\Resources\ExpenseReports\RelationManagers\LinesRelationManager;
use App\Filament\Resources\ExpenseReports\RelationManagers\PartsRelationManager;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\ExpenseReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
 * HROne parity XO-3: the review (trips, distance totals, the approved reimbursable / non-reimbursable, rejected and held
 * amounts, every file, paid receipts, the audit tab, a printable summary) and, on the HR side of the register, the created
 * and payment columns, search by a receipt number, payment / period / created filters and "Download documents".
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

    /** HROne parity XO-5: the report was created by someone other than the employee (HR or a manager, on their behalf). */
    public static function createdForEmployee(ExpenseReport $report): bool
    {
        return $report->created_by !== null && (int) $report->created_by !== (int) $report->employee?->user_id;
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
                // HROne parity XO-3 (matrix 26 §26.4.3 #3): the trips the report covers, linked where the viewer may open them.
                TextEntry::make('created_at')->label('Created on')->dateTime(),
                // HROne parity XO-5 (§26.7 #1): a report HR (or a manager) created or submitted for the employee says who.
                TextEntry::make('filed_by')->label('Filed for the employee')->columnSpan(2)
                    ->state(fn (ExpenseReport $r) => collect([
                        self::createdForEmployee($r) ? 'Created by '.($r->creator?->name ?? 'someone else') : null,
                        $r->filed_on_behalf ? 'Submitted by '.($r->submitter?->name ?? 'someone else') : null,
                    ])->filter()->implode(' · '))
                    ->visible(fn (ExpenseReport $r) => self::createdForEmployee($r) || $r->filed_on_behalf),
                TextEntry::make('trips_list')->label('Trips')->html()->columnSpan(3)->state(fn (ExpenseReport $r) => ExpenseReview::tripsHtml($r))
                    ->visible(fn (ExpenseReport $r) => $r->trips()->exists()),
            ]),
            // HROne parity XO-3 (§26.4.3 #4): mileage totals — requested distance, and approved distance (approved ÷ rate per line).
            Section::make('Distance')->columns(4)->visible(fn (ExpenseReport $r) => ExpenseReview::summary($r)['mileage_lines'] > 0)->schema([
                TextEntry::make('distance_requested')->label('Distance requested (km)')->numeric(2)->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['distance_requested']),
                TextEntry::make('distance_approved')->label('Distance approved (km)')->numeric(2)->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['distance_approved']),
            ]),
            // HROne parity XO-3 (§26.4.3 #20, #21): the split of the decision, once lines are decided.
            Section::make('Decision')->columns(4)->visible(fn (ExpenseReport $r) => ExpenseReview::summary($r)['decided'] > 0 || ExpenseReview::summary($r)['held_count'] > 0)->schema([
                TextEntry::make('approved_reimbursable')->label('Approved, repaid to the employee')->money($money)->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['approved_reimbursable']),
                TextEntry::make('approved_non_reimbursable')->label('Approved, paid by the company')->money($money)->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['approved_non_reimbursable']),
                TextEntry::make('rejected_amount')->label('Rejected')->money($money)->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['rejected']),
                TextEntry::make('held_amount')->label('Held as suspected')->money($money)->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['held'])
                    ->suffix(fn (ExpenseReport $r) => ' ('.ExpenseReview::summary($r)['held_count'].' expense(s))')->visible(fn (ExpenseReport $r) => ExpenseReview::summary($r)['held_count'] > 0),
                TextEntry::make('receipts_submitted')->label('Receipts in the report')->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['receipts']),
            ]),
            Section::make('Payment')->columns(4)->visible(fn (ExpenseReport $r) => ($r->paid_on !== null || $r->isScheduledForPayroll()) && static::showsPayment($r))->schema([
                TextEntry::make('payment_status')->label('Payment')->state(fn (ExpenseReport $r) => $r->paymentStatus()),
                TextEntry::make('payment_scheduled_at')->label('Scheduled for payroll on')->date()->visible(fn (ExpenseReport $r) => $r->paid_on === null),
                // HROne parity XO-4 (§26.4.4 #16, §26.4.3 #22): the payroll month chosen, how an outside payment was made, the note.
                TextEntry::make('pay_month')->label('Pay month')->state(fn (ExpenseReport $r) => $r->payment_scheduled_for?->format('M Y'))
                    ->visible(fn (ExpenseReport $r) => $r->payment_scheduled_for !== null && ($r->paid_on === null || $r->payment_channel === 'payroll')),
                TextEntry::make('paid_on')->date()->visible(fn (ExpenseReport $r) => $r->paid_on !== null),
                TextEntry::make('payment_reference')->label('Reference')->visible(fn (ExpenseReport $r) => $r->paid_on !== null),
                TextEntry::make('payment_mode_label')->label('Payment mode')->state(fn (ExpenseReport $r) => $r->paymentModeLabel())
                    ->visible(fn (ExpenseReport $r) => $r->paid_on !== null && $r->payment_mode !== null),
                TextEntry::make('paying_account')->label('Paying account')
                    ->state(fn (ExpenseReport $r) => $r->paid_from_account_id !== null ? CompanyBankAccount::query()->find($r->paid_from_account_id)?->label() : null)
                    ->visible(fn (ExpenseReport $r) => $r->paid_from_account_id !== null && static::oversees()),
                TextEntry::make('payment_note')->label('Payment note')->columnSpanFull()->visible(fn (ExpenseReport $r) => filled($r->payment_note) && static::oversees()),
                // HROne parity XO-3 (§26.4.3 #21): the receipts paid with it.
                TextEntry::make('paid_receipts')->label('Paid receipts')->state(fn (ExpenseReport $r) => ExpenseReview::summary($r)['paid_receipts'])->visible(fn (ExpenseReport $r) => $r->paid_on !== null),
            ]),
            // HROne parity XO-3 (§26.4.3 #17): every file of the report, through the signed, audited file route.
            Section::make('All files')->collapsible()->visible(fn (ExpenseReport $r) => ExpenseReview::hasFiles($r))->schema([
                TextEntry::make('all_files')->hiddenLabel()->html()->placeholder('None you may open')->state(fn (ExpenseReport $r) => ExpenseReview::filesHtml($r)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // HROne parity XO-3 (§26.4.2 #6): HR finds a report by the number of a receipt in it too.
                TextColumn::make('number')->searchable(query: static::oversees() ? ExpenseReview::numberSearch() : null),
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
                // HROne parity XO-3 (§26.4.2 #5, #8): created on and payment status — HR side, hidden until chosen.
                TextColumn::make('created_at')->label('Created on')->dateTime()->sortable()->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment')->label('Payment')->state(fn (ExpenseReport $r) => $r->paymentStatus())->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ExpenseReport::STATUSES)->multiple(),
                // HROne parity XO-3 (§26.4.2 #8, #9): payment status, the period covered and the created date — HR side.
                ExpenseReview::paymentFilter(fn () => static::oversees()),
                ExpenseReview::periodFilter(fn () => static::oversees()),
                EmployeeFilters::dateRange('created', 'Created', 'created_at')->visible(fn () => static::oversees()),
                // D-XO7: reports with an expense an approver holds as suspected (only once the Suspected decision is offered).
                Filter::make('held')->label('Expenses held as suspected')->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('receipts', fn (Builder $r) => $r->whereNotNull('suspected_at')->whereNull('suspicion_cleared_at')))
                    ->visible(fn () => static::oversees() && app(ExpenseReports::class)->suspicionOffered()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('submit')->icon('heroicon-m-paper-airplane')->color('success')->requiresConfirmation()
                    ->visible(fn (ExpenseReport $r) => $r->isEditable() && auth()->user()->can('update', $r))
                    ->action(fn (ExpenseReport $record) => static::run(fn () => app(ExpenseReports::class)->submit($record), 'Report submitted for approval')),
            ])
            ->toolbarActions([static::downloadDocumentsBulk()]);
    }

    /** HROne parity XO-3 (§26.4.2 #11): the chosen reports' receipt files and / or cover letters as one zip (queued). */
    public static function downloadDocumentsBulk(): BulkAction
    {
        return BulkAction::make('downloadDocuments')->label('Download documents')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn () => static::oversees() && ExpenseDocumentDownloads::mayDownload(auth()->user()))
            ->fetchSelectedRecords(false)->deselectRecordsAfterCompletion()
            ->modalHeading('Download the documents of the selected reports')
            ->modalDescription('Files you may open go in one zip, prepared in the background (you are notified when it is ready). Each file in it is recorded as downloaded.')
            ->schema(fn () => [static::documentsField()])
            ->action(fn (BulkAction $action, array $data) => static::requestDocuments(collect($action->getSelectedRecords())->all(), (array) ($data['documents'] ?? []), 'selected'));
    }

    public static function documentsField(): CheckboxList
    {
        return CheckboxList::make('documents')->label('Documents')->required()->default(['receipts'])
            ->options(fn () => app(ExpenseDocumentDownloads::class)->documentOptions());
    }

    /** @param  list<int|string>  $ids */
    public static function requestDocuments(array $ids, array $documents, string $selection): ?ExpenseDocumentDownload
    {
        $download = null;
        static::run(function () use ($ids, $documents, $selection, &$download) {
            $download = app(ExpenseDocumentDownloads::class)->request(auth()->user(), $ids, $documents, $selection);
        }, 'Preparing the zip: you will be notified when it is ready to download');

        return $download;
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
        // HROne parity XO-3 (§26.4.3 #23): the report's own audit trail, read-only (audit.view).
        return [LinesRelationManager::class, PartsRelationManager::class, AuditHistoryRelationManager::class];
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
