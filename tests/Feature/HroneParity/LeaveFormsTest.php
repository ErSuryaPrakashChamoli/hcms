<?php

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Experience\Services\IntentSearch;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\TeamCalendar;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\CompOffCredits;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeavePrivacy;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Leave\Services\TeamAway;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\Home;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Resources\MyLeaveEncashments\Pages\ListMyLeaveEncashments;
use App\Filament\Support\LeaveActions;
use App\Http\Controllers\ParityFileController;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
 * HROne parity RQ-4 (matrix 20 §20.1.18 Leave #8–10, Encashment #3, #5; §20.4.2 #6): private leave, comp-off source dates,
 * the team-away panel of the leave form, and the encashment form's balance helper and supporting file.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00'); // Monday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);

    // Team 1: Mira manages Asha and Ravi, and holds the manager template's leave rights.
    $this->mira = employeeWithUser(null, ['leave.view', 'leave.apply', 'leave.approve']);
    $this->asha = employeeWithUser($this->mira, ['leave.apply']);
    $this->ravi = employeeWithUser($this->mira, ['leave.apply']);
    // Team 2: Lena leads Kiran but holds no leave rights (a manager by reporting line only).
    $this->lena = employeeWithUser(null, ['leave.apply']);
    $this->kiran = employeeWithUser($this->lena, ['leave.apply']);
    $schedule = weeklySchedule(generalShift());
    foreach ([$this->mira, $this->asha, $this->ravi, $this->lena, $this->kiran] as $employee) {
        forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
        assignSchedule($employee, $schedule);
    }
    leavePolicy(['EL' => ['days' => 12, 'encashment_allowed' => true, 'max_encash_days' => 10, 'max_encash_per_request' => 3, 'min_balance_after_encash' => 2],
        'SL' => ['days' => 6], 'CO' => ['days' => 0]]);
    foreach ([$this->asha, $this->ravi, $this->kiran] as $employee) {
        app(LeaveAccrual::class)->accrue($employee);
    }
    $this->leaves = app(Leaves::class);
    $this->privacy = app(LeavePrivacy::class);
    $this->el = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $this->sl = LeaveType::query()->where('code', 'SL')->firstOrFail();
    $this->co = LeaveType::query()->where('code', 'CO')->firstOrFail();
});

/** Approved leave for $employee (asked by them), private or not. */
function rq4Leave(Employee $employee, LeaveType $type, string $from, string $to, ?bool $private = null, bool $approve = true): LeaveRequest
{
    $request = app(Leaves::class)->request($employee, $type, $from, $to, 'Flu and rest', requester: $employee->user, private: $private);

    return $approve ? app(Leaves::class)->approve($request, null, test()->hr) : $request;
}

/** A second leave policy for everyone that takes precedence over the standard one. */
function rq4Policy(array $entitlements, string $code): void
{
    $policy = leavePolicy($entitlements, code: $code);
    PolicyAssignmentRule::query()->where('policy_id', $policy->id)->update(['priority' => 1]);
}

function rq4As(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(RoleLens::class);
    app()->forgetInstance(HomeComposer::class);
}

// ---- defaults ---------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: visible leave, the question answered "Yes", no file needed to encash', function () {
    $leave = rq4Leave($this->asha, $this->sl, '2026-09-21', '2026-09-22');
    expect($leave->refresh()->is_private)->toBeFalse()->and($leave->isPrivate())->toBeFalse()
        // The request is stored and audited exactly as before: no new field on its creation.
        ->and(AuditEvent::query()->withoutGlobalScopes()->whereIn('entity_type', [LeaveRequest::class, $leave->getMorphClass()])->where('entity_id', $leave->id)->where('action', 'CREATE')->firstOrFail()
            ->fieldChanges()->pluck('field')->all())->toContain('leave_type_id')->not->toContain('is_private')
        ->and(LeavePrivacy::decide(null, null))->toBeFalse()
        ->and($this->privacy->mode($this->asha, $this->sl))->toBe('employee');

    // The form asks, and its answer defaults to "Yes" (visible).
    $radio = collect(LeaveActions::applyForm(fn () => $this->asha))->first(fn ($c) => $c instanceof Radio);
    expect($radio?->getName())->toBe('visible_to_colleagues')->and($radio->getDefaultState())->toBe('yes');

    // A colleague (a manager by reporting line only) sees the leave type of a visible leave, as before.
    $kirans = rq4Leave($this->kiran, $this->sl, '2026-09-21', '2026-09-22');
    rq4As($this->lena->user);
    $home = app(HomeComposer::class)->for($this->lena->user);
    expect($home['team']['away'][0]['type'])->toBe('Sick leave')->and($home['team']['planned'][0]['type'])->toBe('Sick leave')
        ->and($this->privacy->showsDetails($kirans, $this->lena->user))->toBeTrue();

    // Home's "Request leave" submits without the new question as before, and with "No" the leave is private.
    rq4As($this->ravi->user);
    Livewire::test(Home::class)->callAction('requestLeave', data: ['leave_type_id' => $this->el->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28', 'from_session' => 'full', 'reason' => 'Errand'])
        ->assertHasNoActionErrors();
    Livewire::test(Home::class)->callAction('requestLeave', data: ['leave_type_id' => $this->el->id, 'from_date' => '2026-09-29', 'to_date' => '2026-09-29', 'from_session' => 'full', 'reason' => 'Clinic', 'visible_to_colleagues' => 'no'])
        ->assertHasNoActionErrors();
    $ravis = LeaveRequest::query()->where('employee_id', $this->ravi->id)->orderBy('from_date')->get();
    expect($ravis->map->isPrivate()->all())->toBe([false, true]);

    // Encashment without a file rule: no file asked for, the request goes through as before.
    rq4As($this->asha->user);
    expect(app(LeaveAdjustments::class)->encashmentAllowance($this->asha, $this->el)['needs_file'])->toBeFalse();
    $encashment = app(LeaveAdjustments::class)->requestEncashment($this->asha, $this->el, 1, 'Festival');
    expect($encashment->status)->toBe('pending')->and($encashment->refresh()->attachments)->toBeNull();
});

it('follows the leave type\'s privacy rule when the policy fixes it, and asks only when the employee decides', function () {
    rq4Policy(['EL' => ['days' => 12, 'privacy' => 'private'], 'SL' => ['days' => 6, 'privacy' => 'public'], 'CL' => ['days' => 6, 'privacy' => 'employee']], 'RQ4_PRIVACY');
    app(LeaveAccrual::class)->accrue($this->asha);
    $cl = LeaveType::query()->where('code', 'CL')->firstOrFail();

    expect($this->privacy->mode($this->asha, $this->el))->toBe('private')
        ->and($this->privacy->mode($this->asha, $this->sl))->toBe('public')
        ->and($this->privacy->mode($this->asha, $cl))->toBe('employee')
        ->and(LeaveActions::privacyMode($this->asha, $this->el->id))->toBe('private')
        ->and(LeaveActions::privacyMode($this->asha, null))->toBeNull()
        ->and(LeaveActions::privacyMode(new Employee, $this->el->id))->toBeNull();

    expect(rq4Leave($this->asha, $this->el, '2026-09-23', '2026-09-23', false, false)->isPrivate())->toBeTrue()   // always private
        ->and(rq4Leave($this->asha, $this->sl, '2026-09-24', '2026-09-24', true, false)->isPrivate())->toBeFalse()   // always public
        ->and(rq4Leave($this->asha, $cl, '2026-09-25', '2026-09-25', true, false)->isPrivate())->toBeTrue()          // the employee chose
        ->and(rq4Leave($this->asha, $cl, '2026-09-28', '2026-09-28', null, false)->isPrivate())->toBeFalse();        // no answer = visible
});

// ---- private leave on every surface ------------------------------------------------------------------------------------

it('shows a private leave as "Away" to colleagues on every surface and in full to the approver, HR and the employee', function () {
    $kirans = rq4Leave($this->kiran, $this->sl, '2026-09-21', '2026-09-22', true);
    $ashas = rq4Leave($this->asha, $this->sl, '2026-09-21', '2026-09-22', true);
    expect($kirans->refresh()->is_private)->toBeTrue();

    // Lena (Kiran's manager by reporting line, no leave rights): Home team card, planned leaves, search — "Away".
    rq4As($this->lena->user);
    $home = app(HomeComposer::class)->for($this->lena->user);
    expect($home['team']['away'])->toHaveCount(1)->and($home['team']['away'][0]['type'])->toBe('Away')
        ->and($home['team']['planned'][0])->toMatchArray(['name' => $this->kiran->display_name, 'type' => 'Away', 'dates' => '21 Sep – 22 Sep'])
        ->and($home['pulse_team']['away'])->toBe(1); // still counted as away
    $card = view('filament.pages.home.team', ['h' => $home])->render();
    expect($card)->toContain('Team planned leaves')->toContain('Away')->not->toContain('Sick leave')->not->toContain('Flu and rest');
    $search = collect(app(IntentSearch::class)->answer($this->lena->user, 'who is on leave today'))->firstWhere('key', 'on_leave');
    expect($search['rows'][0]['meta'])->toBe('Away · until 22 Sep');
    // Her team calendar never lists leave she may not open (nothing to leak), and the leave calendar is not hers.
    expect(collect(app(TeamCalendar::class)->events($this->lena->user, 'team', 2026, 9))->flatten(1)->where('kind', 'leave')->all())->toBe([])
        ->and(LeaveCalendar::canAccess())->toBeFalse();

    // A peer: "Away"; the employee: in full.
    expect($this->privacy->typeLabel($ashas, $this->ravi->user))->toBe('Away')
        ->and($this->privacy->typeLabel($ashas, $this->asha->user))->toBe('Sick leave')
        ->and($this->privacy->typeLabel($ashas, null))->toBe('Away');

    // The approver (leave.approve in scope, even without leave.view), and whoever decided it: in full.
    $approver = tenantUser($this->tenant, ['leave.approve']);
    $decider = tenantUser($this->tenant, ['leave.apply']);
    $other = rq4Leave($this->ravi, $this->sl, '2026-09-24', '2026-09-24', true, false);
    $this->leaves->approve($other, null, $decider);
    expect($this->privacy->showsDetails($ashas, $approver))->toBeTrue()
        ->and($this->privacy->showsDetails($other->refresh(), $decider))->toBeTrue()
        ->and($this->privacy->showsDetails($ashas, $decider))->toBeFalse();

    // Mira (manager template: leave.view and leave.approve in scope): her Home card in full.
    rq4As($this->mira->user);
    $home = app(HomeComposer::class)->for($this->mira->user);
    expect($home['team']['planned'][0]['type'])->toBe('Sick leave');

    // HR (leave.view): the leave calendar, the team calendar and search in full.
    rq4As($this->hr);
    Livewire::test(LeaveCalendar::class)->assertCanSeeTableRecords([$kirans, $ashas])->assertSee('Sick leave')->assertDontSee('Flu and rest');
    $events = collect(app(TeamCalendar::class)->events($this->hr, 'organisation', 2026, 9))->flatten(1)->where('kind', 'leave')->where('employee_id', $this->kiran->id);
    expect($events->first()['detail'])->toBe('Sick leave · 21 Sep – 22 Sep');
    $search = collect(app(IntentSearch::class)->answer($this->hr, 'who is on leave today'))->firstWhere('key', 'on_leave');
    expect(collect($search['rows'])->pluck('meta')->unique()->all())->toBe(['Sick leave · until 22 Sep']);
});

it('never leaks a private leave\'s type through filters, counts or the team cards', function () {
    $ashasPrivate = rq4Leave($this->asha, $this->sl, '2026-09-23', '2026-09-23', true);
    $ashasVisible = rq4Leave($this->asha, $this->sl, '2026-09-25', '2026-09-25');
    $ravisPrivate = rq4Leave($this->ravi, $this->sl, '2026-09-24', '2026-09-24', true);
    $kirans = rq4Leave($this->kiran, $this->sl, '2026-09-21', '2026-09-21', true);

    // Filtering by the type finds a private leave only for those who may see it in full (Ravi: his own, Asha's visible one).
    $bySick = fn (?User $viewer) => $this->privacy->restrictToDetails(LeaveRequest::query()->where('leave_type_id', $this->sl->id), $viewer)->orderBy('id')->pluck('id')->all();
    expect($bySick($this->ravi->user))->toBe([$ashasVisible->id, $ravisPrivate->id])
        ->and($bySick(null))->toBe([$ashasVisible->id])
        ->and($bySick($this->hr))->toBe([$ashasPrivate->id, $ashasVisible->id, $ravisPrivate->id, $kirans->id]);
    rq4As($this->hr);
    Livewire::test(LeaveCalendar::class)->filterTable('leave_type_id', $this->sl->id)->assertCanSeeTableRecords([$ashasPrivate, $ravisPrivate]);

    // The team cards carry no type, no reason, no code — only names, "Away" and dates; counts are of people.
    rq4As($this->lena->user);
    $home = app(HomeComposer::class)->for($this->lena->user);
    $cards = json_encode([$home['team'], $home['pulse_team']]);
    expect($cards)->not->toContain('Sick leave')->not->toContain('"SL"')->not->toContain('Flu and rest')
        ->and($home['pulse_team']['away'])->toBe(1);
});

// ---- comp-off source dates ---------------------------------------------------------------------------------------------

it('shows the worked days behind the comp-off available, oldest used first', function () {
    $claim = fn (string $worked, string $until, string $status = 'approved') => TimeOfficeRequest::create(['employee_id' => $this->asha->id, 'type' => 'comp_off',
        'status' => $status, 'from_date' => $worked, 'to_date' => $worked, 'days' => 1, 'expires_on' => $until, 'reason' => 'Weekend work']);
    $credits = app(CompOffCredits::class);
    $credits->credit($claim('2026-09-13', '2026-10-13'));
    $credits->credit($claim('2026-09-20', '2026-10-20'));
    $credits->credit($claim('2026-08-30', '2026-09-15')); // validity over: lapsed below
    $cancelled = $claim('2026-09-06', '2026-10-06');
    $credits->credit($cancelled);
    $credits->reverse($cancelled, 'Worked from home instead');
    $cancelled->forceFill(['status' => 'cancelled'])->save();
    expect($credits->expire())->toBe(1);
    app(LeaveAdjustments::class)->creditCompOff($this->asha, 0.5, 'Credited by HR');

    $sources = $credits->sources($this->asha, $this->co);
    expect(collect($sources)->map(fn (array $s) => [$s['worked_on']->toDateString(), $s['left'], $s['expires_on']?->toDateString(), $s['claim_id'] !== null])->all())
        ->toBe([['2026-09-13', 1.0, '2026-10-13', true], ['2026-09-20', 1.0, '2026-10-20', true], ['2026-09-21', 0.5, null, false]]);

    // A pending day of comp-off uses the oldest credit first.
    rq4Leave($this->asha, $this->co, '2026-09-25', '2026-09-25', null, false);
    $html = LeaveActions::renderCompOffSources($this->asha, $this->co->id)->toHtml();
    expect($html)->toContain('Worked on Sun, 20 Sep 2026 · 1 day · use by 20 Oct 2026')->toContain('Credited on Mon, 21 Sep 2026 · 0.5 days')
        ->not->toContain('13 Sep')->not->toContain('30 Aug')->not->toContain('06 Sep')
        ->and(LeaveActions::isCompOff($this->co->id))->toBeTrue()->and(LeaveActions::isCompOff($this->el->id))->toBeFalse()
        ->and(LeaveActions::renderCompOffSources($this->ravi, $this->co->id)->toHtml())->toContain('No comp-off is available');

    // The form shows the panel only for a comp-off type.
    rq4As($this->asha->user);
    Livewire::test(Home::class)->mountAction('requestLeave')->fillForm(['leave_type_id' => $this->co->id])->assertMountedActionModalSee(['Comp-off from', 'Worked on Sun, 20 Sep 2026']);
    Livewire::test(Home::class)->mountAction('requestLeave')->fillForm(['leave_type_id' => $this->el->id])->assertMountedActionModalDontSee('Comp-off from');
});

// ---- team-away panel ---------------------------------------------------------------------------------------------------

it('lists who else in the team is away on the chosen dates by the leave calendar\'s rules', function () {
    rq4Leave($this->ravi, $this->el, '2026-09-23', '2026-09-24');
    rq4Leave($this->kiran, $this->el, '2026-09-23', '2026-09-23'); // not in Asha's team
    rq4Leave($this->asha, $this->sl, '2026-09-21', '2026-09-21');  // her own leave is not "someone else"
    $team = app(TeamAway::class);
    expect($team->team($this->asha))->toBe([$this->ravi->id])
        ->and(collect($team->team($this->mira))->sort()->values()->all())->toBe(collect([$this->asha->id, $this->ravi->id])->sort()->values()->all());

    // Mira (may open the leave calendar): Ravi, in full.
    rq4As($this->mira->user);
    $rows = $team->for($this->mira->user, $this->asha, '2026-09-23', '2026-09-23');
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['employee_id' => $this->ravi->id, 'label' => 'Earned leave', 'pending' => false])
        ->and($team->for($this->mira->user, $this->asha, '2026-09-28', '2026-09-28'))->toBe([]);
    expect(LeaveActions::renderTeamAway($this->asha, '2026-09-23', '2026-09-23')->toHtml())->toContain($this->ravi->display_name)->toContain('Earned leave')->toContain('23 Sep – 24 Sep');

    // HR scoped to another company sees nobody from this team; the same HR scoped to the team's company sees Ravi.
    $hr = tenantUser($this->tenant, ['leave.view']);
    $elsewhere = Company::factory()->create();
    app(AccessScopes::class)->assign($hr, ['company' => [$elsewhere->id]]);
    rq4As($hr);
    expect($team->for($hr, $this->asha, '2026-09-23', '2026-09-23'))->toBe([]);
    app(AccessScopes::class)->assign($hr, ['company' => [$this->asha->currentPosition->company_id]]);
    app(AccessScopes::class)->forget();
    expect(collect($team->for($hr, $this->asha, '2026-09-23', '2026-09-23'))->pluck('employee_id')->all())->toBe([$this->ravi->id]);

    // Ravi and Asha cannot open the leave calendar, so their form has no team-away panel and the read model lists nothing.
    rq4As($this->asha->user);
    expect(TeamAway::mayView($this->asha->user))->toBeFalse()->and($team->for($this->asha->user, $this->asha, '2026-09-23', '2026-09-23'))->toBe([]);
    Livewire::test(Home::class)->mountAction('requestLeave')->fillForm(['leave_type_id' => $this->el->id, 'from_date' => '2026-09-23', 'to_date' => '2026-09-23'])
        ->assertMountedActionModalDontSee('Who else is away');
    rq4As($this->mira->user);
    Livewire::test(Home::class)->mountAction('requestLeave')->fillForm(['leave_type_id' => $this->el->id, 'from_date' => '2026-09-23', 'to_date' => '2026-09-23'])
        ->assertMountedActionModalSee(['Who else is away', '23 Sep – 24 Sep']);
});

it('shows a private leave in the team-away panel in full to the people who may open the calendar, and as "Away" otherwise', function () {
    $ravis = rq4Leave($this->ravi, $this->sl, '2026-09-23', '2026-09-23', true);
    rq4As($this->mira->user);
    expect(app(TeamAway::class)->for($this->mira->user, $this->asha, '2026-09-23', '2026-09-23')[0]['label'])->toBe('Sick leave');
    // The label comes from LeavePrivacy: anyone else reads "Away".
    expect($this->privacy->typeLabel($ravis, $this->asha->user))->toBe('Away');
});

// ---- encashment form ---------------------------------------------------------------------------------------------------

it('shows the balance available and the encashment rule\'s limits before asking', function () {
    $adjust = app(LeaveAdjustments::class);
    $a = $adjust->encashmentAllowance($this->asha, $this->el);
    expect($a)->toMatchArray(['allowed' => true, 'available' => 12.0, 'year_limit' => 10.0, 'already' => 0.0, 'per_request' => 3.0, 'keep' => 2.0, 'max_now' => 3.0, 'needs_file' => false]);

    $adjust->approveEncashment($adjust->requestEncashment($this->asha, $this->el, 3));
    $adjust->requestEncashment($this->asha, $this->el, 3);
    $adjust->requestEncashment($this->asha, $this->el, 3);
    // 9 asked of 10 a year: one day left; 9 available, 2 to keep.
    expect($adjust->encashmentAllowance($this->asha, $this->el))->toMatchArray(['available' => 9.0, 'already' => 9.0, 'max_now' => 1.0]);

    $html = ListMyLeaveEncashments::renderAllowance($this->asha, $this->el->id)->toHtml();
    expect($html)->toContain('Available leave balance: 9 days.')->toContain('At most 10 days a year; 9 days asked for this year.')
        ->toContain('At most 3 days in one request.')->toContain('Keep at least 2 days after encashing.')->toContain('You can encash up to 1 day now.');
    // A type that cannot be encashed says so; nothing can be asked for.
    expect($adjust->encashmentAllowance($this->asha, $this->sl))->toMatchArray(['allowed' => false, 'reason' => 'Sick leave cannot be encashed.', 'max_now' => 0.0])
        ->and(ListMyLeaveEncashments::renderAllowance($this->asha, $this->sl->id)->toHtml())->toContain('Sick leave cannot be encashed.');

    rq4As($this->asha->user);
    Livewire::test(ListMyLeaveEncashments::class)->mountAction('requestEncashment')->fillForm(['leave_type_id' => $this->el->id])
        ->assertMountedActionModalSee('Available leave balance: 9 days.');
});

it('asks for a supporting file only when the encashment rule says so, stores it safely and serves it under the access rules', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    rq4Policy(['EL' => ['days' => 12, 'encashment_allowed' => true, 'encash_document_required' => true], 'SL' => ['days' => 6]], 'RQ4_FILES');
    $adjust = app(LeaveAdjustments::class);
    $disk = Storage::disk('local');
    rq4As($this->asha->user);

    expect($adjust->encashmentAllowance($this->asha, $this->el)['needs_file'])->toBeTrue()
        ->and(ListMyLeaveEncashments::needsFile($this->asha, $this->el->id))->toBeTrue()
        ->and(fn () => $adjust->requestEncashment($this->asha, $this->el, 1, 'Festival'))->toThrow(RuntimeException::class, 'Attach a supporting file to encash Earned leave.');
    Livewire::test(ListMyLeaveEncashments::class)->callAction('requestEncashment', data: ['leave_type_id' => $this->el->id, 'days' => 1])
        ->assertHasActionErrors(['attachments' => 'required']);

    // Only files from the form's upload area, and only documents.
    $disk->put('elsewhere/x.pdf', "%PDF-1.4\n%test\n");
    expect(fn () => $adjust->requestEncashment($this->asha, $this->el, 1, null, attachments: ['elsewhere/x.pdf']))->toThrow(RuntimeException::class, 'was not found');
    $disk->put('leave-encashments/page.pdf', '<html><script>alert(1)</script></html>');
    expect(fn () => $adjust->requestEncashment($this->asha, $this->el, 1, null, attachments: ['leave-encashments/page.pdf'], names: ['leave-encashments/page.pdf' => 'proof.pdf']))
        ->toThrow(RuntimeException::class, 'not an accepted document type');
    $disk->assertMissing('leave-encashments/page.pdf');
    // A request refused for another reason leaves no upload behind.
    $disk->put('leave-encashments/big.pdf', "%PDF-1.4\n%test\n");
    expect(fn () => $adjust->requestEncashment($this->asha, $this->el, 50, null, attachments: ['leave-encashments/big.pdf']))->toThrow(RuntimeException::class);
    $disk->assertMissing('leave-encashments/big.pdf');

    $disk->put('leave-encashments/upload.pdf', "%PDF-1.4\n%test\n");
    $encashment = $adjust->requestEncashment($this->asha, $this->el, 1, 'Festival', attachments: ['leave-encashments/upload.pdf'], names: ['leave-encashments/upload.pdf' => 'bill.pdf']);
    $path = $encashment->refresh()->attachments[0];
    expect($path)->toStartWith("tenants/{$this->tenant->id}/leave-encashments/{$this->asha->id}/")->toEndWith('.pdf')
        ->and(collect(DataInventory::prefixes($this->tenant)['leave'])->pluck('prefix')->all())->toBe(["tenants/{$this->tenant->id}/leave-encashments"]);
    $disk->assertExists($path);
    $disk->assertMissing('leave-encashments/upload.pdf');

    // The employee and HR open it (audited); a colleague may not.
    $url = ParityFileController::url('leave-encashment', $encashment, 0);
    $this->get($url)->assertOk();
    rq4As($this->hr);
    $this->get($url)->assertOk();
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', 'ATTACHMENT_DOWNLOADED')->count())->toBe(2);
    rq4As($this->ravi->user);
    $this->get($url)->assertForbidden();

    // The registers link the file, and the approver's card says a file came with it.
    rq4As($this->asha->user);
    Livewire::test(ListMyLeaveEncashments::class)->assertSee(basename($path));
    rq4As($this->hr);
    $item = app(ApprovalCenter::class)->find($this->hr, 'leave_encashment:'.$encashment->id);
    expect($item?->facts)->toBe(['1 supporting file attached']);
    expect(LeaveEncashment::query()->count())->toBe(1);
});
