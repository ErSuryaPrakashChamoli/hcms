<?php

use App\Domain\Attendance\Imports\GeoLocationImports;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportDownloads;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Identity\Models\Role;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\RecentUploads;
use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Resources\BankAccountImports\Pages\ViewBankAccountImport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne setup parity P2 (Data import → Import approval, Recent imports): who approves an upload (the uploader as before,
| another person, or a workflow), per upload type; the Recent uploads page across modules; audited file downloads.
*/

beforeEach(function () {
    $this->travelTo('2026-10-20 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $perms = ['employee.view', 'employee.import', 'employee.sensitive.view', 'employee.sensitive.update', 'attendance.manage', 'task.view', 'task.act'];
    $this->maker = tenantUser($this->tenant, $perms);
    $this->checker = tenantUser($this->tenant, $perms);
    $this->actingAs($this->maker);
    $this->geo = app(GeoLocationImports::class);
});

/** A validated geo location upload by the signed-in person. */
function igovGeo(string $csv = "Name,Latitude,Longitude,Radius (m)\nHQ,28.6139,77.2090,150\n"): EmployeeImport
{
    $service = app(GeoLocationImports::class);
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = $service->inspect($service->register($path, 'geo.csv', auth()->user()));

    return $service->validate($service->map($import, $import->mapping));
}

function igovSet(array $settings): void
{
    foreach ($settings as $key => $value) {
        app(SettingsRepository::class)->set($key, $value, 'Import approval');
    }
}

it('keeps today\'s approval by default: the person working on the upload approves and runs it', function () {
    expect(config('peopleos.settings')['imports.approval_mode'])->toBe('self')
        ->and(config('peopleos.settings')['imports.approval_types'])->toBe([])
        ->and(ImportGovernance::mode(GeoLocationImports::TYPE))->toBe('self');

    $import = igovGeo();
    expect($import->source)->toBe('ui')->and(ImportGovernance::mayApproveNow($import, $this->maker))->toBeTrue()->and(ImportGovernance::waitingFor($import, $this->maker))->toBeNull();
    $import = $this->geo->run($this->geo->approve($import, $this->maker, 'Go-live'), $this->maker);

    expect($import->status)->toBe('imported')->and($import->approved_by)->toBe($this->maker->id)
        ->and($import->run_started_at)->not->toBeNull()->and($import->run_started_at->lte($import->imported_at))->toBeTrue()
        ->and(GeoFence::query()->count())->toBe(1);
});

it('needs another person under maker-checker, for the listed upload types only, and tells the uploader', function () {
    igovSet(['imports.approval_mode' => 'other_person']);
    $import = igovGeo();
    expect(ImportGovernance::waitingFor($import, $this->maker))->toContain('another person')
        ->and(ImportGovernance::mayApproveNow($import, $this->maker))->toBeFalse()
        ->and(ImportGovernance::mayApproveNow($import, $this->checker))->toBeTrue()
        ->and(fn () => $this->geo->approve($import, $this->maker, 'Mine'))->toThrow(RuntimeException::class, 'uploader cannot approve');
    expect($import->fresh()->status)->toBe('validated');

    $approved = $this->geo->approve($import->fresh(), $this->checker, 'Checked');
    expect($approved->approved_by)->toBe($this->checker->id)
        ->and(NotificationDelivery::query()->where('user_id', $this->maker->id)->where('event', 'import.approved')->exists())->toBeTrue();
    // The uploader runs it once approved.
    expect($this->geo->run($approved, $this->maker)->status)->toBe('imported');

    // Only the listed types follow the rule; the others stay with their uploader.
    igovSet(['imports.approval_types' => [BankAccountImports::TYPE]]);
    expect(ImportGovernance::mode(GeoLocationImports::TYPE))->toBe('self')->and(ImportGovernance::mode(BankAccountImports::TYPE))->toBe('other_person');
    $own = igovGeo("Name,Latitude,Longitude\nPlant,19.07,72.87\n");
    expect($this->geo->approve($own, $this->maker, 'Mine')->status)->toBe('approved');
});

it('narrows the checker to a role when one is set', function () {
    $role = Role::factory()->create(['name' => 'Data checker', 'slug' => 'data-checker']);
    igovSet(['imports.approval_mode' => 'other_person', 'imports.approver_role' => 'data-checker']);
    $import = igovGeo();

    expect(fn () => $this->geo->approve($import, $this->checker, 'No role'))->toThrow(RuntimeException::class, 'Data checker');
    $this->checker->roles()->attach($role);
    expect($this->geo->approve($import->fresh(), $this->checker->fresh(), 'With role')->status)->toBe('approved');
});

it('lets the checker of a bank details upload see and approve it, never before validation, and the uploader runs it', function () {
    $company = Company::factory()->create();
    $employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Test'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $company->id]);
    $bank = app(BankAccountImports::class);
    $path = EmployeeImports::directory().'/bank.csv';
    Storage::disk('local')->put($path, "Employee code,Account holder name,Bank name,Account number,IFSC\nEMP1,Asha Test,HDFC Bank,000111222333,HDFC0001234\n");
    $staged = $bank->inspect($bank->register($path, 'bank.csv', $this->maker, mode: ImportMode::Both));
    $visible = fn ($user) => BankAccountImportResource::getEloquentQuery()->whereKey($staged->id)->exists();

    // Self (default): only the uploader, as before.
    $this->actingAs($this->checker);
    expect($visible($this->checker))->toBeFalse()->and($this->checker->can('view', $staged))->toBeFalse();

    igovSet(['imports.approval_mode' => 'other_person']);
    expect($visible($this->checker))->toBeFalse(); // still being prepared
    $this->actingAs($this->maker);
    $validated = $bank->validate($bank->map($staged, $staged->mapping));
    $this->actingAs($this->checker);
    expect($visible($this->checker))->toBeTrue()->and($this->checker->can('view', $validated))->toBeTrue();
    $outsider = tenantUser($this->tenant, ['employee.import', 'attendance.manage']);
    expect($outsider->can('view', $validated))->toBeFalse();

    // On the upload screen the checker approves or discards; the uploader sees what approval waits for.
    Livewire::actingAs($this->checker)->test(ViewBankAccountImport::class, ['record' => $validated->id])->assertOk()
        ->assertActionVisible('approve')->assertActionHidden('map')->assertActionHidden('validate')->assertActionVisible('downloadResults');
    $this->actingAs($this->maker);
    Livewire::actingAs($this->maker)->test(ViewBankAccountImport::class, ['record' => $validated->id])->assertOk()
        ->assertActionHidden('approve')->assertActionVisible('awaitingApproval')->assertActionVisible('map');

    expect(fn () => $bank->approve($validated, $this->maker, 'Mine'))->toThrow(RuntimeException::class, 'uploader cannot approve');
    $approved = $bank->approve($validated->fresh(), $this->checker, 'Checked against the forms');
    expect(fn () => $bank->run($approved, $this->checker))->toThrow(RuntimeException::class, 'Only the person who uploaded');
    $done = $bank->run($approved, $this->maker);
    expect($done->status)->toBe('imported')->and($employee->bankAccounts()->count())->toBe(1);
});

it('routes an upload through a workflow on "Data upload validated", and falls back to maker-checker without one', function () {
    igovSet(['imports.approval_mode' => 'workflow', 'imports.approval_types' => [GeoLocationImports::TYPE]]);
    // No workflow listens yet: another person approves.
    $plain = igovGeo();
    expect(ImportGovernance::openWorkflow($plain))->toBeNull()->and(fn () => $this->geo->approve($plain, $this->maker, 'Mine'))->toThrow(RuntimeException::class, 'uploader cannot approve');

    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Upload approval', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->checker->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Upload approval', 'trigger_event' => 'import.validated', 'subject_type' => EmployeeImport::class]);

    $import = igovGeo();
    $run = ImportGovernance::openWorkflow($import);
    expect($run)->not->toBeNull()->and($run->started_by)->toBe($this->maker->id)
        ->and(ImportGovernance::waitingFor($import, $this->maker))->toContain('workflow')
        ->and(fn () => $this->geo->approve($import, $this->checker, 'By hand'))->toThrow(RuntimeException::class, 'approved through its workflow');

    app(WorkflowEngine::class)->completeTask($run->tasks()->first(), 'approved', 'Looks right', $this->checker);
    $import->refresh();
    expect($import->status)->toBe('approved')->and($import->approved_by)->toBe($this->checker->id)
        ->and(ImportGovernance::workflowStatus($import))->toBe('Completed: approved');

    // Changing the file after validation cancels its run; validating again starts a new one.
    $changed = igovGeo();
    $first = ImportGovernance::openWorkflow($changed);
    $this->geo->map($changed, $changed->mapping);
    expect($first->refresh()->status)->toBe(InstanceStatus::Cancelled);
    $this->geo->validate($changed->refresh());
    expect(ImportGovernance::openWorkflow($changed->refresh())->id)->not->toBe($first->id);

    // A rejected run discards the upload and tells the uploader.
    $rejected = igovGeo();
    app(WorkflowEngine::class)->completeTask(ImportGovernance::openWorkflow($rejected)->tasks()->first(), 'rejected', 'Wrong radius', $this->checker);
    expect($rejected->refresh()->status)->toBe('discarded')
        ->and(NotificationDelivery::query()->where('user_id', $this->maker->id)->where('event', 'import.rejected')->exists())->toBeTrue()
        ->and(WorkflowInstance::query()->where('subject_type', EmployeeImport::class)->count())->toBe(4);
});

it('downloads the file as uploaded and its result file, audited, only for people who may open the upload', function () {
    $import = $this->geo->run($this->geo->approve(igovGeo("Name,Latitude,Longitude\nHQ,28.6139,77.2090\nBad,95,77\n"), $this->maker, 'Go'), $this->maker);
    $downloads = app(ImportDownloads::class);

    [$name, $contents] = $downloads->original($import, $this->maker);
    expect($name)->toBe('geo.csv')->and($contents)->toContain('HQ,28.6139');
    [$resultName, $csv] = $downloads->results($import, $this->maker);
    expect($resultName)->toBe('geo-results.csv')
        ->and($csv)->toContain('Row,Name,Latitude,Longitude,Action,Status,Employee,"Errors / notes",Result')
        ->and($csv)->toContain('imported')->and($csv)->toContain('error');
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Download->value)->where('entity_type', EmployeeImport::class)->where('entity_id', $import->id)->count())->toBe(2);

    $outsider = tenantUser($this->tenant, ['employee.import']);
    expect(fn () => $downloads->original($import, $outsider))->toThrow(AuthorizationException::class);
});

it('lists every upload type across modules on Recent uploads, month by month, as far as the viewer may see', function () {
    $geo = igovGeo();
    $other = EmployeeImport::query()->create(['type' => BankAccountImports::TYPE, 'original_name' => 'bank.csv', 'disk' => 'local', 'path' => 'x.csv', 'status' => 'validated', 'uploaded_by' => $this->checker->id]);
    $old = igovGeo();
    $old->forceFill(['created_at' => now()->subMonths(2)])->save();

    expect(ImportTypes::labels())->toHaveKeys(['employees', 'bank_accounts', 'punches', 'attendance_month', 'salaries', ...array_keys(RowImportTypes::TYPES)])
        ->and(ImportTypes::moduleLabel('salaries'))->toBe('Payroll')->and(ImportTypes::moduleLabel(GeoLocationImports::TYPE))->toBe('Time office')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'multiselect', 'options_from' => 'import_types']))->toBe(ImportTypes::labels());

    Livewire::actingAs($this->maker)->test(RecentUploads::class)->assertOk()
        ->assertCanSeeTableRecords([$geo])->assertCanNotSeeTableRecords([$other, $old])
        ->filterTable('month', now()->subMonths(2)->format('Y-m'))->assertCanSeeTableRecords([$old]);
    expect(RecentUploads::urlFor($geo))->toContain('time-office-uploads');

    // Someone who may not upload anything does not open the page.
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(RecentUploads::getUrl())->assertForbidden();
});

it('lists the import approval settings in Setup → Governance and validates them', function () {
    $admin = tenantUser($this->tenant, ['*']);
    $catalogue = app(SetupCatalogue::class);
    $changed = $catalogue->save('governance', [SetupCatalogue::field('imports.approval_mode') => 'other_person', SetupCatalogue::field('imports.approval_types') => [GeoLocationImports::TYPE]], 'Maker-checker for geo locations', $admin);
    expect($changed)->toEqualCanonicalizing(['imports.approval_mode', 'imports.approval_types'])
        ->and(fn () => $catalogue->save('governance', [SetupCatalogue::field('imports.approval_mode') => 'anyone'], 'x', $admin))->toThrow(RuntimeException::class, 'Choose a value')
        ->and(fn () => $catalogue->save('governance', [SetupCatalogue::field('imports.approval_types') => ['nothing']], 'x', $admin))->toThrow(RuntimeException::class, 'does not exist');
});
