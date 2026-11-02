<?php

namespace App\Domain\Exit\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Models\FinalSettlementLine;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Payroll\Imports\PayrollImportCells;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data import DI-4 (Payroll → Settlement): amounts for leavers' full and final settlements, one employee and line per
 * row, added as manual lines through FinalSettlements::addLine — the F&F screen's "Add line" — on the settlement of the
 * employee's exit in progress. Only a settlement not yet approved (draft or calculated) takes lines: an approved or
 * paid settlement, or an exit that is not in progress, is refused. A manual line of the same name and type already on
 * the settlement is replaced (update) when the amount differs. Lines survive recalculation; the settlement is then
 * approved and paid on the F&F screen as before (never by the upload).
 */
final class SettlementImports extends RowImports
{
    use PayrollImportCells;

    public const TYPE = 'settlements';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'type' => ['Earning or deduction', true], 'name' => ['Line', true], 'amount' => ['Amount', true], 'note' => ['Note', false]];
    }

    public function label(): string
    {
        return 'Settlement upload';
    }

    protected function module(): string
    {
        return 'exit';
    }

    protected function entityType(): string
    {
        return FinalSettlement::class;
    }

    protected function aliases(): array
    {
        return ['line_type' => 'type', 'kind' => 'type', 'line_name' => 'name', 'description' => 'name', 'component' => 'name', 'remarks' => 'note'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $type = $this->lineType($r['type'] ?? null);
        if ($type === null) {
            $errors[] = 'Earning or deduction must be earning or deduction.';
        }
        $name = trim((string) ($r['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $errors[] = 'Line is required (up to 255 characters).';
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Amount must be a number above zero.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $uploader = $this->uploader($import);
        if ($uploader === null || ! app(AccessScopes::class)->allows($uploader, $employee)) {
            return ['action' => 'error', 'errors' => ['The employee is outside your organisation scope.'], 'employee_id' => $employeeId];
        }
        $case = $this->caseOf($employeeId);
        if ($case === null) {
            return ['action' => 'error', 'errors' => ['The employee has no exit in progress.'], 'employee_id' => $employeeId];
        }
        $settlement = $case->settlement;
        if ($settlement !== null && ! $settlement->isEditable()) {
            return ['action' => 'error', 'errors' => ["The settlement of exit {$case->number} is {$settlement->status}; an approved or paid settlement is never changed."], 'employee_id' => $employeeId];
        }
        if (($duplicate = $this->seenBefore($employeeId.'|'.$type.'|'.mb_strtolower($name), $context, $row->row_number, 'settlement line')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }
        $line = $settlement === null ? null : $this->manualLine($settlement, $type, $name);
        if ($line === null) {
            return ['action' => 'create', 'employee_id' => $employeeId];
        }

        return round((float) $line->amount, 2) === $amount
            ? ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => "The line is already on the settlement of exit {$case->number} with this amount."]]
            : ['action' => 'update', 'employee_id' => $employeeId, 'match' => ['reason' => "Replaces the line on the settlement of exit {$case->number}."]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $service = app(FinalSettlements::class);
        $case = $this->caseOf((int) $row->employee_id) ?? throw new RuntimeException('The employee has no exit in progress.');
        $type = $this->lineType($r['type']);
        $name = trim($r['name']);

        return DB::transaction(function () use ($service, $case, $type, $name, $r, $import) {
            $settlement = FinalSettlement::query()->whereKey($service->ensure($case)->getKey())->lockForUpdate()->firstOrFail();
            if (! $settlement->isEditable()) {
                throw new RuntimeException("The settlement of exit {$case->number} is {$settlement->status}; an approved or paid settlement is never changed.");
            }
            $old = $this->manualLine($settlement, $type, $name);
            if ($old !== null) {
                $service->removeLine($old);
            }
            $line = $service->addLine($settlement, $type, $name, (float) $this->money($r['amount']), trim(($r['note'] ?? '').' ('.$this->label()." #{$import->getKey()})"));

            return ($old !== null ? 'Replaced' : 'Added')." {$line->type} {$line->name} of ".number_format((float) $line->amount, 2)." on exit {$case->number}";
        });
    }

    /** The employee's exit in progress (the latest open case). */
    private function caseOf(int $employeeId): ?ExitCase
    {
        return ExitCase::query()->with('settlement')->where('employee_id', $employeeId)->whereIn('status', ExitCase::OPEN)->latest('id')->first();
    }

    private function manualLine(FinalSettlement $settlement, string $type, string $name): ?FinalSettlementLine
    {
        return $settlement->lines()->where('source', 'manual')->where('type', $type)->get()->first(fn (FinalSettlementLine $l) => mb_strtolower(trim((string) $l->name)) === mb_strtolower($name));
    }

    private function lineType(?string $value): ?string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'earning', 'earnings', 'payable', 'pay' => 'earning',
            'deduction', 'deductions', 'recovery', 'recover' => 'deduction',
            default => null,
        };
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', 'The code of an employee whose exit is in progress.', $code],
                ['Earning or deduction', 'Yes', 'earning (paid to the employee) or deduction (recovered).', 'earning'],
                ['Line', 'Yes', 'What the amount is, as it shows on the settlement.', 'Pending incentive'],
                ['Amount', 'Yes', 'The amount, above zero.', '12000'],
                ['Note', 'No', 'Kept with the line.', 'Q3 incentive approved by sales head'],
            ],
            'notes' => [
                'Lines go on the settlement of the employee\'s exit in progress; one is started (draft) when there is none yet. Calculate, approve and pay it on the F&F screen as before.',
                'A line of the same name and type added earlier by hand or upload is replaced when the amount differs (choose Update existing records or Insert and update); the same amount is skipped.',
                'An approved or paid settlement is never changed, and an exit that is not in progress is refused.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);

        return [
            [$first, 'earning', 'Pending incentive', '12000', 'Q3 incentive'],
            [$second, 'deduction', 'Laptop damage recovery', '3500', 'Asset clearance'],
        ];
    }
}
