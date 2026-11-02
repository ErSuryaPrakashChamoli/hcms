<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Services\AdvanceRecoveries;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OwnRecords;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity XO-6 (matrix 26 §26.3.4 #6, #9, #10 — "Out of System Recovery" for the employees selected): for each
 * employee, everything outstanding that no payroll run is recovering is recorded as paid back outside payroll —
 * AdvanceRecoveries::refundForEmployee, each advance through ExpenseAdvances::refund with the same mode, bank, cheque,
 * reference and date (`expense.pay`; audited on each ledger entry).
 */
final class AdvanceRecoveryRefundOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'advance_recovery.refund';
    }

    public function label(): string
    {
        return 'Record recovery outside payroll';
    }

    public function description(): string
    {
        return 'Records each employee\'s whole outstanding (on advances no payroll is recovering) as paid back, with the mode, reference and date you give. Employees with nothing to recover are listed with the reason.';
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
        return 'heroicon-m-banknotes';
    }

    public function schema(): array
    {
        return [
            Select::make('mode')->label('Recovery mode')->options(ExpenseAdvanceEntry::REFUND_MODES)->required()->native(false)->live(),
            TextInput::make('bank_name')->label('Bank name')->maxLength(120)
                ->visible(fn (Get $get) => in_array($get('mode'), ['bank_transfer', 'cheque'], true))->required(fn (Get $get) => $get('mode') === 'cheque'),
            TextInput::make('cheque_number')->label('Cheque number')->maxLength(30)->required()->visible(fn (Get $get) => $get('mode') === 'cheque'),
            DatePicker::make('cheque_date')->label('Cheque date')->native(false)->required()->visible(fn (Get $get) => $get('mode') === 'cheque'),
            TextInput::make('reference')->label('Reference (UTR or receipt number)')->required()->maxLength(120),
            DatePicker::make('on')->label('Received on')->native(false)->default(today())->maxDate(today())->required(),
            Textarea::make('note')->label('Remarks (optional)')->rows(2)->maxLength(255),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $mode = filled($data['mode'] ?? null) ? (string) $data['mode'] : null;
        $details = [
            'mode' => $mode,
            'bank_name' => in_array($mode, ['bank_transfer', 'cheque'], true) ? ($data['bank_name'] ?? null) : null,
            'cheque_number' => $mode === 'cheque' ? ($data['cheque_number'] ?? null) : null,
            'cheque_date' => $mode === 'cheque' && filled($data['cheque_date'] ?? null) ? CarbonImmutable::parse($data['cheque_date'])->toDateString() : null,
            'note' => filled($data['note'] ?? null) ? (string) $data['note'] : null,
        ];
        if ($mode === null) {
            throw new RuntimeException('Choose how it was paid back: bank transfer, cheque or cash.');
        }
        ExpenseAdvances::refundDetails($details);
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '' || mb_strlen($reference) > 120) {
            throw new RuntimeException('Give the reference (at most 120 characters).');
        }
        $on = CarbonImmutable::parse($data['on'] ?? today())->startOfDay();
        if ($on->gt(CarbonImmutable::today())) {
            throw new RuntimeException('A recovery is recorded on or before today.');
        }

        return ['reference' => $reference, 'on' => $on->toDateString(), 'details' => $details];
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
        $done = app(AdvanceRecoveries::class)->refundForEmployee($record, null, (string) $input['reference'], (string) $input['on'], (array) $input['details'], $actor);

        return 'Recovered '.number_format(array_sum($done), 2).' ('.implode(', ', array_keys($done)).')';
    }
}
