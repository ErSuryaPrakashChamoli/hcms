<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ChangeFeed;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Filament\Pages\Home;
use App\Livewire\Experience\DrawerHost;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| UX.15.6: Home as a living workspace. The brief of the day comes from real counts; decisions wait
| with their person; "what changed" is measured from the previous visit and every change opens in a
| drawer that re-resolves it for the viewer (never raw audit records, never a hidden category).
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'attendance.view', 'task.view', 'employee.view']);
    assignSchedule($this->manager, weeklySchedule(generalShift()));
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'task.view']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->request = app(Leaves::class)->request($this->employee, LeaveType::query()->where('code', 'EL')->first(), '2026-09-23', '2026-09-24', 'Trip to Kochi');
    $this->hr = tenantUser($this->tenant, ['employee.view']);
    actAsTenant(null);
});

it('briefs the manager from real counts and lists the decision with its person', function () {
    $this->actingAs($this->manager->user)->get(Home::getUrl())->assertOk()
        ->assertSee('Here’s what matters today:', false)
        ->assertSee('decision is waiting for you')
        ->assertSee('Decisions waiting for you')
        ->assertSee('data-person="'.$this->employee->id.'"', false)
        ->assertSee('Review 1 decision')
        ->assertDontSee('pos-kpis', false);
});

it('lists every pending leave for the manager even when several requests load together (strict mode)', function () {
    actAsTenant($this->tenant);
    $second = employeeWithUser($this->manager, ['leave.apply']);
    forceLifecycle($second, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($second, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($second);
    app(Leaves::class)->request($second, LeaveType::query()->where('code', 'EL')->first(), '2026-09-28', '2026-09-28', 'Dentist');

    // Regression: the leave source read each requester's manager lazily, which strict mode refuses for
    // multi-row results; the whole source was dropped and managers saw no leave to decide.
    $ids = app(ApprovalCenter::class)->pending($this->manager->user)->pluck('id')->all();
    expect(collect($ids)->filter(fn ($id) => str_starts_with($id, 'leave:')))->toHaveCount(2);
});

it('measures "what changed" from the previous visit and keeps it through a refresh', function () {
    actAsTenant($this->tenant);
    $prefs = app(ExperiencePreferences::class);
    $user = $this->hr;

    expect($prefs->markHomeVisit($user))->toBeNull();
    $this->travel(20)->minutes();
    expect($prefs->markHomeVisit($user))->toBeNull(); // a refresh within 30 minutes keeps the reference

    $this->travel(2)->days();
    $since = (new ExperiencePreferences(app(TenantContext::class)))->markHomeVisit($user);
    expect($since)->not->toBeNull()->and($since->toDateString())->toBe('2026-09-21');

    EmployeeTimelineEntry::query()->create(['employee_id' => $this->employee->id, 'occurred_on' => now()->subDays(5), 'category' => 'position', 'title' => 'Old move']);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->employee->id, 'occurred_on' => now(), 'category' => 'position', 'title' => 'Transferred to Pune']);
    $changes = app(ChangeFeed::class)->since($user, $since);
    expect($changes['items']->pluck('title')->all())->toContain('Transferred to Pune')->not->toContain('Old move')
        ->and($changes['summary'])->toBe('1 move or promotion');
});

it('opens a change in a drawer only when the viewer may see it', function () {
    actAsTenant($this->tenant);
    $move = EmployeeTimelineEntry::query()->create(['employee_id' => $this->employee->id, 'occurred_on' => now(), 'category' => 'reporting', 'title' => 'Line manager assigned']);
    $pay = EmployeeTimelineEntry::query()->create(['employee_id' => $this->employee->id, 'occurred_on' => now(), 'category' => 'compensation', 'title' => 'Salary revised to 9,99,999']);

    $this->actingAs($this->hr);
    Livewire::test(DrawerHost::class)->call('show', 'change', 'timeline:'.$move->id)
        ->assertSee('Line manager assigned')->assertSee('What changed');
    // A sensitive category the viewer may not see answers like a missing change.
    Livewire::test(DrawerHost::class)->call('show', 'change', 'timeline:'.$pay->id)
        ->assertDontSee('9,99,999')->assertSee('Not available');
    Livewire::test(DrawerHost::class)->call('show', 'change', 'timeline:999999')->assertSee('Not available');

    // Someone without employee.view sees only their own record's changes.
    $this->actingAs($this->employee->user);
    expect(app(ChangeFeed::class)->find($this->employee->user, 'timeline:'.$move->id))->not->toBeNull();
    $this->actingAs($this->manager->user);
    $other = EmployeeTimelineEntry::query()->create(['employee_id' => $this->manager->id, 'occurred_on' => now(), 'category' => 'reporting', 'title' => 'Manager change for the boss']);
    expect(app(ChangeFeed::class)->find($this->employee->user, 'timeline:'.$other->id))->toBeNull();
});
