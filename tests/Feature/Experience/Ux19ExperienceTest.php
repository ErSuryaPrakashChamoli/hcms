<?php

use App\Domain\Ai\Services\AiGateway;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Experience\Services\MobileNavigation;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\RoleSignals;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\Experience\AiAssistant;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| UX.19: the final experience decisions.
| - D5: one Administration experience whose sections compose from permissions. An IT-only administrator sees access
|   and security; a configuration-only administrator sees configuration; neither sees the other's governance, and
|   governance is never a way into employee records.
| - D3: payroll is a contextual experience in the shared platform: its own Home lead, My Work lead, phone bar and
|   opening assistant, and nothing of HR operations it may not open.
| - M11: the manager Home lists decisions once; Your team carries team exceptions only (as My Work already did).
| - D8: "View as" is a group of toggle buttons (it switches and remembers the view; there is no tab panel).
*/

function ux19ExperienceUser(Tenant $tenant, array $roles, ?Employee $employee = null): User
{
    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $roles, $employee) {
        $user = User::factory()->forTenant($tenant)->create();
        $user->roles()->attach(Role::query()->whereIn('slug', $roles)->pluck('id'));
        if ($employee !== null) {
            LifecycleEngine::unguarded(fn () => $employee->forceFill(['user_id' => $user->id])->save());
        }

        return $user;
    });
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['name' => 'Acme Tech']);
    $hire = fn (string $first, ?Employee $manager = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Final'], ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test'],
        ['company_id' => $this->company->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->managerEmployee = $hire('Maya');
    $this->report = $hire('Ravi', $this->managerEmployee);
    $this->employee = ux19ExperienceUser($this->tenant, ['employee'], $this->report);
    $this->manager = ux19ExperienceUser($this->tenant, ['employee', 'manager'], $this->managerEmployee);
});

it('composes one Administration experience from permissions: each side sees only its own governance', function () {
    $it = tenantUser($this->tenant, ['user.view', 'user.assign_roles', 'role.view', 'settings.view', 'settings.update', 'security.manage', 'audit.view']);
    $config = tenantUser($this->tenant, ['configuration.view', 'configuration.update', 'configuration.publish', 'policy.view', 'policy.update', 'workflow.view']);
    $lenses = app(RoleLens::class);

    // Both are the one Administration experience, reached through different halves of it.
    expect($lenses->lenses($it))->toContain(RoleLens::SYSTEM_ADMIN)->not->toContain(RoleLens::HR_ADMIN)
        ->and($lenses->lenses($config))->toContain(RoleLens::HR_ADMIN)->not->toContain(RoleLens::SYSTEM_ADMIN)
        ->and(RoleLens::experienceOf($lenses->primary($it)))->toBe('admin')
        ->and(RoleLens::experienceOf($lenses->primary($config)))->toBe('admin');

    $this->actingAs($it);
    $itGov = app(RoleSignals::class)->governance($it);
    $itKeys = collect($itGov['attention'])->pluck('key')->merge(collect($itGov['facts'])->pluck('key'))->all();
    $html = $this->get(Home::getUrl())->assertOk()->assertSee('Governance')->getContent();
    expect($itKeys)->toContain('mfa_not_required', 'active_users', 'audit_week')->not->toContain('config_week')
        ->and($html)->toContain(UserResource::getUrl('index'));

    $this->actingAs($config);
    $configGov = app(RoleSignals::class)->governance($config);
    $configKeys = collect($configGov['attention'])->pluck('key')->merge(collect($configGov['facts'])->pluck('key'))->all();
    $html = $this->get(Home::getUrl())->assertOk()->assertSee('Governance')->getContent();
    expect($configKeys)->toContain('config_week')->not->toContain('active_users', 'mfa_not_required', 'audit_week')
        ->and($html)->not->toContain(UserResource::getUrl('index'));

    // Every governance link opens for the person who sees it; neither is a way into employee records.
    foreach ([[$it, $itGov], [$config, $configGov]] as [$user, $gov]) {
        foreach (collect($gov['attention'])->merge($gov['facts'])->pluck('url')->filter()->unique() as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->actingAs($user)->get(EmployeeResource::getUrl('view', ['record' => $this->report]))->assertForbidden();
    }
});

it('gives payroll its own contextual experience inside the shared platform', function () {
    $payroll = ux19ExperienceUser($this->tenant, ['payroll-admin']);
    $this->actingAs($payroll);

    expect(app(RoleLens::class)->experiences($payroll))->toHaveKey('payroll')
        ->and(RoleLens::experienceOf(app(RoleLens::class)->primary($payroll)))->toBe('payroll')
        ->and(HomeComposer::MAIN['payroll'][0])->toBe('payroll')
        ->and(array_column(app(MobileNavigation::class)->items($payroll), 'key'))->toContain('payroll')
        ->and(AiAssistant::preferredFor($payroll, app(AiGateway::class)->assistantsFor($payroll)))->toBe('payroll_auditor');

    // Home and My Work lead with the run and send the person to the control room, which opens.
    $this->get(Home::getUrl())->assertOk()->assertSee(PayrollControlRoom::getUrl(), false);
    $this->get(MyWork::getUrl())->assertOk()->assertSee('The current run and what blocks sign-off.');
    $this->get(PayrollControlRoom::getUrl())->assertOk();

    // Payroll is not HR operations: no people-operations lead, no administration.
    expect(app(RoleLens::class)->lenses($payroll))->not->toContain(RoleLens::HR, RoleLens::HR_ADMIN, RoleLens::SYSTEM_ADMIN);
});

it('lists the manager\'s decisions once on Home; Your team carries team exceptions only', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    app(LeaveAccrual::class)->accrue($this->report);
    app(Leaves::class)->request($this->report, LeaveType::query()->where('code', 'EL')->first(), '2026-10-12', '2026-10-12', 'Dentist');
    forceLifecycle($this->report, LifecycleState::Probation, ['probation_end_date' => '2026-10-20']);

    $this->actingAs($this->manager);
    expect(app(ApprovalCenter::class)->count($this->manager))->toBeGreaterThan(0)
        // The signal still exists for My Team and the bar …
        ->and(collect(app(RoleSignals::class)->team($this->manager))->pluck('key')->all())->toContain('decisions', 'probation_due');

    // … but Home already leads with the decisions, so Your team does not repeat them.
    $home = app(HomeComposer::class)->for($this->manager);
    expect($home['experience'])->toBe('manager')
        ->and(collect($home['team_signals'])->pluck('key')->all())->toContain('probation_due')->not->toContain('decisions');
    $this->get(Home::getUrl())->assertOk()->assertSee('Decisions waiting for you')->assertDontSee('decision needs you')->assertDontSee('decisions need you');
});

it('offers "View as" as a group of toggle buttons that switches and remembers the view', function () {
    $this->actingAs($this->manager);
    $html = $this->get(Home::getUrl())->assertOk()->getContent();
    preg_match('/<div class="pos-lens pos-lens-scroll[^"]*"([^>]*)>/', $html, $row);
    expect($row[1] ?? '')->toContain('role="group"')->toContain('aria-label="View Home as"')->not->toContain('tablist')
        ->and($html)->not->toContain('role="tab" class="pos-lens-chip"')
        ->and(substr_count($html, 'class="pos-lens-chip" aria-pressed="true"'))->toBe(1);

    Livewire::test(Home::class)->call('switchLens', RoleLens::EMPLOYEE)->assertDispatched('pos-experience-changed');
    $html = $this->get(Home::getUrl())->getContent();
    expect($html)->toMatch('/aria-pressed="true"[^>]*>\s*For you/');
});

it('keeps every part of the Employee 360 header while compacting it on phones', function () {
    $hr = ux19ExperienceUser($this->tenant, ['employee', 'hr-manager']);
    $this->actingAs($hr);
    $html = $this->get(EmployeeResource::getUrl('view', ['record' => $this->report]))->assertOk()->getContent();

    // Identity, lifecycle, relationship (the label kept with the person) and the actions are all still there.
    expect($html)->toContain('Ravi Final')->toContain('pos-360-ribbon')->toContain('One lifetime record')
        ->toContain('class="pos-360-reports"')->toContain('Maya Final')
        ->toContain('class="pos-360-actions"')->toContain('Summarise')->toContain('Message');

    // The person viewing their own record has no action here, so there is no empty action row.
    $this->actingAs($this->employee);
    $own = $this->get(EmployeeResource::getUrl('view', ['record' => $this->report]))->assertOk()->getContent();
    expect($own)->toContain('Ravi Final')->toContain('class="pos-360-reports"')->not->toContain('class="pos-360-actions"');
});
