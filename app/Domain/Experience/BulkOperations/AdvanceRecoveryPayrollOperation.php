<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Services\AdvanceRecoveries;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OwnRecords;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity XO-6 (matrix 26 §26.3.4 #6, #8, #10 — "Payroll Recovery" for the employees selected): for each employee,
 * AdvanceRecoveries::scheduleForEmployee in the chosen payroll month — everything outstanding and not yet scheduled, or at
 * most the amount given — exactly what "Recover through payroll" on the row does (`expense.pay`). The month is checked
 * against each employee's own company's open months; a finalized month, an advance a payroll run is already recovering and
 * one's own advances are refused per employee.
 */
final class AdvanceRecoveryPayrollOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'advance_recovery.payroll';
    }

    public function label(): string
    {
        return 'Recover through payroll';
    }

    public function description(): string
    {
        return 'Schedules each employee\'s outstanding advances (or at most the amount you give) for the payroll month you choose, oldest settle-by first. Employees with nothing left to schedule, or whose payroll of that month is finalized, are listed with the reason.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'expense.pay';
    }

    public function icon(): string
    {
        return 'heroicon-m-calendar-days';
    }

    public function schema(): array
    {
        return [
            Select::make('month')->label('Salary month')->options(app(PayrollMonths::class)->upcoming())->required()->native(false)
                ->helperText('Checked for each employee against their company\'s payroll months: a finalized month is refused.'),
            TextInput::make('amount')->label('At most, per employee (optional)')->numeric()->minValue(0.01)
                ->helperText('Blank: everything outstanding and not yet scheduled.'),
            Textarea::make('note')->label('Comments (optional)')->rows(2)->maxLength(500),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $month = trim((string) ($data['month'] ?? ''));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new RuntimeException('Choose the payroll month that recovers it.');
        }
        $amount = filled($data['amount'] ?? null) ? (is_numeric($data['amount']) ? round((float) $data['amount'], 2) : -1.0) : null;
        if ($amount !== null && $amount <= 0) {
            throw new RuntimeException('Give an amount above 0, or leave it blank.');
        }
        $note = trim((string) ($data['note'] ?? ''));

        return ['month' => $month, 'amount' => $amount, 'note' => $note === '' ? null : mb_substr($note, 0, 500)];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        return app(OwnRecords::class)->isOwn($actor, $record->getKey()) ? 'Your own advances are recovered by someone else.' : null;
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $recoveries = app(AdvanceRecoveries::class);
        // At most the amount given, and never more than what is free for that month (so a smaller outstanding is not refused).
        $amount = $input['amount'] === null ? null : round(min((float) $input['amount'], array_sum($recoveries->preview($record, (string) $input['month'], null))), 2);
        if ($amount !== null && $amount <= 0) {
            throw new RuntimeException('Nothing outstanding is left to schedule.');
        }
        $schedules = $recoveries->scheduleForEmployee($record, (string) $input['month'], $amount, $input['note'] ?? null, $actor);
        $total = round((float) $schedules->sum(fn (ExpenseAdvanceRecoverySchedule $s) => (float) $s->amount), 2);

        return CarbonImmutable::parse($input['month'].'-01')->format('M Y').': '.number_format($total, 2).' ('.$schedules->count().' advance'.($schedules->count() === 1 ? '' : 's').')';
    }
}
