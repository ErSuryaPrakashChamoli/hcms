<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\MobileNavigation;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Pages\People;
use App\Filament\Pages\Preferences;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\Experience\BottomNav;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| UX.17: the mobile and responsive experience. The phone bar follows the person's experience and offers only what they
| may open; a view switch updates it; Home, the Employee 360, the directory and the command center carry their phone
| structure in the markup. Mobile never changes authorisation: tenant, organisation and relationship scope, field gates
| and notification deep links are checked here too.
*/

function ux17User(Tenant $tenant, array $roles, ?Employee $employee = null): User
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

/** @return list<string> */
function ux17Bar(User $user): array
{
    test()->actingAs($user);

    return array_column(app(MobileNavigation::class)->items($user, route: 'filament.admin.pages.home'), 'key');
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['name' => 'Acme Tech']);
    $this->otherCompany = Company::factory()->create(['name' => 'Acme Services']);
    $hire = fn (string $first, ?Employee $manager = null, ?Company $company = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Mobile'], ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test'],
        ['company_id' => ($company ?? $this->company)->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->managerEmployee = $hire('Maya');
    $this->report = $hire('Ravi', $this->managerEmployee);
    $this->stranger = $hire('Omar', null, $this->otherCompany);

    $this->employee = ux17User($this->tenant, ['employee'], $this->report);
    $this->manager = ux17User($this->tenant, ['employee', 'manager'], $this->managerEmployee);
    $this->hr = ux17User($this->tenant, ['hr-manager']);
    $this->executive = ux17User($this->tenant, ['executive']);
    $this->payroll = ux17User($this->tenant, ['payroll-admin']);
    $this->admin = ux17User($this->tenant, ['tenant-hr-admin']);
});

it('gives each experience its own phone bar, with Actions in the centre', function () {
    expect(ux17Bar($this->employee))->toBe(['home', 'work', 'actions', 'services', 'people'])
        ->and(ux17Bar($this->manager))->toBe(['home', 'approvals', 'actions', 'team', 'work'])
        ->and(ux17Bar($this->executive))->toContain('home', 'pulse', 'actions')->not->toContain('approvals')
        ->and(ux17Bar($this->admin))->toBe(['home', 'admin', 'actions', 'users', 'work'])
        ->and(ux17Bar($this->hr))->toContain('home', 'work', 'actions', 'people')
        ->and(ux17Bar($this->payroll))->toContain('home', 'work', 'actions', 'payroll');
});

it('offers only destinations the person may open, falls back when one is refused, and fails closed for anyone else', function () {
    // Every item's destination is one the person can open (the bar grants nothing).
    $open = ['approvals' => Approvals::class, 'team' => MyTeam::class, 'people' => People::class, 'pulse' => WorkforceCommandCentre::class, 'admin' => AdminCentre::class, 'users' => UserResource::class];
    foreach ([$this->employee, $this->manager, $this->hr, $this->executive, $this->payroll, $this->admin] as $user) {
        foreach (ux17Bar($user) as $key) {
            if (isset($open[$key])) {
                expect($open[$key]::canAccess())->toBeTrue("{$key} offered without access");
            }
        }
    }

    // An administrator who may not see users or open the Admin Centre gets the fallbacks, never the refused screens.
    $limitedAdmin = ux17User($this->tenant, ['employee'], $this->stranger);
    app(ExperiencePreferences::class)->update($limitedAdmin, ['lens' => 'system_admin']);
    $this->actingAs($limitedAdmin);
    expect(app(MobileNavigation::class)->items($limitedAdmin, 'admin'))->not->toBeEmpty()
        ->and(array_column(app(MobileNavigation::class)->items($limitedAdmin, 'admin'), 'key'))->not->toContain('admin')->not->toContain('users');

    // Composed for someone other than the signed-in person, the bar is empty.
    $this->actingAs($this->employee);
    expect(app(MobileNavigation::class)->items($this->admin))->toBe([]);
});

it('never loses decisions: the count rides on Approvals, or on Work when Approvals is not in the bar', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    app(LeaveAccrual::class)->accrue($this->report);
    app(Leaves::class)->request($this->report, LeaveType::query()->where('code', 'EL')->first(), '2026-10-12', '2026-10-12', 'Dentist');

    $this->actingAs($this->manager);
    $count = app(ApprovalCenter::class)->count($this->manager);
    $bar = collect(app(MobileNavigation::class)->items($this->manager));
    expect($count)->toBeGreaterThan(0)
        ->and($bar->firstWhere('key', 'approvals')['badge'])->toBe($count)
        ->and($bar->firstWhere('key', 'work')['badge'])->toBe(0);

    // The same manager viewing as an employee: Approvals leaves the bar and the count moves to Work.
    $asEmployee = collect(app(MobileNavigation::class)->items($this->manager, 'employee'));
    expect($asEmployee->pluck('key')->all())->not->toContain('approvals')
        ->and($asEmployee->firstWhere('key', 'work')['badge'])->toBe($count);
});

it('follows a view switch at once: Home and Preferences tell the bar, which re-renders with the new experience', function () {
    $this->actingAs($this->manager);
    $bar = Livewire::test(BottomNav::class)->assertSeeHtml('data-experience="manager"')->assertSeeHtml('data-bar="approvals"');

    Livewire::test(Home::class)->call('switchLens', 'employee')->assertDispatched('pos-experience-changed');
    $bar->dispatch('pos-experience-changed')->assertSeeHtml('data-experience="employee"')->assertDontSeeHtml('data-bar="approvals"');

    Livewire::test(Preferences::class)->call('setLens', 'manager')->assertDispatched('pos-experience-changed');
    $bar->dispatch('pos-experience-changed')->assertSeeHtml('data-experience="manager"');

    // A view the person does not hold is refused, and nothing is announced.
    Livewire::test(Home::class)->call('switchLens', 'executive')->assertNotDispatched('pos-experience-changed');
    expect(app(ExperienceNavigation::class)->experience($this->manager))->toBe('manager');
});

it('renders the bar with the existing landmarks and marks the current place', function () {
    $html = $this->actingAs($this->manager)->get(Approvals::getUrl())->assertOk()->getContent();
    $nav = substr($html, strpos($html, 'class="pos-bottom-nav"'), 5000);

    expect($nav)->toContain('aria-label="Primary"')->toContain('data-experience="manager"')
        ->toContain('>Approvals</span>')->toContain('>Actions</span>')->toContain('>Team</span>')
        ->and(preg_match('/data-bar="approvals"[^>]*aria-current="page"/', $nav))->toBe(1);
});

it('puts the viewer panel straight after Now on the Employee 360, before intelligence and Recently', function () {
    $html = $this->actingAs($this->manager)->get(EmployeeResource::getUrl('view', ['record' => $this->report]))->assertOk()->getContent();
    $now = strpos($html, 'id="now-title"');
    $viewer = strpos($html, 'pos-360-viewer');
    $recently = strpos($html, '>Recently');

    expect($now)->toBeInt()->and($viewer)->toBeGreaterThan($now)->and($recently)->toBeGreaterThan($viewer)
        ->and(substr_count($html, 'aria-label="People snapshot"'))->toBe(1);
});

it('reads as cards on phones and keeps the filters one tap away in the directory', function () {
    // Filters appear once there are more than eight people to filter.
    foreach (['Asha', 'Bilal', 'Chen', 'Dia', 'Eli', 'Farah', 'Gita'] as $first) {
        app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Mobile'], ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test'], ['company_id' => $this->company->id], null);
    }
    $html = $this->actingAs($this->hr)->get(People::getUrl(['view' => 'list']))->assertOk()->getContent();

    expect($html)->toContain('pos-table-cards')->toContain('pos-card-status')->toContain('aria-controls="pos-people-filters"')
        ->toContain('id="pos-people-filters"')->toContain('Tap a name for more.')->toContain('Hover a name to peek');
});

it('gives the command center a Cancel button and a sheet sized to the visible viewport, keeping Ctrl+K', function () {
    $html = $this->actingAs($this->employee)->get(Home::getUrl())->assertOk()->getContent();
    $js = file_get_contents(resource_path('js/peopleos.js'));

    expect($html)->toContain('class="pos-command-cancel"')->toContain('x-ref="dialog"')->toContain('aria-keyshortcuts="Control+K Meta+K"')
        ->and($js)->toContain('visualViewport')->toContain('--pos-vvh');
});

it('shows three rows first on phones with the rest in place, and touch wording for touch screens', function () {
    $this->actingAs($this->employee);
    for ($i = 1; $i <= 5; $i++) {
        app(Notifier::class)->send([$this->employee], ['in_app'], "Reminder {$i}", 'Synthetic', 'communication.published');
    }
    $html = $this->get(Home::getUrl())->assertOk()->getContent();

    expect($html)->toContain('pos-for-touch')->toContain('the + button starts anything')
        ->and($html)->toContain('pos-with-rail');
    // A list longer than three carries the in-place disclosure; the markup for a short one does not.
    if (preg_match('/Show \d+ more/', $html)) {
        expect($html)->toContain('pos-phone-cap')->toContain('aria-expanded="false"');
    }
});

it('keeps tenant, organisation and relationship scope on every mobile surface', function () {
    // Organisation scope: HR limited to one company cannot reach the other company's people from the phone surfaces.
    app(AccessScopes::class)->assign($this->hr, ['company' => [(int) $this->company->id]], 'UX.17 test: one company');
    $this->actingAs($this->hr);
    $this->get(People::getUrl(['view' => 'list']))->assertOk()->assertSee('Ravi Mobile')->assertDontSee('Omar Mobile');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->stranger]))->assertNotFound();

    // Relationship scope: the manager's Team destination (the bar's Team) lists current reports only.
    $this->actingAs($this->manager);
    $this->get(MyTeam::getUrl())->assertOk()->assertSee('Ravi Mobile')->assertDontSee('Omar Mobile');
    // An employee without people access reaches nobody's 360 from the phone (the bar offers People, the directory only their circle).
    $this->actingAs($this->employee);
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->stranger]))->assertForbidden();
    $this->get(People::getUrl())->assertOk()->assertDontSee('Omar Mobile');

    // Tenant isolation: another tenant's administrator sees none of this tenant's people or bar destinations.
    $other = provisionTenant('Other Mobile');
    $outsider = ux17User($other, ['tenant-hr-admin']);
    actAsTenant($other);
    $this->actingAs($outsider);
    expect(collect(app(MobileNavigation::class)->items($outsider))->pluck('url')->filter()->implode(' '))->not->toContain((string) $this->report->id);
    $this->get(People::getUrl(['view' => 'list']))->assertOk()->assertDontSee('Ravi Mobile');
});

it('keeps notification deep links behind each record\'s policy (field and record gates unchanged)', function () {
    $center = new NotificationCenter;

    // An employee without employee.view gets no link to their own 360 (G12 unchanged); HR in scope does.
    $this->actingAs($this->employee);
    expect($center->linkFor($this->report->getMorphClass(), $this->report->id))->toBeNull();

    app(AccessScopes::class)->assign($this->hr, ['company' => [(int) $this->company->id]], 'UX.17 test: one company');
    $this->actingAs($this->hr);
    expect($center->linkFor($this->report->getMorphClass(), $this->report->id))->toContain('/employees/'.$this->report->id)
        ->and($center->linkFor($this->stranger->getMorphClass(), $this->stranger->id))->toBeNull();
});

it('keeps governance and reminder links on the phone Home openable by the person who sees them', function () {
    $checked = 0;
    foreach ([$this->employee, $this->manager, $this->admin] as $user) {
        $html = $this->actingAs($user)->get(Home::getUrl())->assertOk()->getContent();
        preg_match_all('/<a href="(http[^"]+)" wire:navigate class="pos-btn[^"]*(?:pos-signal-link|pos-btn-secondary pos-btn-sm)"/', $html, $m);
        foreach (array_unique($m[1]) as $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            expect($status)->toBeLessThan(400, "{$url} returned {$status}");
            $checked++;
        }
    }
    // The administrator's governance rows and the employee's reminders give real links to check.
    expect($checked)->toBeGreaterThan(0);
});
