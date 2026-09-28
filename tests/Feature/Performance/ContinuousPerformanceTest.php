<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\Permission;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Models\PerformanceReminderLog;
use App\Domain\Performance\Services\Feedback;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\OneOnOnes;
use App\Domain\Performance\Services\PerformanceCheckIns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.checkins', 'performance.feedback']);
    $this->employee = activeEmployee($this->manager, ['performance.goals', 'performance.checkins', 'performance.feedback']);
    $this->peer = activeEmployee($this->manager);
});

it('records a check-in per cadence period, feeds goal progress and takes the manager response', function () {
    $checkIns = app(PerformanceCheckIns::class);
    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'title' => 'Tickets', 'measure_type' => 'numeric', 'target_value' => 100, 'weight' => 50]);

    expect($checkIns->periodFor('weekly', '2026-09-24')->toDateString())->toBe('2026-09-21')
        ->and($checkIns->periodFor('monthly', '2026-09-24')->toDateString())->toBe('2026-09-01')
        ->and($checkIns->periodFor('biweekly', '2026-09-24')->toDateString())->toBe($checkIns->periodFor('biweekly', '2026-09-24')->toDateString())
        ->and($checkIns->periodFor('biweekly', '2026-09-21')->diffInDays($checkIns->periodFor('biweekly', '2026-10-05')))->toEqual(14.0)
        ->and(fn () => $checkIns->periodFor('daily', '2026-09-24'))->toThrow(RuntimeException::class, 'Unknown check-in cadence');

    $draft = $checkIns->save($this->employee, 'weekly', '2026-09-23', ['went_well' => 'Closed backlog', 'blockers' => 'Access'], false, $this->employee->user);
    $submitted = $checkIns->save($this->employee, 'weekly', '2026-09-25', ['went_well' => 'Closed backlog', 'support_needed' => 'Access to prod', 'goal_progress' => [['goal_id' => $goal->id, 'value' => 40]]], true, $this->employee->user);

    expect($submitted->id)->toBe($draft->id)
        ->and($submitted->status)->toBe('submitted')
        ->and($submitted->manager_id)->toBe($this->manager->id)
        ->and((float) $goal->refresh()->progress)->toBe(40.0)
        ->and($goal->checkIns()->first()->source)->toBe('check_in')
        ->and(fn () => $checkIns->save($this->employee, 'weekly', '2026-09-21', ['went_well' => 'edit'], false, $this->employee->user))->toThrow(RuntimeException::class, 'already submitted')
        ->and(fn () => $checkIns->save($this->peer, 'weekly', '2026-09-21', [], false, $this->employee->user))->toThrow(RuntimeException::class, 'their own check-ins')
        ->and(fn () => $checkIns->save($this->employee, 'monthly', '2026-09-21', ['goal_progress' => [['goal_id' => $goal->id + 999, 'value' => 1]]], false, $this->employee->user))->toThrow(RuntimeException::class, 'own open goals')
        ->and(fn () => $checkIns->respond($submitted, 'Nice', [], $this->peer->user))->toThrow(RuntimeException::class, 'Only a manager');

    $reviewed = $checkIns->respond($submitted, 'Good week', [['item' => 'Request prod access', 'owner' => 'manager']], $this->manager->user);
    expect($reviewed->status)->toBe('reviewed')
        ->and($reviewed->actions)->toHaveCount(1)
        ->and(fn () => $checkIns->respond($reviewed, 'again', [], $this->manager->user))->toThrow(RuntimeException::class, 'already reviewed')
        ->and(fn () => $reviewed->update(['went_well' => 'rewrite']))->toThrow(RuntimeException::class, 'read-only')
        ->and(PerformanceCheckIn::query()->count())->toBe(1);
});

it('keeps one-on-one private notes away from the employee and audits other readers', function () {
    $service = app(OneOnOnes::class);
    $meeting = OneOnOne::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $this->manager->id, 'scheduled_at' => now()->addDay(), 'notes' => 'Shared agenda notes']);

    $service->setPrivateNotes($meeting, 'Considering a stretch assignment', $this->manager->user);
    $raw = DB::table('one_on_ones')->where('id', $meeting->id)->value('private_notes');

    expect($raw)->not->toContain('stretch')
        ->and($meeting->refresh()->toArray())->not->toHaveKey('private_notes')
        ->and($service->privateNotesFor($meeting, $this->manager->user))->toBe('Considering a stretch assignment')
        ->and($service->privateNotesFor($meeting, $this->employee->user))->toBeNull()
        ->and($service->privateNotesFor($meeting, $this->peer->user))->toBeNull()
        ->and(fn () => $service->setPrivateNotes($meeting, 'x', $this->employee->user))->toThrow(RuntimeException::class, 'Only the manager');

    // An employee with the reveal permission still never reads notes about themself.
    $this->employee->user->roles()->first()?->permissions()->syncWithoutDetaching(Permission::query()->where('key', 'performance.private_notes')->pluck('id'));
    expect($service->privateNotesFor($meeting, $this->employee->user->refresh()))->toBeNull();

    $before = AuditEvent::query()->where('action', 'VIEW')->count();
    expect($service->privateNotesFor($meeting, $this->hr))->toBe('Considering a stretch assignment')
        ->and(AuditEvent::query()->where('action', 'VIEW')->count())->toBe($before + 1);
});

it('never exposes the author of anonymous feedback without the audited reveal permission', function () {
    $feedback = app(Feedback::class);
    $entry = $feedback->give($this->employee, $this->peer, 'constructive', 'Share context earlier', 'manager', anonymous: true);
    $loaded = FeedbackEntry::query()->findOrFail($entry->id);

    expect($loaded->toArray())->not->toHaveKey('author_id')
        ->and($loaded->authorLabel())->toBe('Anonymous')
        ->and($feedback->authorFor($loaded, $this->employee->user))->toBeNull()
        ->and($feedback->authorFor($loaded, $this->manager->user))->toBeNull()
        ->and($feedback->authorFor($loaded, $this->peer->user)?->id)->toBe($this->peer->id)
        ->and(fn () => $feedback->authorFor($loaded, $this->hr))->toThrow(RuntimeException::class, 'reason is required')
        ->and($feedback->authorFor($loaded, $this->hr, 'Harassment investigation #12')?->id)->toBe($this->peer->id)
        ->and(AuditEvent::query()->where('action', 'VIEW')->where('reason', 'Harassment investigation #12')->exists())->toBeTrue()
        ->and(fn () => $loaded->update(['is_anonymous' => false]))->toThrow(RuntimeException::class, 'cannot change')
        ->and(fn () => $feedback->give($this->employee, $this->peer, 'praise', 'x', 'everyone'))->toThrow(RuntimeException::class, 'Unknown feedback visibility');

    // The recipient notification does not name the author.
    expect($this->employee->user->notifications()->latest()->first()?->data['title'] ?? '')->not->toContain($this->peer->person->full_name);

    $named = $feedback->give($this->employee, $this->peer, 'praise', 'Great demo', 'private');
    expect(FeedbackEntry::query()->findOrFail($named->id)->toArray())->toHaveKey('author_id');
});

it('sends each performance reminder once per subject per day across tenants', function () {
    $checkIns = app(PerformanceCheckIns::class);
    $checkIns->save($this->employee, 'weekly', '2026-09-14', ['went_well' => 'x'], true, $this->employee->user);
    $this->travelTo('2026-09-25 07:00:00');

    $this->artisan('peopleos:performance:reminders')->assertSuccessful();
    $this->artisan('peopleos:performance:reminders')->assertSuccessful();

    expect(PerformanceReminderLog::query()->where('reminder', 'check_in_response')->count())->toBe(1)
        ->and($this->manager->user->notifications()->where('data->title', 'like', 'Reminder:%')->count())->toBe(1);
});
