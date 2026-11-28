<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\SalaryArrearsWriter;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Arrears of a back-dated salary change (owner, 2026-11-27: "revision effective from a past month includes the arrear").
 *
 * When a change is dated in a month whose payroll is finalised, Compensation writes it into the timeline with its true
 * date and this service settles the money: every finalised month from that date is calculated again by the payroll
 * engine on the new salary, and each salary-structure pay item is compared with what was paid for it (the month's own
 * line plus arrears or recoveries of earlier changes for the same month). The difference becomes a payroll adjustment
 * in the employee's next open payroll month — an arrear when more is due, a recovery when less — that names the month
 * it is for (reference period) and the change (source). Closed months, their entries and payslips are never touched.
 *
 * Arrears follow the pay item: its tax, PF and ESI treatment applies in the month that pays them (as the Salary arrear
 * upload does). They are approved at once unless the organisation has "payroll adjustments need approval" on; the
 * change itself was already approved by another person. A recovery is a deduction without a pay item, so it never
 * reduces the month's PF or ESI wages.
 */
final class SalaryArrears implements SalaryArrearsWriter
{
    public const SOURCE = 'compensation_change';

    /** Differences below this (rounding of the pay items) are not paid. */
    private const MINIMUM = 0.5;

    public function __construct(private readonly PayrollCalculator $calculator, private readonly SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('payroll.arrears.back_dated', true);
    }

    public function reach(int $employeeId, CarbonInterface $from): ?array
    {
        $entries = $this->closedEntries($employeeId, $from);
        if ($entries->isEmpty()) {
            return null;
        }
        $last = $entries->last()->run->period;
        [$year, $month] = self::next((int) $last->year, (int) $last->month);

        return [
            'through' => $last->end_date->toDateString(),
            'months' => $entries->map(fn (PayrollEntry $e) => ['period_id' => (int) $e->run->period->id, 'label' => $e->run->period->label(), 'start' => $e->run->period->start_date->toDateString(), 'end' => $e->run->period->end_date->toDateString()])->values()->all(),
            'payout' => Carbon::create($year, $month, 1)->format('M Y'),
        ];
    }

    public function record(int $employeeId, CarbonInterface $from, array $source, ?User $actor = null): array
    {
        $entries = $this->closedEntries($employeeId, $from);
        if ($entries->isEmpty()) {
            return ['months' => [], 'payout' => null, 'total' => 0.0, 'lines' => []];
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($employeeId);
        $payout = $this->payoutPeriod($employee, $entries->last()->run->period);
        $components = SalaryComponent::query()->get()->keyBy('id');
        $lines = [];
        $total = 0.0;

        foreach ($entries as $entry) {
            $period = $entry->run->period;
            $due = $this->due($employee, $period);
            $paid = $this->paid($entry, $period, $employeeId);
            foreach (array_unique([...array_keys($due), ...array_keys($paid)]) as $componentId) {
                $difference = round(($due[$componentId] ?? 0.0) - ($paid[$componentId] ?? 0.0), 2);
                $component = $components->get($componentId);
                if (abs($difference) < self::MINIMUM || $component === null) {
                    continue;
                }
                $this->adjust($employee, $payout, $period, $component, $difference, $source, $actor, $paid[$componentId] ?? 0.0, $due[$componentId] ?? 0.0, $from);
                $lines[] = ['month' => $period->label(), 'code' => (string) $component->code, 'name' => (string) $component->name,
                    'paid' => round($paid[$componentId] ?? 0.0, 2), 'due' => round($due[$componentId] ?? 0.0, 2), 'difference' => $difference];
                $total += $difference;
            }
        }

        return ['months' => $entries->map(fn (PayrollEntry $e) => $e->run->period->label())->values()->all(), 'payout' => $payout->label(), 'total' => round($total, 2), 'lines' => $lines];
    }

    /**
     * The arrears already recorded for a change, for the change's own page: month, pay item, amount, the month paying
     * them and their status.
     *
     * @return list<array{month: ?string, name: string, type: string, amount: float, payout: ?string, status: string}>
     */
    public static function recordedFor(int $changeId): array
    {
        return PayrollAdjustment::query()->withoutGlobalScope(AccessScope::class)->with(['period:id,year,month,company_id,start_date,end_date', 'referencePeriod:id,year,month,company_id,start_date,end_date'])
            ->where('source_type', self::SOURCE)->where('source_id', $changeId)->orderBy('reference_period_id')->orderBy('id')->get()
            ->map(fn (PayrollAdjustment $a) => ['month' => $a->referencePeriod?->label(), 'name' => (string) $a->name, 'type' => (string) $a->type,
                'amount' => (float) $a->amount * ($a->type === 'recovery' ? -1 : 1), 'payout' => $a->period?->label(), 'status' => (string) $a->status])->all();
    }

    /**
     * The employee's entries of finalised or paid runs whose month ends on or after the date, oldest month first.
     *
     * @return Collection<int, PayrollEntry>
     */
    private function closedEntries(int $employeeId, CarbonInterface $from): Collection
    {
        return PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid'])->whereHas('period', fn ($p) => $p->where('end_date', '>=', $from->toDateString())))
            ->with(['run.period', 'lines'])->get()
            ->sortBy(fn (PayrollEntry $e) => $e->run->period->start_date->toDateString())->values();
    }

    /**
     * What the month pays per salary-structure pay item on the salary now on the timeline (the payroll engine's own
     * calculation, with the month's attendance, loss of pay and proration).
     *
     * @return array<int, float> salary component id => amount
     */
    private function due(Employee $employee, PayrollPeriod $period): array
    {
        $computation = $this->calculator->calculate($employee, $period);
        $blocking = collect($computation->exceptions)->first(fn (array $e) => in_array($e['type'], ['no_salary', 'no_structure', 'formula_error', 'invalid_component'], true));
        if ($blocking !== null) {
            throw new RuntimeException("The arrears for {$period->label()} cannot be worked out: {$blocking['message']} Fix it, or date the change after the closed months.");
        }

        return self::structureLines($computation->lines);
    }

    /**
     * What was paid for the month per salary-structure pay item: the finalised entry's own lines, plus arrears and
     * recoveries already recorded for that month by earlier back-dated changes (paid or still waiting).
     *
     * @return array<int, float>
     */
    private function paid(PayrollEntry $entry, PayrollPeriod $period, int $employeeId): array
    {
        $paid = self::structureLines($entry->lines->map(fn ($line) => ['salary_component_id' => $line->salary_component_id, 'basis' => $line->basis, 'amount' => (float) $line->amount, 'type' => $line->type])->all());
        $earlier = PayrollAdjustment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->where('reference_period_id', $period->id)
            ->where('source_type', self::SOURCE)->whereNotIn('status', ['rejected', 'cancelled'])->get(['type', 'amount', 'note', 'salary_component_id']);
        $codes = $earlier->isEmpty() ? [] : SalaryComponent::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper((string) $code) => (int) $id])->all();
        foreach ($earlier as $adjustment) {
            // A recovery carries no pay item (it must not cut PF or ESI wages); the note ends with the pay item's code.
            $componentId = $adjustment->salary_component_id ?? $codes[self::taggedCode((string) $adjustment->note)] ?? null;
            if ($componentId === null) {
                continue;
            }
            $paid[$componentId] = ($paid[$componentId] ?? 0.0) + (float) $adjustment->amount * ($adjustment->type === 'recovery' ? -1 : 1);
        }

        return $paid;
    }

    /**
     * Earnings and reimbursements of the salary structure (lines that carry salary segments), summed per pay item.
     *
     * @param  iterable<array<string, mixed>>  $lines
     * @return array<int, float>
     */
    private static function structureLines(iterable $lines): array
    {
        $sums = [];
        foreach ($lines as $line) {
            $basis = (array) ($line['basis'] ?? []);
            if (empty($line['salary_component_id']) || ! array_key_exists('segments', $basis) || ! in_array($line['type'] ?? '', ['earning', 'reimbursement'], true)) {
                continue;
            }
            $id = (int) $line['salary_component_id'];
            $sums[$id] = round(($sums[$id] ?? 0.0) + (float) $line['amount'], 2);
        }

        return $sums;
    }

    private function adjust(Employee $employee, PayrollPeriod $payout, PayrollPeriod $month, SalaryComponent $component, float $difference, array $source, ?User $actor, float $paid, float $due, CarbonInterface $from): void
    {
        $recovery = $difference < 0;
        $type = $recovery ? 'recovery' : ($component->type === 'reimbursement' ? 'reimbursement' : 'arrear');
        $monthLabel = Carbon::create((int) $month->year, (int) $month->month, 1)->format('M Y');
        // The pay item's code ends the note: a recovery names no pay item (below), and a later change reads it back.
        $note = "Salary change {$source['reference']} effective ".Carbon::parse($from)->format('j M Y')." · {$month->label()}: paid ".number_format($paid, 2).', due '.number_format($due, 2)
            .' · pay item '.$component->code;

        PayrollAdjustment::query()->create([
            'employee_id' => $employee->id, 'payroll_period_id' => $payout->id, 'reference_period_id' => $month->id,
            // A recovery is a plain deduction: naming the pay item would make it a negative Basic (and so less PF).
            'salary_component_id' => $recovery ? null : $component->id,
            'type' => $type, 'name' => mb_substr($component->name.($recovery ? ' recovery ' : ' arrear ').$monthLabel, 0, 255),
            'amount' => abs($difference), 'taxable' => ! $recovery && $type === 'arrear' && (bool) $component->taxable,
            'source_type' => self::SOURCE, 'source_id' => (int) $source['id'], 'note' => mb_substr($note, 0, 255), 'created_by' => $actor?->id,
        ]);
    }

    /** The pay item's code a recorded arrear or recovery was for (the end of its note), upper-cased. */
    private static function taggedCode(string $note): string
    {
        return preg_match('/pay item ([A-Za-z0-9_\-]+)$/', $note, $m) ? strtoupper($m[1]) : '';
    }

    /** The employee's first payroll month after the last closed one that is still open for them. */
    private function payoutPeriod(Employee $employee, PayrollPeriod $last): PayrollPeriod
    {
        [$year, $month] = [(int) $last->year, (int) $last->month];
        for ($i = 0; $i < 24; $i++) {
            [$year, $month] = self::next($year, $month);
            $period = PayrollPeriod::for($this->companyIn($employee, $year, $month, (int) $last->company_id), $year, $month);
            $finalised = PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
                ->whereHas('run', fn ($q) => $q->where('payroll_period_id', $period->id)->whereIn('status', ['finalized', 'paid']))->exists();
            if ($period->status !== 'closed' && ! $finalised) {
                return $period;
            }
        }

        throw new RuntimeException('No open payroll month was found to pay the arrears in.');
    }

    /** The company the employee works for at the end of the month (the last one they worked for when none). */
    private function companyIn(Employee $employee, int $year, int $month, int $fallback): Company
    {
        $end = Carbon::create($year, $month, 1)->endOfMonth();
        $positions = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id);
        $id = (clone $positions)->effectiveOn($end)->orderByDesc('effective_from')->value('company_id')
            ?? (clone $positions)->orderByDesc('effective_from')->value('company_id') ?? $fallback;

        return Company::query()->findOrFail($id);
    }

    /** @return array{0: int, 1: int} the month after */
    private static function next(int $year, int $month): array
    {
        return $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
    }
}
