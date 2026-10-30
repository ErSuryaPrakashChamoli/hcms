<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\CreatePreEmployeeAction;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Onboarding\Events\OnboardingCompleted;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Onboarding\Support\ProfileSections;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\OnboardingTemplates\Pages\ListOnboardingTemplates;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity 08.2 / P13: induction programmes (kind, start before joining, induction evaluation), profile-section and declaration items, issuing items to current employees. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->onboarding = app(Onboarding::class);
    $this->company = Company::factory()->create(['code' => 'ACME']);
});

it('keeps onboarding templates as they were: kind onboarding, preboarding three days before joining, induction templates never picked as the checklist', function () {
    $employee = employeeWithUser();
    $template = OnboardingTemplate::create(['name' => 'Everyone', 'key' => 'everyone']);
    $template->items()->create(['phase' => 'preboarding', 'type' => 'task', 'title' => 'Sign the offer', 'owner_type' => 'employee']);
    OnboardingTemplate::create(['name' => 'Induction', 'key' => 'induction', 'kind' => 'induction', 'priority' => 1]);

    expect($template->refresh()->kind)->toBe('onboarding')->and($template->starts_days_before_joining)->toBeNull()
        ->and($this->onboarding->templateFor($employee)->key)->toBe('everyone')
        ->and(array_slice(array_keys(config('peopleos.onboarding.item_types')), 0, 4))->toBe(['task', 'document', 'form', 'acknowledgement']);

    $plan = $this->onboarding->start($employee);
    expect($plan->kind)->toBe('onboarding')->and($plan->tasks()->value('due_on')->toDateString())->toBe('2024-12-29')
        ->and($employee->onboardingPlan->id)->toBe($plan->id);
});

it('runs an induction programme next to the checklist, from N days before joining, with the induction evaluation answered by its owner', function () {
    Event::fake([OnboardingCompleted::class]);
    app(FeedbackTemplates::class)->addFromLibrary(['INDUCTION_EVAL'], $this->hr);
    $buddy = employeeWithUser();
    $checklist = OnboardingTemplate::create(['name' => 'Everyone', 'key' => 'everyone']);
    $checklist->items()->create(['phase' => 'preboarding', 'type' => 'task', 'title' => 'Sign the offer', 'owner_type' => 'employee']);
    $induction = OnboardingTemplate::create(['name' => 'Induction programme', 'key' => 'induction', 'kind' => 'induction', 'starts_days_before_joining' => 7]);
    $induction->items()->createMany([
        ['phase' => 'preboarding', 'type' => 'task', 'title' => 'Welcome call', 'owner_type' => 'user', 'owner_user_id' => $buddy->user_id, 'sort_order' => 1],
        ['phase' => 'day_30', 'type' => 'feedback', 'title' => 'Induction evaluation', 'owner_type' => 'user', 'owner_user_id' => $buddy->user_id, 'feedback_template_id' => FeedbackTemplate::query()->where('code', 'INDUCTION_EVAL')->value('id'), 'sort_order' => 2],
    ]);

    $joiner = app(CreatePreEmployeeAction::class)->handle(['person' => ['first_name' => 'New', 'last_name' => 'Joiner'], 'offer' => ['external_reference' => 'IND-1', 'expected_joining_date' => '2026-10-15'], 'position' => ['company_code' => 'ACME']]);
    expect($joiner->onboardingPlans()->pluck('kind')->all())->toBe(['onboarding'])
        ->and($this->onboarding->startDue(now()))->toBe(0);

    $this->travelTo('2026-10-08 07:00:00');
    Artisan::call('peopleos:onboarding:start-due', ['--tenant' => $this->tenant->id]);
    expect($this->onboarding->startDue(now()))->toBe(0);
    $plan = OnboardingPlan::query()->where('employee_id', $joiner->id)->where('kind', 'induction')->sole();
    [$welcome, $evaluation] = $plan->tasks;
    $request = FeedbackRequest::query()->where('subject_type', (new OnboardingTask)->getMorphClass())->where('subject_id', $evaluation->id)->sole();

    expect($welcome->due_on->toDateString())->toBe('2026-10-08')->and($evaluation->due_on->toDateString())->toBe('2026-11-14')
        ->and($joiner->refresh()->onboardingPlan->kind)->toBe('onboarding')
        ->and($request->process)->toBe('induction')->and($request->respondent_user_id)->toBe($buddy->user_id)
        ->and(app(ExperienceTasks::class)->for($buddy->user, $buddy)->where('domain', 'feedback')->pluck('kind')->all())->toBe(['induction'])
        ->and(fn () => $this->onboarding->complete($evaluation, null, null, $buddy->user))->toThrow(RuntimeException::class, 'Give the feedback first');

    app(FeedbackRequests::class)->submit($request, ['q1' => 4, 'q2' => 5, 'q3' => 4, 'q4' => 'yes'], $buddy->user);
    expect($evaluation->refresh()->status)->toBe('completed')
        ->and(app(FeedbackRequests::class)->canRead($this->hr, $request))->toBeTrue()
        ->and(app(FeedbackRequests::class)->canRead($joiner->user ?? tenantUser($this->tenant), $request))->toBeFalse();

    $this->onboarding->complete($welcome, 'Done', null, $buddy->user);
    expect($plan->refresh()->status)->toBe('completed')
        ->and($joiner->timelineEntries()->where('title', 'Induction completed')->exists())->toBeTrue();
    Event::assertNotDispatched(OnboardingCompleted::class);
});

it('completes a profile-section item only once the section is filled, and a declaration only when confirmed', function () {
    $employee = employeeWithUser();
    $template = OnboardingTemplate::create(['name' => 'Profile', 'key' => 'profile']);
    $template->items()->createMany([
        ['phase' => 'day_one', 'type' => 'profile_section', 'profile_section' => 'bank', 'title' => 'Add your bank account', 'owner_type' => 'employee', 'sort_order' => 1],
        ['phase' => 'day_one', 'type' => 'acknowledgement', 'title' => 'Code of conduct', 'owner_type' => 'employee', 'declaration_text' => 'I have read and accept the code of conduct.', 'sort_order' => 2],
    ]);
    [$bank, $conduct] = $this->onboarding->start($employee, $template)->tasks;

    expect($conduct->declaration_text)->toBe('I have read and accept the code of conduct.')
        ->and(ProfileSections::filled($employee, 'personal'))->toBeFalse()
        ->and(fn () => $this->onboarding->complete($bank, null, null, $employee->user))->toThrow(RuntimeException::class, 'Bank details is not filled in yet');
    EmployeeBankAccount::create(['employee_id' => $employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $this->onboarding->complete($bank, null, null, $employee->user);

    expect(fn () => $this->onboarding->complete($conduct, null, null, $employee->user))->toThrow(RuntimeException::class, 'Confirm the declaration first');
    $this->onboarding->complete($conduct, null, null, $employee->user, true, '10.0.0.7');
    $conduct->refresh();
    expect($conduct->status)->toBe('completed')->and($conduct->declared_at)->not->toBeNull()->and($conduct->declared_ip)->toBe('10.0.0.7')
        ->and(AuditEvent::query()->withoutTenancy()->where('module', 'onboarding')->get()->contains(fn (AuditEvent $e) => ($e->metadata['declared'] ?? false) === true && ($e->metadata['task_id'] ?? null) === $conduct->id))->toBeTrue();

    $employee->person->update(['date_of_birth' => '1995-05-05', 'gender' => 'female', 'personal_email' => 'someone@example.test']);
    expect(ProfileSections::filled($employee->refresh(), 'personal'))->toBeTrue();
});

it('issues new and changed items to current employees once, as a separate plan, from the template list', function () {
    $first = employeeWithUser();
    $second = employeeWithUser();
    forceLifecycle($first, LifecycleState::Active);
    forceLifecycle($second, LifecycleState::Active);
    $template = OnboardingTemplate::create(['name' => 'Policies', 'key' => 'policies']);
    $handbook = $template->items()->create(['phase' => 'day_one', 'type' => 'task', 'title' => 'Read the handbook', 'owner_type' => 'employee']);
    $onboardingPlan = $this->onboarding->start($first, $template);
    $this->travel(2)->minutes();
    $posh = $template->items()->create(['phase' => 'day_one', 'type' => 'task', 'title' => 'Complete POSH training', 'owner_type' => 'employee', 'due_offset_days' => 7]);
    $campaign = fn ($employee) => OnboardingPlan::query()->where('employee_id', $employee->id)->where('kind', 'task_campaign');

    Livewire::test(ListOnboardingTemplates::class)->callTableAction('issue', $template, ['apply_to' => 'new'])->assertHasNoTableActionErrors();
    expect(OnboardingPlan::query()->where('kind', 'task_campaign')->count())->toBe(0);

    Livewire::test(ListOnboardingTemplates::class)->callTableAction('issue', $template, ['apply_to' => 'all', 'items' => [$handbook->id, $posh->id], 'audit_reason' => 'New POSH course'])->assertHasNoTableActionErrors();
    $firstTasks = OnboardingTask::query()->whereIn('onboarding_plan_id', $campaign($first)->select('id'))->get();
    $secondTasks = OnboardingTask::query()->whereIn('onboarding_plan_id', $campaign($second)->select('id'))->get();
    expect($firstTasks->pluck('title')->all())->toBe(['Complete POSH training'])
        ->and($firstTasks->first()->due_on->toDateString())->toBe('2026-09-28')
        ->and($secondTasks->pluck('title')->sort()->values()->all())->toBe(['Complete POSH training', 'Read the handbook'])
        ->and($first->refresh()->onboardingPlan->id)->toBe($onboardingPlan->id)
        ->and($this->onboarding->issueItems($template, [$handbook->id, $posh->id], [$first, $second]))->toBe(['employees' => 0, 'tasks' => 0]);

    $this->travel(2)->minutes();
    $handbook->update(['title' => 'Read the 2026 handbook']);
    expect($this->onboarding->issueItems($template, [$handbook->id, $posh->id], [$first, $second]))->toBe(['employees' => 2, 'tasks' => 2]);

    // Completing items issued later closes their plan without completing onboarding.
    $plan = $campaign($first)->orderByDesc('id')->first();
    $this->onboarding->complete($plan->tasks()->first(), null, null, $first->user);
    expect($plan->refresh()->status)->toBe('completed')->and($first->timelineEntries()->where('title', 'Onboarding completed')->exists())->toBeFalse();
});
