<?php

namespace App\Domain\ComplianceReports\Support;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The reads behind compliance reports, shared by every report of a pack and memoised for its duration. Payroll is
 * read only from FINALIZED or PAID runs (as statutory returns read it) and never written; attendance, leave and
 * people data are read as the person generating the pack may see them (their access scope applies to every query).
 * An employee belongs to the establishment of their assignment on the day (Phase 6), resolved once per day.
 */
final class ReportData
{
    /** @var array<string, Collection<int, Employee>> */
    private array $employees = [];

    /** @var array<string, Collection<int, PayrollEntry>> */
    private array $payroll = [];

    /** @var array<int, Collection<int, EmployeeEstablishmentAssignment>> */
    private array $assignmentsByEmployee = [];

    /** @var array<int, ?Establishment> */
    private array $establishments = [];

    /** @var array<string, ?string> */
    private array $registrations = [];

    /** @var array<int, ?ComplianceRule> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $memo = [];

    /** @var array<int, string> */
    private array $runIds = [];

    public function __construct(private readonly EstablishmentAssignments $assignments, private readonly StatutoryRegistrations $statutoryRegistrations) {}

    /**
     * Employees of the company employed at any time in the range (joined on or before its end, not exited before its
     * start), each with the establishment they belonged to on their last day in the range. With an establishment, only
     * those of that establishment.
     *
     * @return Collection<int, Employee>
     */
    public function employees(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?Establishment $establishment = null): Collection
    {
        $key = $company->getKey().':'.$from->toDateString().':'.$to->toDateString();
        $all = $this->employees[$key] ??= $this->loadEmployees($company, $from, $to);

        return $establishment === null ? $all : $all->filter(fn (Employee $e) => (int) $e->getAttribute('report_establishment_id') === (int) $establishment->getKey())->values();
    }

    /** The position effective on a day, from the employee's loaded positions. */
    public function positionOn(Employee $employee, CarbonImmutable|string $on): ?EmployeePosition
    {
        $day = CarbonImmutable::parse($on)->toDateString();

        return $employee->positions
            ->filter(fn (EmployeePosition $p) => $p->effective_from?->toDateString() <= $day && ($p->effective_to === null || $p->effective_to->toDateString() >= $day))
            ->sortByDesc(fn (EmployeePosition $p) => $p->effective_from?->toDateString())
            ->first() ?? $employee->positions->sortByDesc(fn (EmployeePosition $p) => $p->effective_from?->toDateString())->first();
    }

    public function establishment(?int $id): ?Establishment
    {
        if ($id === null) {
            return null;
        }

        return $this->establishments[$id] ??= Establishment::query()->with('legalEntity')->find($id);
    }

    /**
     * Finalized (or paid) payroll entries of a month for the company, every establishment. Each entry carries its
     * establishment (recorded at calculation; resolved from the assignment for entries calculated before Phase 5).
     *
     * @return Collection<int, PayrollEntry>
     */
    public function payroll(Company $company, ReportPeriod $month): Collection
    {
        return $this->payroll[$company->getKey().':'.$month->key()] ??= $this->loadPayroll($company, $month);
    }

    /** The context's slice of a month's payroll: its establishment, legal entity or state (PT / LWF by line state). */
    public function payrollFor(ReportContext $context, ?ReportPeriod $month = null): Collection
    {
        $entries = $this->payroll($context->company, $month ?? $context->period);

        if ($context->establishment !== null) {
            return $entries->filter(fn (PayrollEntry $e) => (int) $e->getAttribute('report_establishment_id') === (int) $context->establishment->getKey())->values();
        }
        if ($context->legalEntity !== null) {
            return $entries->filter(fn (PayrollEntry $e) => (int) $e->getAttribute('report_legal_entity_id') === (int) $context->legalEntity->getKey())->values();
        }

        return $entries;
    }

    /**
     * The newest calculated run of the month whatever its status, with its entries: the variance check is run before
     * finalization too, so it is the one report that also reads unfinalized payroll (and says so).
     */
    public function latestRun(Company $company, ReportPeriod $month): ?PayrollRun
    {
        return PayrollRun::query()->with(['period', 'entries.lines', 'entries.employee.person'])
            ->where('company_id', $company->getKey())->whereNotNull('calculated_at')
            ->whereHas('period', fn ($q) => $q->where('year', $month->start->year)->where('month', $month->start->month))
            ->orderByDesc('id')->first();
    }

    /** @return array<int, string> the finalized or paid payroll runs read so far, run id => month key */
    public function payrollRunIds(): array
    {
        ksort($this->runIds);

        return $this->runIds;
    }

    /** Runs of the month that are not finalized yet: their figures are not in any report. */
    public function openRuns(Company $company, ReportPeriod $month): Collection
    {
        return PayrollRun::query()->where('company_id', $company->getKey())->whereNotIn('status', ['finalized', 'paid'])
            ->whereHas('period', fn ($q) => $q->where('year', $month->start->year)->where('month', $month->start->month))->get();
    }

    /**
     * Processed attendance days of the employees in the range, keyed employee id => date => record (with the leave
     * request and its type, for leave codes).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, AttendanceRecord>>
     */
    public function attendance(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            AttendanceRecord::query()->with(['leaveRequest.leaveType', 'shift'])
                ->whereIn('employee_id', $chunk)
                ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
                ->orderBy('date')
                ->each(function (AttendanceRecord $record) use (&$out) {
                    $out[(int) $record->employee_id][$record->date->toDateString()] = $record;
                });
        }

        return $out;
    }

    /**
     * Approved leave of the employees overlapping the range (cancellations pending count as taken).
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, LeaveRequest>
     */
    public function leave(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return collect(array_chunk($employeeIds, 500))->flatMap(fn (array $chunk) => LeaveRequest::query()->with('leaveType')
            ->whereIn('employee_id', $chunk)->whereIn('status', LeaveRequest::TAKEN)
            ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())
            ->orderBy('from_date')->get())->values();
    }

    /**
     * The statutory rule versions behind the lines of these codes, keyed by rule id, with their current status (a
     * version verified after the payroll ran shows as verified; the status recorded at calculation is kept too).
     *
     * @param  iterable<PayrollEntry>  $entries
     * @param  list<string>  $codes
     * @return array<int, array<string, mixed>>
     */
    public function ruleRefs(iterable $entries, array $codes): array
    {
        $refs = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $id = (int) ($line->basis['rule_id'] ?? 0);
                if ($id === 0 || ! in_array($line->code, $codes, true) || isset($refs[$id])) {
                    continue;
                }
                $rule = $this->rules[$id] ??= ComplianceRule::query()->find($id);
                $refs[$id] = [
                    'id' => $id, 'code' => $rule?->code ?? ($line->basis['rule_code'] ?? $line->code), 'state' => $rule?->state, 'version' => (int) ($rule?->version ?? $line->basis['rule_version'] ?? 0),
                    'verification_status' => $rule?->verification_status ?? ($line->basis['verification_status'] ?? 'unknown'),
                    'status_at_calculation' => $line->basis['verification_status'] ?? null,
                    'label' => ($rule?->label() ?? ($line->basis['rule'] ?? $line->code)).' ('.($rule?->verification_status ?? 'unknown').')',
                ];
            }
        }
        ksort($refs);

        return $refs;
    }

    /**
     * States recorded on the professional tax and labour welfare fund lines of the company's finalized payroll in the
     * range: the states PT and LWF statements are due for, wherever the establishments are.
     *
     * @return list<string>
     */
    public function payrollStates(Company $company, CarbonImmutable $from, CarbonImmutable $to, string $code): array
    {
        $key = "states:{$company->getKey()}:{$from->toDateString()}:{$to->toDateString()}:{$code}";

        return $this->memo[$key] ??= PayrollEntryLine::query()
            ->whereIn('code', $code === 'LWF' ? ['LWF_EE', 'LWF_ER'] : [$code])
            ->whereHas('entry.run', fn ($q) => $q->where('company_id', $company->getKey())->whereIn('status', ['finalized', 'paid'])
                ->whereHas('period', fn ($p) => $p->monthsBetween($from->startOfMonth(), $to)))
            ->get(['id', 'basis'])
            ->map(fn (PayrollEntryLine $line) => ReportCatalogue::canonicalState($line->basis['state'] ?? null))
            ->filter()->unique()->sort()->values()->all();
    }

    /**
     * Employees of the establishment counted the way a threshold measures them:
     *  employed_any_day   — the largest number employed on any one day of the period (e.g. "20 or more persons on any day");
     *  employed_period_end — employed on the period's last day;
     *  employed_during    — everyone employed at any time in the period.
     * Only employees PeopleOS holds are counted: contract labour, apprentices and others it does not hold are not.
     */
    public function headcount(Company $company, Establishment $establishment, ReportPeriod $period, string $measure = 'employed_any_day'): int
    {
        $employees = $this->employees($company, $period->start, $period->end, $establishment);
        $interval = fn (Employee $e) => [max($e->joining_date->toDateString(), $period->start->toDateString()), min($e->exit_date?->toDateString() ?? $period->end->toDateString(), $period->end->toDateString())];

        return match ($measure) {
            'employed_period_end' => $employees->filter(fn (Employee $e) => $e->joining_date->toDateString() <= $period->end->toDateString() && ($e->exit_date === null || $e->exit_date->toDateString() >= $period->end->toDateString()))->count(),
            'employed_during' => $employees->count(),
            default => (function () use ($employees, $interval) {
                $events = [];
                foreach ($employees as $employee) {
                    [$from, $to] = $interval($employee);
                    if ($from > $to) {
                        continue;
                    }
                    $events[] = [$from, 1];
                    $events[] = [CarbonImmutable::parse($to)->addDay()->toDateString(), -1];
                }
                usort($events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
                $current = $max = 0;
                foreach ($events as [, $delta]) {
                    $current += $delta;
                    $max = max($max, $current);
                }

                return $max;
            })(),
        };
    }

    /** The registration number a report is filed under (establishment level, else the legal entity's). */
    public function registrationNumber(ReportContext $context, string $type): ?string
    {
        $establishment = $context->establishment;
        $key = $type.':'.($establishment?->getKey() ?? 'e'.$context->legalEntity?->getKey()).':'.$context->period->end->toDateString();
        if (array_key_exists($key, $this->registrations)) {
            return $this->registrations[$key];
        }

        if ($establishment !== null) {
            $registration = $this->statutoryRegistrations->forEstablishment($establishment, $type, $context->period->end);
        } else {
            $registration = $context->legalEntity === null ? null : StatutoryRegistration::query()
                ->where('legal_entity_id', $context->legalEntity->getKey())->whereNull('establishment_id')
                ->where('registration_type', $type)->where('status', 'active')->orderByDesc('effective_from')->first();
        }

        return $this->registrations[$key] = $registration?->registration_number;
    }

    private function loadEmployees(Company $company, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $employees = Employee::query()
            ->with(['person.familyMembers', 'person.addresses', 'person.qualifications', 'statutoryDetail', 'bankAccounts',
                'positions' => fn ($q) => $q->with(['designation', 'department', 'employmentType', 'employeeCategory', 'location', 'grade'])])
            ->whereNotNull('joining_date')->whereDate('joining_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>=', $from->toDateString()))
            ->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding'])
            ->whereHas('positions', fn ($q) => $q->where('company_id', $company->getKey()))
            ->orderBy('employee_code')
            ->get();

        $this->primeAssignments($employees->modelKeys());

        return $employees->map(function (Employee $employee) use ($company, $to) {
            $on = $employee->exit_date !== null && $employee->exit_date->lt($to) ? CarbonImmutable::parse($employee->exit_date) : $to;
            [$establishment, $source] = $this->establishmentOn((int) $employee->getKey(), $on);
            $position = $this->positionOn($employee, $on);
            if ($establishment !== null ? (int) $establishment->company_id !== (int) $company->getKey() : (int) $position?->company_id !== (int) $company->getKey()) {
                return null;
            }
            $employee->setAttribute('report_establishment_id', $establishment?->getKey());
            $employee->setAttribute('report_establishment_source', $source);

            return $employee;
        })->filter()->values();
    }

    private function loadPayroll(Company $company, ReportPeriod $month): Collection
    {
        $runs = PayrollRun::query()->with('period')->where('company_id', $company->getKey())->whereIn('status', ['finalized', 'paid'])
            ->whereHas('period', fn ($q) => $q->where('year', $month->start->year)->where('month', $month->start->month))->pluck('id');
        if ($runs->isEmpty()) {
            return collect();
        }
        foreach ($runs as $id) {
            $this->runIds[(int) $id] = $month->key();
        }

        $entries = PayrollEntry::query()->with(['lines', 'run.period', 'employee.person.familyMembers', 'employee.statutoryDetail', 'employee.bankAccounts',
            'employee.positions' => fn ($q) => $q->with(['designation', 'department'])])
            ->whereIn('payroll_run_id', $runs)->orderBy('payroll_run_id')->get()
            ->sortBy(fn (PayrollEntry $e) => (string) $e->employee?->employee_code)->values();

        $this->primeAssignments($entries->pluck('employee_id')->unique()->values()->all());

        return $entries->each(function (PayrollEntry $entry) use ($month) {
            [$establishment, $source] = $entry->establishment_id !== null
                ? [$this->establishment((int) $entry->establishment_id), $entry->inputs['statutory_context']['establishment_source'] ?? 'payroll_entry']
                : $this->establishmentOn((int) $entry->employee_id, $month->end);
            $entry->setAttribute('report_establishment_id', $establishment?->getKey());
            $entry->setAttribute('report_establishment_source', $source);
            $entry->setAttribute('report_legal_entity_id', $entry->legal_entity_id ?? $establishment?->legal_entity_id);
        });
    }

    /** @param  list<int>  $employeeIds */
    private function primeAssignments(array $employeeIds): void
    {
        $missing = array_values(array_diff($employeeIds, array_keys($this->assignmentsByEmployee)));
        foreach (array_chunk($missing, 500) as $chunk) {
            $rows = EmployeeEstablishmentAssignment::query()->whereIn('employee_id', $chunk)->orderByDesc('effective_from')->get()->groupBy('employee_id');
            foreach ($chunk as $id) {
                $this->assignmentsByEmployee[$id] = $rows->get($id, collect());
            }
        }
    }

    /** @return array{0: ?Establishment, 1: ?string} the establishment on the day and how it was found (assignment, location, company_primary) */
    private function establishmentOn(int $employeeId, CarbonImmutable $on): array
    {
        $day = $on->toDateString();
        $assignment = ($this->assignmentsByEmployee[$employeeId] ?? collect())
            ->first(fn (EmployeeEstablishmentAssignment $a) => $a->effective_from->toDateString() <= $day && ($a->effective_to === null || $a->effective_to->toDateString() >= $day));

        if ($assignment !== null) {
            return [$this->establishment((int) $assignment->establishment_id), 'assignment'];
        }

        ['establishment' => $resolved, 'source' => $source] = $this->assignments->resolve($employeeId, $on);

        return [$resolved === null ? null : $this->establishments[$resolved->getKey()] ??= $resolved->loadMissing('legalEntity'), $source];
    }
}
