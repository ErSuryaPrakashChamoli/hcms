<?php

namespace App\Filament\Resources\ExpenseReports\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseCoverLetters;
use App\Domain\Expense\Services\ExpenseReports;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use RuntimeException;

class ViewExpenseReport extends PeopleViewRecord
{
    protected static string $resource = ExpenseReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addExpenses')->label('Add expenses')->icon('heroicon-m-plus')->color('gray')
                ->visible(fn (ExpenseReport $record) => $record->isEditable() && auth()->user()->can('update', $record))
                ->schema(fn (ExpenseReport $record) => [CheckboxList::make('receipts')->label('Unreported expenses')->columns(1)->required()
                    ->options(fn () => ListExpenseReports::unreported(Employee::query()->find($record->employee_id)))])
                ->action(fn (ExpenseReport $record, array $data) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->addReceipts($record, array_map('intval', (array) $data['receipts'])), 'Expenses added')),
            Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->color('success')->requiresConfirmation()
                ->visible(fn (ExpenseReport $record) => $record->isEditable() && auth()->user()->can('update', $record))
                ->action(fn (ExpenseReport $record) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->submit($record), 'Report submitted for approval')),
            Action::make('markPaid')->label('Record payment')->icon('heroicon-m-banknotes')
                ->visible(fn (ExpenseReport $record) => $record->isPayable() && auth()->user()->can('pay', $record))
                ->schema([TextInput::make('reference')->label('Payment reference')->required()->maxLength(120), DatePicker::make('paid_on')->native(false)->default(today())->maxDate(today())->required()])
                ->action(fn (ExpenseReport $record, array $data) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->markPaid($record, $data['reference'], $data['paid_on']), 'Payment recorded')),
            Action::make('coverLetter')->label('Download cover letter')->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->visible(fn (ExpenseReport $record) => app(ExpenseCoverLetters::class)->enabled() && $record->status !== 'cancelled')
                ->action(function (ExpenseReport $record) {
                    try {
                        $file = app(ExpenseCoverLetters::class)->download($record, auth()->user());

                        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => $file['mime']]);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not available')->body($e->getMessage())->send();

                        return null;
                    }
                }),
            ExpenseReportResource::cancelAction(),
        ];
    }
}
