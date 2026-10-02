<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 13 test helpers: separate people for each duty (preparer ≠ approver), staff with
 * effective-dated positions, and a survey taken through its real lifecycle to "open".
 *
 * @return array{preparer: User, approver: User, analyst: User}
 */
function engagementActors(): array
{
    $tenant = app(TenantContext::class)->current();

    return [
        'preparer' => tenantUser($tenant, ['engagement.view', 'engagement.manage', 'communication.manage', 'communication.view']),
        'approver' => tenantUser($tenant, ['engagement.view', 'engagement.approve', 'communication.approve', 'communication.view']),
        'analyst' => tenantUser($tenant, ['engagement.view', 'engagement.analytics', 'engagement.comments']),
    ];
}

/** An active employee (with a login) in a department, optionally reporting to a manager. */
function engagementStaff(?Department $department = null, ?Employee $manager = null, array $permissions = ['engagement.participate', 'communication.view']): Employee
{
    $tenant = app(TenantContext::class)->current();
    $user = tenantUser($tenant, $permissions);
    $company = Company::query()->first() ?? Company::factory()->create();
    $employee = app(HireEmployeeAction::class)->handle(
        ['first_name' => $user->name, 'last_name' => 'Staff'],
        ['joining_date' => '2025-01-01', 'work_email' => $user->email, 'user_id' => $user->id],
        array_filter(['company_id' => $company->id, 'department_id' => $department?->id]),
        $manager?->id,
    );
    forceLifecycle($employee, LifecycleState::Active);

    return $employee->refresh();
}

/** @return list<Employee> */
function engagementTeam(int $count, ?Department $department = null, ?Employee $manager = null): array
{
    return array_map(fn () => engagementStaff($department, $manager), range(1, $count));
}

/**
 * Create → questions → submit → approve (another person) → publish → open.
 *
 * @param  array<string, mixed>  $settings
 * @param  list<array<string, mixed>>|null  $questions
 */
function openEngagementSurvey(array $actors, array $settings = [], ?array $questions = null, ?string $code = null): SurveyVersion
{
    $surveys = app(Surveys::class);
    $survey = $surveys->create(['code' => $code ?? 'S'.strtoupper(substr(uniqid(), -6)), 'name' => $settings['name'] ?? 'Pulse', 'closes_at' => now()->addDays(14)->toDateTimeString()]
        + $settings + ['audience_criteria' => ['lifecycle_states' => ['active']]], $actors['preparer']);
    $version = $survey->versions()->first();
    foreach ($questions ?? [
        ['key' => 'mood', 'type' => 'likert', 'prompt' => 'I enjoy my work', 'required' => true],
        ['key' => 'tools', 'type' => 'multiple_choice', 'prompt' => 'Which tools help?', 'options' => ['Laptop', 'Wiki', 'Chat']],
        ['key' => 'comment', 'type' => 'text', 'prompt' => 'Anything else?'],
    ] as $question) {
        $surveys->saveQuestion($version, $question, $actors['preparer']);
    }
    $version = $surveys->submit($version->refresh(), $actors['preparer']);
    $version = $surveys->approve($version, 'ok', $actors['approver']);

    return $surveys->publish($version, $actors['preparer'])->refresh();
}
