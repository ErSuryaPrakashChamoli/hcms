<?php

namespace App\Filament\Resources\ExpenseAdvances\Pages;

use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpensePolicies;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptForm;
use App\Filament\Support\ExpenseRegisters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListExpenseAdvances extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = ExpenseAdvanceResource::class;

    public static function registerKey(): string
    {
        return 'expense_advances';
    }

    /**
     * HROne parity XO-1 (matrix 26 §26.1 #1, §26.3.1–§26.3.3): HROne's Advance requests, Advances (dispense) and Advance
     * cancellations registers as views of this one register. The files column stays in every view.
     */
    public static function presetViews(): array
    {
        $view = fn (string $label, array $filters, array $columns) => ['label' => $label, 'filters' => $filters, 'columns' => ['files', ...$columns]];

        return [
            'waiting' => $view('Waiting for approval', ['status' => ['values' => ['requested']]], ['created_at', 'waiting_with']),
            'to_dispense' => $view('Approved, to dispense', ['status' => ['values' => ['approved']]], ['decided_at', 'decider.name']),
            'dispensed' => $view('Dispensed, not yet settled', ['status' => ['values' => ['disbursed']]], ['decided_at']),
            'cancellations' => $view('Advance cancellations', ['cancellation' => ['values' => array_keys(ExpenseRegisters::CANCELLATION)]], ['waiting_with', 'cancel_refused_at']),
            'cancellations_waiting' => $view('Cancellations waiting for a decision', ['cancellation' => ['values' => ['requested']]], ['waiting_with']),
            'closed' => $view('Settled, recovered or written off', ['status' => ['values' => ['settled', 'recovered', 'written_off']]], []),
        ];
    }

    public static function registerReports(): array
    {
        return ['expense_advance_amount', 'outstanding_expense_advances', 'expense_advance_recovery_register'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the advance datasets */
    public function registerReportFilters(): array
    {
        return ExpenseRegisters::reportFilters((array) ($this->tableFilters ?? []), ['status' => ['status', fn (string $v) => ExpenseAdvance::STATUSES[$v] ?? $v]],
            ['request_dates' => 'requested_on', 'decision_dates' => 'decided_on']);
    }

    protected function getHeaderActions(): array
    {
        $rules = fn () => ($me = ExpenseReceiptForm::ownEmployee()) ? app(ExpensePolicies::class)->advanceRules($me, today()) : null;

        return [
            // HROne parity XO-1: views and report for the HR side; an employee's own list keeps its action.
            ...(ExpenseAdvanceResource::oversees() ? $this->registerToolActions() : []),
            Action::make('requestAdvance')->label('Request an advance')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('expense.claim') && ExpenseReceiptForm::ownEmployee() !== null)
                ->modalDescription(fn () => $rules()?->refusal() ?? ($rules()?->maxAmount !== null ? 'Your policy allows up to '.number_format($rules()->maxAmount, 2).' per advance.' : null))
                ->schema([
                    // HROne parity RQ-2: whose request it is.
                    RequestChrome::header(fn () => ExpenseReceiptForm::ownEmployee()),
                    TextInput::make('currency')->maxLength(3)->default(fn () => app(CurrencyRates::class)->baseCurrency()),
                    TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                    DatePicker::make('needed_on')->label('Needed on')->native(false)->minDate(today()),
                    Textarea::make('purpose')->maxLength(500)->required(fn () => (bool) $rules()?->purposeRequired),
                    FileUpload::make('attachments')->label('Supporting documents')->multiple()->disk(config('peopleos.documents.disk', 'local'))->directory(fn () => ExpenseAdvances::directory())
                        ->visibility('private')->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
                        ->required(fn () => (bool) $rules()?->attachmentRequired),
                    // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the expense policy.
                    RequestChrome::route('expense_advance', fn () => ExpenseReceiptForm::ownEmployee()),
                ])
                ->action(function (array $data) {
                    try {
                        $advance = app(ExpenseAdvances::class)->request(ExpenseReceiptForm::ownEmployee(), ['attachments' => array_values((array) ($data['attachments'] ?? []))] + $data);
                        RequestChrome::confirmed('expense_advance', $advance, 'Advance '.$advance->number.' sent for approval');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
