<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Contracts\SlaCalendar;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Phase 12: the SLA clocks of a request.
 *
 * **Start:** at submission:
 * - first-response and resolution targets come from the service version's SLA policy (per priority,
 *   business or calendar hours);
 * - else from the legacy ticket category (calendar hours).
 *
 * **Pause, resume and restart:**
 * - In a pause status (configurable; default waiting for employee and awaiting approval) the clock
 *   stops.
 * - On resume, the due times move later by the paused service time.
 * - Reopening restarts the resolution clock.
 *
 * The due times are snapshotted on the request, so a later policy change never rewrites an open case.
 * A workflow approval wait (`awaiting_approval`) is not service time; it is not counted as resolution
 * either.
 */
final class SlaClock
{
    public function __construct(private readonly SlaCalendars $calendars) {}

    public function start(Ticket $ticket, ?ServiceSlaPolicy $policy, ?TicketCategory $category = null): void
    {
        $from = Carbon::instance($ticket->submitted_at ?? now());
        if ($policy !== null) {
            $target = $policy->targetFor((string) $ticket->priority);
            $calendar = $this->calendar($ticket, $policy, $policy->calendar);
            $ticket->forceFill([
                'sla_policy_id' => $policy->id,
                'sla_mode' => $policy->calendar,
                'first_response_due_at' => $target['first_response_hours'] > 0 ? $calendar->addMinutes($from, (int) round($target['first_response_hours'] * 60)) : null,
                'due_at' => $calendar->addMinutes($from, (int) round($target['resolution_hours'] * 60)),
            ]);

            return;
        }
        $category ??= $ticket->loaded('category');
        $hours = $category ? $this->legacyHours($category, (string) $ticket->priority) : (int) config('peopleos.servicedesk.legacy_sla_hours', 48);
        $ticket->forceFill([
            'sla_policy_id' => null,
            'sla_mode' => 'calendar',
            'first_response_due_at' => $category ? $from->copy()->addHours($category->first_response_hours) : null,
            'due_at' => $from->copy()->addHours($hours),
        ]);
    }

    /** Pause / resume / restart / stop for one status move (called under the request lock). */
    public function onTransition(Ticket $ticket, string $from, string $to): void
    {
        $pause = $this->pauseStatuses($ticket);
        $wasPaused = $ticket->sla_paused_at !== null;
        if (in_array($to, Ticket::DONE, true) || $to === 'draft') {
            if ($wasPaused) {
                $this->resume($ticket);
            }

            return;
        }
        if (in_array($from, ['resolved', 'closed'], true)) {
            $this->restart($ticket);

            return;
        }
        if (in_array($to, $pause, true) && ! $wasPaused) {
            $ticket->sla_paused_at = now();
        } elseif (! in_array($to, $pause, true) && $wasPaused) {
            $this->resume($ticket);
        }
    }

    /** Remaining service minutes (negative once breached); null without a due time. */
    public function remainingMinutes(Ticket $ticket): ?int
    {
        if ($ticket->due_at === null) {
            return null;
        }
        $calendar = $this->forTicket($ticket);
        $now = $ticket->sla_paused_at ? Carbon::instance($ticket->sla_paused_at) : now();

        return $now->lessThan($ticket->due_at) ? $calendar->minutesBetween($now, $ticket->due_at) : -$calendar->minutesBetween($ticket->due_at, $now);
    }

    /** Share of the resolution time used (0–100+), counting only unpaused service time. */
    public function usedPercent(Ticket $ticket): ?float
    {
        if ($ticket->due_at === null || $ticket->submitted_at === null) {
            return null;
        }
        $calendar = $this->forTicket($ticket);
        $total = $calendar->minutesBetween($ticket->submitted_at, $ticket->due_at) - (int) $ticket->sla_paused_minutes;
        $now = $ticket->sla_paused_at ? Carbon::instance($ticket->sla_paused_at) : now();
        $used = $calendar->minutesBetween($ticket->submitted_at, $now) - (int) $ticket->sla_paused_minutes;

        return $total > 0 ? round(100 * $used / $total, 1) : 100.0;
    }

    public function forTicket(Ticket $ticket): SlaCalendar
    {
        return $this->calendar($ticket, $ticket->sla_policy_id ? $ticket->slaPolicy()->first() : null, $ticket->sla_mode ?? 'calendar');
    }

    /** @return list<string> */
    public function pauseStatuses(Ticket $ticket): array
    {
        $policy = $ticket->sla_policy_id ? ($ticket->relationLoaded('slaPolicy') ? $ticket->slaPolicy : $ticket->slaPolicy()->first()) : null;

        return $policy?->pauseStatuses() ?? config('peopleos.servicedesk.sla_pause_statuses', []);
    }

    private function resume(Ticket $ticket): void
    {
        $calendar = $this->forTicket($ticket);
        $paused = $calendar->minutesBetween($ticket->sla_paused_at, now());
        $ticket->sla_paused_minutes = (int) $ticket->sla_paused_minutes + $paused;
        if ($paused > 0) {
            $ticket->due_at = $ticket->due_at ? $calendar->addMinutes($ticket->due_at, $paused) : null;
            if ($ticket->first_responded_at === null && $ticket->first_response_due_at !== null) {
                $ticket->first_response_due_at = $calendar->addMinutes($ticket->first_response_due_at, $paused);
            }
        }
        $ticket->sla_paused_at = null;
    }

    private function restart(Ticket $ticket): void
    {
        $calendar = $this->forTicket($ticket);
        $policy = $ticket->sla_policy_id ? $ticket->slaPolicy()->first() : null;
        $minutes = $policy ? (int) round($policy->targetFor((string) $ticket->priority)['resolution_hours'] * 60)
            : 60 * (int) ($ticket->loaded('category')?->sla_hours ?? 48); // legacy reopen: the category's full SLA, as before Phase 12
        $ticket->sla_paused_at = null;
        $ticket->sla_paused_minutes = 0;
        $ticket->submitted_at = $ticket->submitted_at ?? now();
        $ticket->due_at = $calendar->addMinutes(CarbonImmutable::now(), $minutes);
    }

    private function calendar(Ticket $ticket, ?ServiceSlaPolicy $policy, string $mode): SlaCalendar
    {
        $employee = $ticket->employee_id ? Employee::query()->withoutGlobalScope(AccessScope::class)->find($ticket->employee_id) : null;

        return $this->calendars->for($employee, $policy, $mode);
    }

    /** The pre-Phase 12 category SLA: urgent ¼, high ½, low ×2 of the category hours. */
    private function legacyHours(TicketCategory $category, string $priority): int
    {
        return match ($priority) {
            'urgent' => max(4, intdiv($category->sla_hours, 4)), 'high' => max(8, intdiv($category->sla_hours, 2)), 'low' => $category->sla_hours * 2, default => $category->sla_hours
        };
    }
}
