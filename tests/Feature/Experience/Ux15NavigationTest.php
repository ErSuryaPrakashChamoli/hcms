<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\IntentSearch;
use App\Domain\Experience\Services\QuickActions;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\People;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Livewire\Experience\CommandCenter;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| UX.15.5: navigation as a workspace. Workspaces carry no module bar; module pages show their area's
| everyday modules with the rest one step away; the command center starts person-first changes only for
| people who may make them; the "recent organisation changes" answer reads the viewer's own feed; the
| Admin Centre finds only settings the viewer may open; My HR opens on the services front door.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::query()->first() ?? Company::factory()->create();
    $hire = fn (string $first, ?Employee $manager, array $perms) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Nav'], ['joining_date' => '2024-01-01', 'user_id' => tenantUser($this->tenant, $perms)->id],
        ['company_id' => $company->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->boss = $hire('Bhavna', null, ['employee.view', 'leave.approve']);
    $this->priya = $hire('Priya', $this->boss, ['leave.apply', 'servicedesk.request']);
    $this->stranger = $hire('Sunil', null, ['leave.apply']);
    $this->hr = tenantUser($this->tenant, ['employee.view', 'employee.position', 'employee.update']);
    $this->admin = tenantUser($this->tenant, ['*']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actAsTenant(null);
});

it('shows no module bar on workspaces and a grouped area bar on module pages', function () {
    $this->actingAs($this->admin);

    $list = $this->get(EmployeeResource::getUrl('index'))->assertOk();
    $list->assertSee('pos-spacebar', false)->assertSee('All in Lifecycle')->assertSee('Employees');

    foreach ([Home::getUrl(), People::getUrl(), MyHr::canAccess() ? MyHr::getUrl() : Home::getUrl(), AdminCentre::getUrl(), EmployeeResource::getUrl('view', ['record' => $this->priya])] as $url) {
        $this->get($url)->assertOk()->assertDontSee('class="pos-spacebar"', false);
    }

    expect(ExperienceNavigation::EVERYDAY['Growth'])->toHaveCount(6)
        ->and(collect(ExperienceNavigation::EVERYDAY)->every(fn (array $m) => count($m) <= 6))->toBeTrue();
});

it('starts a person-first change only for people allowed to make it, and only towards profiles they may open', function () {
    actAsTenant($this->tenant);

    $this->actingAs($this->priya->user);
    expect(collect(app(QuickActions::class)->for($this->priya->user))->pluck('key'))->not->toContain('start_transfer');
    Livewire::test(CommandCenter::class)->call('pick', 'assignPosition')->assertSet('pick', null)->assertSet('mode', 'all');

    $this->actingAs($this->hr);
    expect(collect(app(QuickActions::class)->for($this->hr))->pluck('key'))->toContain('start_transfer', 'change_manager');
    $command = Livewire::test(CommandCenter::class)->call('pick', 'assignPosition')->assertSet('pick', 'assignPosition')->assertSet('mode', 'people')
        ->set('query', 'sunil');
    $people = collect($command->instance()->groups)->firstWhere('key', 'people');
    expect($people['items'][0]['url'])->toEndWith('?action=assignPosition')->and($people['items'][0]['drawer'])->toBeNull();

    // An unknown change is refused, not trusted from the browser.
    Livewire::test(CommandCenter::class)->call('pick', 'deleteEverything')->assertSet('pick', null);
});

it('answers "recent organisation changes" from the viewer\'s own permission-aware feed', function () {
    actAsTenant($this->tenant);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->stranger->id, 'occurred_on' => now()->subDays(2), 'category' => 'position', 'title' => 'Transferred to Pune']);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->priya->id, 'occurred_on' => now()->subDays(1), 'category' => 'reporting', 'title' => 'Line manager assigned']);

    $hrAnswer = collect(app(IntentSearch::class)->answer($this->hr, 'show recent organisation changes'))->firstWhere('key', 'org_changes');
    expect($hrAnswer['answer'])->toContain('2 changes')
        ->and(collect($hrAnswer['rows'])->pluck('meta')->implode(' '))->toContain('Transferred to Pune');

    // Without employee.view the feed is the viewer's own record only.
    $own = collect(app(IntentSearch::class)->answer($this->priya->user, 'what changed this month'))->firstWhere('key', 'org_changes');
    expect(collect($own['rows'] ?? [])->pluck('meta')->implode(' '))->not->toContain('Transferred to Pune');
});

it('finds only settings the viewer may open in the Admin Centre, and shows governance only with access', function () {
    $this->actingAs($this->admin);
    $this->get(AdminCentre::getUrl())->assertOk()->assertSee('What do you want to manage?')->assertSee('Find a setting')->assertSee('Time and leave rules');

    actAsTenant($this->tenant);
    $page = Livewire::test(AdminCentre::class)->set('find', 'leave');
    $matches = $page->instance()->matches;
    expect($matches)->not->toBeEmpty();

    $limited = tenantUser($this->tenant, ['user.view']);
    $this->actingAs($limited);
    $limitedPage = Livewire::test(AdminCentre::class)->set('find', 'leave');
    expect(collect($limitedPage->instance()->matches)->pluck('label')->implode(' '))->not->toContain('Leave types')
        ->and($limitedPage->instance()->governance['pending'])->toBeNull();
});

it('opens My HR on the services front door and keeps every tab one click away', function () {
    $this->actingAs($this->priya->user);

    $this->get(MyHr::getUrl())->assertOk()->assertSee('How can HR help?')->assertSee('My requests')->assertSee('Start a request')
        ->assertSee('Everything in My HR', false);
    $this->get(MyHr::getUrl(['tab' => 'documents']))->assertOk()->assertSee('My HR');
});

it('puts Actions in the phone bar and keeps your profile in the top bar', function () {
    $html = $this->actingAs($this->priya->user)->get(Home::getUrl())->assertOk()->getContent();
    $nav = substr($html, strpos($html, 'class="pos-bottom-nav"'), 4000);

    expect($nav)->toContain('>Actions</span>')->toContain("pos-command-open', { mode: 'actions' }")->not->toContain('>Profile</span>');
});
