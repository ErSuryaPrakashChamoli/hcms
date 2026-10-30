<?php

namespace App\Filament\Resources\ExpenseReports\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReports;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptForm;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Support\ExpenseRegisters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
        return ExpenseRegisters::reportFilters((array) ($this->tableFilters ?? []), ['status' => ['status', fn (string $v) => ExpenseReport::STATUSES[$v] ?? $v]]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity XO-1: views and report for the HR side; an employee's own list keeps its action.
            ...(ExpenseReportResource::oversees() ? $this->registerToolActions() : []),
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
        ];
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
