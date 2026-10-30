<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Jobs\RunBulkOperation;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\SavedViews;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\BulkRuns\Pages\ListBulkRuns;
use App\Filament\Resources\BulkRuns\Pages\ViewBulkRun;
use App\Filament\Resources\BulkRuns\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ConfirmationReviews\Pages\ListConfirmationReviews;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\ExitCases\Pages\ListExitCases;
use App\Filament\Resources\Reports\ReportResource;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| HROne parity WO-1 (matrix 23 §23.1 #1–2, #9–14 and the bulk rows of §23.2.2, §23.3.2, §23.3.3, §23.4, §23.6.3, §23.6.4):
| the workforce desk, saved views, the per-register report link and the bulk-action framework (selected rows or all
| matching the filters, typed-count confirmation, queued run with a per-record result).
*/

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
});

function wo12Hire(string $first, Location $location, array $employment = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => '2025-01-01'] + $employment, ['company_id' => $location->company_id, 'location_id' => $location->id]);
}

it('initiates exits for the selected employees, one by one through Exits, under one audit operation', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);

    $run = app(BulkRuns::class)->start($this->hr, 'employees', 'employees.initiate_exit', [$asha->id, $ravi->id], ['type' => 'resignation', 'reason' => 'Restructuring']);

    expect($run->status)->toBe('completed')->and($run->done)->toBe(2)->and($run->refused)->toBe(0)
        ->and($asha->refresh()->lifecycle_state)->toBe(LifecycleState::NoticePeriod)
        ->and(ExitCase::query()->whereIn('employee_id', [$asha->id, $ravi->id])->count())->toBe(2)
        ->and(BulkRunItem::query()->where('bulk_run_id', $run->id)->pluck('outcome')->unique()->all())->toBe(['done']);

    // Every audit event of the run shares the run's operation id, and a BULK_OPERATION summary closes it.
    $summary = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::BulkOperation->value)->where('operation_id', $run->operation_id)->sole();
    expect($summary->metadata['success_count'])->toBe(2)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('operation_id', $run->operation_id)->where('entity_type', ExitCase::class)->count())->toBeGreaterThanOrEqual(2);
});

it('reports the records the owning service refuses and still does the others', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);
    app(Exits::class)->initiate($ravi, 'resignation', 'Already leaving');

    $run = app(BulkRuns::class)->start($this->hr, 'employees', 'employees.initiate_exit', [$asha->id, $ravi->id, 999999], ['type' => 'resignation']);

    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->done)->toBe(1)->and($run->refused)->toBe(2)
        ->and($items[$asha->id]->outcome)->toBe('done')
        ->and($items[$ravi->id]->outcome)->toBe('refused')
        ->and($items[$ravi->id]->message)->toContain('An exit is already in progress')
        ->and($items[999999]->message)->toContain('Not in your access')
        ->and(ExitCase::query()->where('employee_id', $ravi->id)->count())->toBe(1);
});

it('acts only on records inside the person\'s access scope', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $vikram = wo12Hire('Vikram', $this->mumbai);
    $delhiHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]]);
    $this->actingAs($delhiHr);

    $run = app(BulkRuns::class)->start($delhiHr, 'employees', 'employees.initiate_exit', [$asha->id, $vikram->id], ['type' => 'resignation']);

    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($items[$asha->id]->outcome)->toBe('done')
        ->and($items[$vikram->id]->outcome)->toBe('refused')
        ->and($vikram->refresh()->lifecycle_state)->not->toBe(LifecycleState::NoticePeriod);
});

it('asks for the typed number of records above 25 and refuses runs without the permissions', function () {
    $ids = range(1001, 1026);
    $runs = app(BulkRuns::class);

    expect(fn () => $runs->start($this->hr, 'employees', 'employees.initiate_exit', $ids, ['type' => 'resignation']))->toThrow(RuntimeException::class, 'Type the number of records (26)')
        ->and(fn () => $runs->start($this->hr, 'employees', 'employees.initiate_exit', $ids, ['type' => 'resignation'], confirmedCount: 25))->toThrow(RuntimeException::class, 'Type the number of records');

    $run = $runs->start($this->hr, 'employees', 'employees.initiate_exit', $ids, ['type' => 'resignation'], confirmedCount: 26);
    expect($run->total)->toBe(26)->and($run->refused)->toBe(26);

    // 25 records need no typed count.
    expect($runs->start($this->hr, 'employees', 'employees.initiate_exit', array_slice($ids, 0, 25), ['type' => 'resignation'])->total)->toBe(25);

    config(['peopleos.bulk.max_records' => 10]);
    expect(fn () => $runs->start($this->hr, 'employees', 'employees.initiate_exit', array_slice($ids, 0, 11), ['type' => 'resignation']))->toThrow(RuntimeException::class, 'at most 10 records');

    $noBulk = tenantUser($this->tenant, ['employee.view', 'exit.manage']);
    $noExit = tenantUser($this->tenant, ['employee.view', 'bulk.run']);
    expect(fn () => $runs->start($noBulk, 'employees', 'employees.initiate_exit', [1001], ['type' => 'resignation']))->toThrow(RuntimeException::class, 'cannot run "Initiate exit" in bulk')
        ->and(fn () => $runs->start($noExit, 'employees', 'employees.initiate_exit', [1001], ['type' => 'resignation']))->toThrow(RuntimeException::class, 'cannot run')
        ->and(fn () => $runs->start($this->hr, 'employees', 'employees.initiate_exit', [1001], ['type' => 'nope']))->toThrow(RuntimeException::class, 'Choose an exit type');
});

it('runs the selected rows from the register and asks for the count in the form above 25', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);
    $template = OnboardingTemplate::create(['name' => 'Everyone', 'key' => 'everyone', 'priority' => 100]);
    $template->items()->create(['phase' => 'day_one', 'type' => 'task', 'title' => 'Issue laptop', 'owner_type' => 'manager', 'sort_order' => 1]);

    Livewire::test(ListEmployees::class)
        ->callTableBulkAction('bulk_employees_start_onboarding', [$asha, $ravi], ['template_id' => $template->id])
        ->assertHasNoTableBulkActionErrors();

    $run = BulkRun::query()->latest('id')->firstOrFail();
    expect($run->selection)->toBe('selected')->and($run->done)->toBe(2)
        ->and(OnboardingPlan::query()->whereIn('employee_id', [$asha->id, $ravi->id])->where('status', 'in_progress')->count())->toBe(2);

    // Over the threshold the typed count is required and must match.
    config(['peopleos.bulk.confirm_over' => 1]);
    Livewire::test(ListEmployees::class)
        ->callTableBulkAction('bulk_employees_initiate_exit', [$asha, $ravi], ['type' => 'resignation', 'resignation_date' => '2026-10-20', 'confirm_count' => '3'])
        ->assertHasTableBulkActionErrors(['confirm_count']);
    expect(ExitCase::query()->count())->toBe(0);
});

it('acts on every record matching the filters, and never on an unfiltered register', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);
    $vikram = wo12Hire('Vikram', $this->mumbai);

    Livewire::test(ListEmployees::class)
        ->callAction('all_employees_initiate_exit', ['type' => 'resignation', 'resignation_date' => '2026-10-20']);
    expect(BulkRun::query()->count())->toBe(0);

    Livewire::test(ListEmployees::class)
        ->filterTable('location', $this->delhi->id)
        ->callAction('all_employees_initiate_exit', ['type' => 'resignation', 'resignation_date' => '2026-10-20'])
        ->assertHasNoActionErrors();

    $run = BulkRun::query()->sole();
    expect($run->selection)->toBe('all_matching')->and($run->record_ids)->toEqualCanonicalizing([$asha->id, $ravi->id])
        ->and($vikram->refresh()->lifecycle_state)->toBe(LifecycleState::Probation);
});

it('confirms pending reviews in bulk through ConfirmationReviews, refusing one\'s own', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $own = wo12Hire('Own', $this->delhi, ['user_id' => $this->hr->id]);
    $reviews = collect([$asha, $own])->map(fn (Employee $e) => ConfirmationReview::create(['employee_id' => $e->id, 'probation_end_date' => '2026-10-15', 'due_on' => '2026-10-05', 'status' => 'pending', 'extension_number' => 0]));

    Livewire::test(ListConfirmationReviews::class)
        ->callTableBulkAction('bulk_confirmation_reviews_confirm', $reviews, ['note' => 'Good work']);

    $run = BulkRun::query()->sole();
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($items[$reviews[0]->id]->outcome)->toBe('done')
        ->and($asha->refresh()->lifecycle_state)->toBe(LifecycleState::Confirmed)
        ->and($items[$reviews[1]->id]->outcome)->toBe('refused')
        ->and($items[$reviews[1]->id]->message)->toContain('your own confirmation');
});

it('starts clearance and completes exits in bulk, refusing exits that are not ready', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);
    $first = app(Exits::class)->initiate($asha, 'resignation', 'Moving');
    $second = app(Exits::class)->initiate($ravi, 'resignation', 'Moving');
    app(Exits::class)->startClearance($second);

    $run = app(BulkRuns::class)->start($this->hr, 'exit_cases', 'exit_cases.start_clearance', [$first->id, $second->id]);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($items[$first->id]->outcome)->toBe('done')->and($first->refresh()->status)->toBe('clearance')
        ->and($items[$second->id]->outcome)->toBe('refused')->and($items[$second->id]->message)->toContain('only on an exit serving its notice');

    $complete = app(BulkRuns::class)->start($this->hr, 'exit_cases', 'exit_cases.complete', [$first->id], ['skip_settlement' => true]);
    expect($complete->refused)->toBe(1)
        ->and(BulkRunItem::query()->where('bulk_run_id', $complete->id)->value('message'))->toContain('clearance stages must be cleared')
        ->and($first->refresh()->status)->toBe('clearance');

    Livewire::test(ListExitCases::class)->assertTableBulkActionExists('bulk_exit_cases_start_clearance')->assertTableBulkActionExists('bulk_exit_cases_complete');
});

it('initiates background checks only with each employee\'s own consent document', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);
    $consent = DocumentType::create(['name' => 'BGV consent', 'code' => 'BGVC', 'category' => 'other', 'status' => 'active']);
    EmployeeDocument::create(['employee_id' => $asha->id, 'document_type_id' => $consent->id, 'title' => 'Consent', 'disk' => 'local', 'path' => 'x/consent.pdf', 'original_name' => 'consent.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'version' => 1, 'status' => 'verified']);

    $run = app(BulkRuns::class)->start($this->hr, 'employees', 'employees.initiate_bgv', [$asha->id, $ravi->id], ['provider' => 'manual', 'checks' => ['identity'], 'consent_document_type_id' => $consent->id]);

    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($items[$asha->id]->outcome)->toBe('done')
        ->and(BgvCase::query()->where('employee_id', $asha->id)->value('consent_document_id'))->not->toBeNull()
        ->and($items[$ravi->id]->outcome)->toBe('refused')->and($items[$ravi->id]->message)->toContain('No BGV consent document on file')
        ->and(BgvCase::query()->where('employee_id', $ravi->id)->exists())->toBeFalse();
});

it('e-mails employees in bulk, refusing those without an address and keeping the body out of the audit trail', function () {
    $asha = wo12Hire('Asha', $this->delhi, ['work_email' => 'asha@acme.test']);
    $ravi = wo12Hire('Ravi', $this->delhi);

    $run = app(BulkRuns::class)->start($this->hr, 'employees', 'employees.send_email', [$asha->id, $ravi->id], ['subject' => 'Town hall', 'body' => 'Secret agenda', 'to' => ['work']]);

    expect($run->done)->toBe(1)->and($run->refused)->toBe(1)
        ->and(NotificationDelivery::query()->where('recipient', 'asha@acme.test')->where('event', 'employee.emailed')->exists())->toBeTrue();
    $audit = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::EmployeeEmailed->value)->where('entity_id', (string) $asha->id)->sole();
    expect($audit->metadata['subject'])->toBe('Town hall')->and(json_encode($audit->metadata))->not->toContain('Secret agenda')
        ->and(BulkRun::query()->find($run->id)->auditEvents()->with('fieldChanges')->get()->flatMap->fieldChanges->pluck('field'))->not->toContain('input');
});

it('lists a person\'s own runs and every run only with bulk.view_all', function () {
    $mine = app(BulkRuns::class)->start($this->hr, 'employees', 'employees.initiate_exit', [5555], ['type' => 'resignation']);
    $other = tenantUser($this->tenant, ['employee.view', 'bulk.run', 'exit.manage']);
    $auditor = tenantUser($this->tenant, ['bulk.view_all']);

    $this->actingAs($other);
    Livewire::test(ListBulkRuns::class)->assertCanNotSeeTableRecords([$mine]);
    expect($other->can('view', $mine))->toBeFalse();
    // Someone else's run does not exist for them (the list is narrowed to their own runs).
    $this->get(BulkRunResource::getUrl('view', ['record' => $mine]))->assertNotFound();

    $this->actingAs($auditor);
    Livewire::test(ListBulkRuns::class)->assertCanSeeTableRecords([$mine]);
    $this->get(BulkRunResource::getUrl('view', ['record' => $mine]))->assertOk()->assertSee('Initiate exit');
    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $mine, 'pageClass' => ViewBulkRun::class])->assertSee('Not in your access');
});

it('stops a run whose starter lost the permission, and retries skip the records already reached', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);
    $run = BulkRun::create(['user_id' => $this->hr->id, 'register' => 'employees', 'operation' => 'employees.initiate_exit', 'operation_label' => 'Initiate exit', 'selection' => 'selected',
        'record_ids' => [$asha->id, $ravi->id], 'input' => ['type' => 'resignation', 'resignation_date' => '2026-10-20'], 'total' => 2, 'status' => 'running']);
    BulkRunItem::create(['bulk_run_id' => $run->id, 'record_id' => $asha->id, 'outcome' => 'done', 'message' => 'earlier attempt']);

    app(BulkRuns::class)->execute($run);
    expect($run->refresh()->done)->toBe(2)->and(ExitCase::query()->where('employee_id', $asha->id)->exists())->toBeFalse()
        ->and(ExitCase::query()->where('employee_id', $ravi->id)->exists())->toBeTrue();

    $limited = tenantUser($this->tenant, ['employee.view', 'exit.manage']);
    $stale = BulkRun::create(['user_id' => $limited->id, 'register' => 'employees', 'operation' => 'employees.initiate_exit', 'operation_label' => 'Initiate exit', 'selection' => 'selected',
        'record_ids' => [$asha->id], 'input' => ['type' => 'resignation'], 'total' => 1, 'status' => 'queued']);
    app(BulkRuns::class)->execute($stale);
    expect($stale->refresh()->status)->toBe('failed')->and($stale->error)->toContain('no longer holds the permission');

    $queued = BulkRun::create(['user_id' => $this->hr->id, 'register' => 'employees', 'operation' => 'employees.initiate_exit', 'operation_label' => 'Initiate exit', 'selection' => 'selected',
        'record_ids' => [$asha->id], 'input' => ['type' => 'resignation'], 'total' => 1, 'status' => 'queued']);
    (new RunBulkOperation($queued->id))->failed(new RuntimeException('worker lost'));
    expect($queued->refresh()->status)->toBe('failed')->and($queued->error)->toContain('worker lost');

    $job = new RunBulkOperation($run->id);
    expect($job)->toBeInstanceOf(TenantAwareJob::class)->and($job->tenantId())->toBe($this->tenant->id)
        ->and($job->tries)->toBe(3)->and($job->timeout)->toBeGreaterThan(0)->and($job->backoff)->not->toBeEmpty()
        ->and($job->middleware()[0])->toBeInstanceOf(BindTenantContext::class);
});

it('keeps saved views per person and per tenant, applies them and opens a register with its default view', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $vikram = wo12Hire('Vikram', $this->mumbai);
    // Preferences are memoised per request: read them through a fresh service after each Livewire request.
    $views = function () {
        app()->forgetInstance(ExperiencePreferences::class);

        return app(SavedViews::class);
    };

    Livewire::test(ListEmployees::class)
        ->filterTable('location', $this->mumbai->id)
        ->callAction('posSaveView', ['name' => 'Mumbai team', 'is_default' => true])
        ->assertHasNoActionErrors();
    $saved = $views()->for($this->hr, 'employees');
    expect($saved)->toHaveCount(1)->and($saved[0]['name'])->toBe('Mumbai team')->and($saved[0]['filters']['location']['value'])->toBe($this->mumbai->id);

    // The default view opens the register afresh; another saved view can be applied from the header.
    session()->flush();
    Livewire::test(ListEmployees::class)->assertCanSeeTableRecords([$vikram])->assertCanNotSeeTableRecords([$asha]);
    $views()->save($this->hr, 'employees', 'Everyone', ['filters' => []]);
    $everyone = collect($views()->for($this->hr, 'employees'))->firstWhere('name', 'Everyone');
    Livewire::test(ListEmployees::class)->callAction('posView_'.$everyone['id'])->assertCanSeeTableRecords([$asha, $vikram]);

    // Another person, and the same person's preferences in another tenant, see none of these views.
    $colleague = tenantUser($this->tenant, ['*']);
    expect($views()->for($colleague, 'employees'))->toBe([]);
    $this->actingAs($colleague);
    session()->flush();
    Livewire::test(ListEmployees::class)->assertCanSeeTableRecords([$asha, $vikram]);

    $other = provisionTenant('Other');
    actAsTenant($other);
    app()->forgetInstance(ExperiencePreferences::class);
    $outsider = tenantUser($other, ['*']);
    expect(app(SavedViews::class)->for($outsider, 'employees'))->toBe([]);
    actAsTenant($this->tenant);

    // Saving under the same name replaces; the cap is per register.
    config(['peopleos.bulk.saved_views_per_register' => 2]);
    $views()->save($this->hr, 'employees', 'everyone', ['search' => 'a']);
    expect($views()->for($this->hr, 'employees'))->toHaveCount(2)
        ->and(fn () => $views()->save($this->hr, 'employees', 'Third', []))->toThrow(RuntimeException::class, 'at most 2 views');
});

it('opens a register on a preset view named in the url', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $ravi = wo12Hire('Ravi', $this->delhi);
    app(Exits::class)->initiate($ravi, 'resignation', 'Moving');

    Livewire::withQueryParams(['view' => 'notice'])->test(ListEmployees::class)
        ->assertCanSeeTableRecords([$ravi])->assertCanNotSeeTableRecords([$asha]);
    $this->get(EmployeeResource::getUrl('index', ['view' => 'notice']))->assertOk();
    $this->get(ExitCaseResource::getUrl('index', ['view' => 'resignations']))->assertOk();
});

it('creates the register\'s report from the library with the register\'s filters', function () {
    wo12Hire('Asha', $this->delhi);

    Livewire::test(ListEmployees::class)
        ->filterTable('lifecycle_state', ['probation'])
        ->filterTable('location', $this->delhi->id)
        ->callAction('registerReport', ['template' => 'employee_master', 'with_filters' => true])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $report = Report::query()->latest('id')->firstOrFail();
    $filters = collect($report->definition['filters']);
    expect($report->dataset)->toBe('employees')
        ->and($filters->firstWhere('field', 'location'))->toMatchArray(['operator' => 'in', 'value' => 'Delhi'])
        ->and($filters->where('field', 'lifecycle_state')->pluck('value'))->toContain('probation');
    $this->get(ReportResource::getUrl('view', ['record' => $report]))->assertOk();
});

it('shows the workforce desk to HR operators only, with links to the existing registers', function () {
    $this->get(WorkforceDesk::getUrl())->assertOk()
        ->assertSee('Confirmations')->assertSee('Separation')
        ->assertSee(ExitCaseResource::getUrl('index', ['view' => 'resignations']), false);

    $manager = tenantUser($this->tenant, ['employee.view', 'onboarding.view', 'exit.clear']);
    $this->actingAs($manager);
    expect(WorkforceDesk::canAccess())->toBeFalse();
    $this->get(WorkforceDesk::getUrl())->assertForbidden();
});

it('initiates one exit from the Employee 360 with the same inputs as the bulk action', function () {
    $asha = wo12Hire('Asha', $this->delhi);

    Livewire::test(ViewEmployee::class, ['record' => $asha->getRouteKey()])
        ->callAction('initiateExit', ['type' => 'resignation', 'resignation_date' => '2026-10-20', 'reason' => 'Moving'])
        ->assertHasNoActionErrors();

    expect(ExitCase::query()->where('employee_id', $asha->id)->sole()->type)->toBe('resignation');
    Livewire::test(ViewEmployee::class, ['record' => $asha->getRouteKey()])->assertActionHidden('initiateExit');
});

it('keeps the register as it was for people without the new permissions', function () {
    $asha = wo12Hire('Asha', $this->delhi);
    $viewer = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($viewer);

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$asha])
        ->assertActionHidden('all_employees_initiate_exit')
        ->assertTableBulkActionHidden('bulk_employees_initiate_exit')
        ->assertActionHidden('registerReport');
    expect(app(SavedViews::class)->for($viewer, 'employees'))->toBe([]);
});
