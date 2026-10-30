<?php

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Str;

/*
 | HROne setup parity (Expense): the expense module reads compensation only through the CompensationOutput contract
 | (a percentage-of-CTC limit), touches payroll only as a master it points at (a CTC pay component), and keeps every
 | record tenant-scoped and audited.
 */

/** @return array<string, string> file => code without comments, for every PHP file of the expense domain */
function expenseDomainSources(): array
{
    $sources = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Expense'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = '';
        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $sources[Str::after($file->getPathname(), base_path().'/')] = $code;
    }
    ksort($sources);

    return $sources;
}

it('reads compensation only through the CompensationOutput contract, never its models or tables', function () {
    $sources = expenseDomainSources();
    expect($sources)->not->toBeEmpty();

    $offending = [];
    foreach ($sources as $file => $code) {
        preg_match_all('/App\\\\Domain\\\\Compensation\\\\([A-Za-z\\\\]+)/', $code, $m);
        foreach ($m[1] as $class) {
            if ($class !== 'Contracts\\CompensationOutput' && ! str_starts_with($class, 'Support\\')) {
                $offending[] = "{$file}: {$class}";
            }
        }
        if (preg_match('/employee_salary_assignments|salary_structure|compensation_changes|EmployeeSalaryAssignment|SalaryStructure\b/', $code)) {
            $offending[] = "{$file}: compensation table or model";
        }
    }
    expect($offending)->toBe([])
        ->and(array_keys(array_filter($sources, fn (string $code) => str_contains($code, 'CompensationOutput'))))->toBe(['app/Domain/Expense/Support/CompensationAmounts.php']);
});

it('points at payroll only as a master (the CTC pay component) and never writes payroll', function () {
    $offending = [];
    foreach (expenseDomainSources() as $file => $code) {
        preg_match_all('/App\\\\Domain\\\\Payroll\\\\([A-Za-z\\\\]+)/', $code, $m);
        foreach ($m[1] as $class) {
            if (! in_array($class, ['Models\\SalaryComponent', 'Services\\StructureFormulas', 'Support\\FormulaVariables'], true)) {
                $offending[] = "{$file}: {$class}";
            }
        }
        if (preg_match('/payroll_entries|payroll_runs|payslips|PayrollEntry|PayrollRun\b/', $code)) {
            $offending[] = "{$file}: payroll records";
        }
    }
    expect($offending)->toBe([]);
});

it('scopes every expense model to its tenant and audits it', function () {
    $models = collect(glob(app_path('Domain/Expense/Models/*.php')))->map(fn (string $path) => 'App\\Domain\\Expense\\Models\\'.basename($path, '.php'));
    expect($models)->not->toBeEmpty();
    foreach ($models as $class) {
        expect(class_uses_recursive($class))->toHaveKey(BelongsToTenant::class)->toHaveKey(Auditable::class);
    }
});
