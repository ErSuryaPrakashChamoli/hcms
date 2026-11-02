<?php

namespace App\Domain\Compensation\Imports;

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Imports\PayrollImportCells;
use App\Domain\Payroll\Models\SalaryComponent;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Data import DI-4: the Payroll head's compensation uploads (Change pay group, Salary structure with component amounts).
 * Like the salary upload (SalaryImports), the file never writes pay: each row is checked as a proposal of the uploader
 * (CompensationChanges::check) and against the timeline rules execution would refuse (UploadTimeline), and running it
 * prepares one draft "salary upload" compensation cycle per company (CompensationCycles::fromUpload) whose lines are
 * the rows. Each cycle is then submitted, reviewed, approved and executed by four different people; Compensation stays
 * the sole writer of employee_salary_assignments. Only the uploader works on such a file (it holds pay) and runs it.
 */
abstract class CompensationRowImports extends RowImports
{
    use PayrollImportCells;

    /** Preparing such an upload is preparing a cycle and proposing its lines (as the salary upload). */
    public static function allowedFor(?User $user): bool
    {
        return SalaryImports::allowed($user);
    }

    /**
     * The proposal a valid row asks for, or what is wrong with it.
     *
     * @return array{0: ?Employee, 1: array<string, mixed>, 2: list<string>}
     */
    abstract protected function proposal(array $r, array $context, EmployeeImport $import): array;

    protected function module(): string
    {
        return 'compensation';
    }

    protected function entityType(): string
    {
        return CompensationChange::class;
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['structures' => SalaryStructure::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all()];
    }

    public function register(string $path, string $originalName, User $user, ?string $reason = null, array $options = []): EmployeeImport
    {
        if (! self::allowedFor($user)) {
            throw new RuntimeException('Preparing compensation from a file needs compensation.cycles and compensation.propose.');
        }

        return parent::register($path, $originalName, $user, $reason, $options);
    }

    /**
     * RowImports::validate without the surrounding transaction (as the salary upload): the payroll-closure check of each
     * row takes payroll run row locks that finalisation also needs, and they must not be held for the whole file.
     */
    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['mapped', 'validated']);
        $mapping = $import->mapping ?? [];
        $counts = ['valid' => 0, 'error' => 0, 'create' => 0, 'update' => 0, 'skip' => 0];
        $context = $this->prepare($import);
        $mode = ImportMode::of($import);

        foreach ($import->rows()->orderBy('row_number')->cursor() as $row) {
            try {
                $decision = $this->check($this->recordFor($row, $mapping) + $this->defaults($import), $context, $row, $import);
            } catch (Throwable $e) {
                $decision = ['action' => 'error', 'errors' => [$e->getMessage()]];
            }
            $action = ($decision['errors'] ?? []) !== [] ? 'error' : $decision['action'];
            $match = $decision['match'] ?? null;
            if (($action === 'create' && $mode === ImportMode::Update) || ($action === 'update' && $mode === ImportMode::New)) {
                $match = ['reason' => $action === 'create' ? 'Not on file; this upload only updates.' : 'Already on file; this upload only adds.'];
                $action = 'skip';
            }
            $row->update(['action' => $action, 'errors' => ($decision['errors'] ?? []) ?: null, 'match' => $match, 'employee_id' => $decision['employee_id'] ?? null, 'status' => 'pending', 'result' => null]);
            $counts[$action === 'error' ? 'error' : 'valid']++;
            if ($action !== 'error') {
                $counts[$action]++;
            }
        }
        $import->update(['status' => 'validated', 'valid_count' => $counts['valid'], 'error_count' => $counts['error'], 'review_count' => 0, 'create_count' => $counts['create'], 'update_count' => $counts['update'], 'skip_count' => $counts['skip']]);

        return $import->refresh();
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $actor = $this->uploader($import) ?? throw new RuntimeException('The uploader of this file no longer exists.');
        [$employee, $proposal, $errors] = $this->proposal($r, $context, $import);
        if ($employee !== null && ($duplicate = $this->seenBefore((string) $employee->id, $context, $row->row_number, 'employee')) !== null) {
            $errors[] = $duplicate;
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => array_values(array_unique($errors)), 'employee_id' => $employee?->id];
        }
        $match = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'matched_on' => ['employee code']];
        try {
            $content = app(CompensationChanges::class)->check($employee, $proposal, $actor);
        } catch (CompensationRuleViolation $e) {
            return ['action' => 'error', 'errors' => [$e->getMessage()], 'employee_id' => $employee->id];
        }
        $timeline = app(UploadTimeline::class);
        $company = $timeline->companyOn($employee, $content['effective_from']);
        if ($company === null) {
            return ['action' => 'error', 'errors' => ["Employee {$employee->employee_code} has no position on {$content['effective_from']}."], 'employee_id' => $employee->id];
        }
        [$action, $note] = $timeline->against($employee, $content, $company);
        if ($action === 'error') {
            return ['action' => 'error', 'errors' => [$note], 'employee_id' => $employee->id];
        }
        if ($action === 'skip') {
            return ['action' => 'skip', 'employee_id' => $employee->id, 'match' => $match + ['reason' => $note]];
        }

        // New or existing: an employee with no compensation on file gets a first one; the rest are revised.
        return ['action' => $timeline->onFile($employee) ? 'update' : 'create', 'employee_id' => $employee->id, 'match' => $match + ['reason' => null]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        throw new RuntimeException('A compensation upload runs as a whole: its rows become the lines of a compensation cycle.');
    }

    /** The uploader approves their own file, unless the type is under maker-checker or workflow approval (as salary uploads). */
    public function approve(EmployeeImport $import, User $user, ?string $reason = null): EmployeeImport
    {
        if (! self::allowedFor($user) || (! ImportGovernance::checkedByAnother((string) $import->type) && (int) $import->uploaded_by !== (int) $user->getKey())) {
            throw new RuntimeException('Only the person who uploaded a compensation file approves it, unless another person checks uploads of this kind.');
        }

        return parent::approve($import, $user, $reason);
    }

    /**
     * Prepare one draft compensation cycle per company from the approved rows (each row is resolved again: facts may have
     * moved since validation). The uploader becomes each cycle's preparer.
     */
    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        $this->assert($import, ['approved']);
        if ((int) $import->uploaded_by !== (int) $user->getKey() || ! self::allowedFor($user)) {
            throw new RuntimeException('Only the person who uploaded a compensation file runs it: they prepare the compensation cycle.');
        }
        $import->update(['status' => 'importing']);
        $mapping = $import->mapping ?? [];
        $context = $this->prepare($import);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $byCompany = [];
        $actions = [];

        foreach ($import->rows()->whereIn('action', ['create', 'update', 'skip'])->orderBy('row_number')->cursor() as $row) {
            if ($row->action === 'skip') {
                $row->update(['status' => 'skipped', 'result' => 'Skipped: '.($row->match['reason'] ?? 'nothing to change')]);
                $result['skipped']++;

                continue;
            }
            [$employee, $proposal, $errors] = $this->proposal($this->recordFor($row, $mapping) + $this->defaults($import), $context, $import);
            $company = $employee !== null && $errors === [] ? app(UploadTimeline::class)->companyOn($employee, (string) $proposal['effective_from']) : null;
            if ($errors !== [] || $company === null) {
                $row->update(['status' => 'failed', 'result' => Str::limit(implode(' ', $errors ?: ['The employee has no position on the effective date.']), 500)]);
                $result['failed']++;

                continue;
            }
            $byCompany[$company][$row->id] = [$employee, $proposal];
            $actions[$row->id] = $row->action;
        }

        $cycles = [];
        try {
            $operationId = $this->audit->operation('compensation', $this->label()." #{$import->getKey()} ({$import->original_name})", function () use ($import, $user, $byCompany, $actions, &$result, &$cycles) {
                $ids = [];
                foreach ($byCompany as $companyId => $lines) {
                    $outcome = app(CompensationCycles::class)->fromUpload([
                        'code' => $this->cycleCode($import, $companyId, count($byCompany) > 1), 'name' => Str::limit($this->label().": {$import->original_name}", 250),
                        'company_id' => $companyId, 'effective_from' => collect($lines)->map(fn ($line) => $line[1]['effective_from'])->min(),
                    ], $lines, $user);
                    $cycle = $outcome['cycle'];
                    $cycles[] = $cycle->id;
                    foreach ($outcome['lines'] as $rowId => $line) {
                        $row = EmployeeImportRow::query()->findOrFail($rowId);
                        if ($line instanceof CompensationChange) {
                            $row->update(['status' => 'imported', 'result' => "Line {$line->reference} in compensation cycle {$cycle->code}"]);
                            $result[$actions[$rowId] === 'update' ? 'updated' : 'created']++;
                            $ids[] = $line->id;
                        } else {
                            $row->update(['status' => 'failed', 'result' => Str::limit($line, 500)]);
                            $result['failed']++;
                        }
                    }
                }

                return ['succeeded' => $result['created'] + $result['updated'] + $result['skipped'], 'failed' => $result['failed'], 'ids' => $ids];
            }, reason: $this->label().' '.$import->original_name, entityType: CompensationChange::class);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        }

        $import->update(['status' => 'imported', 'imported_at' => now(), 'operation_id' => $operationId, 'options' => array_merge($import->options ?? [], ['compensation_cycle_ids' => $cycles]),
            'create_count' => $result['created'], 'update_count' => $result['updated'], 'skip_count' => $result['skipped'], 'failure_count' => $result['failed']]);

        return $import->refresh();
    }

    /** @return list<CompensationCycle> the cycles a run prepared */
    public function cycles(EmployeeImport $import): array
    {
        return CompensationCycle::query()->whereIn('id', (array) ($import->options['compensation_cycle_ids'] ?? []))->orderBy('id')->get()->all();
    }

    /** The employee a row names (by code, within the uploader's reach), or the error. @return array{0: ?Employee, 1: list<string>} */
    protected function employee(array $r, array $context): array
    {
        [$id, $errors] = $this->employeeOf($r, $context);

        return [$id === null ? null : Employee::query()->with('person')->find($id), $errors];
    }

    /** The structure a code names, or null. */
    protected function structureId(?string $code, array $context): ?int
    {
        return $code === null ? null : ($context['structures'][mb_strtolower(trim($code))] ?? null);
    }

    /** Fixed-amount and input pay items (non-statutory, active) that a compensation row can give amounts for: code => name. */
    protected static function componentColumns(): array
    {
        return SalaryComponent::query()->whereIn('calculation_method', ['fixed', 'input'])->where('is_statutory', false)->where('status', 'active')
            ->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    /** A pay item's code as a column header ("CONV") maps to its amount column. @return array<string, string> */
    protected function componentAliases(): array
    {
        return collect(self::componentColumns())->mapWithKeys(fn ($name, $code) => [Str::slug((string) $code, '_') => "component.{$code}"])->all();
    }

    /** component.CODE fields of a row as code => amount, and what is not a number. @return array{0: array<string, float>, 1: list<string>} */
    protected function componentValues(array $r): array
    {
        $values = [];
        $errors = [];
        foreach ($r as $field => $value) {
            if (! str_starts_with($field, 'component.')) {
                continue;
            }
            $amount = $this->money($value);
            if ($amount === null || $amount < 0) {
                $errors[] = 'The amount for component '.Str::after($field, '.')." '{$value}' must be a number, zero or more.";
            } else {
                $values[Str::after($field, '.')] = $amount;
            }
        }

        return [$values, $errors];
    }

    /** A change type key from its key or label, or null. */
    protected function changeType(?string $value): ?string
    {
        $types = (array) config('peopleos.compensation.change_types', []);
        $given = mb_strtolower(trim((string) $value));
        foreach ($types as $key => $label) {
            if ($given === mb_strtolower($key) || $given === mb_strtolower($label)) {
                return $key;
            }
        }

        return null;
    }

    private function cycleCode(EmployeeImport $import, int $companyId, bool $several): string
    {
        $code = 'UPLOAD-'.$import->getKey().($several ? "-C{$companyId}" : '');

        return CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->where('code', $code)->exists() ? $code.'-'.now()->format('YmdHis') : $code;
    }
}
