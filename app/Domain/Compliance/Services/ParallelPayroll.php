<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\ParallelPayrollLine;
use App\Domain\Compliance\Models\ParallelPayrollRun;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6.5 controlled parallel payroll (§22–23).
 *
 * 1. import — a person uploads the business's reference values for a FINALIZED run (CSV:
 *    level,employee_code,scope,component,amount; level "employee" for employee × component, or
 *    "return" with scope TYPE:ESTABLISHMENT_CODE and a return-total component such as ee_share).
 * 2. compare — PeopleOS values are laid beside them; every key on either side becomes a line.
 * 3. review — each difference or missing value needs a reason, a resolution and a reviewer who is
 *    not the importer. Rules are never changed to make totals match.
 * 4. reconcile — only when every open line is resolved, every PeopleOS employee component has a
 *    reference value, and at least one statutory return total was compared. Return totals the
 *    business did not supply are shown as "not compared". Matching totals alone never count.
 */
final class ParallelPayroll
{
    private const TOLERANCE = 0.005;

    public function __construct(private readonly AuditRecorder $audit) {}

    public function import(PayrollRun $run, User $actor, string $csv, string $source, ?string $description = null): ParallelPayrollRun
    {
        $this->requirePermission($actor);
        if (! in_array($run->status, ['finalized', 'paid'], true)) {
            throw new RuntimeException('A parallel run compares a finalized payroll run.');
        }
        if (blank(trim($source))) {
            throw new RuntimeException('Name the reference source (the payroll system or portal the values come from).');
        }

        $rows = $this->parse($csv);

        return DB::transaction(function () use ($run, $actor, $csv, $source, $description, $rows) {
            ParallelPayrollRun::query()->where('payroll_run_id', $run->getKey())->whereIn('status', ['imported', 'compared'])->update(['status' => 'abandoned']);

            $parallel = ParallelPayrollRun::query()->create([
                'company_id' => $run->company_id, 'payroll_run_id' => $run->getKey(), 'reference_source' => trim($source), 'reference_description' => $description,
                'reference_file_sha256' => hash('sha256', $csv), 'status' => 'imported', 'imported_by' => $actor->getKey(), 'imported_at' => now(),
            ]);
            $employees = Employee::query()->whereIn('employee_code', collect($rows)->pluck('employee_code')->filter()->unique())->pluck('id', 'employee_code');

            foreach ($rows as $row) {
                ParallelPayrollLine::query()->create([
                    'parallel_payroll_run_id' => $parallel->getKey(), 'level' => $row['level'], 'employee_code' => $row['employee_code'], 'employee_id' => $employees[$row['employee_code']] ?? null,
                    'scope' => $row['scope'], 'component' => $row['component'], 'reference_value' => $row['amount'], 'status' => 'missing_peopleos',
                ]);
            }

            $this->audit->record(action: AuditAction::ParallelRunImported, module: 'compliance', entity: $parallel, reason: "Reference values from {$source}", metadata: ['payroll_run_id' => $run->getKey(), 'rows' => count($rows), 'sha256' => $parallel->reference_file_sha256], actor: $actor);

            return $parallel;
        });
    }

    public function compare(ParallelPayrollRun $parallel, User $actor): ParallelPayrollRun
    {
        $this->requirePermission($actor);
        if (! in_array($parallel->status, ['imported', 'compared'], true)) {
            throw new RuntimeException("A {$parallel->status} parallel run cannot be compared.");
        }

        return DB::transaction(function () use ($parallel) {
            $values = [...$this->employeeValues($parallel->payrollRun), ...$this->returnValues($parallel->payrollRun)];
            $lines = ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->getKey())->get()->keyBy(fn ($l) => $this->key($l->level, $l->employee_code, $l->scope, $l->component));

            foreach ($values as $key => $value) {
                $line = $lines->get($key) ?? new ParallelPayrollLine(['parallel_payroll_run_id' => $parallel->getKey()] + $value['identity']);
                $this->settle($line, $value['amount']);
                $lines->put($key, $line);
            }
            foreach ($lines as $key => $line) {
                if (! isset($values[$key])) {
                    $this->settle($line, null);
                }
            }

            $parallel->update(['status' => 'compared', 'compared_at' => now(), 'summary' => $this->summary($parallel)]);

            return $parallel;
        });
    }

    /** A reviewer (never the importer) explains a difference and records its resolution. */
    public function review(ParallelPayrollLine $line, User $reviewer, string $reason, string $resolution): ParallelPayrollLine
    {
        $this->requirePermission($reviewer);
        $parallel = ParallelPayrollRun::query()->findOrFail($line->parallel_payroll_run_id);
        if ((int) $parallel->imported_by === (int) $reviewer->getKey()) {
            throw new RuntimeException('The person who imported the reference values cannot review the differences.');
        }
        if (! in_array($line->status, ParallelPayrollLine::OPEN, true)) {
            throw new RuntimeException('Only an open difference can be reviewed.');
        }
        if (blank(trim($reason)) || blank(trim($resolution))) {
            throw new RuntimeException('A difference needs both its reason and its resolution.');
        }

        $line->update(['status' => 'resolved', 'reason' => $reason, 'resolution' => $resolution, 'reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now()]);
        $this->audit->record(action: AuditAction::ParallelDifferenceReviewed, module: 'compliance', entity: $parallel, reason: $reason, metadata: ['line_id' => $line->getKey(), 'component' => $line->component, 'employee_code' => $line->employee_code, 'scope' => $line->scope, 'peopleos' => (string) $line->peopleos_value, 'reference' => (string) $line->reference_value, 'resolution' => $resolution], actor: $reviewer);
        $parallel->update(['summary' => $this->summary($parallel)]);

        return $line;
    }

    public function reconcile(ParallelPayrollRun $parallel, User $actor): ParallelPayrollRun
    {
        $this->requirePermission($actor);
        if ($parallel->status !== 'compared') {
            throw new RuntimeException('Compare the run before reconciling it.');
        }
        if ((int) $parallel->imported_by === (int) $actor->getKey()) {
            throw new RuntimeException('The person who imported the reference values cannot sign off the reconciliation.');
        }

        $lines = ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->getKey())->get();
        $open = $lines->whereIn('status', ParallelPayrollLine::OPEN)->count();
        if ($open > 0) {
            throw new RuntimeException("{$open} difference(s) are not explained and resolved.");
        }
        if (! $lines->where('level', 'return')->whereIn('status', ['matched', 'resolved'])->count()) {
            throw new RuntimeException('Compare at least one statutory return total; employee-level matching alone is not a reconciled parallel run.');
        }
        if ($lines->where('level', 'employee')->isEmpty()) {
            throw new RuntimeException('No employee-level values were compared.');
        }

        $parallel->update(['status' => 'reconciled', 'reconciled_by' => $actor->getKey(), 'reconciled_at' => now(), 'summary' => $this->summary($parallel)]);
        $this->audit->record(action: AuditAction::ParallelRunReconciled, module: 'compliance', entity: $parallel, reason: 'Parallel run reconciled', metadata: $parallel->summary, actor: $actor);

        return $parallel;
    }

    /** @return array<string, int> */
    public function summary(ParallelPayrollRun $parallel): array
    {
        $lines = ParallelPayrollLine::query()->where('parallel_payroll_run_id', $parallel->getKey())->get();

        return ['lines' => $lines->count(), 'employees' => $lines->where('level', 'employee')->pluck('employee_code')->unique()->count(), 'return_totals' => $lines->where('level', 'return')->whereNotNull('reference_value')->count()]
            + $lines->countBy('status')->all() + array_fill_keys(['matched', 'difference', 'missing_reference', 'missing_peopleos', 'resolved', 'not_compared'], 0);
    }

    /** @return array<string, array{identity: array<string, mixed>, amount: float}> */
    private function employeeValues(PayrollRun $run): array
    {
        $values = [];

        foreach (PayrollEntry::query()->with(['lines', 'employee'])->where('payroll_run_id', $run->getKey())->get() as $entry) {
            $code = $entry->employee?->employee_code;
            $amounts = ['GROSS' => (float) $entry->gross, 'NET' => (float) $entry->net_pay] + $entry->lines->groupBy('code')->map(fn ($l) => (float) $l->sum('amount'))->all();
            foreach ($amounts as $component => $amount) {
                $values[$this->key('employee', $code, null, $component)] = ['identity' => ['level' => 'employee', 'employee_code' => $code, 'employee_id' => $entry->employee_id, 'scope' => null, 'component' => $component], 'amount' => round($amount, 2)];
            }
        }

        return $values;
    }

    /** @return array<string, array{identity: array<string, mixed>, amount: float}> */
    private function returnValues(PayrollRun $run): array
    {
        $values = [];
        $returns = StatutoryReturn::query()->whereNotIn('status', [StatutoryReturn::CANCELLED, StatutoryReturn::REVISED])->whereIn('return_type', ['EPF', 'ESI', 'PT', 'LWF'])->get()
            ->filter(fn (StatutoryReturn $r) => in_array($run->getKey(), array_map('intval', (array) $r->payroll_run_ids), true));

        foreach ($returns as $return) {
            $scope = $return->return_type.':'.(Establishment::query()->withoutGlobalScope(AccessScope::class)->whereKey($return->establishment_id)->value('code') ?? $return->establishment_id);
            foreach ((array) $return->totals as $component => $amount) {
                if (is_numeric($amount)) {
                    $key = $this->key('return', null, $scope, $component);
                    $values[$key] ??= ['identity' => ['level' => 'return', 'employee_code' => null, 'employee_id' => null, 'scope' => $scope, 'component' => $component], 'amount' => 0.0];
                    $values[$key]['amount'] = round($values[$key]['amount'] + (float) $amount, 2);
                }
            }
        }

        return $values;
    }

    private function settle(ParallelPayrollLine $line, ?float $peopleos): void
    {
        $reference = $line->reference_value === null ? null : (float) $line->reference_value;
        $difference = ($peopleos !== null && $reference !== null) ? round($peopleos - $reference, 2) : null;
        $status = match (true) {
            $peopleos === null => 'missing_peopleos',
            // Return totals the business did not supply are shown but not compared; employee
            // components without a reference value stay open.
            $reference === null && $line->level === 'return' => 'not_compared',
            $reference === null => 'missing_reference',
            abs((float) $difference) <= self::TOLERANCE => 'matched',
            default => 'difference',
        };

        // A reviewed line stays resolved only while the values it explained are unchanged.
        if ($line->exists && $line->status === 'resolved' && (float) $line->peopleos_value === (float) $peopleos && $line->reference_value !== null) {
            return;
        }

        $line->fill(['peopleos_value' => $peopleos, 'difference' => $difference, 'status' => $status])->save();
    }

    /** @return list<array{level: string, employee_code: ?string, scope: ?string, component: string, amount: float}> */
    private function parse(string $csv): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($csv)) ?: []), fn ($l) => $l !== ''));
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines) ?? '', ',', '"', ''));
        if ($header !== ['level', 'employee_code', 'scope', 'component', 'amount']) {
            throw new RuntimeException('The reference file needs the header: level,employee_code,scope,component,amount.');
        }

        $rows = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            [$level, $code, $scope, $component, $amount] = array_pad(array_map('trim', str_getcsv($line, ',', '"', '')), 5, '');
            $n = $i + 2;
            if (! in_array($level, ['employee', 'return'], true) || $component === '' || ! is_numeric($amount)) {
                throw new RuntimeException("Reference file line {$n} is invalid.");
            }
            if (($level === 'employee' && $code === '') || ($level === 'return' && $scope === '')) {
                throw new RuntimeException("Reference file line {$n}: employee lines need an employee code, return lines a scope.");
            }
            $key = $this->key($level, $code ?: null, $scope ?: null, $component);
            if (isset($seen[$key])) {
                throw new RuntimeException("Reference file line {$n} repeats {$component}.");
            }
            $seen[$key] = true;
            $rows[] = ['level' => $level, 'employee_code' => $code ?: null, 'scope' => $scope ?: null, 'component' => strtoupper($level) === 'EMPLOYEE' ? strtoupper($component) : $component, 'amount' => round((float) $amount, 2)];
        }

        if ($rows === []) {
            throw new RuntimeException('The reference file has no values.');
        }

        return $rows;
    }

    private function key(string $level, ?string $code, ?string $scope, string $component): string
    {
        return implode('|', [$level, $code ?? '', $scope ?? '', $level === 'employee' ? strtoupper($component) : $component]);
    }

    private function requirePermission(User $user): void
    {
        if (! $user->hasPermission('compliance.parallel.manage')) {
            throw new RuntimeException('You do not have the compliance.parallel.manage permission.');
        }
    }
}
