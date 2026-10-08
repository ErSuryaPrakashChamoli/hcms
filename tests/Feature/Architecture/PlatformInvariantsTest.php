<?php

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Services\Employee360;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Services\ExternalReferences;
use App\Domain\Integration\Services\IntegrationSystems;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\People\Models\Person;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 14 §21: the thirty final architecture invariants, one executable check each. Several restate,
 * in one place, rules that domain invariant suites also enforce in more depth (Compliance, Payroll,
 * Talent, Workforce, Engagement, ...). The MySQL concurrency suites back invariant 18 under real locking.
 */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create();
    $this->hire = fn (string $first, array $position = []) => app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Inv'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id] + $position);
});

/** @return list<string> application PHP source paths under $dir (relative to base) */
function invariantFiles(string $dir): array
{
    $out = [];
    if (! is_dir(base_path($dir))) {
        return $out;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }
    sort($out);

    return $out;
}

/** Files under $dir whose source matches $pattern. @return list<string> */
function invariantOffenders(string $dir, string $pattern, array $allow = []): array
{
    return array_values(array_filter(invariantFiles($dir), fn ($f) => ! in_array($f, $allow, true) && preg_match($pattern, file_get_contents(base_path($f))) === 1));
}

/** @return list<class-string<Model>> */
function invariantModels(): array
{
    return collect(glob(app_path('Domain/*/Models/*.php')))
        ->map(fn ($f) => 'App\\Domain\\'.basename(dirname($f, 2)).'\\Models\\'.basename($f, '.php'))
        ->filter(fn ($c) => class_exists($c) && is_subclass_of($c, Model::class))->values()->all();
}

it('1. has no RMS dependency: no RMS models, tables, connections or shared identifiers', function () {
    expect(invariantOffenders('app', '~namespace\s+[^;]*\\\\Rms\b|use\s+[^;]*\\\\Rms\\\\|RecruitmentEdge\\\\|connection\([\'"]rms[\'"]\)~i'))->toBe([])
        ->and(invariantOffenders('database/migrations', '~Schema::(create|table)\([\'"]rms_~i'))->toBe([])
        ->and(array_filter(array_keys(config('database.connections')), fn ($n) => str_contains(strtolower($n), 'rms')))->toBe([]);
});

it('2. keeps Employee the one canonical employment identity (one per person per tenant)', function () {
    $employee = ($this->hire)('Asha');
    expect(fn () => Employee::query()->create(['person_id' => $employee->person_id, 'employee_code' => 'DUP-1', 'lifecycle_state' => 'pre_employee', 'joining_date' => '2025-01-01']))->toThrow(QueryException::class);
    $identityTables = collect(Schema::getTables())->pluck('name')->filter(fn ($t) => Schema::hasColumns($t, ['person_id', 'employee_code']))->values()->all();
    expect($identityTables)->toBe(['employees']);
});

it('3. keeps Person the one canonical natural person', function () {
    $tables = collect(Schema::getTables())->pluck('name')->filter(fn ($t) => Schema::hasColumns($t, ['first_name', 'date_of_birth']))->values()->all();
    expect($tables)->toBe(['people']);
});

it('4. keeps the Employee 360 a read surface, never a system of record', function () {
    expect(invariantOffenders('app/Domain/Experience/Services', '~(->|::)(create|update|save|delete|insert|upsert|forceFill)\(~', array_values(array_diff(invariantFiles('app/Domain/Experience/Services'), ['app/Domain/Experience/Services/Employee360.php']))))->toBe([])
        ->and(collect(Schema::getTables())->pluck('name')->filter(fn ($t) => str_contains($t, '360'))->all())->toBe([]);
    $employee = ($this->hire)('Bala');
    $before = AuditEvent::query()->count();
    app(Employee360::class)->for($this->hr, $employee);
    expect(AuditEvent::query()->count())->toBe($before);
});

it('5. keeps salary assignment out of Payroll', function () {
    expect(glob(app_path('Domain/Payroll/Models/*SalaryAssignment*.php')))->toBe([])
        ->and(invariantOffenders('app/Domain/Payroll', '~EmployeeSalaryAssignment::(create|query\(\)->(update|delete))|new EmployeeSalaryAssignment~'))->toBe([]);
});

it('6. makes Compensation the owner of salary assignment', function () {
    expect(class_exists(EmployeeSalaryAssignment::class))->toBeTrue()
        ->and(invariantOffenders('app/Domain', '~EmployeeSalaryAssignment::create\(|new EmployeeSalaryAssignment\(~', invariantFiles('app/Domain/Compensation')))->toBe([]);
});

it('7. makes Attendance consume Leave through its contract, not Leave tables', function () {
    expect(invariantOffenders('app/Domain/Attendance/Services', '~LeaveRequest::|LeaveBalance|LeaveLedgerEntry|LeaveAccrual~'))->toBe([])
        ->and(file_get_contents(app_path('Domain/Attendance/Services/AttendanceProcessor.php')))->toContain('LeaveDayResolver');
});

it('8. makes Payroll consume Attendance through its output contract', function () {
    expect(invariantOffenders('app/Domain/Payroll', '~AttendancePunch|AttendanceProcessor~'))->toBe([])
        ->and(invariantOffenders('app/Domain/Payroll', '~AttendanceRecord::~', ['app/Domain/Payroll/Services/PayrollRuns.php']))->toBe([]);
});

it('9. makes Payroll consume Compensation through its output contract', function () {
    expect(invariantOffenders('app/Domain/Payroll', '~use App\\\\Domain\\\\Compensation\\\\Models\\\\~'))->toBe([])
        ->and(interface_exists(CompensationOutput::class))->toBeTrue();
});

it('10. keeps Performance from mutating Learning', function () {
    expect(invariantOffenders('app/Domain/Performance', '~use App\\\\Domain\\\\Learning\\\\(Models|Actions)\\\\~'))->toBe([]);
});

it('11. keeps Learning from mutating Career automatically', function () {
    expect(invariantOffenders('app/Domain/Learning', '~use App\\\\Domain\\\\Career\\\\(Models|Actions|Services)\\\\~'))->toBe([]);
});

it('12. keeps Career from mutating Employment', function () {
    expect(invariantOffenders('app/Domain/Career', '~use App\\\\Domain\\\\Employment\\\\Actions\\\\|EmployeePosition::create|->lifecycle_state\s*=~'))->toBe([]);
});

it('13. keeps Workforce Planning from mutating employment', function () {
    expect(invariantOffenders('app/Domain/Workforce', '~use App\\\\Domain\\\\Employment\\\\Actions\\\\|EmployeePosition::create|LifecycleEngine~'))->toBe([]);
});

it('14. keeps the Service Desk a request layer, not a system of record for other domains', function () {
    // Fulfilment goes through the owning domain's action (ServiceDesk\DomainActions delegate); the desk never writes those records itself.
    expect(invariantOffenders('app/Domain/ServiceDesk', '~(LeaveRequest|PayrollRun|EmployeeSalaryAssignment|EmployeePosition|Payslip|EmployeeBankAccount|ReportingRelationship|Employee|Person)::(create|query\(\)->(update|delete))\(~'))->toBe([])
        ->and(collect(Schema::getColumnListing('tickets'))->intersect(['salary', 'account_number', 'lifecycle_state', 'manager_id'])->values()->all())->toBe([]);
});

it('15. keeps Engagement from mutating Performance automatically', function () {
    expect(invariantOffenders('app/Domain/Engagement', '~use App\\\\Domain\\\\Performance\\\\~'))->toBe([]);
});

it('16. keeps Communication from storing employee state', function () {
    $tables = ['announcements', 'announcement_reads', 'communication_recipients', 'communication_preferences'];
    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'lifecycle_state'))->toBeFalse()->and(Schema::hasColumn($table, 'joining_date'))->toBeFalse();
    }
    expect(invariantOffenders('app/Domain/Communication', '~(Employee|Person|EmployeePosition)::query\(\)->(update|delete)|use App\\\\Domain\\\\Employment\\\\Actions\\\\~'))->toBe([]);
});

it('17. keeps the audit trail append-only', function () {
    ($this->hire)('Chitra');
    $event = AuditEvent::query()->firstOrFail();
    expect(fn () => AuditEvent::query()->whereKey($event->id)->update(['reason' => 'x']))->toThrow('append-only')
        ->and(fn () => AuditEvent::query()->whereKey($event->id)->delete())->toThrow('append-only');
});

it('18. keeps the audit chain valid (per-chain lock; MySQL races verify it under concurrency)', function () {
    ($this->hire)('Dev');
    ($this->hire)('Esha');
    expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
        ->and(file_get_contents(app_path('Domain/Audit/Services/AuditRecorder.php')))->toContain('audit_chain_locks');
});

it('19. lets AI propose but never mutate a protected domain', function () {
    expect(invariantOffenders('app/Domain/Ai', '~use App\\\\Domain\\\\(?!Ai\\\\)\w+\\\\Actions\\\\~'))->toBe([]);
    $writes = collect(invariantFiles('app/Domain/Ai'))->flatMap(fn ($f) => collect(explode("\n", file_get_contents(base_path($f))))
        ->filter(fn ($l) => preg_match('~(->|::)(create|update|save|delete|forceFill|insert|upsert)\(~', $l) && ! str_contains($l, 'AiInteraction::create(') && ! str_contains($l, '$interaction->update(') && ! preg_match('~public function~', $l))
        ->map(fn ($l) => "{$f}: ".trim($l)))->values()->all();
    expect($writes)->toBe([]);
});

it('20. keeps canonical employee identity out of the Integration Hub', function () {
    foreach (['integration_systems', 'external_references', 'inbound_events', 'integration_mappings'] as $table) {
        expect(Schema::hasColumn($table, 'first_name'))->toBeFalse()->and(Schema::hasColumn($table, 'employee_code'))->toBeFalse();
    }
    expect(invariantOffenders('app/Domain/Integration', '~(Employee|Person)::(create|query\(\)->(update|delete))|use App\\\\Domain\\\\Employment\\\\Actions\\\\~'))->toBe([]);
});

it('21. never lets an external id replace or re-point a PeopleOS id', function () {
    $system = app(IntegrationSystems::class)->create(['code' => 'erp', 'name' => 'ERP'], $this->hr)['system'];
    [$a, $b] = [($this->hire)('Farah'), ($this->hire)('Gita')];
    $reference = app(ExternalReferences::class)->link($system, $a, 'worker', 'W-21');
    expect($reference->entity_id)->toBe($a->id)
        ->and(fn () => app(ExternalReferences::class)->link($system, $b, 'worker', 'W-21'))->toThrow(IntegrationRejected::class)
        ->and(Employee::query()->whereKey($a->id)->value('employee_code'))->toBe($a->employee_code);
});

it('22. scopes every tenant-owned model to its tenant', function () {
    // Documented exception: User (authentication runs before a tenant is bound; every lookup by input uses forCurrentTenant()).
    // AuditEvent registers the TenantScope itself (the trait would stamp writes; the recorder owns those).
    $unscoped = collect(invariantModels())->reject(fn ($c) => $c === User::class)
        ->filter(fn ($c) => Schema::hasColumn((new $c)->getTable(), 'tenant_id') && ! in_array(BelongsToTenant::class, class_uses_recursive($c), true)
            && ! array_key_exists(TenantScope::class, (new $c)->getGlobalScopes()))->values()->all();
    expect($unscoped)->toBe([]);
});

it('23. protects every highly sensitive attribute (encrypted or hidden)', function () {
    foreach (config('peopleos.data_classification.highly_sensitive') as $class => $attributes) {
        $model = new $class;
        foreach ((array) $attributes as $attribute) {
            $cast = $model->getCasts()[$attribute] ?? '';
            expect(str_contains((string) $cast, 'encrypted') || in_array($attribute, $model->getHidden(), true) || $attribute === 'password')->toBeTrue("{$class}::{$attribute}");
        }
    }
});

it('24. never treats a mentor, buddy or project lead as the manager', function () {
    $employee = ($this->hire)('Hari');
    $mentor = ($this->hire)('Isha');
    app(ChangeManagerAction::class)->handle($employee, $mentor, 'mentor');
    app(ChangeManagerAction::class)->handle($employee, ($this->hire)('Jai'), 'project');
    expect($employee->refresh()->currentManager)->toBeNull()
        ->and(config('peopleos.performance.manager_relationship_types'))->not->toContain('mentor')->not->toContain('buddy')->not->toContain('project');
});

it('25. preserves tenant context in every queued job', function () {
    $jobs = collect(explode("\n", trim((string) shell_exec('grep -rl "implements.*ShouldQueue" '.escapeshellarg(app_path())))))
        ->map(fn ($f) => 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($f, strlen(app_path()) + 1)));
    foreach ($jobs as $class) {
        expect(is_subclass_of($class, ShouldQueue::class) && is_subclass_of($class, TenantAwareJob::class))->toBeTrue($class)
            ->and(file_get_contents((new ReflectionClass($class))->getFileName()))->toContain('new BindTenantContext');
    }
    expect(class_exists(BindTenantContext::class))->toBeTrue();
});

it('26. protects every scheduled job against overlap and multi-server double runs', function () {
    foreach (app(Schedule::class)->events() as $event) {
        expect($event->withoutOverlapping)->toBeTrue($event->command)->and($event->onOneServer)->toBeTrue($event->command);
    }
});

it('27. keeps effective dating intact: a change closes the old row and opens a new one', function () {
    $employee = ($this->hire)('Kiran');
    $department = Department::factory()->create(['company_id' => $this->company->id]);
    app(AssignPositionAction::class)->handle($employee, ['company_id' => $this->company->id, 'department_id' => $department->id], 'transfer', '2026-01-01');
    $rows = EmployeePosition::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)->and($rows[0]->effective_to?->toDateString())->toBe('2025-12-31')->and($rows[1]->department_id)->toBe($department->id);
});

it('28. keeps history reconstructable: every audited model is audited with field changes', function () {
    $employee = ($this->hire)('Lata');
    $employee->refresh()->update(['work_email' => 'lata@new.test']);
    $event = AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', (string) $employee->id)->latest('id')->firstOrFail();
    expect($event->fieldChanges->pluck('field')->all())->toContain('work_email')
        ->and(in_array(Auditable::class, class_uses_recursive(Person::class), true))->toBeTrue();
});

it('29. never lets the statutory production gate be bypassed', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['peopleos.payroll.enforce_verified_rules' => false]);
    expect(ComplianceRules::enforced())->toBeTrue();
    app()->detectEnvironment(fn () => 'testing');
    expect(file_get_contents(app_path('Domain/Compliance/Services/Returns/StatutoryReturns.php')))->toContain('assertExportable')
        ->and(ComplianceRule::query()->where('verification_status', 'verified')->count())->toBe(0);
});

it('30. builds the Employee 360 only from what each domain lets the viewer see', function () {
    $employee = ($this->hire)('Meera');
    $viewer = tenantUser($this->tenant, ['employee.view']);
    expect(collect(app(Employee360::class)->for($viewer, $employee))->pluck('key')->all())->toBe(['identity', 'employment', 'organisation'])
        ->and(collect(app(Employee360::class)->for($this->hr, $employee))->pluck('key')->all())->toContain('payroll', 'compensation', 'performance', 'exit');
});
