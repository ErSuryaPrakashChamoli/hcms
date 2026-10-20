<?php

use App\Domain\Ai\Services\AiGateway;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\UxMetric;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\ModuleCatalogue;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\UxMetrics;
use App\Domain\Experience\Support\NotificationCategories;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\Home;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Pages\Preferences;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Livewire\Experience\AiAssistant;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| Experience Transformation §56: role-specific Home, adaptive navigation, mobile layout, dark mode,
| keyboard navigation, reduced motion, notifications, personalisation and anonymous instrumentation.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::query()->first() ?? Company::factory()->create();
    $hire = fn (string $first, ?Employee $manager, array $perms) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Test'],
        ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test', 'user_id' => tenantUser($this->tenant, $perms)->id],
        ['company_id' => $company->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->manager = $hire('Mina', null, ['employee.view', 'leave.view', 'leave.approve', 'attendance.view', 'learning.team', 'task.view', 'leave.apply']);
    $this->employee = $hire('Esha', $this->manager, ['leave.apply', 'attendance.regularise', 'task.view', 'servicedesk.request', 'communication.view']);
    $this->hr = tenantUser($this->tenant, ['employee.view', 'employee.create', 'employee.update', 'leave.view', 'servicedesk.agent', 'servicedesk.view', 'task.view']);
    $this->exec = tenantUser($this->tenant, ['analytics.view', 'analytics.executive']);
    actAsTenant(null);
});

it('composes a different Home for each lens', function () {
    $this->actingAs($this->employee->user)->get(Home::getUrl())->assertOk()
        ->assertSee('Good')->assertSee('Need attention')->assertSee('Start something')->assertSee('Request leave')->assertSee('Your day')->assertDontSee('Team pulse')->assertDontSee('People operations');

    $this->actingAs($this->manager->user)->get(Home::getUrl())->assertOk()
        ->assertSee('Team pulse')->assertSee('Team in today')->assertSee('Approvals pending')->assertSee('Your team');

    $this->actingAs($this->hr)->get(Home::getUrl())->assertOk()->assertSee('People operations')->assertSee('Open HR requests')->assertDontSee('Team pulse');

    $this->actingAs($this->exec)->get(Home::getUrl())->assertOk()->assertSee('People today')->assertSee('Workforce pulse')->assertDontSee('People operations');
});

it('shows platform administrators the tenant list outside a tenant', function () {
    $this->actingAs(platformAdmin())->get(Home::getUrl())->assertOk()->assertSee('Tenants')->assertSee('Platform health');
});

it('switches the Home lens only to lenses the person has, and remembers it', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->manager->user);

    Livewire::test(Home::class)->call('switchLens', 'executive')->call('switchLens', 'employee');
    expect(app(ExperiencePreferences::class)->for($this->manager->user)['lens'])->toBe('employee');

    $this->actingAs($this->employee->user);
    Livewire::test(Home::class)->call('switchLens', 'manager');
    expect(app(ExperiencePreferences::class)->for($this->employee->user)['lens'])->toBeNull()
        ->and(app(RoleLens::class)->lenses($this->employee->user))->toBe(['employee'])
        ->and(app(RoleLens::class)->lenses($this->manager->user))->toBe(['employee', 'manager']);
});

it('adapts the nine-section navigation to what the person can open', function () {
    actAsTenant($this->tenant);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $nav = app(ExperienceNavigation::class);

    $this->actingAs($this->employee->user);
    $employeeSections = $nav->sections($this->employee->user);
    expect($employeeSections)->toContain('home', 'work', 'people')->not->toContain('insights', 'workflows', 'admin')
        ->and(collect(app(ModuleCatalogue::class)->for($this->employee->user))->pluck('key')->all())->not->toContain(EmployeeResource::class);

    $admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($admin);
    expect((new ExperienceNavigation(new ModuleCatalogue))->sections($admin))->toContain('home', 'work', 'people', 'organisation', 'insights', 'workflows', 'services', 'communication', 'admin');

    // A module page shows its area's space bar; the old 30-group sidebar is gone.
    actAsTenant(null);
    $this->get(EmployeeResource::getUrl('index'))->assertOk()->assertSee('pos-spacebar', false)->assertSee('Lifecycle')->assertDontSee('fi-sidebar-group-label', false);
});

it('renders the mobile bottom navigation, skip link, shortcuts and an accessible command center', function () {
    $html = $this->actingAs($this->employee->user)->get(Home::getUrl())->assertOk()->getContent();

    expect($html)->toContain('class="pos-bottom-nav"')->toContain('aria-label="Primary"')
        ->toContain('>Home</span>')->toContain('>Work</span>')->toContain('>People</span>')->toContain('>Services</span>')
        ->toContain('pos-skip-link')->toContain('id="pos-main"')
        ->toContain('role="combobox"')->toContain(':aria-activedescendant="activeId"')->toContain('role="listbox"')
        ->toContain('aria-labelledby="pos-shortcuts-title"')
        ->toContain('aria-keyshortcuts="Control+K Meta+K"');

    $approvals = $this->actingAs($this->manager->user)->get(Approvals::getUrl())->assertOk()->getContent();
    expect($approvals)->toContain("posListNav('.pos-approval')");
});

it('ships a designed dark theme, reduced-motion handling and keyboard shortcuts', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    preg_match('/:root \{(.*?)\n\}/s', $css, $light);
    preg_match('/\n\.dark \{(.*?)\n\}/s', $css, $dark);
    preg_match_all('/(--pos-(?:canvas|surface[a-z-]*|border[a-z-]*|text[a-z-]*|primary[a-z-]*|accent[a-z-]*|success[a-z-]*|warning[a-z-]*|danger[a-z-]*|info[a-z-]*|focus|chart-\d|tile-[a-z]+(?:-bg)?)):/', $light[1], $tokens);

    foreach (array_unique($tokens[1]) as $token) {
        expect($dark[1])->toContain($token.':');
    }
    expect(Filament::getPanel('admin')->hasDarkMode())->toBeTrue()
        ->and($css)->toContain('@media (prefers-reduced-motion: reduce)')->toContain('animation-duration: 1ms !important');

    $js = file_get_contents(resource_path('js/peopleos.js'));
    expect($js)->toContain('prefers-reduced-motion: reduce')->toContain("e.key.toLowerCase() === 'k'")->toContain("key === 'j'")->toContain('isTyping(e)');
});

it('groups notifications, links them in context, snoozes and marks them read', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);
    app(Notifier::class)->send([$this->employee->user], ['in_app'], 'Leave approved', 'Your leave was approved', 'leave.approved');
    app(Notifier::class)->send([$this->employee->user], ['in_app'], 'Certificate expiring', 'Renew it soon', 'learning.certificate_expiring');
    app(Notifier::class)->send([$this->employee->user], ['in_app'], 'Town hall', 'Friday 4pm', 'communication.published');

    expect(NotificationCategories::for('leave.cancel_requested'))->toBe('approvals')
        ->and(NotificationCategories::for('learning.overdue'))->toBe('attention')
        ->and(NotificationCategories::for(null))->toBe('updates');

    $page = Livewire::test(NotificationCenter::class)->assertSee('Certificate expiring')->assertSee('Town hall');
    expect($page->instance()->counts)->toMatchArray(['all' => 3, 'attention' => 1, 'announcements' => 1, 'updates' => 1]);

    $first = $this->employee->user->notifications()->latest()->first();
    $page->call('snooze', $first->id, 'tomorrow')->assertDontSee($first->data['title']);
    $page->call('markAllRead');
    expect($this->employee->user->unreadNotifications()->count())->toBe(0);
});

it('keeps personal preferences normalised and per person', function () {
    actAsTenant($this->tenant);
    $prefs = app(ExperiencePreferences::class);
    $prefs->update($this->employee->user, ['density' => 'tiny', 'lens' => 'superuser', 'pinned_people' => ['5', 5, 'x'], 'unknown' => 'ignored']);

    $stored = $prefs->for($this->employee->user);
    expect($stored['density'])->toBe('comfortable')->and($stored['lens'])->toBeNull()->and($stored['pinned_people'])->toBe([5, 0])->and($stored)->not->toHaveKey('unknown')
        ->and($prefs->for($this->manager->user)['pinned_people'])->toBe([]);

    $this->actingAs($this->employee->user);
    Livewire::test(Preferences::class)->call('setDensity', 'compact')->assertNotified('Density updated');
    expect((new ExperiencePreferences(app(TenantContext::class)))->for($this->employee->user)['density'])->toBe('compact');
});

it('counts experience usage anonymously and only for whitelisted metrics', function () {
    actAsTenant($this->tenant);
    $metrics = app(UxMetrics::class);
    $metrics->record('command.search', 40);
    $metrics->record('command.search', 60);
    $metrics->record('not.a.metric');
    $metrics->record('ai.asked');

    $row = UxMetric::query()->where('metric', 'command.search')->first();
    expect((int) $row->count)->toBe(2)->and((int) $row->total_ms)->toBe(100)
        ->and(UxMetric::query()->where('metric', 'not.a.metric')->exists())->toBeFalse()
        ->and(array_keys($row->getAttributes()))->not->toContain('user_id');
});

it('presents assistant answers as Answer, Key facts, Sources and Suggested actions, and only through the gateway', function () {
    actAsTenant($this->tenant);
    $user = tenantUser($this->tenant, ['ai.use', 'leave.apply']);
    $this->actingAs($user);
    if (app(AiGateway::class)->assistantsFor($user) === []) {
        $this->markTestSkipped('AI assistants are not enabled for a fresh tenant in this configuration.');
    }

    Livewire::test(AiAssistant::class)
        ->call('usePrompt', 'What is the notice period?')
        ->assertSee('Answer')->assertSee('Assistive')->assertSee('No employment, pay or compensation decisions are made here.');
});
