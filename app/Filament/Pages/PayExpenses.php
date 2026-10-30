<?php

namespace App\Filament\Pages;

use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReports;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity EX-D (Expense → Pay expenses): approved expense reports with an amount still owed to the employee,
 * for `expense.pay` holders to record the outside payment (reference and date) or — EX-E, the payment step — to pay
 * them with payroll (the next payroll repays them and marks them paid when it is finalized). A report a payroll run
 * not yet finalized or an unpaid final settlement already carries is paid by it. One's own reports are never paid here.
 */
class PayExpenses extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Pay expenses';

    protected static ?string $title = 'Pay expenses';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.pay-expenses';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('expense.pay');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        $form = [TextInput::make('reference')->label('Payment reference')->required()->maxLength(120), DatePicker::make('paid_on')->label('Paid on')->native(false)->default(today())->maxDate(today())->required()];

        return $table
            ->query(fn (): Builder => ExpenseReport::query()->with('employee.person')->whereIn('status', ['approved', 'partly_approved'])->where('net_payable', '>', 0)->whereNull('paid_on'))
            ->columns([
                TextColumn::make('number')->searchable()->url(fn (ExpenseReport $r) => ExpenseReportResource::getUrl('view', ['record' => $r])),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('title')->limit(40),
                TextColumn::make('decided_at')->label('Approved')->date(),
                TextColumn::make('total_approved')->label('Approved amount')->numeric(2),
                TextColumn::make('advance_adjusted')->label('Advances settled')->numeric(2),
                TextColumn::make('net_payable')->label('To pay')->numeric(2),
                TextColumn::make('payment')->label('Payment')->badge()->state(fn (ExpenseReport $r) => $r->paymentStatus())
                    ->description(fn (ExpenseReport $r) => $r->isScheduledForPayroll() ? 'since '.$r->payment_scheduled_at?->format('d M Y') : null)
                    ->color(fn (ExpenseReport $r) => $r->isScheduledForPayroll() ? 'info' : 'warning'),
            ])
            ->defaultSort('decided_at')
            ->recordActions([
                Action::make('payWithPayroll')->label('Pay with payroll')->icon('heroicon-m-calendar-days')->color('gray')->requiresConfirmation()
                    ->modalDescription('The next payroll repays this report (non-taxable) and marks it paid when it is finalized.')
                    ->visible(fn (ExpenseReport $r) => ! $r->isScheduledForPayroll() && auth()->user()->can('pay', $r))
                    ->action(function (ExpenseReport $record) {
                        try {
                            app(ExpenseReports::class)->scheduleForPayroll($record);
                            Notification::make()->success()->title('Scheduled for payroll')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not scheduled')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('pay')->label('Record payment')->icon('heroicon-m-banknotes')->visible(fn (ExpenseReport $r) => auth()->user()->can('pay', $r))->schema($form)
                    ->action(function (ExpenseReport $record, array $data) {
                        try {
                            app(ExpenseReports::class)->markPaid($record, $data['reference'], $data['paid_on']);
                            Notification::make()->success()->title('Payment recorded')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('payAllWithPayroll')->label('Pay the selected with payroll')->icon('heroicon-m-calendar-days')->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $scheduled = 0;
                        $refused = [];
                        foreach ($records as $report) {
                            try {
                                app(ExpenseReports::class)->scheduleForPayroll($report);
                                $scheduled++;
                            } catch (RuntimeException $e) {
                                $refused[] = $report->number.': '.$e->getMessage();
                            }
                        }
                        Notification::make()->{$refused === [] ? 'success' : 'warning'}()->title("{$scheduled} report(s) scheduled for payroll")->body($refused === [] ? null : implode(' ', $refused))->send();
                    }),
                BulkAction::make('payAll')->label('Record one payment for the selected')->icon('heroicon-m-banknotes')->schema($form)
                    ->action(function (Collection $records, array $data) {
                        $paid = 0;
                        $refused = [];
                        foreach ($records as $report) {
                            try {
                                app(ExpenseReports::class)->markPaid($report, $data['reference'], $data['paid_on']);
                                $paid++;
                            } catch (RuntimeException $e) {
                                $refused[] = $report->number.': '.$e->getMessage();
                            }
                        }
                        Notification::make()->{$refused === [] ? 'success' : 'warning'}()->title("{$paid} report(s) marked paid")->body($refused === [] ? null : implode(' ', $refused))->send();
                    }),
            ]);
    }
}
