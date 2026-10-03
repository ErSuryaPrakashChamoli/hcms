<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ChangeFeed;
use App\Domain\Experience\Services\CommandSearch;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\IntentSearch;
use App\Domain\Experience\Services\JourneyMap;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\QuickActions;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\Home;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Pages\OrganisationMap;
use App\Filament\Pages\People;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Livewire\Experience\DrawerHost;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| Experience Transformation §55–56: the presentation layer grants nothing. Tenant → permission →
| organisation scope → relationship scope → field security → record, for search, drawers, the 360,
| feeds and deep links.
*/

function uxGroups(array $groups): array
{
    return collect($groups)->mapWithKeys(fn ($g) => [$g['key'] => collect($g['items'])->pluck('title')->all()])->all();
}

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->delhi = Location::factory()->create(['name' => 'Delhi']);
    $this->pune = Location::factory()->create(['name' => 'Pune']);
    $company = Company::query()->first() ?? Company::factory()->create();
    $hire = fn (string $first, string $last, ?Employee $manager, Location $loc, array $perms) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => $last],
        ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test', 'user_id' => tenantUser($this->tenant, $perms, ['email' => strtolower($first).'@acme.test'])->id],
        ['company_id' => $company->id, 'location_id' => $loc->id],
        $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->boss = $hire('Bhavna', 'Boss', null, $this->delhi, ['employee.view', 'leave.approve', 'task.view']);
    $this->priya = $hire('Priya', 'Nair', $this->boss, $this->delhi, ['leave.apply', 'task.view', 'servicedesk.request', 'communication.view']);
    $this->stranger = $hire('Sunil', 'Rao', null, $this->pune, ['leave.apply']);
    $this->hr = tenantUser($this->tenant, ['employee.view', 'employee.create', 'employee.update', 'leave.view', 'audit.view']);

    $this->other = provisionTenant('Elsewhere');
    actAsTenant($this->other);
    $this->foreign = app(HireEmployeeAction::class)->handle(['first_name' => 'Zed', 'last_name' => 'Outsider'], ['joining_date' => '2024-01-01'], ['company_id' => Company::factory()->create()->id]);
    actAsTenant($this->tenant);
});

it('finds only the viewer\'s circle without employee.view, everyone in scope with it, and never another tenant', function () {
    $search = app(CommandSearch::class);
    $priyaUser = $this->priya->user;

    expect(uxGroups($search->search($priyaUser, 'bhavna'))['people'] ?? [])->toContain('Bhavna Boss')
        ->and(uxGroups($search->search($priyaUser, 'sunil'))['people'] ?? [])->toBe([])
        ->and(uxGroups($search->search($this->hr, 'sunil'))['people'] ?? [])->toContain('Sunil Rao')
        ->and(uxGroups($search->search($this->hr, 'zed'))['people'] ?? [])->toBe([]);

    expect(app(PeopleVisibility::class)->query($priyaUser)->pluck('id')->all())->toEqualCanonicalizing([$this->priya->id, $this->boss->id])
        ->and(app(PeopleVisibility::class)->query($this->hr)->pluck('id')->all())->not->toContain($this->foreign->id);
});

it('applies organisation scope to search, the directory and the person drawer', function () {
    $delhiHr = tenantUser($this->tenant, ['employee.view']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]], 'Delhi only');

    expect(uxGroups(app(CommandSearch::class)->search($delhiHr, 'sunil'))['people'] ?? [])->toBe([]);

    actAsTenant(null);
    $this->actingAs($delhiHr);
    $this->get(People::getUrl())->assertOk()->assertSee('Priya Nair')->assertDontSee('Sunil Rao');

    actAsTenant($this->tenant);
    Livewire::test(DrawerHost::class)->call('show', 'person', $this->stranger->id)->assertSee('Not available')->assertDontSee('Sunil Rao');
    Livewire::test(DrawerHost::class)->call('show', 'person', $this->priya->id)->assertSee('Priya Nair')->assertDontSee('Not available');
    Livewire::test(DrawerHost::class)->call('show', 'person', $this->foreign->id)->assertSee('Not available');
});

it('offers only actions and smart answers the viewer is allowed', function () {
    $search = app(CommandSearch::class);
    $this->actingAs($this->priya->user);
    $employeeActions = uxGroups($search->search($this->priya->user, 'add'))['actions'] ?? [];
    $this->actingAs($this->hr);
    $hrActions = uxGroups(app(CommandSearch::class)->search($this->hr, 'add'))['actions'] ?? [];
    // Asked about someone other than the signed-in user, actions fail closed.
    expect(app(QuickActions::class)->for($this->priya->user))->toBe([]);
    $this->actingAs($this->priya->user);

    expect($employeeActions)->not->toContain('Add an employee')
        ->and($hrActions)->toContain('Add an employee')
        ->and(uxGroups($search->search($this->priya->user, 'leave'))['actions'] ?? [])->toContain('Request leave');

    expect(app(IntentSearch::class)->answer($this->priya->user, 'who is joining next week'))->toBe([])
        ->and(collect(app(IntentSearch::class)->answer($this->hr, 'who is joining next week'))->pluck('key')->all())->toContain('joining');

    // Unmatched or hostile text is never turned into a query.
    expect(app(IntentSearch::class)->answer($this->hr, "'; DROP TABLE employees; --"))->toBe([])
        ->and(fn () => app(CommandSearch::class)->search($this->hr, "%' OR 1=1 --"))->not->toThrow(Throwable::class);
});

it('keeps the Employee 360 behind its policy and hides what the viewer may not do', function () {
    actAsTenant(null);
    $this->actingAs($this->priya->user);
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->stranger]))->assertForbidden();

    $this->actingAs($this->hr);
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->priya]))->assertOk()->assertSee('People snapshot')->assertSee('Journey')->assertSee('Bhavna Boss');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->foreign]))->assertNotFound();

    actAsTenant($this->tenant);
    Livewire::test(ViewEmployee::class, ['record' => $this->priya->id])
        ->assertActionHidden('assignPosition')->assertActionHidden('editStatutory')->assertActionVisible('message');
});

it('filters journey and change-feed events by the same field rules as the timeline', function () {
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->priya->id, 'occurred_on' => now()->subDays(3), 'category' => 'compensation', 'title' => 'Salary revised to 9,99,999']);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->priya->id, 'occurred_on' => now()->subDays(2), 'category' => 'reporting', 'title' => 'Line manager assigned']);

    $events = fn ($user) => collect(app(JourneyMap::class)->for($user, $this->priya->refresh())['stages'])->flatMap(fn ($s) => collect($s['events'])->pluck('title'))->all();
    expect($events($this->hr))->toContain('Line manager assigned')->not->toContain('Salary revised to 9,99,999');

    $sensitive = tenantUser($this->tenant, ['employee.view', 'employee.sensitive.view']);
    expect($events($sensitive))->toContain('Salary revised to 9,99,999');

    expect(app(ChangeFeed::class)->for($this->hr, 40)->pluck('title')->all())->toContain('Line manager assigned')->not->toContain('Salary revised to 9,99,999');

    // The person themselves sees their own journey, still without sensitive categories.
    expect($events($this->priya->user))->toContain('Line manager assigned')->not->toContain('Salary revised to 9,99,999');
    // An unrelated employee sees nothing.
    expect(app(JourneyMap::class)->for($this->stranger->user, $this->priya)['stages'])->toBe([]);
});

it('shows directory fields only in a preview the viewer may not open in full', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->priya->user);

    Livewire::test(DrawerHost::class)->call('show', 'person', $this->boss->id)
        ->assertSee('Bhavna Boss')->assertDontSee('With us for')->assertDontSee('Open profile')->assertDontSee('Start a change');
    Livewire::test(DrawerHost::class)->call('show', 'person-action', $this->boss->id)->assertSee('No changes available');
    Livewire::test(DrawerHost::class)->call('show', 'person', $this->stranger->id)->call('togglePin');

    expect(app(ExperiencePreferences::class)->for($this->priya->user)['pinned_people'])->toBe([]);
});

it('blocks unauthorised deep links and hides actions mounted from a URL', function () {
    actAsTenant(null);
    $this->actingAs($this->priya->user);
    $this->get(WorkforceCommandCentre::getUrl())->assertForbidden();
    $this->get(AdminCentre::getUrl())->assertForbidden();
    $this->get(OrganisationMap::getUrl())->assertOk()->assertDontSee('Sunil Rao');

    $noLeave = tenantUser($this->tenant, ['task.view']);
    $this->actingAs($noLeave);
    actAsTenant($this->tenant);
    Livewire::test(Home::class)->assertActionHidden('requestLeave')->assertActionHidden('regularise');
});

it('resolves notification deep links only for records the viewer may open, within the tenant', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->priya->user);
    $page = new NotificationCenter;

    expect($page->linkFor(Employee::class, $this->stranger->id))->toBeNull()
        ->and($page->linkFor(Employee::class, $this->foreign->id))->toBeNull()
        ->and($page->linkFor('NoSuch\\Model', 1))->toBeNull();

    $this->actingAs($this->hr);
    expect($page->linkFor(Employee::class, $this->priya->id))->toBe(EmployeeResource::getUrl('view', ['record' => $this->priya]))
        ->and($page->linkFor(Employee::class, $this->foreign->id))->toBeNull();

    expect(app(TenantContext::class)->id())->toBe($this->tenant->id);
});
