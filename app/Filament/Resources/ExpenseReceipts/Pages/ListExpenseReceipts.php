<?php

namespace App\Filament\Resources\ExpenseReceipts\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptForm;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Support\ExpenseRegisters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListExpenseReceipts extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = ExpenseReceiptResource::class;

    public static function registerKey(): string
    {
        return 'expense_receipts';
    }

    /** HROne parity XO-2 (matrix 26 §26.4.1 #18–#21): HROne's receipt status views and the checks approvers look for. */
    public static function presetViews(): array
    {
        return [
            'suspected' => ['label' => 'Suspected duplicates and invoices already claimed', 'filters' => ['checks' => ['values' => ['suspected_duplicate', 'same_invoice_number']]], 'columns' => ['invoice_number']],
            'over_limit' => ['label' => 'Over the limit', 'filters' => ['checks' => ['values' => ['over_limit']]]],
            'mileage' => ['label' => 'Mileage', 'filters' => ['type' => ['value' => 'mileage']]],
            'unreported' => ['label' => 'Not yet in a report', 'filters' => ['status' => ['values' => ['unreported']]], 'columns' => ['updated_at']],
            'awaiting_payment' => ['label' => 'Approved, waiting for payment', 'filters' => ['payment_status' => ['value' => 'waiting']], 'columns' => ['payment']],
            'paid' => ['label' => 'Paid', 'filters' => ['payment_status' => ['value' => 'paid']], 'columns' => ['payment']],
        ];
    }

    public static function registerReports(): array
    {
        return ['expense_receipt_details', 'expense_receipts_by_amount', 'expense_receipts_by_mileage', 'expense_by_category_this_year'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the receipts dataset */
    public function registerReportFilters(): array
    {
        return ExpenseRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'status' => ['status', fn (string $v) => ExpenseReceipt::STATUSES[$v] ?? $v],
            'type' => ['type', fn (string $v) => (string) config("peopleos.expense.types.{$v}", $v)],
        ], ['expense_dates' => 'expense_date']);
    }

    protected function getHeaderActions(): array
    {
        $file = function (?Employee $employee, array $data, string $done) {
            try {
                if ($employee === null) {
                    throw new RuntimeException('Choose the employee.');
                }
                $receipt = app(ExpenseReceipts::class)->capture($employee, ExpenseReceiptForm::toData($data));
                Notification::make()->success()->title($done)->body($receipt->number.($receipt->is_exceptional ? ' (exceptional)' : '').((float) $receipt->over_limit_amount > 0 && ! $receipt->is_exceptional ? ': capped at the limit' : ''))->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
            }
        };

        return [
            // HROne parity XO-2: views and report for the HR side; an employee's own list keeps its actions.
            ...(ExpenseReceiptResource::oversees() ? $this->registerToolActions() : []),
            Action::make('addExpense')->label('Add expense')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('expense.claim') && ExpenseReceiptForm::ownEmployee() !== null)
                ->schema(fn () => ExpenseReceiptForm::schema(fn (Get $get) => ExpenseReceiptForm::ownEmployee()))
                ->action(fn (array $data) => $file(ExpenseReceiptForm::ownEmployee(), $data, 'Expense saved')),
            Action::make('addForEmployee')->label('Add expense for an employee')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn () => auth()->user()->can('expense.approve'))
                ->schema(fn () => [
                    Select::make('employee_id')->label('Employee')->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search) => Employee::query()->with('person')->where('user_id', '!=', auth()->id())
                            ->whereHas('person', fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))->limit(20)->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->display_name.' ('.$e->employee_code.')']))
                        ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->display_name),
                    ...ExpenseReceiptForm::schema(fn (Get $get) => ($id = $get('employee_id')) ? Employee::query()->find($id) : null),
                ])
                ->action(function (array $data) use ($file) {
                    $employee = Employee::query()->find($data['employee_id'] ?? null);
                    if ($employee !== null && ! app(ExpensePolicy::class)->onBehalf(auth()->user(), $employee)) {
                        $employee = null;
                    }
                    unset($data['employee_id']);
                    $file($employee, $data, 'Expense saved for the employee');
                }),
        ];
    }
}
