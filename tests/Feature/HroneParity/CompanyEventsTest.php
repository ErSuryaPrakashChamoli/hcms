<?php

use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventAttendance;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Models\CompanyEventSession;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Engagement\Support\EventInvitationTaskSource;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Department;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Models\WallPost;
use App\Domain\Recognition\Services\SocialWall;
use App\Filament\Pages\EngageDesk;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyEvents;
use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Resources\CompanyEvents\Pages\CreateCompanyEvent;
use App\Filament\Resources\CompanyEvents\Pages\ListCompanyEvents;
use App\Filament\Resources\CompanyEvents\Pages\ViewCompanyEvent;
use App\Filament\Resources\CompanyEvents\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\CompanyEvents\RelationManagers\SessionsRelationManager;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Engagement/EngagementTestHelpers.php';

/* HROne parity GO-15 — Engage → Events ("Add event"): company events with sessions, an audience, invitations (accept /
 * acknowledge, decline, nominate a colleague), seats, attendance per session and its CSV, cancellation that tells the
 * invitees, the My tasks item, the Home card, the wall post, the employee's My events page and the HR register. */

const GO15_STAFF = ['engagement.participate', 'communication.view', 'wall.view'];

/** A draft town hall for the Sales department (online, 10 Nov 2026 10:00 India time, 90 minutes). */
function go15Event(array $overrides = []): CompanyEvent
{
    return app(CompanyEvents::class)->create($overrides + [
        'title' => 'Town hall', 'event_type' => 'town_hall', 'mode' => 'online', 'meeting_url' => 'https://meet.example.com/town-hall',
        'start_date' => '2026-11-10', 'start_time' => '10:00', 'duration' => '01:30', 'timezone' => 'Asia/Kolkata',
        'audience_criteria' => ['department_ids' => [test()->sales->id]],
    ], test()->hr);
}

function go15Invitation(CompanyEvent $event, Employee $employee): CompanyEventInvitation
{
    return CompanyEventInvitation::query()->withoutGlobalScopes()->where('event_id', $event->id)->where('employee_id', $employee->id)->firstOrFail();
}

/** @return list<string> the step labels of a page's form wizard */
function go15Steps($page): array
{
    $wizard = $page->instance()->form->getComponents()[0];
    expect($wizard)->toBeInstanceOf(Wizard::class);

    return array_values(array_map(fn (Step $step) => $step->getLabel(), $wizard->getChildSchema()->getComponents()));
}

beforeEach(function () {
    $this->travelTo('2026-11-01 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['engagement.events', 'engagement.view', 'employee.view', 'wall.view']);
    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->ops = Department::factory()->create(['name' => 'Operations']);
    $this->asha = engagementStaff($this->sales, null, GO15_STAFF);
    $this->ravi = engagementStaff($this->sales, null, GO15_STAFF);
    $this->omar = engagementStaff($this->ops, null, GO15_STAFF);
    $this->events = app(CompanyEvents::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('creates a draft with its session, then publishes it to the audience only, telling each invitee', function () {
    $event = go15Event(['seats' => 50, 'description' => 'Quarterly update']);

    expect($event->status)->toBe('draft')
        ->and($event->sessions()->count())->toBe(1)
        ->and($event->sessions()->first()->starts_at->toDateTimeString())->toBe('2026-11-10 04:30:00') // 10:00 India time in UTC
        ->and($event->sessions()->first()->duration_minutes)->toBe(90)
        ->and($event->ends_at->toDateTimeString())->toBe('2026-11-10 06:00:00')
        ->and(CompanyEventInvitation::query()->withoutGlobalScopes()->count())->toBe(0);

    $published = $this->events->publish($event, $this->hr);

    $invited = CompanyEventInvitation::query()->withoutGlobalScopes()->where('event_id', $event->id)->pluck('employee_id')->sort()->values()->all();
    expect($published->status)->toBe('published')
        ->and($published->invited_count)->toBe(2)
        ->and($invited)->toBe(collect([$this->asha->id, $this->ravi->id])->sort()->values()->all())
        ->and(go15Invitation($event, $this->asha)->status)->toBe('invited')
        ->and(NotificationDelivery::query()->where('event', 'event.published')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->asha->user_id, $this->ravi->user_id])->sort()->values()->all())
        ->and(NotificationDelivery::query()->where('event', 'event.published')->value('subject'))->toStartWith('You are invited: Town hall (Tue 10 Nov 2026, 10:00–11:30');

    // Fixed from now on: the schedule and audience cannot change, a second publish is refused.
    expect(fn () => $this->events->publish($event->fresh(), $this->hr))->toThrow(EngagementRuleViolation::class, 'Only a draft event is published.')
        ->and(fn () => $published->fresh()->update(['timezone' => 'UTC']))->toThrow(RuntimeException::class, 'keeps its schedule and audience');
});

it('refuses an audience that reaches nobody, an event that is over, and incomplete places', function () {
    $nobody = go15Event(['audience_criteria' => ['department_ids' => [Department::factory()->create()->id]]]);
    expect(fn () => $this->events->publish($nobody, $this->hr))->toThrow(EngagementRuleViolation::class, 'reaches nobody')
        ->and($nobody->fresh()->status)->toBe('draft');

    $past = go15Event(['start_date' => '2026-10-20']);
    expect(fn () => $this->events->publish($past, $this->hr))->toThrow(EngagementRuleViolation::class, 'Every session of this event is over')
        ->and(fn () => go15Event(['mode' => 'online', 'meeting_url' => null]))->toThrow(EngagementRuleViolation::class, 'needs a meeting URL')
        ->and(fn () => go15Event(['mode' => 'offline', 'meeting_url' => null]))->toThrow(EngagementRuleViolation::class, 'needs a location or a venue')
        ->and(fn () => go15Event(['meeting_url' => 'javascript:alert(1)']))->toThrow(EngagementRuleViolation::class, 'web address')
        ->and(fn () => go15Event(['event_type' => 'not_a_type']))->toThrow(EngagementRuleViolation::class, 'from the list');

    $hybrid = go15Event(['mode' => 'hybrid', 'venue' => 'Board room']);
    expect($hybrid->placeLabel())->toBe('Board room and online')->and($hybrid->meeting_url)->toBe('https://meet.example.com/town-hall');
});

it('generates the sessions of a repeating event in its time zone, across a daylight-saving change, within the limit', function () {
    $weekly = go15Event(['start_date' => '2026-11-02', 'repeat' => 'weekly', 'repeat_every' => 1, 'repeat_weekdays' => [1, 3], 'repeat_until' => '2026-11-18']);
    expect($weekly->sessions()->get()->map(fn ($s) => $s->starts_at->setTimezone('Asia/Kolkata')->format('D d H:i'))->all())
        ->toBe(['Mon 02 10:00', 'Wed 04 10:00', 'Mon 09 10:00', 'Wed 11 10:00', 'Mon 16 10:00', 'Wed 18 10:00'])
        ->and($weekly->recurrence)->toMatchArray(['repeat' => 'weekly', 'every' => 1, 'weekdays' => [1, 3], 'until' => '2026-11-18']);

    // New York leaves daylight saving on 1 Nov 2026: the local time stays 09:00, the UTC time moves.
    $daily = go15Event(['start_date' => '2026-10-31', 'start_time' => '09:00', 'timezone' => 'America/New_York', 'repeat' => 'daily', 'repeat_every' => 1, 'repeat_until' => '2026-11-02']);
    expect($daily->sessions()->get()->map(fn ($s) => $s->starts_at->format('Y-m-d H:i'))->all())->toBe(['2026-10-31 13:00', '2026-11-01 14:00', '2026-11-02 14:00']);

    $everyOther = CompanyEvents::plan(['start_date' => '2026-11-01', 'start_time' => '08:00', 'duration' => 30, 'timezone' => 'UTC', 'repeat' => 'daily', 'repeat_every' => 2, 'repeat_until' => '2026-11-07']);
    expect(array_map(fn ($s) => $s->format('d'), $everyOther['starts']))->toBe(['01', '03', '05', '07'])
        ->and(fn () => go15Event(['repeat' => 'daily', 'repeat_until' => '2027-03-01']))->toThrow(EngagementRuleViolation::class, 'at most 60 sessions')
        ->and(fn () => go15Event(['repeat' => 'daily', 'repeat_until' => '2026-11-01']))->toThrow(EngagementRuleViolation::class, 'end date on or after')
        ->and(fn () => go15Event(['duration' => '00:00']))->toThrow(EngagementRuleViolation::class, 'duration');
});

it('lets invitees accept or decline, keeps a mandatory event acknowledged, and only the invitee answers', function () {
    $event = $this->events->publish(go15Event(), $this->hr);
    $asha = go15Invitation($event, $this->asha);

    expect(fn () => $this->events->respond($asha, 'accepted', $this->ravi->user))->toThrow(EngagementRuleViolation::class, 'Only the person invited')
        ->and(fn () => $this->events->respond($asha, 'maybe', $this->asha->user))->toThrow(EngagementRuleViolation::class, 'Accept or decline');

    $answered = $this->events->respond($asha, 'accepted', $this->asha->user);
    expect($answered->status)->toBe('accepted')->and($answered->responded_at)->not->toBeNull();
    expect($this->events->respond($asha->fresh(), 'declined', $this->asha->user)->status)->toBe('declined')
        ->and($this->events->stats($event))->toMatchArray(['invited' => 2, 'pending' => 1, 'accepted' => 0, 'declined' => 1]);

    $mandatory = $this->events->publish(go15Event(['title' => 'Safety briefing', 'is_mandatory' => true, 'seats' => 1, 'allow_nomination' => true]), $this->hr);
    expect($mandatory->seats)->toBeNull()->and($mandatory->allow_nomination)->toBeFalse()
        ->and(fn () => $this->events->respond(go15Invitation($mandatory, $this->ravi), 'declined', $this->ravi->user))->toThrow(EngagementRuleViolation::class, 'mandatory')
        ->and($this->events->respond(go15Invitation($mandatory, $this->ravi), 'accepted', $this->ravi->user)->status)->toBe('accepted')
        ->and($this->events->respond(go15Invitation($mandatory, $this->asha), 'accepted', $this->asha->user)->status)->toBe('accepted'); // seats never limit a mandatory event

    // Answers stop when the event is over.
    $this->travelTo('2026-11-10 06:30:00');
    expect(fn () => $this->events->respond(go15Invitation($event, $this->ravi), 'accepted', $this->ravi->user))->toThrow(EngagementRuleViolation::class, 'This event is over.');
});

it('stops acceptances when the seats are taken and frees a seat when someone declines', function () {
    $event = $this->events->publish(go15Event(['seats' => 1]), $this->hr);

    $this->events->respond(go15Invitation($event, $this->asha), 'accepted', $this->asha->user);
    expect(fn () => $this->events->respond(go15Invitation($event, $this->ravi), 'accepted', $this->ravi->user))->toThrow(EngagementRuleViolation::class, 'The event is full: all 1 seats are taken.')
        ->and($this->events->stats($event)['seats_left'])->toBe(0);

    $this->events->respond(go15Invitation($event, $this->asha), 'declined', $this->asha->user);
    expect($this->events->respond(go15Invitation($event, $this->ravi), 'accepted', $this->ravi->user)->status)->toBe('accepted');

    // A published event's seats never drop below the acceptances made.
    expect(fn () => $this->events->update($event->fresh(), ['title' => 'Town hall', 'mode' => 'online', 'meeting_url' => 'https://meet.example.com/x', 'seats' => 0], $this->hr))
        ->toThrow(EngagementRuleViolation::class, 'at least 1')
        ->and($this->events->update($event->fresh(), ['title' => 'Town hall (Q4)', 'mode' => 'online', 'meeting_url' => 'https://meet.example.com/x', 'seats' => 3], $this->hr)->title)->toBe('Town hall (Q4)')
        ->and($event->fresh()->starts_at->toDateTimeString())->toBe('2026-11-10 04:30:00');
});

it('lets an invitee nominate a colleague when the event allows it; the nominee answers and is told', function () {
    $closed = $this->events->publish(go15Event(), $this->hr);
    expect(fn () => $this->events->nominate($closed, $this->omar, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'does not take nominations');

    $event = $this->events->publish(go15Event(['title' => 'Hackathon', 'allow_nomination' => true, 'seats' => 5]), $this->hr);
    expect(fn () => $this->events->nominate($event, $this->asha, $this->omar->user))->toThrow(EngagementRuleViolation::class, 'Only invitees nominate')
        ->and(fn () => $this->events->nominate($event, $this->asha, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'invited already')
        ->and(fn () => $this->events->nominate($event, $this->ravi, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'That colleague is invited already.');

    $nomination = $this->events->nominate($event, $this->omar, $this->asha->user);
    expect($nomination->status)->toBe('nominated')
        ->and($nomination->source)->toBe('nomination')
        ->and($nomination->nominated_by)->toBe($this->asha->id)
        ->and($event->fresh()->invited_count)->toBe(3)
        ->and(NotificationDelivery::query()->where('event', 'event.nominated')->where('user_id', $this->omar->user_id)->value('subject'))->toContain('nominated you for Hackathon');

    expect($this->events->respond($nomination, 'accepted', $this->omar->user)->status)->toBe('accepted')
        ->and($this->events->stats($event))->toMatchArray(['invited' => 3, 'accepted' => 1, 'seats_left' => 4]);
});

it('marks attendance per session with the exact signature, re-marks it, and downloads the sheet', function () {
    $event = $this->events->publish(go15Event(['start_date' => '2026-11-09', 'repeat' => 'daily', 'repeat_until' => '2026-11-10']), $this->hr);
    [$first, $second] = $event->sessions()->get()->all();
    expect(fn () => $this->events->markAttendance($first, $this->asha, true, $this->hr))->toThrow(EngagementRuleViolation::class, 'has not started yet');

    $this->travelTo('2026-11-09 05:00:00');
    $row = $this->events->markAttendance($first, $this->asha, true, $this->hr);
    expect($row)->toBeInstanceOf(CompanyEventAttendance::class)
        ->and($row->attended)->toBeTrue()->and($row->marked_by)->toBe($this->hr->id)->and($row->marked_at)->not->toBeNull()
        ->and(fn () => $this->events->markAttendance($first, $this->omar, true, $this->hr))->toThrow(EngagementRuleViolation::class, 'is not invited to this event')
        ->and(fn () => $this->events->markAttendance($first, $this->ravi, true, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'cannot mark attendance')
        ->and(fn () => $this->events->markAttendance($second, $this->asha, true, $this->hr))->toThrow(EngagementRuleViolation::class, 'has not started yet');

    $this->events->markAttendance($first, $this->ravi, false, $this->hr);
    $again = $this->events->markAttendance($first, $this->asha, false, $this->hr);
    expect($again->id)->toBe($row->id)->and($again->fresh()->attended)->toBeFalse()
        ->and(CompanyEventAttendance::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and($this->events->attendedCount($first))->toBe(0);
    $this->events->markAttendance($first, $this->asha, true, $this->hr);

    // The host marks attendance on their own event without engagement.events.
    $hosted = $this->events->publish(go15Event(['title' => 'Book club', 'host_employee_id' => $this->omar->id, 'start_date' => '2026-11-09']), $this->hr);
    expect($this->events->markAttendance($hosted->sessions()->first(), $this->asha, true, $this->omar->user)->attended)->toBeTrue();

    $csv = $this->events->attendanceCsv($event, $this->hr);
    $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    expect($lines[0])->toBe(['Employee code', 'Employee', 'Department', 'Invitation', 'How invited', 'Nominated by', 'Answered on', 'Session 1 · 2026-11-09 10:00', 'Session 2 · 2026-11-10 10:00'])
        ->and(collect($lines)->firstWhere(0, $this->asha->employee_code))->toMatchArray([2 => 'Sales', 3 => 'Invited', 7 => 'Attended', 8 => ''])
        ->and(collect($lines)->firstWhere(0, $this->ravi->employee_code)[7])->toBe('Absent')
        ->and(str_getcsv(explode("\n", $this->events->attendanceCsv($event, $this->hr, $second))[0]))->toHaveCount(8)
        ->and(fn () => $this->events->attendanceCsv($event, $this->asha->user))->toThrow(EngagementRuleViolation::class);
});

it('cancels with a reason, tells everyone invited who has not declined, keeps the data and stops answers', function () {
    $event = $this->events->publish(go15Event(), $this->hr);
    $this->events->respond(go15Invitation($event, $this->ravi), 'declined', $this->ravi->user);
    expect(fn () => $this->events->cancel($event, '  ', $this->hr))->toThrow(EngagementRuleViolation::class, 'needs a reason');

    $cancelled = $this->events->cancel($event, 'Venue unavailable', $this->hr);

    expect($cancelled->status)->toBe('cancelled')->and($cancelled->cancel_reason)->toBe('Venue unavailable')->and($cancelled->cancelled_by)->toBe($this->hr->id)
        ->and(NotificationDelivery::query()->where('event', 'event.cancelled')->pluck('user_id')->all())->toBe([$this->asha->user_id])
        ->and(NotificationDelivery::query()->where('event', 'event.cancelled')->value('subject'))->toBe('Cancelled: Town hall — Venue unavailable')
        ->and(CompanyEventInvitation::query()->withoutGlobalScopes()->where('event_id', $event->id)->count())->toBe(2)
        ->and(CompanyEventSession::query()->where('event_id', $event->id)->count())->toBe(1)
        ->and(fn () => $this->events->respond(go15Invitation($event, $this->asha), 'accepted', $this->asha->user))->toThrow(EngagementRuleViolation::class, 'This event was cancelled.')
        ->and(fn () => $this->events->cancel($cancelled, 'Again', $this->hr))->toThrow(EngagementRuleViolation::class, 'Only a draft or published event')
        ->and(fn () => $cancelled->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('completes an event once its last session has started', function () {
    $event = $this->events->publish(go15Event(), $this->hr);
    expect(fn () => $this->events->complete($event, $this->hr))->toThrow(EngagementRuleViolation::class, 'not over yet');
    $this->travelTo('2026-11-10 05:00:00');
    expect($this->events->complete($event, $this->hr)->status)->toBe('completed')
        ->and($this->events->markAttendance($event->sessions()->first(), $this->asha, true, $this->hr)->attended)->toBeTrue();
});

it('gates every HR operation on engagement.events and lets invitees and the host open their own event', function () {
    $clerk = tenantUser($this->tenant, ['engagement.manage', 'communication.manage']);
    expect(fn () => $this->events->create(['title' => 'X'], $clerk))->toThrow(EngagementRuleViolation::class, 'This needs engagement.events');
    $event = go15Event(['host_employee_id' => $this->omar->id]);
    expect(fn () => $this->events->publish($event, $clerk))->toThrow(EngagementRuleViolation::class, 'engagement.events')
        ->and(fn () => $this->events->cancel($event, 'No', $clerk))->toThrow(EngagementRuleViolation::class, 'engagement.events');

    // Draft: only HR and the host. Published: invitees too, never anyone else.
    expect(Gate::forUser($this->asha->user)->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser($this->omar->user)->allows('view', $event))->toBeTrue();
    $this->events->publish($event, $this->hr);
    $outsider = engagementStaff($this->ops, null, GO15_STAFF);
    expect(Gate::forUser($this->asha->user)->allows('view', $event->fresh()))->toBeTrue()
        ->and(Gate::forUser($outsider->user)->allows('view', $event->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->asha->user)->allows('viewAny', CompanyEvent::class))->toBeFalse()
        ->and(Gate::forUser($this->hr)->allows('update', $event->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->hr)->allows('delete', $event->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->hr)->allows('create', CompanyEventInvitation::class))->toBeFalse();

    $this->actingAs($this->asha->user);
    expect(CompanyEventResource::canAccess())->toBeFalse();
    $this->get(CompanyEventResource::getUrl('index'))->assertForbidden();
    $this->get(CompanyEventResource::getUrl('create'))->assertForbidden();

    // The permission is declared and given to the HR roles (sync note: peopleos:sync-permissions).
    expect(collect(config('peopleos.permissions'))->flatMap(fn ($keys) => array_keys($keys)))->toContain('engagement.events')
        ->and(config('peopleos.roles.hr-manager.permissions'))->toContain('engagement.events')
        ->and(config('peopleos.roles.tenant-hr-admin.permissions'))->toContain('engagement.*')
        ->and(config('peopleos.roles.employee.permissions'))->not->toContain('engagement.events');
});

it('keeps events, invitations and attendance inside their tenant', function () {
    $event = $this->events->publish(go15Event(), $this->hr);
    $this->travelTo('2026-11-10 05:00:00');
    $this->events->markAttendance($event->sessions()->first(), $this->asha, true, $this->hr);

    $other = provisionTenant('Globex');
    actAsTenant($other);
    $otherHr = tenantUser($other, ['engagement.events']);
    expect(CompanyEvent::query()->count())->toBe(0)
        ->and(CompanyEventSession::query()->count())->toBe(0)
        ->and(CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(0)
        ->and(CompanyEventAttendance::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(0)
        ->and(fn () => $this->events->publish($event, $otherHr))->toThrow(ModelNotFoundException::class);

    $this->actingAs($otherHr);
    $this->get(CompanyEventResource::getUrl('view', ['record' => $event->id]))->assertNotFound();
    actAsTenant($this->tenant);
});

it('puts unanswered invitations in My tasks until answered, and raises pre- and post-event feedback', function () {
    $pre = FeedbackTemplate::create(['code' => 'EVPRE', 'name' => 'Before the event', 'process' => 'event_pre', 'questions' => [['title' => 'What do you expect?', 'type' => 'text']]]);
    $post = FeedbackTemplate::create(['code' => 'EVPOST', 'name' => 'After the event', 'process' => 'event_post', 'questions' => [['title' => 'How was it?', 'type' => 'rating']]]);
    $event = $this->events->publish(go15Event(['pre_feedback_template_id' => $pre->id, 'post_feedback_template_id' => $post->id]), $this->hr);

    $tasks = app(ExperienceTasks::class)->for($this->asha->user, $this->asha)->where('kind', 'event_invitation')->values();
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->title)->toBe('Respond to the invitation: Town hall')
        ->and($tasks[0]->dueAt->toDateTimeString())->toBe('2026-11-10 04:30:00')
        ->and($tasks[0]->url)->toBe(MyEvents::getUrl())
        ->and(config('peopleos.experience.task_sources'))->toContain(EventInvitationTaskSource::class);

    $this->events->respond(go15Invitation($event, $this->asha), 'accepted', $this->asha->user);
    expect(app(EventInvitationTaskSource::class)->tasksFor($this->asha->user, $this->asha))->toHaveCount(0)
        ->and(FeedbackRequest::query()->withoutGlobalScopes()->where('process', 'event_pre')->where('respondent_user_id', $this->asha->user_id)->value('title'))->toBe('Pre-event feedback: Town hall');

    $this->travelTo('2026-11-10 05:00:00');
    $this->events->markAttendance($event->sessions()->first(), $this->asha, true, $this->hr);
    $this->events->markAttendance($event->sessions()->first(), $this->asha, true, $this->hr);
    expect(FeedbackRequest::query()->withoutGlobalScopes()->where('process', 'event_post')->where('respondent_user_id', $this->asha->user_id)->count())->toBe(1)
        ->and(config('peopleos.feedback.processes'))->toHaveKeys(['event_pre', 'event_post']);

    // A cancelled event takes its unanswered feedback with it.
    $other = $this->events->publish(go15Event(['title' => 'Offsite', 'pre_feedback_template_id' => $pre->id]), $this->hr);
    $this->events->respond(go15Invitation($other, $this->ravi), 'accepted', $this->ravi->user);
    $this->events->cancel($other, 'Postponed', $this->hr);
    expect(FeedbackRequest::query()->withoutGlobalScopes()->where('process', 'event_pre')->where('respondent_user_id', $this->ravi->user_id)->value('status'))->toBe('cancelled');
});

it('posts a published event on the wall for its invitees only, and hides it when cancelled', function () {
    app(SettingsRepository::class)->set('engage.wall.enabled', true, 'Social wall');
    $event = $this->events->publish(go15Event(), $this->hr);
    $post = WallPost::query()->where('type', 'event')->firstOrFail();
    $wall = app(SocialWall::class);

    expect($post->title)->toBe('Town hall')->and($post->subject_id)->toBe($event->id)
        ->and($wall->feed($this->asha->user)->pluck('id')->all())->toContain($post->id)
        ->and($wall->feed($this->omar->user)->pluck('id')->all())->not->toContain($post->id);

    $this->events->cancel($event, 'Postponed', $this->hr);
    expect($post->fresh()->status)->toBe('hidden')->and($wall->feed($this->asha->user)->pluck('id')->all())->not->toContain($post->id);
});

it('shows Upcoming events on Home and answers from it; My events lists, details, accepts, declines and nominates', function () {
    $event = $this->events->publish(go15Event(['allow_nomination' => true, 'seats' => 10]), $this->hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->asha->user);

    expect(app(HomeComposer::class)->for($this->asha->user)['events']['rows'])->toHaveCount(1)
        ->and(HomeComposer::blockOptions())->toHaveKey('events');
    Livewire::test(Home::class)->assertOk()->assertSee('Upcoming events')->assertSee('Town hall')
        ->call('eventRespond', go15Invitation($event, $this->asha)->id, 'accepted');
    expect(go15Invitation($event, $this->asha)->status)->toBe('accepted');
    Livewire::test(Home::class)->call('eventRespond', go15Invitation($event, $this->ravi)->id, 'accepted'); // not Asha's
    expect(go15Invitation($event, $this->ravi)->status)->toBe('invited');

    $this->get(MyEvents::getUrl())->assertOk()->assertSee('My events');
    $invitation = go15Invitation($event, $this->asha);
    Livewire::test(MyEvents::class)->assertCanSeeTableRecords([$invitation])
        ->callTableAction('decline', $invitation)
        ->callTableAction('nominate', $invitation, ['employee_id' => $this->omar->id]);
    expect(go15Invitation($event, $this->asha)->status)->toBe('declined')
        ->and(go15Invitation($event, $this->omar)->status)->toBe('nominated');
    Livewire::test(MyEvents::class)->callTableAction('accept', $invitation);
    expect(go15Invitation($event, $this->asha)->status)->toBe('accepted');

    $this->actingAs($this->ravi->user);
    Livewire::test(MyEvents::class)->assertCanSeeTableRecords([go15Invitation($event, $this->ravi)])->assertCanNotSeeTableRecords([$invitation]);
});

it('runs the HR register: Add event wizard (create and publish), list, view with RSVPs, sessions and attendance', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();

    $page = Livewire::test(CreateCompanyEvent::class);
    expect(go15Steps($page))->toBe(['Basics', 'Schedule', 'Place & material', 'Audience & feedback', 'Review']);
    $page->fillForm([
        'title' => 'Diwali celebration', 'event_type' => 'celebration', 'event_sub_type' => 'festival', 'seats' => 20,
        'start_date' => '2026-11-06', 'start_time' => '16:00', 'duration' => '02:00', 'timezone_country' => 'IN', 'timezone' => 'Asia/Kolkata', 'repeat' => 'none',
        'mode' => 'offline', 'venue' => 'Cafeteria', 'audience_criteria' => ['department_ids' => [$this->sales->id]], 'publish_now' => true,
    ])->call('create')->assertHasNoFormErrors();

    $event = CompanyEvent::query()->where('title', 'Diwali celebration')->firstOrFail();
    expect($event->status)->toBe('published')->and($event->invited_count)->toBe(2)->and($event->venue)->toBe('Cafeteria')
        ->and($event->typeLabel())->toBe('Celebration')->and($event->subTypeLabel())->toBe('Festival');

    $draft = go15Event(['title' => 'Draft only']);
    $this->get(CompanyEventResource::getUrl('index'))->assertOk()->assertSee('Diwali celebration')->assertSee('Add event');
    Livewire::test(ListCompanyEvents::class)->assertCanSeeTableRecords([$event, $draft])
        ->filterTable('status', 'draft')->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([$event])
        ->removeTableFilters()->filterTable('timeline', 'upcoming')->assertCanSeeTableRecords([$event])
        ->callTableAction('publish', $draft);
    expect($draft->fresh()->status)->toBe('published');

    $this->get(CompanyEventResource::getUrl('view', ['record' => $event]))->assertOk()->assertSee('Diwali celebration')->assertSee('RSVPs');
    Livewire::test(InvitationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewCompanyEvent::class])
        ->assertCanSeeTableRecords([go15Invitation($event, $this->asha), go15Invitation($event, $this->ravi)]);

    // Add invitees (pasted codes), then mark attendance for the started session and download it.
    Livewire::test(ViewCompanyEvent::class, ['record' => $event->id])->callAction('addInvitees', ['codes' => $this->omar->employee_code])->assertHasNoActionErrors();
    expect(go15Invitation($event, $this->omar)->source)->toBe('hr');
    $this->travelTo('2026-11-06 11:00:00');
    $session = $event->sessions()->first();
    Livewire::test(SessionsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewCompanyEvent::class])
        ->callTableAction('markAttendance', $session, ['attended' => [$this->asha->id]])->assertHasNoTableActionErrors();
    expect($this->events->attendedCount($session))->toBe(1)
        ->and(CompanyEventAttendance::query()->withoutGlobalScopes()->where('session_id', $session->id)->count())->toBe(3);
    Livewire::test(ViewCompanyEvent::class, ['record' => $event->id])->callAction('attendanceCsv', ['session_id' => null])->assertFileDownloaded('event-'.$event->id.'-attendance.csv');
    Livewire::test(ViewCompanyEvent::class, ['record' => $event->id])->callAction('cancel', ['reason' => 'Rain'])->assertHasNoActionErrors();
    expect($event->fresh()->status)->toBe('cancelled');
});

it('wires the package in: option lists, desk, navigation, data inventory, dataset and attachments', function () {
    expect(app(OptionLists::class)->options('event_types'))->toHaveKey('town_hall')
        ->and(app(OptionLists::class)->options('event_sub_types'))->toHaveKey('webinar')
        ->and(DataInventory::categoryOf('company_events'))->toBe('engagement')
        ->and(DataInventory::categoryOf('company_event_attendances'))->toBe('engagement')
        ->and(collect(DataInventory::prefixes($this->tenant)['engagement'])->pluck('prefix')->all())->toContain("tenants/{$this->tenant->id}/events")
        ->and(WallPost::TYPES)->toHaveKey('event');

    $event = $this->events->publish(go15Event(), $this->hr);
    $this->events->respond(go15Invitation($event, $this->asha), 'accepted', $this->asha->user);
    $this->travelTo('2026-11-10 05:00:00');
    $this->events->markAttendance($event->sessions()->first(), $this->asha, true, $this->hr);
    $rows = collect(app(ReportRunner::class)->execute('event_participants', ['fields' => ['event', 'event_type', 'employee_code', 'invitation', 'sessions', 'sessions_attended', 'attended']], $this->hr)->rows);
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('employee_code', $this->asha->employee_code))->toMatchArray(['event' => 'Town hall', 'event_type' => 'Town hall', 'invitation' => 'accepted', 'sessions' => 1, 'sessions_attended' => 1, 'attended' => 'yes'])
        ->and($rows->firstWhere('employee_code', $this->ravi->employee_code))->toMatchArray(['sessions_attended' => 0, 'attended' => 'no'])
        ->and(fn () => app(ReportRunner::class)->execute('event_participants', [], $this->asha->user))->toThrow(RuntimeException::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->hr);
    expect(EngageDesk::canAccess())->toBeTrue()
        ->and(collect((new EngageDesk)->deskSections())->pluck('label')->all())->toContain('Events')
        ->and(ExperienceNavigation::EVERYDAY['Engagement'])->toContain('CompanyEventResource')
        ->and(ExperienceNavigation::MODULE_AREA['MyEvents'])->toBe('My events');
});
