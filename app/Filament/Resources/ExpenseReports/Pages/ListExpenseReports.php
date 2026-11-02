<?php

namespace App\Filament\Resources\ExpenseReports\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseDocumentDownloads;
use App\Domain\Expense\Services\ExpenseReports;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptForm;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\ExpenseOnBehalf;
use App\Filament\Support\ExpenseRegisters;
use App\Filament\Support\ExpenseReview;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListExpenseReports extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = ExpenseReportResource::class;

    public static function registerKey(): string
    {
        return 'expense_reports';
    }

    /** HROne parity XO-1 (matrix 26 §26.1 #1, #4): the report register's everyday views and its library reports. */
    public static function presetViews(): array
    {
        return [
            'waiting' => ['label' => 'Waiting for approval', 'filters' => ['status' => ['values' => ['submitted']]]],
            'returned' => ['label' => 'Returned to the employee', 'filters' => ['status' => ['values' => ['returned']]]],
            'decided' => ['label' => 'Approved or partly approved', 'filters' => ['status' => ['values' => ['approved', 'partly_approved']]]],
            'paid' => ['label' => 'Paid', 'filters' => ['status' => ['values' => ['paid']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['expense_report_details', 'expense_reports_pending', 'expense_payment_register'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the reports dataset */
    public function registerReportFilters(): array
    {
        // HROne parity XO-3: the payment status as the dataset words it, and the period as an overlap of its first and last expense.
        return ExpenseRegisters::reportFilters((array) ($this->tableFilters ?? []), ['status' => ['status', fn (string $v) => ExpenseReport::STATUSES[$v] ?? $v],
            'payment' => ['payment_status', fn (string $v) => ExpenseReview::PAYMENT[$v] ?? $v]], [], ['period' => ['period_from', 'period_to']]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity XO-1: views and report for the HR side; an employee's own list keeps its action.
            ...(ExpenseReportResource::oversees() ? $this->registerToolActions() : []),
            $this->downloadMatching(),
            Action::make('newReport')->label('New expense report')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('expense.claim') && ExpenseReceiptForm::ownEmployee() !== null)
                ->schema([
                    // HROne parity RQ-2: whose request it is.
                    RequestChrome::header(fn () => ExpenseReceiptForm::ownEmployee()),
                    TextInput::make('title')->required()->maxLength(160)->default(fn () => 'Expenses '.now()->format('M Y')),
                    CheckboxList::make('receipts')->label('Expenses to report')->columns(1)
                        ->options(fn () => self::unreported(ExpenseReceiptForm::ownEmployee())),
                    // HROne parity RQ-2: who approves it once submitted (a read-only preview), and the expense policy.
                    RequestChrome::route('expense_report', fn () => ExpenseReceiptForm::ownEmployee()),
                ])
                ->action(function (array $data) {
                    try {
                        $report = app(ExpenseReports::class)->create(ExpenseReceiptForm::ownEmployee(), $data['title'], array_map('intval', (array) ($data['receipts'] ?? [])));
                        Notification::make()->success()->title('Report '.$report->number.' created')->body('Submit it when it is complete.')->send();
                        $this->redirect(ExpenseReportResource::getUrl('view', ['record' => $report]));
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not created')->body($e->getMessage())->send();
                    }
                }),
            $this->newReportForEmployee(),
        ];
    }

    /**
     * HROne parity XO-5 (matrix 26 §26.7 #1, HROne's global "Add expense" filed by HR): HR (or a manager) builds a report
     * for an employee in their access scope — never their own ("New expense report") — from the employee's unreported
     * expenses, as a wizard: the employee, the expenses, then whether to submit it now. Submitting on the employee's behalf
     * is exempt from the submission windows (as the service always allowed); the report records who created and submitted it.
     */
    private function newReportForEmployee(): Action
    {
        $employee = fn (Get $get) => ExpenseOnBehalf::picked($get('employee_id'));

        return Action::make('newReportForEmployee')->label('New report for an employee')->icon(Heroicon::OutlinedUserPlus)->color('gray')
            ->visible(fn () => ExpenseOnBehalf::offered())
            ->modalHeading('New expense report for an employee')
            ->steps([
                Step::make('Employee')->description('Whose report it is')->schema([
                    ExpenseOnBehalf::employeeSelect(),
                    RequestChrome::header($employee),
                ]),
                Step::make('Expenses')->description('Title and the expenses to report')->schema([
                    TextInput::make('title')->required()->maxLength(160)->default(fn () => 'Expenses '.now()->format('M Y')),
                    CheckboxList::make('receipts')->label('Expenses to report')->columns(1)
                        ->options(fn (Get $get) => self::unreported($employee($get)))
                        ->helperText('The employee\'s expenses not yet in a report.'),
                ]),
                Step::make('Submit')->description('Draft or straight to approval')->schema([
                    Toggle::make('submit_now')->label('Submit it for approval now')->default(false)
                        ->helperText('Off: the report is kept as a draft for the employee (or you) to complete and submit. Filed on the employee\'s behalf, it is not held to the submission windows.'),
                    RequestChrome::route('expense_report', $employee),
                ]),
            ])
            ->action(function (array $data) {
                try {
                    $for = ExpenseOnBehalf::resolve($data['employee_id'] ?? null, 'New expense report');
                    $reports = app(ExpenseReports::class);
                    $report = $reports->create($for, (string) ($data['title'] ?? ''), array_map('intval', (array) ($data['receipts'] ?? [])));
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not created')->body($e->getMessage())->send();

                    return;
                }
                if ((bool) ($data['submit_now'] ?? false)) {
                    try {
                        $report = $reports->submit($report);
                        Notification::make()->success()->title('Report '.$report->number.' submitted for '.$for->display_name)->body('It goes to the employee\'s approvers.')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->warning()->title('Report '.$report->number.' created, not submitted')->body($e->getMessage())->send();
                    }
                } else {
                    Notification::make()->success()->title('Report '.$report->number.' created for '.$for->display_name)->body('Submit it when it is complete.')->send();
                }
                $this->redirect(ExpenseReportResource::getUrl('view', ['record' => $report]));
            });
    }

    /**
     * HROne parity XO-3 (§26.4.2 #11, "In order to download documents in bulk, please apply appropriate filter"): the
     * documents of every report matching the register's filters and search, as one zip prepared in the background.
     */
    private function downloadMatching(): Action
    {
        return Action::make('downloadDocuments')->label('Download documents')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn () => ExpenseReportResource::oversees() && ExpenseDocumentDownloads::mayDownload(auth()->user()))
            ->modalHeading('Download the documents of the reports matching the filters')
            ->modalSubmitActionLabel('Prepare the zip')
            ->schema(fn () => [
                Text::make(BulkRecordActions::isNarrowed($this)
                    ? 'Reports matching the register\'s filters and search right now: '.BulkRecordActions::matchingCount($this).' (at most '.ExpenseDocumentDownloads::max().'). Only files you may open go in; each is recorded as downloaded. You are notified when the zip is ready.'
                    : 'Apply a filter or a search first (for example a status and a period), so the zip holds the reports you mean.'),
                ExpenseReportResource::documentsField(),
            ])
            ->action(function (array $data) {
                if (! BulkRecordActions::isNarrowed($this)) {
                    Notification::make()->warning()->title('Apply a filter or a search first')->send();

                    return;
                }
                $ids = $this->getFilteredTableQuery()->limit(ExpenseDocumentDownloads::max() + 1)->pluck('expense_reports.id')->all();
                if ($ids === []) {
                    Notification::make()->warning()->title('No report matches the filters')->send();

                    return;
                }
                ExpenseReportResource::requestDocuments($ids, (array) ($data['documents'] ?? []), 'all_matching');
            });
    }

    /** @return array<int, string> the employee's unreported expenses, as report lines */
    public static function unreported(?Employee $employee): array
    {
        if ($employee === null) {
            return [];
        }

        return ExpenseReceipt::query()->with('category')->where('employee_id', $employee->id)->where('status', 'unreported')->orderBy('expense_date')->get()
            ->mapWithKeys(fn (ExpenseReceipt $r) => [$r->id => $r->number.' · '.$r->expense_date->format('d M Y').' · '.($r->category?->name ?? '').' · '.number_format((float) $r->claimable_amount, 2)])->all();
    }
}
