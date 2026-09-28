<?php

namespace App\Filament\Support;

use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\BankFile;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/** The run pipeline as Filament actions, shared by the control room, the list and the view page. */
final class PayrollActions
{
    public static function openRun(): Action
    {
        return Action::make('openRun')->label('Open payroll run')->icon(Heroicon::OutlinedPlusCircle)
            ->authorize(fn () => auth()->user()->can('payroll.calculate'))
            ->schema([
                Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->required(),
                Select::make('year')->options(collect(range(now()->year - 1, now()->year + 1))->mapWithKeys(fn ($y) => [$y => $y])->all())->default(now()->year)->required(),
                Select::make('month')->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m, 1)->format('F')])->all())->default(now()->month)->required(),
            ])
            ->action(function (array $data) {
                try {
                    $run = app(PayrollRuns::class)->open(Company::query()->findOrFail($data['company_id']), (int) $data['year'], (int) $data['month'], auth()->user());
                    Notification::make()->success()->title('Run opened: '.$run->period->label())->send();

                    return redirect(PayrollRunResource::getUrl('view', ['record' => $run]));
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot open run')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /** @return array<int, Action> */
    public static function forRun(): array
    {
        return [
            Action::make('calculate')->label(fn (PayrollRun $record) => $record->status === 'draft' ? 'Calculate' : 'Recalculate')->icon(Heroicon::OutlinedCalculator)->color('primary')
                ->visible(fn (PayrollRun $record) => $record->isEditable() && auth()->user()->can('payroll.calculate'))
                ->requiresConfirmation()->modalDescription('Runs every employee of the company through the pipeline. Existing results for this run are replaced.')
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayrollRuns::class)->calculate($record, auth()->user()), fn (PayrollRun $r) => "Calculated for {$r->total('employees')} employee(s); {$r->exception_count} with exceptions")),
            Action::make('validate')->label('Validate')->icon(Heroicon::OutlinedShieldCheck)->color('info')
                ->visible(fn (PayrollRun $record) => $record->status === 'calculated' && auth()->user()->can('payroll.calculate'))
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayrollRuns::class)->validate($record), 'Run validated; ready for approval')),
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (PayrollRun $record) => $record->status === 'validated' && auth()->user()->can('approve', $record))
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (PayrollRun $record, array $data) => self::run(fn () => app(PayrollRuns::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Run approved')),
            Action::make('finalize')->label('Finalize & generate payslips')->icon(Heroicon::OutlinedLockClosed)->color('success')
                ->visible(fn (PayrollRun $record) => $record->status === 'approved' && auth()->user()->can('finalize', $record))
                ->requiresConfirmation()->modalDescription('Locks attendance for the period, closes the period and issues payslips to employees. Reopening later is audited.')
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayrollRuns::class)->finalize($record, auth()->user()), 'Run finalized; payslips generated')),
            Action::make('bankFile')->label('Bank file')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (PayrollRun $record) => $record->isLocked() && auth()->user()->can('finalize', $record))
                ->action(function (PayrollRun $record) {
                    $csv = app(BankFile::class)->csv($record);
                    $name = 'salary-'.$record->period->year.'-'.str_pad((string) $record->period->month, 2, '0', STR_PAD_LEFT).'.csv';

                    return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv']);
                }),
            Action::make('markPaid')->label('Mark as paid')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn (PayrollRun $record) => $record->status === 'finalized' && auth()->user()->can('finalize', $record))
                ->schema([DatePicker::make('paid_on')->native(false)->default(now())->required()])
                ->action(fn (PayrollRun $record, array $data) => self::run(fn () => app(PayrollRuns::class)->markPaid($record, $data['paid_on'], auth()->user()), 'Run marked as paid')),
            Action::make('paymentDate')->label('Set payment date')->icon(Heroicon::OutlinedCalendarDays)
                ->modalDescription('Salary TDS follows the payment date (Income-tax Act, 1961 up to 31 Mar 2026; Income-tax Act, 2025 s.392(1) from 1 Apr 2026). A calculated run returns to draft for recalculation.')
                ->visible(fn (PayrollRun $record) => $record->isEditable() && auth()->user()->can('payroll.calculate'))
                ->schema([DatePicker::make('payment_date')->required(), Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (PayrollRun $record, array $data) => self::run(fn () => app(PayrollRuns::class)->setPaymentDate($record, $data['payment_date'], $data['reason'], auth()->user()), 'Payment date set; recalculate the run')),
            Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedLockOpen)->color('danger')
                ->visible(fn (PayrollRun $record) => in_array($record->status, ['approved', 'finalized'], true) && auth()->user()->can('finalize', $record))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (PayrollRun $record, array $data) => self::run(fn () => app(PayrollRuns::class)->reopen($record, $data['reason'], auth()->user()), $record->status === 'approved' ? 'Run returned to draft for recalculation' : 'Run reopened; payslips withdrawn')),
        ];
    }

    private static function run(callable $callback, string|callable $success): void
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Payroll failed')->body($e->getMessage())->persistent()->send();
        }
    }
}
