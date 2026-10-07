<?php

namespace App\Domain\Billing\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * SaaS.7 completion (B-2): the billable quantity of a billing period: the highest number of employed employees on
 * any billable day, rebuilt from the effective-dated lifecycle history (never from today's state of a past day).
 *
 * - Employed means LifecycleState::isEmployed(), the single definition of a commercial unit (SaaS.3 BillableUnits):
 *   pre-employee and preboarding are not counted; onboarding to notice period, on leave and suspended are; exited
 *   and alumni are not.
 * - The state on a day is the one set by the latest recorded transition effective by that day (a back-dated hire
 *   recorded after its preboarding still counts from its joining date). Leaving employment takes effect after its
 *   effective date: the exit date is the last day employed, as in payroll, workforce snapshots and occupancy.
 * - One employee is one unit: a rehire is the same record (counted once), and a day never counts anyone twice.
 * - An employee without any recorded transition (imported data) falls back to the current state between the
 *   joining and exit dates; such employees are named in the evidence.
 *
 * The evidence (method, peak day, the employee ids counted that day, their SHA-256, the daily counts) is frozen on
 * the billing period and the invoice line, so a later HR correction never changes a billed quantity.
 */
final class BillableQuantity
{
    public const METHOD = 'lifecycle_transitions_v1';

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  list<string>  $days  the billable days (Y-m-d)
     * @return array{method: string, peak: int, peak_day: ?string, employee_ids: list<int>, employee_ids_sha256: string, daily_counts: array<string, int>,
     *     fallback_employee_ids: list<int>, computed_at: string}
     */
    public function peak(Tenant $tenant, array $days): array
    {
        $days = array_values(array_unique($days));
        sort($days);
        $evidence = ['method' => self::METHOD, 'peak' => 0, 'peak_day' => null, 'employee_ids' => [], 'employee_ids_sha256' => hash('sha256', ''),
            'daily_counts' => [], 'fallback_employee_ids' => [], 'computed_at' => now()->toIso8601String()];
        if ($days === []) {
            return $evidence;
        }
        $last = end($days);

        return $this->tenants->runAs($tenant, function () use ($days, $last, $evidence) {
            $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')
                ->get(['id', 'lifecycle_state', 'joining_date', 'exit_date']);
            $transitions = EmployeeLifecycleTransition::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')
                ->get(['id', 'employee_id', 'from_state', 'to_state', 'effective_date'])->groupBy('employee_id');

            $employedOn = array_fill_keys($days, []);
            $fallback = [];
            foreach ($employees as $employee) {
                $history = $transitions->get($employee->id);
                $flags = $history === null ? $this->fromDates($employee, $days) : $this->fromHistory($history->all(), $days, $last);
                if ($history === null) {
                    $fallback[] = $employee->id;
                }
                foreach ($flags as $day => $employed) {
                    if ($employed) {
                        $employedOn[$day][] = $employee->id;
                    }
                }
            }
            foreach ($employedOn as $day => $ids) {
                $evidence['daily_counts'][$day] = count($ids);
                if ($evidence['peak_day'] === null || count($ids) > $evidence['peak']) {
                    $evidence['peak'] = count($ids);
                    $evidence['peak_day'] = $day;
                    $evidence['employee_ids'] = $ids;
                }
            }
            $evidence['employee_ids_sha256'] = hash('sha256', implode(',', $evidence['employee_ids']));
            $evidence['fallback_employee_ids'] = $fallback;

            return $evidence;
        });
    }

    /**
     * @param  list<EmployeeLifecycleTransition>  $history  in recorded (id) order
     * @param  list<string>  $days
     * @return array<string, bool>
     */
    private function fromHistory(array $history, array $days, string $last): array
    {
        // Before anything was recorded the employee was in the first transition's starting state.
        $initial = $history[0]->from_state?->isEmployed() ?? false;
        $events = [];
        foreach ($history as $t) {
            $employed = $t->to_state->isEmployed();
            $from = $t->effective_date->toDateString();
            $effective = $employed ? $from : Carbon::parse($from)->addDay()->toDateString();
            if ($effective <= $last) {
                $events[] = ['day' => $effective, 'id' => $t->id, 'employed' => $employed];
            }
        }
        usort($events, fn (array $a, array $b) => [$a['day'], $a['id']] <=> [$b['day'], $b['id']]);

        $flags = [];
        $latest = null;     // the latest recorded transition effective so far
        $next = 0;
        foreach ($days as $day) {
            while ($next < count($events) && $events[$next]['day'] <= $day) {
                if ($latest === null || $events[$next]['id'] > $latest['id']) {
                    $latest = $events[$next];
                }
                $next++;
            }
            $flags[$day] = $latest === null ? $initial : $latest['employed'];
        }

        return $flags;
    }

    /** @param  list<string>  $days  @return array<string, bool> */
    private function fromDates(Employee $employee, array $days): array
    {
        $state = $employee->lifecycle_state;
        $joined = $employee->joining_date?->toDateString();
        $exited = $employee->exit_date?->toDateString();
        $flags = [];
        foreach ($days as $day) {
            $flags[$day] = match (true) {
                $state->isEmployed() => ($joined === null || $joined <= $day) && ($exited === null || $day <= $exited),
                in_array($state, [LifecycleState::Exited, LifecycleState::Alumni], true) => $joined !== null && $exited !== null && $joined <= $day && $day <= $exited,
                default => false,
            };
        }

        return $flags;
    }
}
