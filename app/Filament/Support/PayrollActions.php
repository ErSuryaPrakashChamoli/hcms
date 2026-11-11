<?php

namespace App\Filament\Support;

use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\AttendanceMonthNotice;
use App\Domain\Payroll\Services\BankFile;
use App\Domain\Payroll\Services\JournalVoucher;
use App\Domain\Payroll\Services\PayCycle;
use App\Domain\Payroll\Services\PayrollMonthReopen;
use App\Domain\Payroll\Services\PayrollRunChecklist;
use App\Domain\Payroll\Services\PayrollRunCockpit;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\PayslipPublishing;
use App\Filament\Pages\RunPayroll;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
                Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->required()->live(),
                Select::make('year')->options(collect(range(now()->year - 1, now()->year + 1))->mapWithKeys(fn ($y) => [$y => $y])->all())->default(fn () => app(PayCycle::class)->monthOf(today())[0])->required()->live(),
                Select::make('month')->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m, 1)->format('F')])->all())->default(fn () => app(PayCycle::class)->monthOf(today())[1])->required()->live()
                    ->helperText(fn (Get $get) => self::periodDates($get('company_id'), $get('year'), $get('month'))),
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

    /** Streamline S2: the one way into a pay month's run — the Run payroll wizard (it starts the month on its first step). */
    public static function runPayroll(): Action
    {
        return Action::make('runPayroll')->label('Run payroll')->icon(Heroicon::OutlinedPlayCircle)
            ->visible(fn () => RunPayroll::canAccess())->url(fn () => RunPayroll::getUrl());
    }

    /** "Covers 25 Oct – 24 Nov 2026" for the month the open-run form names. */
    public static function periodDates(mixed $companyId, mixed $year, mixed $month): ?string
    {
        if (! is_numeric($companyId) || ! is_numeric($year) || ! is_numeric($month)) {
            return null;
        }
        $cycle = app(PayCycle::class);
        $period = PayrollPeriod::query()->where('company_id', (int) $companyId)->where('year', (int) $year)->where('month', (int) $month)->first();
        try {
            [$start, $end] = $period?->isFixed() ? [$period->start_date, $period->end_date] : $cycle->bounds((int) $companyId, (int) $year, (int) $month);
        } catch (RuntimeException) {
            return null;
        }

        return 'Covers '.$start->format('j M').' – '.$end->format('j M Y').' ('.((int) $start->diffInDays($end) + 1).' days)';
    }

    /** @return array<int, Action> */
    public static function forRun(): array
    {
        return [
            Action::make('calculate')->label(fn (PayrollRun $record) => $record->status === 'draft' ? 'Calculate' : 'Recalculate')->icon(Heroicon::OutlinedCalculator)->color('primary')
                ->visible(fn (PayrollRun $record) => $record->isEditable() && auth()->user()->can('payroll.calculate'))
                ->requiresConfirmation()->modalDescription(fn (PayrollRun $record) => self::withAttendanceNotice('Runs every employee of the company through the pipeline. Existing results for this run are replaced.', $record))
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayrollRuns::class)->calculate(self::ready($record), auth()->user()), fn (PayrollRun $r) => "Calculated for {$r->total('employees')} employee(s); {$r->exception_count} with exceptions")),
            // HROne parity PO-4 (§25.6.1 #3): the same calculation queued as CalculatePayrollRun; the person is told when it is done.
            Action::make('calculateInBackground')->label(fn (PayrollRun $record) => $record->status === 'draft' ? 'Calculate in background' : 'Recalculate in background')->icon(Heroicon::OutlinedClock)->color('gray')
                ->visible(fn (PayrollRun $record) => $record->isEditable() && auth()->user()->can('payroll.calculate'))
                ->requiresConfirmation()->modalDescription(fn (PayrollRun $record) => self::withAttendanceNotice('Runs every employee of the company through the pipeline in the background. Existing results for this run are replaced. You are told in the app and by e-mail when it is done.', $record))
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayrollRunCockpit::class)->queueCalculation(self::ready($record), auth()->user()), 'Calculation queued in the background; you will be told when it is done')),
            Action::make('validate')->label('Validate')->icon(Heroicon::OutlinedShieldCheck)->color('info')
                ->visible(fn (PayrollRun $record) => $record->status === 'calculated' && auth()->user()->can('payroll.calculate'))
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayrollRuns::class)->validate($record), 'Run validated; ready for approval')),
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (PayrollRun $record) => $record->status === 'validated' && auth()->user()->can('approve', $record))
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (PayrollRun $record, array $data) => self::run(fn () => app(PayrollRuns::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Run approved')),
            Action::make('finalize')->label('Close payroll')->icon(Heroicon::OutlinedLockClosed)->color('success')
                ->visible(fn (PayrollRun $record) => $record->status === 'approved' && auth()->user()->can('finalize', $record))
                ->requiresConfirmation()->modalHeading(fn (PayrollRun $record) => 'Close payroll · '.$record->period->label())
                ->modalDescription(fn (PayrollRun $record) => self::withAttendanceNotice('Locks the month\'s attendance, settles what the month pays (loans, claims, variable pay, encashments …) and prepares the payslips. '
                    .(app(PayslipPublishing::class)->atClose() ? 'Employees see their payslips at once.' : 'Employees see them once you publish them.')
                    .' Until the month is marked paid or a return is filed from it, it can be reopened to change it.', $record))
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayrollRuns::class)->finalize($record, auth()->user()), fn (PayrollRun $r) => $r->payslips_published_at !== null ? 'Payroll closed; payslips published' : 'Payroll closed; payslips ready to publish')),
            // Streamline S2 (ST-2): payslips reach employees in their own step.
            Action::make('publishPayslips')->label('Publish payslips')->icon(Heroicon::OutlinedPaperAirplane)->color('success')
                ->visible(fn (PayrollRun $record) => $record->isLocked() && $record->payslips_published_at === null && auth()->user()->can('payroll.publish'))
                ->requiresConfirmation()->modalHeading(fn (PayrollRun $record) => 'Publish payslips · '.$record->period->label())
                ->modalDescription('Each employee sees their payslip on the web and the app, and is told it is ready. Reopening the month later withdraws them until it is closed again.')
                ->action(fn (PayrollRun $record) => self::run(fn () => app(PayslipPublishing::class)->publish($record, auth()->user()), 'Payslips published to employees')),
            Action::make('bankFile')->label('Bank file')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (PayrollRun $record) => $record->isLocked() && auth()->user()->can('finalize', $record))
                // HROne setup parity BP-13 / CA-5: with paying accounts, one file per account in its bank's layout.
                ->schema(fn (PayrollRun $record) => ($groups = app(BankFile::class)->groups($record)) === [] ? [] : [
                    Select::make('account_id')->label('Paying account')->required()->default((string) array_key_first($groups))
                        ->options(collect($groups)->mapWithKeys(fn (array $g, int $id) => [(string) $id => $g['account']->label().' · '.$g['count'].' transfer(s), '.number_format($g['total'], 2)])->all() + ['all' => 'Every transfer (plain file)']),
                ])
                ->action(function (PayrollRun $record, array $data) {
                    $account = filled($data['account_id'] ?? null) && $data['account_id'] !== 'all' ? (int) $data['account_id'] : null;
                    $csv = app(BankFile::class)->csv($record, $account);
                    $name = app(BankFile::class)->fileName($record, $account);

                    return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => str_ends_with($name, '.txt') ? 'text/plain' : 'text/csv']);
                }),
            // Data import DI-1 (decision D2): the journal voucher of the run, for the books (ledgers and segments are JV codes).
            Action::make('journalVoucher')->label('Journal voucher (JV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (PayrollRun $record) => $record->isLocked() && auth()->user()->can('finalize', $record))
                ->schema(fn () => IntegrationSystem::query()->exists() ? [
                    Select::make('integration_system_id')->label('Accounting system')->placeholder('Every system (common codes)')
                        ->options(fn () => IntegrationSystem::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('A system\'s own JV codes win over the common ones.'),
                ] : [])
                ->action(function (PayrollRun $record, array $data) {
                    $system = filled($data['integration_system_id'] ?? null) ? IntegrationSystem::query()->find($data['integration_system_id']) : null;
                    $journal = app(JournalVoucher::class);
                    $csv = $journal->csv($record, $system);

                    return response()->streamDownload(fn () => print ($csv), $journal->fileName($record, $system), ['Content-Type' => 'text/csv']);
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
            // Streamline S2 (ST-1): the latest closed month reopens to be changed, until it is paid or a return is filed.
            Action::make('reopenMonth')->label('Reopen month')->icon(Heroicon::OutlinedLockOpen)->color('danger')
                ->visible(fn (PayrollRun $record) => $record->status === 'finalized' && auth()->user()->can('payroll.reopen_closed'))
                ->requiresConfirmation()->modalHeading(fn (PayrollRun $record) => 'Reopen '.$record->period->label())
                ->modalDescription(fn (PayrollRun $record) => self::reopenText($record))
                ->schema([Textarea::make('reason')->label('Why it is reopened')->required()->maxLength(500)])
                ->action(fn (PayrollRun $record, array $data) => self::run(fn () => app(PayrollMonthReopen::class)->reopen($record, $data['reason'], auth()->user()), fn (PayrollRun $r) => $r->period->label().' reopened: change it, then calculate, approve and close it again')),
            // Only an approved run that is not finalized yet returns to draft this way; a closed month uses Reopen month.
            Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedLockOpen)->color('danger')
                ->visible(fn (PayrollRun $record) => $record->status === 'approved' && auth()->user()->can('finalize', $record))
                ->requiresConfirmation()->modalDescription('Returns the approved run to draft for recalculation. It then needs validating and an independent approval again.')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (PayrollRun $record, array $data) => self::run(fn () => app(PayrollRuns::class)->reopen($record, $data['reason'], auth()->user()), 'Run returned to draft for recalculation')),
        ];
    }

    /**
     * Streamline S2 (ST-3, ST-4): the run starts only on a month whose attendance is done and whose pre-payroll checks are
     * ticked (each as the organisation requires); the refusal names what is left and where it is done.
     */
    private static function ready(PayrollRun $run): PayrollRun
    {
        $run->loadMissing('period');
        $blockers = app(PayrollRunChecklist::class)->blockers((int) $run->company_id, (int) $run->period->year, (int) $run->period->month);
        if ($blockers !== []) {
            throw new RuntimeException('Finish the steps before the run in Run payroll first: '.implode(' ', array_slice($blockers, 0, 4)).(count($blockers) > 4 ? ' (and '.(count($blockers) - 4).' more)' : ''));
        }

        return $run;
    }

    private static function reopenText(PayrollRun $run): string
    {
        $reopen = app(PayrollMonthReopen::class);
        if (($blocker = $reopen->blocker($run)) !== null) {
            return $blocker;
        }
        $preview = $reopen->preview($run);
        $inputs = array_sum($preview['inputs']);

        return 'The month returns to draft: attendance unlocks, '.$preview['payslips'].' payslip(s) are withdrawn'.($preview['published'] ? ' from employees' : '').' (kept on record), and '
            .$inputs.' settled item(s) — loans, claims, variable pay, encashments and the like — are due again.'
            .($preview['later_runs'] !== [] ? ' Calculated later months ('.implode(', ', $preview['later_runs']).') return to draft too.' : '')
            .' It then needs a fresh calculation, an independent approval and closing again; re-issued payslips carry a revision number.';
    }

    /** HROne parity TX-3: the attendance month's warning (month close in use and the month still open) after the text. */
    private static function withAttendanceNotice(string $text, PayrollRun $run): string
    {
        $notice = app(AttendanceMonthNotice::class)->forRun($run);

        return $notice === null ? $text : $text.' Warning: '.$notice;
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
