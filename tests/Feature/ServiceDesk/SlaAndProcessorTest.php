<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\ServiceDesk\Jobs\ProcessServiceDesk;
use App\Domain\ServiceDesk\Models\ServiceDeskReminderLog;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceDeskProcessor;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Domain\ServiceDesk\Support\BusinessHoursCalendar;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ServiceDeskTestHelpers.php';

it('counts business time across nights, weekends, holidays, half days and timezones', function () {
    $calendar = new BusinessHoursCalendar('Asia/Kolkata', [1, 2, 3, 4, 5], '09:00', '18:00', ['2026-09-23' => false, '2026-09-24' => true]);
    // Monday 21 Sep 17:00 IST = 11:30 UTC. One hour left that day, Tuesday 9 h, Wednesday holiday, Thursday half day (4.5 h), Friday.
    $from = CarbonImmutable::parse('2026-09-21 11:30:00', 'UTC');
    expect($calendar->addMinutes($from, 60)->toDateTimeString())->toBe('2026-09-21 12:30:00')       // Mon 18:00 IST
        ->and($calendar->addMinutes($from, 120)->toDateTimeString())->toBe('2026-09-22 04:30:00')     // Tue 10:00 IST
        ->and($calendar->addMinutes($from, 60 * 14)->toDateTimeString())->toBe('2026-09-24 07:30:00') // Thu 13:00 IST (half day ends 13:30)
        ->and($calendar->addMinutes($from, 60 * 15)->toDateTimeString())->toBe('2026-09-25 04:00:00') // Fri 09:30 IST
        ->and($calendar->minutesBetween($from, CarbonImmutable::parse('2026-09-28 03:30:00', 'UTC')))->toBe(60 + 540 + 270 + 540) // to Mon 09:00 IST
        ->and($calendar->minutesBetween($from, $from->subHour()))->toBe(0);
    // Saturday and Sunday never count.
    expect($calendar->addMinutes(CarbonImmutable::parse('2026-09-26 06:00:00', 'UTC'), 30)->toDateTimeString())->toBe('2026-09-28 04:00:00');
});

describe('the scheduled processor', function () {
    beforeEach(function () {
        $this->travelTo('2026-09-21 09:00:00');
        $this->tenant = provisionTenant();
        actAsTenant($this->tenant);
        $this->actingAs(tenantUser($this->tenant, ['*']));
        $this->employee = activeEmployee(null, ['servicedesk.request']);
        $this->agent = sdAgent();
        $this->escalation = sdTeam($lead = sdAgent());
        $this->lead = $lead;
        $this->policy = ServiceSlaPolicy::query()->create(['code' => 'CAL', 'name' => 'Calendar', 'calendar' => 'calendar', 'effective_from' => '2026-01-01', 'warn_percent' => 75,
            'escalation_role_id' => $this->escalation->id, 'escalation_repeat_hours' => 24, 'max_escalation_level' => 2, 'targets' => ['normal' => ['first_response_hours' => 4, 'resolution_hours' => 8]]]);
        $this->service = sdApprovedService('SLA_Q', ['sla_policy_id' => $this->policy->id, 'assignment' => ['role_id' => sdTeam($this->agent)->id]]);
        $this->ticket = app(ServiceRequests::class)->submit($this->service, $this->employee, $this->employee->user);
        $this->processor = app(ServiceDeskProcessor::class);
    });

    it('warns once when the SLA is close, escalates one level per interval up to the maximum, and never repeats', function () {
        expect($this->ticket->due_at->toDateTimeString())->toBe('2026-09-21 17:00:00');
        $this->travelTo('2026-09-21 15:30:00'); // 81% used
        expect($this->processor->run()['warned'])->toBe(1)->and($this->processor->run()['warned'])->toBe(0);

        $this->travelTo('2026-09-21 18:00:00');
        expect($this->processor->run()['escalated'])->toBe(1)->and($this->processor->run()['escalated'])->toBe(0)
            ->and($this->ticket->refresh()->escalation_level)->toBe(1)
            ->and($this->lead->notifications()->count())->toBeGreaterThan(0);
        $this->travelTo('2026-09-22 18:00:00');
        expect($this->processor->run()['escalated'])->toBe(1)->and($this->ticket->refresh()->escalation_level)->toBe(2);
        $this->travelTo('2026-09-24 18:00:00');
        expect($this->processor->run()['escalated'])->toBe(0) // the policy's maximum level
            ->and(AuditEvent::query()->where('action', 'REQUEST_ESCALATED')->count())->toBe(2)
            ->and(ServiceDeskReminderLog::query()->where('reminder', 'escalation')->count())->toBe(2);
    });

    it('pauses while waiting for the employee, reminds a bounded number of times, and auto-closes resolved requests', function () {
        $desk = app(ServiceDesk::class);
        $desk->waitOnEmployee($this->ticket, $this->agent);
        foreach (['2026-09-24 09:00:00', '2026-09-24 10:00:00', '2026-09-27 09:00:00', '2026-09-30 09:00:00', '2026-10-03 09:00:00'] as $at) {
            $this->travelTo($at);
            $this->processor->run();
        }
        expect(ServiceDeskReminderLog::query()->where('reminder', 'waiting_employee')->count())->toBe(3) // every 3 days, at most 3
            ->and(AuditEvent::query()->where('action', 'REQUEST_ESCALATED')->count())->toBe(0); // the SLA was paused

        $desk->comment($this->ticket, $this->employee->user, 'Sent');
        $desk->resolve($this->ticket->refresh(), 'Done', $this->agent);
        $this->travelTo('2026-10-09 09:00:00');
        expect($this->processor->run()['auto_closed'])->toBe(1)->and($this->ticket->refresh()->status)->toBe('closed')
            ->and($this->ticket->transitions()->reorder()->latest('id')->value('via'))->toBe('system');
    });

    it('runs as one tenant-bound, unique job per tenant from the scheduler command', function () {
        Queue::fake();
        $this->artisan('peopleos:service-desk:process', ['--queue' => true])->assertSuccessful();
        Queue::assertPushed(ProcessServiceDesk::class, fn (ProcessServiceDesk $job) => $job->tenantId() === $this->tenant->id);
        $job = new ProcessServiceDesk;
        expect($job)->toBeInstanceOf(TenantAwareJob::class)->and($job->middleware()[0])->toBeInstanceOf(BindTenantContext::class)
            ->and($job->uniqueId())->toBe('service-desk-process-'.$this->tenant->id);
        $this->artisan('peopleos:servicedesk:tick')->assertSuccessful();
    });
});
