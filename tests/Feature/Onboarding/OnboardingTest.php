<?php

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Actions\CreatePreEmployeeAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Onboarding\Events\OnboardingCompleted;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->onboarding = app(Onboarding::class);
    $this->company = Company::factory()->create(['code' => 'ACME']);
    $this->sales = Department::factory()->create(['name' => 'Sales', 'code' => 'SALES']);
    $this->hrRole = Role::query()->where('slug', 'hr-executive')->first();
    $this->manager = employeeWithUser();
});

/** Templates are created after the employees under test so auto-start does not pre-empt explicit starts. */
function makeTemplates(): void
{
    $t = test();
    $t->generic = OnboardingTemplate::create(['name' => 'Everyone', 'key' => 'everyone', 'priority' => 100]);
    $t->generic->items()->createMany([
        ['phase' => 'preboarding', 'type' => 'document', 'title' => 'Upload PAN', 'owner_type' => 'employee', 'document_type_id' => DocumentType::query()->where('code', 'PAN')->value('id'), 'sort_order' => 1],
        ['phase' => 'day_one', 'type' => 'task', 'title' => 'Issue laptop', 'owner_type' => 'role', 'owner_role_id' => $t->hrRole->id, 'sort_order' => 2],
        ['phase' => 'day_one', 'type' => 'task', 'title' => 'Team introduction', 'owner_type' => 'manager', 'sort_order' => 3],
        ['phase' => 'day_30', 'type' => 'task', 'title' => '30-day check-in', 'owner_type' => 'manager', 'due_offset_days' => 28, 'is_mandatory' => false, 'sort_order' => 4],
    ]);
    $t->salesTemplate = OnboardingTemplate::create(['name' => 'Sales', 'key' => 'sales', 'priority' => 10, 'conditions' => [['field' => 'department_id', 'operator' => 'equals', 'value' => $t->sales->id]]]);
    $t->salesTemplate->items()->create(['phase' => 'week_one', 'type' => 'task', 'title' => 'CRM training', 'owner_type' => 'user', 'owner_user_id' => $t->hr->id]);
}

it('picks the highest-priority matching template and generates dated, owned tasks', function () {
    $employee = employeeWithUser($this->manager);
    makeTemplates();

    expect($this->onboarding->templateFor($employee)->key)->toBe('everyone');

    $plan = $this->onboarding->start($employee, null, 'Welcome');
    $tasks = $plan->tasks;

    expect($tasks)->toHaveCount(4)
        ->and($tasks[0]->due_on->toDateString())->toBe('2024-12-29')
        ->and($tasks[0]->owner_user_id)->toBe($employee->user_id)
        ->and($tasks[0]->document_type_id)->toBe(DocumentType::query()->where('code', 'PAN')->value('id'))
        ->and($tasks[1]->owner_role_id)->toBe($this->hrRole->id)
        ->and($tasks[1]->due_on->toDateString())->toBe('2025-01-01')
        ->and($tasks[2]->owner_user_id)->toBe($this->manager->user_id)
        ->and($tasks[3]->due_on->toDateString())->toBe('2025-01-29')
        ->and($plan->progress)->toBe(0)
        ->and($employee->timelineEntries()->where('category', 'onboarding')->value('title'))->toBe('Onboarding started (Everyone)')
        ->and(NotificationDelivery::query()->where('event', 'onboarding.task.assigned')->where('user_id', $employee->user_id)->exists())->toBeTrue();

    $salesHire = app(HireEmployeeAction::class)->handle(['first_name' => 'S', 'last_name' => 'X'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id, 'department_id' => $this->sales->id]);
    expect($this->onboarding->templateFor($salesHire)->key)->toBe('sales');
});

it('tracks completion, enforces ownership and auto-completes the plan', function () {
    Event::fake([OnboardingCompleted::class]);
    $employee = employeeWithUser($this->manager);
    makeTemplates();
    $plan = $this->onboarding->start($employee);
    [$pan, $laptop, $intro, $checkin] = $plan->tasks;

    expect(fn () => $this->onboarding->complete($laptop, null, null, $employee->user))->toThrow(RuntimeException::class, 'not assigned');

    $this->onboarding->complete($pan, 'Uploaded', null, $employee->user);
    expect($plan->fresh()->progress)->toBe(25);

    $hrExec = tenantUser($this->tenant, ['onboarding.act']);
    $hrExec->roles()->attach($this->hrRole);
    $this->onboarding->complete($laptop, null, null, $hrExec);
    $this->onboarding->complete($intro, null, null, $this->manager->user);
    expect($plan->fresh()->status)->toBe('in_progress')->and($plan->fresh()->progress)->toBe(75);

    $this->onboarding->skip($checkin, 'Manager on leave', $this->hr);

    $plan->refresh();
    expect($plan->status)->toBe('completed')
        ->and($plan->progress)->toBe(100)
        ->and($employee->timelineEntries()->where('title', 'Onboarding completed')->exists())->toBeTrue();
    Event::assertDispatched(OnboardingCompleted::class);

    expect(fn () => $this->onboarding->complete($pan->fresh(), null, null, $employee->user))->toThrow(RuntimeException::class, 'already closed');
});

it('starts onboarding automatically when a pre-employee enters preboarding', function () {
    makeTemplates();
    $employee = app(CreatePreEmployeeAction::class)->handle([
        'person' => ['first_name' => 'Auto', 'last_name' => 'Start'],
        'offer' => ['external_reference' => 'X1', 'expected_joining_date' => '2027-01-05'],
        'position' => ['company_code' => 'ACME'],
    ]);

    $plan = $employee->onboardingPlan;
    expect($plan)->not->toBeNull()
        ->and($plan->anchor_date->toDateString())->toBe('2027-01-05')
        ->and($plan->tasks()->where('phase', 'preboarding')->first()->due_on->toDateString())->toBe('2027-01-02')
        ->and(OnboardingPlan::query()->count())->toBe(1);
});

it('refuses a second open plan and can cancel', function () {
    $employee = employeeWithUser($this->manager);
    makeTemplates();
    $plan = $this->onboarding->start($employee);

    expect(fn () => $this->onboarding->start($employee))->toThrow(RuntimeException::class, 'already has');

    $this->onboarding->cancel($plan, 'Offer withdrawn');
    expect($plan->fresh()->status)->toBe('cancelled')->and($plan->tasks()->where('status', 'pending')->count())->toBe(0);
});

it('needs a matching template', function () {
    makeTemplates();
    OnboardingTemplate::query()->update(['status' => 'inactive']);
    expect(fn () => $this->onboarding->start(Employee::factory()->create()))->toThrow(RuntimeException::class, 'No onboarding template');
});
