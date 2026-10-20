<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12: factual HR service analytics — volume, backlog, SLA and resolution — for one date range.
 *
 * **Scope:** the viewer's organisation scope (the ticket's access scope) and servicedesk.analytics.
 *
 * **Privacy:**
 * - Never employee names or request content.
 * - Restricted (confidential) cases are counted only in the total, and only when at least the minimum
 *   group size.
 * - In every breakdown (service, organisation, team, priority, status), a group with fewer than
 *   `servicedesk.analytics_min_group` distinct employees is suppressed. When only one group would be
 *   suppressed, the next smallest is suppressed too, so the total cannot be used to subtract it back.
 * - The dimensions are fixed and combined only with the date range, so filters cannot be combined to
 *   reconstruct a suppressed group.
 */
final class ServiceDeskAnalytics
{
    public function minGroup(): int
    {
        return max(1, (int) config('peopleos.servicedesk.analytics_min_group', 5));
    }

    /** @return array<string, mixed> */
    public function summary(User $viewer, Carbon|string|null $from = null, Carbon|string|null $to = null): array
    {
        if (! $viewer->hasPermission('servicedesk.analytics')) {
            throw new ServiceDeskRuleViolation('This needs servicedesk.analytics.');
        }
        $from = Carbon::parse($from ?? Carbon::today()->subDays(90))->startOfDay();
        $to = Carbon::parse($to ?? Carbon::today())->endOfDay();
        $min = $this->minGroup();
        $base = fn () => Ticket::query()->where('tickets.status', '!=', 'draft')->whereBetween('tickets.created_at', [$from, $to]);
        $open = fn () => Ticket::query()->whereIn('tickets.status', Ticket::OPEN)->where('tickets.confidentiality', '!=', 'restricted');

        $population = (clone $base())->distinct()->count('tickets.employee_id');
        if ($population < $min) {
            return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'min_group' => $min, 'suppressed' => true, 'note' => "Fewer than {$min} employees raised requests in this period; no figures are shown."];
        }
        $standard = fn () => $base()->where('tickets.confidentiality', '!=', 'restricted');
        $resolved = $standard()->whereNotNull('resolved_at')->get(['submitted_at', 'created_at', 'resolved_at', 'first_responded_at', 'due_at', 'sla_paused_minutes']);
        $restricted = (clone $base())->where('tickets.confidentiality', 'restricted')->count();

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'min_group' => $min, 'suppressed' => false,
            'received' => $standard()->count(),
            'confidential_cases' => $restricted >= $min ? $restricted : ($restricted > 0 ? "fewer than {$min}" : 0),
            'open' => $open()->count(),
            'backlog_overdue' => $open()->whereNull('sla_paused_at')->where('due_at', '<', now())->count(),
            'resolved' => $resolved->count(),
            'average_first_response_hours' => $this->averageHours($resolved->filter(fn ($t) => $t->first_responded_at !== null), 'first_responded_at'),
            'average_resolution_hours' => $this->averageHours($resolved, 'resolved_at'),
            'sla_compliance_percent' => $resolved->filter(fn ($t) => $t->due_at !== null)->isEmpty() ? null
                : round(100 * $resolved->filter(fn ($t) => $t->due_at !== null && $t->resolved_at->lessThanOrEqualTo($t->due_at))->count() / $resolved->filter(fn ($t) => $t->due_at !== null)->count(), 1),
            'breached' => $standard()->whereNotNull('due_at')->where(fn (Builder $q) => $q->whereColumn('resolved_at', '>', 'due_at')->orWhere(fn (Builder $o) => $o->whereNull('resolved_at')->whereIn('status', Ticket::OPEN)->where('due_at', '<', now())))->count(),
            'by_status' => $this->breakdown($standard(), 'tickets.status', fn ($k) => config("peopleos.servicedesk.statuses.{$k}", $k)),
            'by_priority' => $this->breakdown($standard(), 'tickets.priority', fn ($k) => config("peopleos.servicedesk.priorities.{$k}", $k)),
            'by_service' => $this->breakdown($standard()->leftJoin('service_definitions as sd', 'sd.id', '=', 'tickets.service_definition_id')->leftJoin('ticket_categories as tc', 'tc.id', '=', 'tickets.ticket_category_id'), DB::raw('coalesce(sd.name, tc.name)'), fn ($k) => $k ?? 'Unclassified'),
            'by_team' => $this->breakdown($standard()->leftJoin('roles as r', 'r.id', '=', 'tickets.assigned_role_id'), 'r.name', fn ($k) => $k ?? 'No team'),
            'by_organisation' => $this->byOrganisation($standard(), $to),
        ];
    }

    /** @return list<array{group: string, requests: int|string, suppressed: bool}> */
    private function breakdown(Builder $query, mixed $column, callable $label): array
    {
        $rows = $query->toBase()->select([DB::raw((is_string($column) ? $column : $column->getValue(DB::connection()->getQueryGrammar())).' as grp'), DB::raw('count(*) as requests'), DB::raw('count(distinct tickets.employee_id) as people')])
            ->groupBy(DB::raw(is_string($column) ? $column : $column->getValue(DB::connection()->getQueryGrammar())))->get();

        return $this->suppress($rows->map(fn ($r) => ['group' => (string) $label($r->grp), 'requests' => (int) $r->requests, 'people' => (int) $r->people]));
    }

    private function byOrganisation(Builder $query, Carbon $asOf): array
    {
        $rows = $query->toBase()
            ->join('employee_positions as ep', fn ($j) => $j->on('ep.employee_id', '=', 'tickets.employee_id')->where('ep.effective_from', '<=', $asOf->toDateString())
                ->where(fn ($w) => $w->whereNull('ep.effective_to')->orWhere('ep.effective_to', '>=', $asOf->toDateString())))
            ->leftJoin('departments as d', 'd.id', '=', 'ep.department_id')
            ->select([DB::raw('coalesce(d.name, \'No department\') as grp'), DB::raw('count(distinct tickets.id) as requests'), DB::raw('count(distinct tickets.employee_id) as people')])
            ->groupBy(DB::raw('coalesce(d.name, \'No department\')'))->get();

        return $this->suppress($rows->map(fn ($r) => ['group' => (string) $r->grp, 'requests' => (int) $r->requests, 'people' => (int) $r->people]));
    }

    /**
     * Suppress groups below the minimum, plus complementary suppression of the next smallest group
     * when only one would be hidden.
     *
     * @param  Collection<int, array{group: string, requests: int, people: int}>  $rows
     */
    private function suppress(Collection $rows): array
    {
        $min = $this->minGroup();
        $rows = $rows->sortBy('people')->values();
        $small = $rows->filter(fn ($r) => $r['people'] < $min)->keys();
        if ($small->count() === 1 && $rows->count() > 1) {
            $small->push($rows->keys()->first(fn ($k) => ! $small->contains($k)));
        }

        return $rows->map(fn ($r, $k) => $small->contains($k)
            ? ['group' => $r['group'], 'requests' => "fewer than {$min} people", 'suppressed' => true]
            : ['group' => $r['group'], 'requests' => $r['requests'], 'suppressed' => false])->sortBy('group')->values()->all();
    }

    private function averageHours(Collection $tickets, string $end): ?float
    {
        $hours = $tickets->map(fn ($t) => max(0, (Carbon::parse($t->submitted_at ?? $t->created_at)->diffInMinutes($t->{$end}, true) - (int) $t->sla_paused_minutes) / 60));

        return $hours->isEmpty() ? null : round($hours->avg(), 1);
    }
}
