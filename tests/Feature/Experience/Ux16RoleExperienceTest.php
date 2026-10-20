<?php

use App\Domain\Ai\Services\AiGateway;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Actions\TransferEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\CommandSearch;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\PersonWorkspace;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\RoleSignals;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Livewire\Experience\AiAssistant;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/*
| UX.16: five role experiences on one platform. The experience follows the person's responsibilities (permissions
| and relationships, several at once); every role-aware surface stays inside the existing authorisation: tenant,
| permission, organisation scope, relationship scope and field gates. These tests cover multi-role resolution,
| each role's Home and My Work, command and search, notifications, navigation, the Employee 360 emphasis and AI.
*/

function ux16RoleUser(Tenant $tenant, array $roles, ?Employee $employee = null): User
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
    $this->otherCompany = Company::factory()->create(['name' => 'Acme Services']);
    $hire = fn (string $first, ?Employee $manager = null, ?Company $company = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Role'], ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test'],
        ['company_id' => ($company ?? $this->company)->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->managerEmployee = $hire('Maya');
    $this->report = $hire('Ravi', $this->managerEmployee);
    $this->stranger = $hire('Omar', null, $this->otherCompany);

    $this->employee = ux16RoleUser($this->tenant, ['employee'], $this->report);
    $this->manager = ux16RoleUser($this->tenant, ['employee', 'manager'], $this->managerEmployee);
    $this->hr = ux16RoleUser($this->tenant, ['hr-manager']);
    $this->executive = ux16RoleUser($this->tenant, ['executive']);
    $this->payroll = ux16RoleUser($this->tenant, ['payroll-admin']);
    $this->admin = ux16RoleUser($this->tenant, ['tenant-hr-admin']);
    $this->superAdmin = ux16RoleUser($this->tenant, ['tenant-super-admin']);
});

it('resolves one primary experience per person and keeps every held experience one switch away', function () {
    $lenses = app(RoleLens::class);
    $primary = fn (User $u) => RoleLens::experienceOf($lenses->primary($u));

    expect($primary($this->employee))->toBe('employee')
        ->and($primary($this->manager))->toBe('manager')
        ->and($primary($this->hr))->toBe('hr')
        ->and($primary($this->payroll))->toBe('payroll')
        ->and($primary($this->executive))->toBe('executive')
        // UX.16 (G1): a tenant HR admin's role includes analytics, but their Home opens on administration.
        ->and($primary($this->admin))->toBe('admin')
        ->and($primary($this->superAdmin))->toBe('admin')
        ->and(array_keys($lenses->experiences($this->admin)))->toContain('hr', 'payroll', 'executive', 'admin');

    // One Administration chip (HR admin and system admin are one experience), and the role's own Home.
    $html = $this->actingAs($this->admin)->get(Home::getUrl())->assertOk()->assertSee('Governance')->assertSee('Open Admin Centre')->getContent();
    expect(substr_count($html, '>Administration</button>'))->toBe(1)->and($html)->not->toContain('>HR admin</button>')->not->toContain('>Platform</button>');

    // A stored "Home opens as" choice always wins, and only for experiences the person holds.
    app(ExperiencePreferences::class)->update($this->admin, ['lens' => 'executive']);
    $this->actingAs($this->admin)->get(Home::getUrl())->assertOk()->assertSee('Open Workforce pulse')->assertDontSee('>Governance<', false);
    $this->actingAs($this->employee);
    Livewire::test(Home::class)->call('switchLens', 'system_admin');
    expect(app(ExperiencePreferences::class)->for($this->employee)['lens'])->toBeNull();
});

it('leads each Home with the role\'s own work', function () {
    forceLifecycle($this->report, LifecycleState::Probation, ['probation_end_date' => now()->addDays(12)->toDateString()]);

    $this->actingAs($this->employee)->get(Home::getUrl())->assertOk()->assertSee('For you')->assertSee('Your probation review is due in 12 days')->assertDontSee('Governance');
    $this->actingAs($this->manager)->get(Home::getUrl())->assertOk()->assertSee('Your team')->assertSee('team member&#039;s probation decision is due', false);
    $this->actingAs($this->hr)->get(Home::getUrl())->assertOk()->assertSee('People operations')->assertDontSee('Workforce pulse');
    $this->actingAs($this->executive)->get(Home::getUrl())->assertOk()->assertSee('Workforce pulse')->assertSee('Open Workforce pulse')->assertDontSee('People operations');
    $this->actingAs($this->admin)->get(Home::getUrl())->assertOk()->assertSee('Governance')->assertSee('At a glance')->assertDontSee('Probation decisions overdue');

    // My Work leads with the same role work.
    $this->actingAs($this->hr)->get(MyWork::getUrl())->assertOk()->assertSee('People operations');
    $this->actingAs($this->admin)->get(MyWork::getUrl())->assertOk()->assertSee('Governance');
});

it('shows team signals only for the manager\'s own reports', function () {
    foreach ([$this->report, $this->stranger] as $e) {
        AttendanceRecord::query()->create(['employee_id' => $e->id, 'date' => now()->subDay()->toDateString(), 'status' => 'absent', 'is_regularised' => false]);
    }
    $this->actingAs($this->manager);
    $team = collect(app(RoleSignals::class)->team($this->manager))->keyBy('key');
    expect($team['attendance_exceptions']['count'] ?? null)->toBe(1);

    // Someone who manages nobody gets no team signals at all.
    $this->actingAs($this->employee);
    expect(app(RoleSignals::class)->team($this->employee))->toBe([]);
});

it('counts HR operations within the viewer\'s organisation scope', function () {
    foreach ([$this->report, $this->stranger] as $e) {
        forceLifecycle($e, LifecycleState::Probation, ['probation_end_date' => now()->subDays(5)->toDateString()]);
    }
    $scopedHr = ux16RoleUser($this->tenant, ['hr-manager']);
    app(AccessScopes::class)->assign($scopedHr, ['company' => [(int) $this->company->id]], 'UX.16 test: one company');
    app(AccessScopes::class)->forget();

    $this->actingAs($scopedHr);
    expect(collect(app(RoleSignals::class)->operations($scopedHr))->firstWhere('key', 'probation_overdue')['count'] ?? null)->toBe(1);
    app(AccessScopes::class)->forget();
    $this->actingAs($this->hr);
    expect(collect(app(RoleSignals::class)->operations($this->hr))->firstWhere('key', 'probation_overdue')['count'] ?? null)->toBe(2);
});

it('gates governance by screen and never counts another tenant', function () {
    $other = provisionTenant('Other');
    tenantUser($other);                       // a role-less user in another tenant
    actAsTenant($this->tenant);
    tenantUser($this->tenant);                // a role-less user in this tenant

    $this->actingAs($this->admin);
    $governance = app(RoleSignals::class)->governance($this->admin);
    expect(collect($governance['attention'])->firstWhere('key', 'users_without_role')['count'] ?? null)->toBe(1)
        ->and(collect($governance['facts'])->pluck('key')->all())->toContain('active_users', 'mfa')
        ->and(collect($governance['attention'])->pluck('key')->all())->not->toContain('failed_jobs');

    // Without the security screen, nothing about security; without user access, nothing about users.
    $configurator = tenantUser($this->tenant, ['configuration.view']);
    $this->actingAs($configurator);
    $limited = app(RoleSignals::class)->governance($configurator);
    expect(collect($limited['attention'])->pluck('key')->all())->not->toContain('mfa_not_required', 'users_without_role')
        ->and(collect($limited['facts'])->pluck('key')->all())->toBe(['config_week']);
});

it('answers command and search with role verbs only when allowed, and a manager\'s team first', function () {
    $ids = function (User $user, string $q) {
        $this->actingAs($user);

        return collect(app(CommandSearch::class)->search($user, $q))->flatMap(fn ($g) => $g['items'])->pluck('id')->all();
    };

    expect($ids($this->employee, 'permission'))->not->toContain('action:manage_permissions')
        ->and($ids($this->admin, 'permission'))->toContain('action:manage_permissions')
        ->and($ids($this->employee, 'transfer employee'))->not->toContain('action:start_transfer')
        ->and($ids($this->hr, 'transfer employee'))->toContain('action:start_transfer')
        ->and($ids($this->admin, 'audit'))->toContain('action:open_audit');

    // The manager sees everyone (no scope rows) but their own report ranks first and is marked.
    $this->actingAs($this->manager);
    $people = collect(app(CommandSearch::class)->search($this->manager, 'Role', 'people'))->firstWhere('key', 'people')['items'];
    expect($people[0]['person_id'])->toBe($this->report->id)->and($people[0]['subtitle'])->toStartWith('Your team');
    // The employee (no employee.view) only ever finds their own circle.
    $this->actingAs($this->employee);
    $mine = collect(collect(app(CommandSearch::class)->search($this->employee, 'Role', 'people'))->firstWhere('key', 'people')['items'] ?? [])->pluck('person_id')->all();
    expect($mine)->not->toContain($this->stranger->id);
});

it('orders unread notifications by what matters to the role', function () {
    $send = function (User $user, string $group, string $title) {
        $user->notifyNow(Notification::make()->title($title)->viewData(['peopleos' => ['group' => $group]])->toDatabase());
        $this->travel(1)->minutes();
    };
    $send($this->manager, 'approvals', 'Leave request from Ravi');
    $send($this->manager, 'updates', 'A newer update');
    $send($this->admin, 'approvals', 'A decision');
    $send($this->admin, 'system', 'Webhook endpoint failing');
    $send($this->admin, 'updates', 'The newest update');

    $this->actingAs($this->manager);
    expect(Livewire::test(NotificationCenter::class)->instance()->visible()->first()['title'])->toBe('Leave request from Ravi');
    $this->actingAs($this->admin);
    expect(Livewire::test(NotificationCenter::class)->instance()->visible()->first()['title'])->toBe('Webhook endpoint failing');
});

it('orders the navigation by role and hides nothing the person may open', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $nav = app(ExperienceNavigation::class);

    $this->actingAs($this->admin);
    $adminSections = $nav->sections($this->admin);
    expect(array_slice($adminSections, 0, 2))->toBe(['home', 'admin'])->and($adminSections)->toContain('insights', 'people', 'workflows');

    $this->actingAs($this->executive);
    expect($nav->sections($this->executive)[1])->toBe('insights');

    $this->actingAs($this->employee);
    expect($nav->sections($this->employee))->toContain('home', 'work', 'people')->not->toContain('admin', 'insights');

    $this->actingAs($this->manager);
    expect($nav->landing($this->manager, 'people', 'manager')['url'])->toBe(MyTeam::getUrl());
});

it('emphasises the Employee 360 by relationship and gates the identity view', function () {
    $view = function (User $viewer, Employee $subject) {
        $this->actingAs($viewer);

        return app(PersonWorkspace::class)->for($viewer, $subject)['viewer'] ?? null;
    };

    expect($view($this->manager, $this->report)['as'] ?? null)->toBe('manager')
        ->and($view($this->manager, $this->stranger))->toBeNull()
        ->and($view($this->hr, $this->report)['as'] ?? null)->toBe('hr')
        ->and($view($this->admin, $this->report)['as'] ?? null)->toBe('admin')
        ->and(collect($view($this->admin, $this->report)['facts'])->pluck('label')->all())->toContain('Roles', 'Last sign-in', 'Organisation scope');

    // Without user access an administrator-like viewer gets no identity facts; executives cannot open a 360 at all.
    $noUsers = tenantUser($this->tenant, ['employee.view', 'configuration.publish']);
    expect($view($noUsers, $this->report)['as'] ?? null)->not->toBe('admin');
    $this->actingAs($this->executive)->get(EmployeeResource::getUrl('view', ['record' => $this->report]))->assertForbidden();
});

it('asks each role\'s own question through the existing assistants and boundaries', function () {
    $this->actingAs($this->admin);
    $available = app(AiGateway::class)->assistantsFor($this->admin);
    expect(AiAssistant::preferredFor($this->admin, $available))->toBe('hr');
    $this->actingAs($this->executive);
    expect(AiAssistant::preferredFor($this->executive, app(AiGateway::class)->assistantsFor($this->executive)))->toBe('workforce');

    // The manager's "changes in my team" covers their reports only.
    $location = Location::factory()->create();
    foreach ([$this->report, $this->stranger] as $e) {
        app(TransferEmployeeAction::class)->handle($e, ['location_id' => $location->id], now()->subDays(3)->toDateString(), null, 'UX.16 test move');
    }
    $this->actingAs($this->manager);
    $answer = app(AiGateway::class)->ask($this->manager, 'manager', 'Summarise changes in my team')->answer;
    expect($answer)->toContain($this->report->fresh()->display_name)->not->toContain($this->stranger->fresh()->display_name);

    // HR's "what needs attention" comes from the same scoped operations as their Home.
    forceLifecycle($this->stranger, LifecycleState::Probation, ['probation_end_date' => now()->subDays(2)->toDateString()]);
    $this->actingAs($this->hr);
    expect(app(AiGateway::class)->ask($this->hr, 'hr', 'What employee lifecycle actions need attention?')->answer)->toContain('Probation decisions overdue');
});
