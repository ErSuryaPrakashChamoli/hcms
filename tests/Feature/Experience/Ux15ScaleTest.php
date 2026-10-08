<?php

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\OrganisationMap;
use App\Filament\Pages\People;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Database\Seeders\UxScaleShowcaseSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| UX.15.19 / UX.15.20: the experience at enterprise volume. Found against 10,000+ synthetic employees
| (UxScaleShowcaseSeeder): the approval queue looked up team overlap and balances per request, the
| Approvals page rendered every decision card, the org map scanned every line per node and put people not
| yet joined at the top, and a queue capped at its scan limit under-counted. Each guard below fails
| without its fix; none relaxes a policy (every item is still re-authorised by its domain policy).
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->type = LeaveType::query()->where('code', 'EL')->first();
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'task.view', 'employee.view']);
    $this->team = collect(range(1, 24))->map(fn () => tap(employeeWithUser($this->manager, ['leave.apply']), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active)));
    $this->leave = fn (Employee $e, string $from, string $to, string $status = 'pending') => LeaveRequest::query()->create([
        'employee_id' => $e->id, 'leave_type_id' => $this->type->id, 'from_date' => $from, 'to_date' => $to, 'from_session' => 'full', 'to_session' => 'full',
        'days' => Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1, 'reason' => 'Synthetic', 'status' => $status,
    ]);
});

it('builds a manager\'s queue with no per-request team-overlap or balance lookups', function () {
    $measure = function () {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $items = (new ApprovalCenter(app(TenantContext::class)))->pending($this->manager->user);
        DB::flushQueryLog();

        return [$items, $queries];
    };
    $this->team->take(4)->each(fn (Employee $e, int $i) => ($this->leave)($e, '2026-10-'.(12 + $i), '2026-10-'.(12 + $i)));
    [$four, $q4] = $measure();
    $this->team->slice(4)->each(fn (Employee $e, int $i) => ($this->leave)($e, '2026-11-'.(1 + $i % 25), '2026-11-'.(1 + $i % 25)));
    [$twentyFour, $q24] = $measure();

    expect($four)->toHaveCount(4)->and($twentyFour)->toHaveCount(24)
        // What remains per request is the domain policy's own re-authorisation, never skipped.
        ->and((count($q24) - count($q4)) / 20)->toBeLessThanOrEqual(2)
        // Balances are looked up only when a card shows the Before → After.
        ->and(collect($q24)->filter(fn ($sql) => str_contains($sql, 'leave_balances')))->toBeEmpty()
        ->and($twentyFour->first()->changes)->toBeArray();
});

it('keeps the team-overlap rule: overlapping approved leave of others in the line, never the requester\'s own', function () {
    [$asking, $b, $c, $d] = $this->team->take(4)->all();
    $request = ($this->leave)($asking, '2026-10-20', '2026-10-21');
    ($this->leave)($b, '2026-10-21', '2026-10-23', 'approved');
    ($this->leave)($c, '2026-10-19', '2026-10-20', 'approved');
    ($this->leave)($d, '2026-10-25', '2026-10-26', 'approved');
    ($this->leave)($asking, '2026-10-20', '2026-10-20', 'approved');

    $item = app(ApprovalCenter::class)->pending($this->manager->user)->firstWhere('id', 'leave:'.$request->id);

    expect($item->impact)->toBe('2 other people in the team away on these dates')
        ->and($item->risk)->toBe('high')
        ->and($item->riskReason)->toBe('Several people in the team are away');
});

it('answers the decisions about one person without building the whole queue, with the same result', function () {
    $this->team->take(6)->each(fn (Employee $e, int $i) => ($this->leave)($e, '2026-10-'.(12 + $i), '2026-10-'.(12 + $i)));
    $subject = $this->team[2];

    $about = (new ApprovalCenter(app(TenantContext::class)))->pendingAbout($this->manager->user, $subject->id);
    $all = (new ApprovalCenter(app(TenantContext::class)))->pending($this->manager->user)->where('subjectEmployeeId', $subject->id)->values();

    expect($about->pluck('id')->all())->toBe($all->pluck('id')->all())->toHaveCount(1);
});

it('says 200+ when a source reaches its scan limit, and renders the queue in pages', function () {
    $member = $this->team->first();
    $rows = collect(range(0, 219))->map(fn ($i) => ['tenant_id' => $this->tenant->id, 'employee_id' => $member->id, 'leave_type_id' => $this->type->id,
        'from_date' => now()->addDays(3 + $i)->toDateString(), 'to_date' => now()->addDays(3 + $i)->toDateString(), 'from_session' => 'full', 'to_session' => 'full',
        'days' => 1, 'reason' => 'Synthetic', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $rows->chunk(100)->each(fn ($chunk) => DB::table('leave_requests')->insert($chunk->values()->all()));

    expect(app(ApprovalCenter::class)->capped($this->manager->user))->toBeTrue();

    $this->actingAs($this->manager->user);
    $page = Livewire::test(Approvals::class)->assertSee('200+ decisions need you')->assertSee('More are waiting than are listed');
    expect(substr_count($page->html(), 'pos-stream-row pos-queue-row'))->toBe(Approvals::PAGE)
        ->and(substr_count($page->html(), 'pos-approval-detail'))->toBe(Approvals::PAGE);

    $page->call('showMore');
    expect(substr_count($page->html(), 'pos-stream-row pos-queue-row'))->toBe(Approvals::PAGE * 2);
});

it('shows the first rows of a long My work stream and the rest on request', function () {
    $this->team->take(20)->each(fn (Employee $e, int $i) => ($this->leave)($e, '2026-11-'.(1 + $i), '2026-11-'.(1 + $i)));
    $this->actingAs($this->manager->user);

    $decisionRows = fn ($page) => preg_match_all('/pos-work-row"[^>]*data-approval-id=/', $page->html());

    $page = Livewire::test(MyWork::class)->assertSee('Show all 20');
    expect($decisionRows($page))->toBe(MyWork::PAGE);

    $page->call('showAll', 'decisions')->assertDontSee('Show all 20');
    expect($decisionRows($page))->toBe(20);
});

it('keeps people not yet joined off the current reporting lines', function () {
    $joining = employeeWithUser(null, ['leave.apply']);
    forceLifecycle($joining, LifecycleState::Preboarding, ['joining_date' => null, 'expected_joining_date' => '2026-11-02']);
    ReportingRelationship::query()->where('employee_id', $joining->id)->update(['manager_id' => $this->manager->id, 'effective_from' => '2026-11-02']);
    $hr = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($hr);

    $map = Livewire::test(OrganisationMap::class)->instance();

    expect(array_keys($map->lines))->not->toContain($joining->id)
        ->and($map->roots())->toContain($this->manager->id)->not->toContain($joining->id)
        ->and($map->childrenOf($this->manager->id))->toHaveCount(24);
});

it('keeps average tenure exactly as before (Carbon month difference per person), computed once per joining date', function () {
    $this->team->take(5)->each(fn (Employee $e, int $i) => $e->update(['joining_date' => ['2019-03-14', '2021-07-01', '2021-07-01', '2024-12-30', '2025-02-28'][$i]]));
    $expected = round(Employee::query()->employed()->whereNotNull('joining_date')->get()->avg(fn (Employee $e) => $e->joining_date->diffInMonths(now())), 1);

    expect(app(WorkforceMetrics::class)->avgTenure())->toBe($expected);
});

it('tells same-named people apart on the People cards and keeps long names whole on hover', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $someone = $this->team->first()->refresh();

    Livewire::test(People::class)->set('search', $someone->employee_code)
        ->assertSee($someone->employee_code)->assertSee('title="'.e($someone->display_name).'"', false);
});

it('refuses to seed the scale population anywhere but a disposable showcase database', function () {
    app()['env'] = 'local';

    expect(fn () => app(UxScaleShowcaseSeeder::class)->run(app(TenantContext::class)))->toThrow(RuntimeException::class, '*_showcase');
});
