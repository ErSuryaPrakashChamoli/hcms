<?php

use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\ServiceDesk\Contracts\ServiceDomainAction;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Jobs\ProcessServiceDesk;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\DomainActionExecutor;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

/*
 | Phase 12 §49 architecture invariants (1–20), plus the People Domain Change Actions decision's
 | invariants (P1–P19). Static checks run on the code base with comments stripped, so a docblock that
 | names a boundary is not a hit. Behaviour in depth: tests/Feature/ServiceDesk and
 | tests/Feature/Employment/ProfileChangeActionsTest.
 */

function serviceDeskSource(string $path): string
{
    return collect(token_get_all(file_get_contents($path)))->map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t)->implode('');
}

/** @return array<string, string> relative path => source (comments stripped) */
function serviceDeskFiles(string $dir = 'Domain/ServiceDesk'): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[Str::after($file->getPathname(), base_path().'/')] = serviceDeskSource($file->getPathname());
        }
    }

    return $files;
}

/** Files under app/ that write a model directly (create / insert / upsert / new …), for the given class basenames. */
function directWrites(array $classes, array $files): array
{
    $alternation = implode('|', array_map('preg_quote', $classes));
    $pattern = '/\b(?:'.$alternation.')::(?:query\(\)->)?(?:create|forceCreate|insert|insertOrIgnore|upsert|updateOrCreate|firstOrCreate|updateOrInsert)\(|new\s+(?:'.$alternation.')\s*\(/';

    return array_keys(array_filter($files, fn (string $src) => preg_match($pattern, $src) === 1));
}

const FOREIGN_RECORDS = [
    'Employee', 'Person', 'EmployeePosition', 'EmployeeBankAccount', 'EmployeeStatutoryDetail', 'PersonAddress', 'PersonEmergencyContact', 'PersonFamilyMember',
    'EmployeeSalaryAssignment', 'CompensationChange', 'PayrollRun', 'PayrollEntry', 'Payslip', 'PayrollAdjustment', 'LeaveRequest', 'LeaveBalance', 'LeaveLedgerEntry',
    'AttendanceRecord', 'AttendancePunch', 'AttendanceRegularisation', 'EmployeeDocument', 'Letter', 'Appraisal', 'Goal', 'LearningEnrolment', 'LearningCompletion',
    'CareerProfile', 'TalentPoolMembership', 'SuccessionPlan', 'Successor', 'ReportingRelationship',
];

it('1 · scopes every Service Desk model to a tenant', function () {
    $unscoped = collect(glob(app_path('Domain/ServiceDesk/Models/*.php')))->map(fn ($p) => 'App\\Domain\\ServiceDesk\\Models\\'.basename($p, '.php'))
        ->reject(fn ($c) => in_array(BelongsToTenant::class, class_uses_recursive($c), true))->values()->all();
    expect($unscoped)->toBe([]);
});

it('2–10 · never writes Employee, Payroll, Compensation, Leave, Attendance, Documents, Performance, Learning or Career / Talent / Succession records from Service Desk code', function () {
    $files = serviceDeskFiles();
    expect(directWrites(FOREIGN_RECORDS, $files))->toBe([])
        ->and(array_keys(array_filter($files, fn ($src) => str_contains($src, 'DB::table('))))->toBe([])
        ->and(array_keys(array_filter($files, fn ($src) => preg_match('/AssignmentWriter|PayrollCalculator|PayrollFinali[sz]|LeaveLedger|LeaveBalances|AttendanceProcessor/', $src) === 1)))->toBe([]);
});

it('11 · has no RecruitmentEdge / RMS dependency in Service Desk or the experience layer', function () {
    $files = [...serviceDeskFiles(), ...serviceDeskFiles('Domain/Experience')];
    // Class references and imports only (a variable named $candidate is not a dependency).
    $pattern = '/RecruitmentEdge|App\\\\Domain\\\\(Recruitment|Rms)\\\\|use [^;]*\\\\(Candidate|Requisition|JobApplication|Interview)[A-Za-z]*;|\\b(Candidate|Requisition|JobApplication)::/';
    expect(array_keys(array_filter($files, fn ($src) => preg_match($pattern, $src) === 1)))->toBe([]);
});

it('12 · hands every service change to the owning domain action / contract', function () {
    $owners = ['profile.bank_account' => 'Employment\\Actions\\ChangeBankAccountAction', 'profile.statutory_identity' => 'Employment\\Actions\\ChangeStatutoryIdentityAction',
        'profile.address' => 'People\\Actions\\ChangeAddressAction', 'profile.emergency_contact' => 'People\\Actions\\ChangeEmergencyContactAction',
        'profile.family_member' => 'People\\Actions\\ChangeFamilyMemberAction', 'employment.manager_change' => 'Employment\\Actions\\ChangeManagerAction',
        'leave.request' => 'Leave\\Services\\Leaves', 'attendance.regularisation' => 'Attendance\\Services\\Regularisations', 'letter.request' => 'Letters\\Services\\Letters',
        'compensation.proposal' => 'Compensation\\Models\\CompensationChange'];
    foreach (config('peopleos.servicedesk.domain_actions') as $key => $class) {
        expect(is_subclass_of($class, ServiceDomainAction::class))->toBeTrue()
            ->and(serviceDeskSource((new ReflectionClass($class))->getFileName()))->toContain($owners[$key]);
    }
});

it('13 · cannot bypass domain approval: no execution before approval, and no second approval on a domain that approves itself', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $this->actingAs(tenantUser($tenant, ['*']));
    $approver = tenantUser($tenant, ['task.act']);
    sdApprovalWorkflow($approver, 'inv_approval');
    $employee = activeEmployee(null, ['servicedesk.request']);
    $service = sdApprovedService('INV_BANK', ['domain_action' => 'profile.bank_account', 'approval_required' => true, 'workflow_key' => 'inv_approval', 'confidentiality' => 'sensitive']);
    $ticket = app(ServiceRequests::class)->submit($service, $employee, $employee->user, ['account_holder_name' => 'A', 'bank_name' => 'B', 'account_number' => '123456789012']);
    expect(fn () => app(DomainActionExecutor::class)->execute($ticket, sdAgent(['employee.sensitive.update'])))->toThrow(ServiceDeskRuleViolation::class, 'not ready');

    $preparer = tenantUser($tenant, ['servicedesk.manage']);
    $catalogue = app(ServiceCatalogue::class);
    $leave = $catalogue->create(['code' => 'INV_LEAVE', 'name' => 'Leave', 'domain_action' => 'leave.request', 'approval_required' => true, 'workflow_key' => 'inv_approval'], $preparer);
    expect(fn () => $catalogue->submit($leave->versions()->first(), $preparer))->toThrow(ServiceDeskRuleViolation::class, 'own domain');
});

it('14 · opens restricted cases only through explicit access (no "HR sees everything")', function () {
    $src = serviceDeskSource(app_path('Domain/ServiceDesk/Services/CaseAccess.php'));
    expect($src)->toContain("'servicedesk.confidential'")->toContain('TicketAccessGrant')
        ->and(preg_match('/is_platform_admin\)\s*{\s*return true/', $src))->toBe(0);
    $grievances = serviceDeskSource(app_path('Domain/Grievance/Services/Grievances.php'));
    expect(preg_match('/if \(\$user->is_platform_admin\)\s*{\s*return true;/', $grievances))->toBe(0);
});

it('15 · keeps internal and restricted notes out of the employee API', function () {
    $api = serviceDeskSource(app_path('Http/Controllers/Api/V1/ServiceDeskController.php'));
    expect($api)->toContain("where('visibility', 'employee')")->not->toContain('attachment_path\' =>')
        ->and(preg_match('/Route::(post|put|patch|delete)\([^)]*service-desk/', file_get_contents(base_path('routes/api.php'))))->toBe(0);
});

it('16–17 · runs the SLA processor tenant-bound, unique per tenant, without overlap, on one server', function () {
    expect(new ProcessServiceDesk)->toBeInstanceOf(TenantAwareJob::class)
        ->and(collect((new ProcessServiceDesk)->middleware())->contains(fn ($m) => $m instanceof BindTenantContext))->toBeTrue()
        ->and(file_get_contents(base_path('routes/console.php')))->toContain("Schedule::command('peopleos:service-desk:process')->hourly()->withoutOverlapping()->onOneServer()");
    $processor = serviceDeskSource(app_path('Domain/ServiceDesk/Services/ServiceDeskProcessor.php'));
    expect(substr_count($processor, 'chunkById('))->toBeGreaterThanOrEqual(5)->and($processor)->not->toContain('Ticket::all(');
});

it('18 · prevents duplicate requests with an idempotency key and a unique index', function () {
    $indexes = collect(Schema::getIndexes('tickets'));
    expect($indexes->firstWhere('name', 'tickets_idempotency_unique'))->toMatchArray(['unique' => true, 'columns' => ['tenant_id', 'employee_id', 'idempotency_key']]);
});

it('19 · keeps attachments private: private disk, tenant and request scoped paths, signed and re-authorised downloads', function () {
    $attachments = serviceDeskSource(app_path('Domain/ServiceDesk/Services/CaseAttachments.php'));
    expect($attachments)->toContain('temporarySignedRoute')->toContain('tenants/{$ticket->tenant_id}/servicedesk/{$ticket->id}')->not->toContain('->url(');
    $controller = serviceDeskSource(app_path('Http/Controllers/TicketAttachmentController.php'));
    expect($controller)->toContain('hasValidSignature')->toContain("Gate::authorize('view'")->toContain('commentVisibilities');
});

it('20 · keeps the audit trail append-only', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $this->actingAs(tenantUser($tenant, ['*']));
    $employee = activeEmployee(null, ['servicedesk.request']);
    app(ServiceRequests::class)->submit(sdApprovedService('AUDIT_Q'), $employee, $employee->user);
    $event = AuditEvent::query()->where('action', 'REQUEST_CREATED')->firstOrFail();
    expect(fn () => $event->update(['reason' => 'x']))->toThrow(ImmutableAuditRecordException::class)
        ->and(fn () => $event->delete())->toThrow(ImmutableAuditRecordException::class);
});

it('P1, P3, P5 · Service Desk never writes profile tables, uses the People / Employment actions, and keeps no copy of profile data', function () {
    $profile = ['EmployeeBankAccount', 'EmployeeStatutoryDetail', 'PersonAddress', 'PersonEmergencyContact', 'PersonFamilyMember'];
    $all = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $all[Str::after($file->getPathname(), base_path().'/')] = serviceDeskSource($file->getPathname());
        }
    }
    $writers = directWrites($profile, $all);
    expect(collect($writers)->reject(fn ($p) => Str::startsWith($p, ['app/Domain/Employment/Actions/Change', 'app/Domain/People/Actions/Change']))->values()->all())->toBe([]);
    $relationWrites = array_keys(array_filter($all, fn ($src) => preg_match('/->(bankAccounts|statutoryDetail|addresses|emergencyContacts|familyMembers)\(\)->(create|update|delete|updateOrCreate|createMany|save)\(/', $src) === 1));
    expect($relationWrites)->toBe([]);

    $tables = collect(Schema::getTableListing())->map(fn ($t) => Str::afterLast($t, '.'));
    expect($tables->filter(fn ($t) => preg_match('/^(service_desk|ticket|service)_.*(bank|address|statutory|pan|uan|family|emergency|salary)/', $t) === 1)->values()->all())->toBe([]);
});

it('P2 · Employee 360 changes profile data only through the canonical actions', function () {
    $map = ['BankAccountsRelationManager' => 'ChangeBankAccountAction', 'AddressesRelationManager' => 'ChangeAddressAction', 'EmergencyContactsRelationManager' => 'ChangeEmergencyContactAction', 'FamilyMembersRelationManager' => 'ChangeFamilyMemberAction'];
    foreach ($map as $manager => $action) {
        expect(serviceDeskSource(app_path("Filament/Resources/Employees/RelationManagers/{$manager}.php")))->toContain($action);
    }
    $view = serviceDeskSource(app_path('Filament/Resources/Employees/Pages/ViewEmployee.php'));
    expect($view)->toContain('ChangeStatutoryIdentityAction')->toContain('ChangeStatutoryApplicabilityAction')->toContain('ChangeManagerAction::class)->change(');
});

it('P4 · exposes no profile write API: profile data is changed only in PeopleOS through the actions', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    expect(preg_match('/Route::(post|put|patch)\([^)]*(bank|address|statutory|emergency|family|update-profile|profile)/i', $routes))->toBe(0);
});

it('P15–P18 · leaves Compensation, Payroll, Attendance and Leave ownership untouched', function () {
    $files = serviceDeskFiles();
    expect(array_keys(array_filter($files, fn ($src) => preg_match('/EmployeeSalaryAssignment|AssignmentWriter|PayrollRun|Payslip|PayrollEntry|AttendanceRecord|AttendancePunch|LeaveLedgerEntry|LeaveBalance\b/', $src) === 1)))->toBe([]);
    // The compensation write guard still holds: nothing but AssignmentWriter writes salary rows.
    expect(serviceDeskSource(app_path('Domain/Compensation/Models/EmployeeSalaryAssignment.php')))->toContain('AssignmentWriter::isWriting()');
});

it('P19 · keeps the profile models People / Employment owned (no Service Desk relation or table)', function () {
    foreach ([EmployeeBankAccount::class, EmployeeStatutoryDetail::class, PersonAddress::class, PersonEmergencyContact::class, PersonFamilyMember::class] as $model) {
        expect(Str::startsWith($model, ['App\\Domain\\Employment\\', 'App\\Domain\\People\\']))->toBeTrue();
    }
    expect(collect(Schema::getColumnListing('tickets'))->intersect(['account_number', 'pan', 'uan', 'esic_number', 'address_line_1'])->all())->toBe([])
        ->and((new Ticket)->getCasts()['form_data'])->toBe('encrypted:array')
        ->and(method_exists(CaseAccess::class, 'apiFormData'))->toBeTrue();
});
