<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\ServiceDeskReminderLog;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Phase 12: the scheduled service-desk run for one tenant (`peopleos:service-desk:process`, hourly,
 * withoutOverlapping, onOneServer).
 *
 * **What it does:**
 * - promotes due catalogue versions;
 * - warns of approaching SLAs;
 * - escalates breaches by level;
 * - reminds about requests waiting too long for the employee or for HR;
 * - auto-closes resolved requests;
 * - escalates overdue grievances;
 * - sends a weekly policy-acknowledgement reminder.
 *
 * **Deterministic and idempotent.** Each notification first claims a service_desk_reminder_logs row
 * keyed by kind, subject and a deterministic bucket (the due time, the escalation level, the
 * waiting-period number, the week). A second run, a retried job or a concurrent worker cannot send it
 * twice. Escalation levels move under the request lock (RequestLifecycle). A reminder never triggers
 * another.
 *
 * **Bounded.** It reads open requests through the (tenant, status, due_at) index in id chunks; it never
 * scans the full ticket table. Each request is processed in its own transaction.
 */
final class ServiceDeskProcessor
{
    public function __construct(
        private readonly SlaClock $sla,
        private readonly RequestLifecycle $lifecycle,
        private readonly CaseAccess $access,
        private readonly AuditRecorder $audit,
        private readonly ServiceCatalogue $catalogue,
    ) {}

    /** @return array<string, int> */
    public function run(): array
    {
        $result = ['promoted' => $this->catalogue->promoteDue(), 'warned' => 0, 'escalated' => 0, 'waiting_employee' => 0, 'waiting_hr' => 0, 'auto_closed' => 0, 'grievances' => 0, 'acknowledgements' => 0];
        $now = Carbon::now();

        // Approaching SLA: due within the next week, not paused, past the policy's warning share.
        Ticket::query()->withoutGlobalScope(AccessScope::class)->with('slaPolicy')->whereIn('status', Ticket::OPEN)->whereNull('sla_paused_at')
            ->where('due_at', '>', $now)->where('due_at', '<=', $now->copy()->addDays(7))->chunkById(200, function ($tickets) use (&$result) {
                foreach ($tickets as $ticket) {
                    $warn = (int) ($ticket->slaPolicy?->warn_percent ?? 75);
                    if (($this->sla->usedPercent($ticket) ?? 0) >= $warn && $this->claim('sla_warning', $ticket, 'due:'.$ticket->due_at->timestamp)) {
                        ServiceDeskEvent::dispatch('servicedesk.ticket.sla_warning', $ticket, $this->lifecycle->context($ticket) + ['due_at' => $ticket->due_at->toDateTimeString()], $this->workers($ticket));
                        $result['warned']++;
                    }
                }
            });

        // Breached: escalate one level per repeat interval, up to the policy's maximum.
        Ticket::query()->withoutGlobalScope(AccessScope::class)->with(['slaPolicy.escalationRole', 'category.escalationRole'])->whereIn('status', Ticket::OPEN)->whereNull('sla_paused_at')
            ->where('due_at', '<', $now)->chunkById(200, function ($tickets) use (&$result, $now) {
                foreach ($tickets as $ticket) {
                    $policy = $ticket->slaPolicy;
                    $repeat = max(1, (int) ($policy?->escalation_repeat_hours ?? 24));
                    $max = max(1, (int) ($policy?->max_escalation_level ?? 1));
                    $level = min($max, 1 + intdiv((int) floor($ticket->due_at->diffInHours($now, true)), $repeat));
                    if ($level <= (int) $ticket->escalation_level || ! $this->claim('escalation', $ticket, 'L'.$level.'@'.$ticket->due_at->timestamp)) {
                        continue;
                    }
                    try {
                        $role = $policy?->escalationRole ?? $ticket->category?->escalationRole;
                        $recipients = array_values(array_unique(array_filter([...($role?->users()->get()->filter(fn (User $u) => $u->isActive() && $this->access->canView($u, $ticket))->pluck('id')->all() ?? []), $ticket->assignee_id])));
                        $this->lifecycle->move($ticket, null, null, AuditAction::RequestEscalated, 'SLA breached', ['escalation_level' => $level, 'escalated_at' => now()], 'system',
                            guard: fn (Ticket $fresh) => $fresh->isOpen() && $fresh->sla_paused_at === null && (int) $fresh->escalation_level < $level ?: throw new ServiceDeskRuleViolation('Already handled.'),
                            notify: ['event' => 'servicedesk.ticket.escalated', 'context' => ['due_at' => $ticket->due_at->toDateTimeString(), 'level' => $level], 'recipients' => $recipients],
                            metadata: ['level' => $level, 'due_at' => $ticket->due_at->toDateTimeString()]);
                        $result['escalated']++;
                    } catch (ServiceDeskRuleViolation) {
                        // moved or escalated by someone else since it was read
                    }
                }
            });

        // Waiting too long for the employee: every N days, at most M reminders.
        $days = max(1, (int) config('peopleos.servicedesk.waiting_employee_reminder_days', 3));
        $maxReminders = max(1, (int) config('peopleos.servicedesk.waiting_employee_max_reminders', 3));
        Ticket::query()->withoutGlobalScope(AccessScope::class)->where('status', 'waiting_employee')->where('status_changed_at', '<=', $now->copy()->subDays($days))
            ->chunkById(200, function ($tickets) use (&$result, $days, $maxReminders, $now) {
                foreach ($tickets as $ticket) {
                    $n = intdiv((int) floor($ticket->status_changed_at->diffInDays($now, true)), $days);
                    if ($n >= 1 && $n <= $maxReminders && $ticket->visible_to_employee && $this->claim('waiting_employee', $ticket, 'n'.$n.'@'.$ticket->status_changed_at->timestamp)) {
                        ServiceDeskEvent::dispatch('servicedesk.reminder.waiting_for_employee', $ticket, $this->lifecycle->context($ticket), array_filter([$this->employeeUserId($ticket)]));
                        $result['waiting_employee']++;
                    }
                }
            });

        // Waiting too long for HR: once per interval.
        $hours = max(1, (int) config('peopleos.servicedesk.waiting_hr_reminder_hours', 24));
        Ticket::query()->withoutGlobalScope(AccessScope::class)->where('status', 'waiting_hr')->where('status_changed_at', '<=', $now->copy()->subHours($hours))
            ->chunkById(200, function ($tickets) use (&$result, $hours, $now) {
                foreach ($tickets as $ticket) {
                    $n = intdiv((int) floor($ticket->status_changed_at->diffInHours($now, true)), $hours);
                    if ($n >= 1 && $this->claim('waiting_hr', $ticket, 'n'.$n.'@'.$ticket->status_changed_at->timestamp)) {
                        ServiceDeskEvent::dispatch('servicedesk.reminder.waiting_for_hr', $ticket, $this->lifecycle->context($ticket), $this->workers($ticket));
                        $result['waiting_hr']++;
                    }
                }
            });

        // Resolved and not reopened: closed automatically.
        Ticket::query()->withoutGlobalScope(AccessScope::class)->where('status', 'resolved')->where('resolved_at', '<', $now->copy()->subDays((int) config('peopleos.servicedesk.auto_close_days', 5)))
            ->chunkById(200, function ($tickets) use (&$result) {
                foreach ($tickets as $ticket) {
                    try {
                        app(ServiceDesk::class)->close($ticket);
                        $result['auto_closed']++;
                    } catch (ServiceDeskRuleViolation) {
                        // reopened since it was read
                    }
                }
            });

        // Grievances past their due date: once a day to the assignee.
        Grievance::query()->whereIn('status', Grievance::OPEN)->whereDate('due_on', '<', $now->toDateString())->chunkById(200, function ($cases) use (&$result, $now) {
            foreach ($cases as $case) {
                if ($this->claim('grievance_overdue', $case, 'd'.$now->toDateString())) {
                    $this->audit->record(AuditAction::Escalated, 'grievance', $case, [], 'Past due date', metadata: ['due_on' => $case->due_on->toDateString()]);
                    ServiceDeskEvent::dispatch('grievance.escalated', $case, ['number' => $case->number, 'due_on' => $case->due_on->toDateString()], array_filter([$case->assignee_id]));
                    $result['grievances']++;
                }
            }
        });

        $result['acknowledgements'] = $this->acknowledgementReminders();

        return $result;
    }

    /** Weekly: remind employees of policies they have not acknowledged (one sweep per tenant per week). */
    private function acknowledgementReminders(): int
    {
        $week = Carbon::now()->format('o-\WW');
        $days = (int) config('peopleos.servicedesk.policy_acknowledgement_reminder_days', 7);
        if (! app(KnowledgeBase::class)->hasAcknowledgementsDue($days) || ! $this->claimRaw('kb_ack_sweep', 'Tenant', 0, $week)) {
            return 0;
        }

        return app(KnowledgeBase::class)->remindPendingAcknowledgements($days, fn (Employee $e, int $articleId) => $this->claimRaw('kb_ack', 'Employee', $e->id, $week.':'.$articleId));
    }

    /** Claim a reminder slot; false if it was already sent (unique key — safe across concurrent runs). */
    public function claim(string $reminder, Model $subject, string $bucket): bool
    {
        return $this->claimRaw($reminder, class_basename($subject), (int) $subject->getKey(), $bucket);
    }

    private function claimRaw(string $reminder, string $type, int $id, string $bucket): bool
    {
        if (ServiceDeskReminderLog::query()->where('reminder', $reminder)->where('subject_type', $type)->where('subject_id', $id)->where('bucket', $bucket)->exists()) {
            return false;
        }
        try {
            ServiceDeskReminderLog::query()->create(['reminder' => $reminder, 'subject_type' => $type, 'subject_id' => $id, 'bucket' => $bucket]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return list<int> the assignee, else active team members who can work the case */
    private function workers(Ticket $ticket): array
    {
        if ($ticket->assignee_id) {
            return [(int) $ticket->assignee_id];
        }

        return $ticket->loaded('team')?->users()->get()->filter(fn (User $u) => $this->access->eligibleAgent($u, $ticket))->take(25)->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
    }

    private function employeeUserId(Ticket $ticket): ?int
    {
        $id = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->employee_id)->value('user_id');

        return $id === null ? null : (int) $id;
    }
}
