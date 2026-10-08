<?php

use App\Domain\Analytics\Datasets\EmployeesDataset;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationReminderLog;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Services\CompensationAnalytics;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationReminders;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Department;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\CompensationAnalyticsPage;
use App\Filament\Pages\MyCompensation;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\CompensationChangesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* Phase 11.4: field security, manager and self-service visibility, API, analytics privacy, notifications, webhooks, reminders, export. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->actors = compensationActors();
    $this->changes = app(CompensationChanges::class);
    $this->structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $this->manager = activeEmployee(null, ['employee.view', 'compensation.team']);
    $this->employee = salariedEmployee(600000, ['compensation.self', 'task.view'], '2026-04-01', ['CONV' => 1600], $this->manager);
    $this->propose = fn (array $data = []) => $this->changes->propose($this->employee, $data + ['change_type' => 'annual_increment', 'effective_from' => '2026-10-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 660000, 'reason' => 'Confidential reason', 'internal_notes' => 'Never shown to the employee'], $this->actors['proposer']);
});

it('shows employees their own approved compensation only, when the tenant allows it', function () {
    ($this->propose)();   // a draft proposal: never visible to the employee
    $user = $this->employee->user;
    $access = app(CompensationAccess::class);
    $colleague = salariedEmployee(900000, ['compensation.self'], '2026-04-01');

    expect($access->level($user, $this->employee))->toBe('self')
        ->and($access->level($user, $colleague))->toBeNull()
        ->and($access->mayViewChange($user, CompensationChange::query()->where('employee_id', $this->employee->id)->latest('id')->first()))->toBeFalse();

    $this->actingAs($user);
    Livewire::test(MyCompensation::class)->assertOk()->assertSee('600,000.00')->assertDontSee('Confidential reason')->assertDontSee('660,000.00')->assertDontSee('900,000.00');
    expect(CompensationChangesRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'VIEW')->where('entity_id', (string) $this->employee->id)->where('metadata->scope', 'compensation')->exists())->toBeTrue();

    app(SettingsRepository::class)->set('compensation.self_service', false);
    expect(MyCompensation::canAccess())->toBeFalse()->and($access->level($user->fresh(), $this->employee))->toBeNull();
});

it('lets a line manager with compensation.team see approved compensation of reports, never through a mentor relationship', function () {
    $access = app(CompensationAccess::class);
    $mentor = activeEmployee(null, ['employee.view', 'compensation.team']);
    ReportingRelationship::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $mentor->id, 'type' => 'mentor', 'is_primary' => false, 'effective_from' => '2026-01-01']);
    $plainManager = activeEmployee(null, ['employee.view']);
    $other = salariedEmployee(800000, ['task.view'], '2026-04-01', ['CONV' => 1600], $plainManager);

    expect($access->level($this->manager->user, $this->employee))->toBe('team')
        ->and(app(CompensationAccess::class)->level($mentor->user, $this->employee))->toBeNull()
        ->and(app(CompensationAccess::class)->level($plainManager->user, $other))->toBeNull()   // a manager without compensation.team
        ->and($access->level($this->manager->user, $other))->toBeNull();

    $this->actingAs($this->manager->user);
    Livewire::test(CompensationRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertSee('600,000.00')->assertDontSee('Propose compensation change');
    expect(CompensationChangesRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse();
});

it('hides salary without the compensation permission, even from bank/statutory readers and reports', function () {
    $sensitiveHr = tenantUser($this->tenant, ['employee.view', 'employee.sensitive.view', 'analytics.view', 'payroll.view']);
    $dataset = app(EmployeesDataset::class);
    expect(CompensationRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeTrue();   // admin
    $this->actingAs($sensitiveHr);
    expect(CompensationRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse()
        ->and(array_keys($dataset->fieldsFor($sensitiveHr)))->not->toContain('ctc_annual')
        ->and(array_keys($dataset->fieldsFor(tenantUser($this->tenant, ['employee.view', 'employee.sensitive.view', 'compensation.view']))))->toContain('ctc_annual');
});

it('serves read-only compensation APIs: definitions with compensation.read, amounts only with compensation.sensitive, audited, 404 across tenants', function () {
    ($this->propose)();
    $code = $this->employee->employee_code;
    $definitions = app(ApiKeys::class)->issue('defs', ['compensation.read'])['plaintext'];
    $sensitive = app(ApiKeys::class)->issue('pay', ['compensation.read', 'compensation.sensitive'])['plaintext'];
    $tenantB = provisionTenant('B');
    actAsTenant($tenantB);
    $keyB = app(ApiKeys::class)->issue('B', ['compensation.read', 'compensation.sensitive'])['plaintext'];
    actAsTenant(null);
    auth()->logout();

    $this->withHeader('X-Api-Key', $definitions)->getJson('/api/v1/compensation/structures')->assertOk()->assertJsonPath('data.0.code', 'STANDARD')->assertJsonPath('data.0.versions.0.version', 1);
    $this->withHeader('X-Api-Key', $definitions)->getJson("/api/v1/compensation/employees/{$code}")->assertForbidden();
    $this->flushHeaders();
    $this->withHeader('X-Api-Key', $sensitive)->getJson("/api/v1/compensation/employees/{$code}?on=2026-09-30")->assertOk()
        ->assertJsonPath('data.compensation.ctc_annual', 600000)->assertJsonMissingPath('data.compensation.reason');
    $this->withHeader('X-Api-Key', $sensitive)->getJson("/api/v1/compensation/employees/{$code}/history")->assertOk()->assertJsonCount(1, 'data.history');   // the draft is not compensation
    $this->flushHeaders();
    $this->withHeader('X-Api-Key', $keyB)->getJson("/api/v1/compensation/employees/{$code}")->assertNotFound();
    $this->flushHeaders();
    $this->getJson('/api/v1/compensation/structures')->assertUnauthorized();

    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('action', 'VIEW')->where('module', 'compensation')->where('entity_id', (string) $this->employee->id)->count())->toBe(2);
});

it('suppresses analytics below the threshold for the population and for every group, whatever the filter', function () {
    $analytics = app(CompensationAnalytics::class);
    $viewer = tenantUser($this->tenant, ['compensation.analytics']);
    collect(range(1, 3))->each(fn ($i) => salariedEmployee(500000 + $i * 10000, ['task.view'], '2026-04-01'));
    expect($analytics->summary($viewer)['suppressed'])->toBeTrue();   // 4 people

    salariedEmployee(550000, ['task.view'], '2026-04-01');
    $summary = $analytics->summary($viewer);
    expect($summary['suppressed'])->toBeFalse()->and($summary['population'])->toBe(5)
        ->and($summary['totals']['INR']['total_fixed'])->toBe(600000.0 + 510000 + 520000 + 530000 + 550000);

    // A filter that isolates fewer than five people shows nothing; small groups show no amounts.
    $department = Department::factory()->create();
    expect($analytics->summary($viewer, ['department_id' => $department->id])['suppressed'])->toBeTrue()
        ->and(collect($summary['by_grade'])->every(fn ($g) => $g['suppressed'] || $g['people'] >= 5))->toBeTrue()
        ->and(fn () => $analytics->summary(tenantUser($this->tenant, ['employee.view'])))->toThrow(RuntimeException::class, 'compensation.analytics');
});

it('notifies only the people in the approval chain, publishes allow-listed webhooks without amounts and throttles reminders', function () {
    WebhookEndpoint::create(['name' => 'Sink', 'url' => 'https://example.test/hook', 'secret' => 's', 'events' => ['*']]);
    $change = ($this->propose)();
    $this->changes->submit($change, $this->actors['proposer']);

    expect(NotificationDelivery::query()->where('event', 'compensation.change.submitted')->where('user_id', $this->actors['reviewer']->id)->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('event', 'like', 'compensation.%')->where('user_id', $this->employee->user_id)->exists())->toBeFalse();

    $this->changes->review($change, $this->actors['reviewer']);
    $this->changes->approve($change, $this->actors['approver']);
    $events = WebhookDelivery::query()->where('event', 'like', 'compensation.%')->get();
    expect($events->pluck('event')->all())->toBe(['compensation.change.approved'])
        ->and(json_encode($events->first()->payload))->not->toContain('660000')->not->toContain('Confidential');

    // Waiting for the executor for more than three days: one reminder, then nothing the same week.
    $this->travelTo('2026-09-26 09:00:00');
    expect(app(CompensationReminders::class)->tick())->toBe(['changes' => 1, 'cycles' => 0])
        ->and(app(CompensationReminders::class)->tick())->toBe(['changes' => 0, 'cycles' => 0])
        ->and(NotificationDelivery::query()->where('event', 'compensation.reminder.pending_change')->where('user_id', $this->actors['executor']->id)->exists())->toBeTrue()
        ->and(CompensationReminderLog::query()->count())->toBe(1);
});

it('audits the export of individual compensation and refuses it without compensation.export', function () {
    Livewire::test(CompensationAnalyticsPage::class)->assertOk()->callAction('export');
    expect(AuditEvent::query()->where('action', 'EXPORT')->where('module', 'compensation')->exists())->toBeTrue();

    $this->actingAs(tenantUser($this->tenant, ['compensation.analytics']));
    Livewire::test(CompensationAnalyticsPage::class)->assertOk()->assertActionHidden('export');
});
