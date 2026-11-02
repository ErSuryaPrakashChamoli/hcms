<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Expense\Services\AdvanceRecoveries;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use RuntimeException;

/**
 * HROne parity XO-6 (matrix 26 §26.3.4 #8, #9): the forms and their submission for "Recover through payroll" and "Record
 * recovery outside payroll" — one employee at a time, on the Advance recovery desk and on the employee's advance ledger —
 * and for changing or cancelling a scheduled recovery. Each is a step wizard; every rule is AdvanceRecoveries'.
 */
final class AdvanceRecoveryActions
{
    /** May the signed-in person recover this employee's advances (expense.pay, in scope, never their own)? */
    public static function mayRecover(?Employee $employee): bool
    {
        $user = auth()->user();

        return $employee !== null && $user instanceof User && app(ExpensePolicy::class)->pay($user, $employee);
    }

    /** "Recover through payroll": the month and amount, then the confirmation (HROne's "Yes, Recover Payroll"). */
    public static function payrollSteps(Employee $employee): array
    {
        $recoveries = app(AdvanceRecoveries::class);
        $free = $recoveries->unscheduledFor($employee);

        return [
            Step::make('Month and amount')->schema([
                Select::make('month')->label('Salary month')->options(app(PayrollMonths::class)->openFor((int) $employee->id))->required()->native(false)->live()
                    ->helperText('Only a month whose payroll is not finalized. That payroll deducts the amount; it is recovered when that payroll is finalized.'),
                TextInput::make('amount')->label('Recovery amount')->numeric()->minValue(0.01)->maxValue($free)->required()->default($free)->live(onBlur: true)
                    ->helperText('Up to '.number_format($free, 2).' — outstanding and not yet scheduled — spread over the advances, oldest settle-by first.'),
                Textarea::make('note')->label('Comments (optional)')->rows(2)->maxLength(500),
            ]),
            Step::make('Confirm')->schema([
                Text::make(fn (Get $get) => self::payrollSummary($employee, $get('month'), $get('amount')))->columnSpanFull(),
            ]),
        ];
    }

    public static function payrollSummary(Employee $employee, mixed $month, mixed $amount): string
    {
        $month = is_string($month) ? $month : null;
        $split = app(AdvanceRecoveries::class)->preview($employee, $month, is_numeric($amount) ? (float) $amount : null);
        if ($month === null || $split === []) {
            return 'Choose the salary month and the amount first.';
        }
        $label = CarbonImmutable::parse($month.'-01')->format('M Y');
        $parts = collect($split)->map(fn (float $a, string $number) => $number.' '.number_format($a, 2))->implode(', ');

        return 'You are about to recover '.number_format(array_sum($split), 2).' from '.$employee->employee_code.'\'s payroll of '.$label.' ('.$parts.'). '
            .'Until that payroll is finalized the automatic recovery of these advances waits; nothing more than what is outstanding is ever deducted.';
    }

    public static function runPayroll(Employee $employee, array $data): void
    {
        try {
            $schedules = app(AdvanceRecoveries::class)->scheduleForEmployee($employee, (string) ($data['month'] ?? ''), is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null, $data['note'] ?? null);
            $total = round((float) $schedules->sum(fn (ExpenseAdvanceRecoverySchedule $s) => (float) $s->amount), 2);
            Notification::make()->success()->title('Recovery scheduled')
                ->body(number_format($total, 2).' in the payroll of '.$schedules->first()?->monthLabel().' ('.$schedules->count().' advance'.($schedules->count() === 1 ? '' : 's').').')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not scheduled')->body($e->getMessage())->send();
        }
    }

    /** "Record recovery outside payroll": how it came back, then the amount and reference. */
    public static function outsideSteps(Employee $employee): array
    {
        $max = app(AdvanceRecoveries::class)->refundableFor($employee);

        return [
            Step::make('How it was paid back')->schema(self::modeFields(true)),
            Step::make('Amount and reference')->schema([
                TextInput::make('amount')->label('Amount recovered')->numeric()->minValue(0.01)->maxValue($max)->required()->default($max)
                    ->helperText('Up to '.number_format($max, 2).', spread over the advances, oldest settle-by first.'),
                ...self::referenceFields(),
            ]),
        ];
    }

    /**
     * The refund's mode fields (bank transfer, cheque with bank, number and date, cash).
     *
     * @param  bool  $required  the desk asks for the mode; the advance's "Record refund" keeps it optional (as before)
     */
    public static function modeFields(bool $required): array
    {
        return [
            Select::make('mode')->label('Recovery mode')->options(ExpenseAdvanceEntry::REFUND_MODES)->native(false)->live()->required($required)
                ->placeholder($required ? null : 'Not recorded'),
            TextInput::make('bank_name')->label('Bank name')->maxLength(120)
                ->visible(fn (Get $get) => in_array($get('mode'), ['bank_transfer', 'cheque'], true))->required(fn (Get $get) => $get('mode') === 'cheque'),
            TextInput::make('cheque_number')->label('Cheque number')->maxLength(30)->required()->visible(fn (Get $get) => $get('mode') === 'cheque'),
            DatePicker::make('cheque_date')->label('Cheque date')->native(false)->required()->visible(fn (Get $get) => $get('mode') === 'cheque'),
        ];
    }

    public static function referenceFields(): array
    {
        return [
            TextInput::make('reference')->label('Reference (UTR or receipt number)')->maxLength(120)->required(fn (Get $get) => ! in_array($get('mode'), ['cheque', 'cash'], true))
                ->helperText('For a cheque or cash it may stay blank: "Cheque <number>" or "Cash <date>" is recorded.'),
            DatePicker::make('on')->label('Received on')->native(false)->default(today())->maxDate(today())->required(),
            Textarea::make('note')->label('Remarks (optional)')->rows(2)->maxLength(255),
        ];
    }

    /**
     * The refund's reference and details from the form; a blank reference becomes "Cheque <number>" or "Cash <date>".
     *
     * @return array{0: string, 1: array{mode: ?string, bank_name: ?string, cheque_number: ?string, cheque_date: mixed, note: ?string}}
     */
    public static function refundData(array $data): array
    {
        $mode = filled($data['mode'] ?? null) ? (string) $data['mode'] : null;
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '') {
            $reference = match ($mode) {
                'cheque' => 'Cheque '.trim((string) ($data['cheque_number'] ?? '')),
                'cash' => 'Cash '.CarbonImmutable::parse($data['on'] ?? today())->format('d M Y'),
                default => '',
            };
        }

        return [$reference, [
            'mode' => $mode,
            'bank_name' => in_array($mode, ['bank_transfer', 'cheque'], true) ? ($data['bank_name'] ?? null) : null,
            'cheque_number' => $mode === 'cheque' ? ($data['cheque_number'] ?? null) : null,
            'cheque_date' => $mode === 'cheque' ? ($data['cheque_date'] ?? null) : null,
            'note' => $data['note'] ?? null,
        ]];
    }

    public static function runOutside(Employee $employee, array $data): void
    {
        [$reference, $details] = self::refundData($data);
        try {
            $done = app(AdvanceRecoveries::class)->refundForEmployee($employee, is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null, $reference, $data['on'] ?? today(), $details);
            Notification::make()->success()->title('Recovery recorded')
                ->body(number_format(array_sum($done), 2).' ('.collect($done)->map(fn (float $a, string $n) => $n.' '.number_format($a, 2))->implode(', ').').')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();
        }
    }

    /** "Change": another open month and, if wished, another amount. */
    public static function rescheduleSchema(ExpenseAdvanceRecoverySchedule $schedule): array
    {
        return [
            Select::make('month')->label('Salary month')->options(app(PayrollMonths::class)->openFor((int) $schedule->employee_id))->required()->native(false),
            TextInput::make('amount')->label('Recovery amount')->numeric()->minValue(0.01)->required(),
            Textarea::make('note')->label('Comments (optional)')->rows(2)->maxLength(500),
        ];
    }

    public static function runReschedule(ExpenseAdvanceRecoverySchedule $schedule, array $data): void
    {
        try {
            app(AdvanceRecoveries::class)->reschedule($schedule, (string) ($data['month'] ?? ''), is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null, $data['note'] ?? null);
            Notification::make()->success()->title('Recovery changed')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();
        }
    }

    public static function runCancel(ExpenseAdvanceRecoverySchedule $schedule, array $data): void
    {
        try {
            app(AdvanceRecoveries::class)->cancel($schedule, $data['reason'] ?? null);
            Notification::make()->success()->title('Recovery cancelled')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();
        }
    }
}
