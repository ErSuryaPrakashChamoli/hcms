<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Support\CompensationSegment;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\AssignSalaryOperation;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\SalaryProposalPreview;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Employee 360 → Current salary (owner, 2026-11-27): the read side of one employee's salary and the arithmetic of a
 * revision. Nothing here writes: every revision is still a proposal through SalaryAssignments → CompensationChanges,
 * reviewed, approved and executed by other people.
 *
 * - Revise on CTC, gross pay or net pay (`resolve`): a hike % or a new amount on any of the three. CTC on a structure that
 *   balances to CTC is passed through as before (SalaryAssignments' percent / amount). Gross or net pay — and CTC on a
 *   structure built from component amounts — is solved: the CTC (or the scale of the component amounts) is searched until
 *   the structure's own formulas give the target, so the breakup is always the structure's, never typed in. Net pay is the
 *   take-home of the read-only statutory estimate (verified rules only, TDS excluded), as the breakup preview shows it.
 * - Before → after (`compare`): every component, the gross pay, the deductions, the net pay, the employer contributions,
 *   the CTC and the variable target, each with its change and percentage — both sides on the same date, so only the
 *   revision differs.
 * - The salary story (`history`): every approved compensation since joining (until exit), each with the change against
 *   the one before it and who proposed, reviewed, approved and executed it, and the analysis across them (how many
 *   revisions, total and yearly growth, average and highest hike, time since the last one).
 */
final class SalaryRevisions
{
    /** What a revision is given on. */
    public const BASES = ['ctc' => 'CTC', 'gross' => 'Gross pay', 'net' => 'Net pay (take-home)'];

    /** How close a solved amount must come to its target (a month of gross or net pay; a year of CTC). */
    private const TOLERANCE = ['gross' => 0.5, 'net' => 0.5, 'ctc' => 1.0];

    public function __construct(
        private readonly CompensationOutput $output,
        private readonly SalaryAssignments $assignments,
        private readonly SalaryProposalPreview $preview,
    ) {}

    // --- A revision ---------------------------------------------------------------------------------------------------

    /**
     * The wizard's answers as SalaryAssignments reads them, with a revision on gross or net pay (or on CTC for a structure
     * built from component amounts) worked out into the CTC or the component amounts that give it. Throws a
     * CompensationRuleViolation with the reason when it cannot.
     *
     * @param  array<string, mixed>  $state  the wizard's answers (basis, ctc_mode, increase_percent, ctc_annual, target_monthly …)
     * @return array{input: array<string, mixed>, solved: ?array{basis: string, label: string, current: ?float, target: float, achieved: float, difference: float, currency: string}}
     */
    public function resolve(Employee $employee, array $state): array
    {
        $basis = (string) ($state['basis'] ?? 'ctc');
        $basis = array_key_exists($basis, self::BASES) ? $basis : 'ctc';
        $mode = (string) ($state['ctc_mode'] ?? 'amount');
        $input = AssignSalaryOperation::input($state);
        if ($mode === 'keep') {
            return ['input' => $input, 'solved' => null];
        }

        // The proposal's shape (structure, version, fixed amounts, currency, variable target) without its CTC yet.
        $from = $this->date($state['effective_from'] ?? null);
        $current = $this->output->on($employee, $from);
        $template = $this->assignments->proposal($employee, ['ctc_mode' => 'amount', 'ctc_annual' => $current?->ctcAnnual ?? 1.0] + $input);
        $segment = $this->assignments->segment($employee, $template)
            ?? throw new CompensationRuleViolation('The salary structure has no approved version on '.$from->format('j M Y').'.');
        $componentsMode = $segment->ctcMode === 'components';
        $percent = null;
        if ($mode === 'percent') {
            $value = $state['increase_percent'] ?? null;
            if (! is_numeric($value) || (float) $value <= -100 || (float) $value > 1000) {
                throw new CompensationRuleViolation('The hike must be a percentage above -100 and at most 1000.');
            }
            $percent = round((float) $value, 2);
            if ($current === null) {
                throw new CompensationRuleViolation('No salary is in force on '.$from->format('j M Y').' to give a hike on; enter the amount instead.');
            }
        }

        if ($basis === 'ctc' && ! $componentsMode) {
            // As before: SalaryAssignments revises the CTC by the percentage, or takes the amount. The CTC percentage is
            // recorded on the change either way.
            if ($mode === 'amount' && $current !== null && is_numeric($input['ctc_annual'] ?? null) && $this->sameCurrency($current, $template['currency'])) {
                $input['recorded_percent'] = self::percent($current->ctcAnnual, (float) $input['ctc_annual']);
            }

            return ['input' => $input, 'solved' => null];
        }

        // The figure the target is set on, today's value of it (for a hike) and the target.
        $metric = fn (CompensationSegment $trial): float => $this->metric($employee, $trial, $basis);
        $before = $current !== null ? $this->segmentFor($employee, $current, $from) : null;
        // A hike on CTC starts from the CTC recorded on the salary in force (as SalaryAssignments::revise does), not from
        // what the same amounts would cost under today's statutory rules.
        $now = match (true) {
            $before === null => null,
            $basis === 'ctc' => $current->ctcAnnual,
            default => $metric($before),
        };
        if ($mode === 'percent') {
            $target = round((float) $now * (1 + $percent / 100), 2);
        } else {
            $amount = $basis === 'ctc' ? ($state['ctc_annual'] ?? null) : ($state['target_monthly'] ?? null);
            if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount >= 1e11) {
                throw new CompensationRuleViolation('Enter the new '.mb_strtolower(self::BASES[$basis]).($basis === 'ctc' ? ' (a year).' : ' (a month).'));
            }
            $target = round((float) $amount, 2);
        }
        if ($target <= 0) {
            throw new CompensationRuleViolation('The '.mb_strtolower(self::BASES[$basis]).' in force is nil, so a hike on it gives nothing; enter the amount instead.');
        }

        if ($componentsMode) {
            // The component amounts (not the inputs, which are quantities) scale together until the structure gives the target.
            $values = (array) $template['component_values'];
            $inputs = SalaryComponent::query()->where('calculation_method', 'input')->whereIn('code', array_keys($values))->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();
            $scalable = array_diff_key($values, array_flip($inputs));
            if (array_sum(array_map('floatval', $scalable)) <= 0) {
                throw new CompensationRuleViolation('This structure is built from component amounts: enter the amounts under "Fixed amounts and inputs" (or the CTC there is nothing to scale).');
            }
            $scaled = fn (float $scale): array => array_map(fn ($v) => round((float) $v * $scale, 2), $scalable) + $values;
            [$scale] = $this->preview->remembering(fn () => self::solve(fn (float $s) => $metric($this->withAmounts($segment, 0.0, $scaled($s))), $target, $now !== null && $now > 0 ? $target / $now : 1.0, self::TOLERANCE[$basis], 1e-7));
            $final = $this->withAmounts($segment, 0.0, $scaled($scale));
            $newCtc = $this->metric($employee, $final, 'ctc');
            // CompensationChanges works the CTC out from these amounts again; the amount mode carries them through.
            $input['ctc_mode'] = 'amount';
            $input['ctc_annual'] = $newCtc;
            $input['currency'] = $template['currency'];
            $input['component_values'] = $final->compensation->componentValues;
        } else {
            $guess = $now !== null && $now > 0 ? $current->ctcAnnual * $target / $now : $target * ($basis === 'net' ? 16.5 : 14.0);
            [$ctc] = $this->preview->remembering(fn () => self::solve(fn (float $x) => $metric($this->withAmounts($segment, round($x, 2), $segment->compensation->componentValues)), $target, $guess, self::TOLERANCE[$basis], 0.01));
            $newCtc = round($ctc, 2);
            $final = $this->withAmounts($segment, $newCtc, $segment->compensation->componentValues);
            $input['ctc_mode'] = 'amount';
            $input['ctc_annual'] = $newCtc;
            $input['currency'] = $template['currency'];
        }
        if ($current !== null && $this->sameCurrency($current, $template['currency'])) {
            $input['recorded_percent'] = self::percent($current->ctcAnnual, $newCtc);
        }
        $achieved = round($metric($final), 2);

        return ['input' => $input, 'solved' => [
            'basis' => $basis, 'label' => self::BASES[$basis], 'current' => $now !== null ? round($now, 2) : null, 'target' => $target,
            'achieved' => $achieved, 'difference' => round($achieved - $target, 2), 'currency' => (string) $template['currency'],
        ]];
    }

    /**
     * The breakup a compensation gives on a date: the structure version in force then, with this compensation's CTC,
     * amounts and variable target (what the salary would be on that date without the revision).
     */
    public function segmentFor(Employee $employee, CompensationSnapshot $snapshot, CarbonInterface|string $on): ?CompensationSegment
    {
        return $this->assignments->segment($employee, [
            'change_type' => $snapshot->changeType, 'effective_from' => Carbon::parse($on)->toDateString(), 'salary_structure_id' => $snapshot->structureId,
            'ctc_annual' => $snapshot->ctcAnnual, 'currency' => $snapshot->currency, 'pay_frequency' => $snapshot->payFrequency,
            'component_values' => $snapshot->componentValues, 'variable_target_annual' => $snapshot->variableTargetAnnual,
        ]);
    }

    /** The salary in force on the date as payroll reads it (the structure version in force then), or null. */
    public function inForce(Employee $employee, CarbonInterface|string|null $on = null): ?CompensationSegment
    {
        $day = Carbon::parse($on ?? today())->startOfDay();
        $segment = $this->output->forPayroll($employee, $day, $day)->last();

        return $segment !== null && $segment->components->isNotEmpty() ? $segment : null;
    }

    /** @return array{inputs: array<string, mixed>, lines: list<array<string, mixed>>, preview: array<string, mixed>, estimate: array<string, mixed>} */
    public function figures(Employee $employee, CompensationSegment $segment): array
    {
        return $this->preview->figures($employee, $segment);
    }

    // --- Before → after -----------------------------------------------------------------------------------------------

    /**
     * Every line of the salary before and after, with the change and its percentage: earnings (and reimbursements), gross
     * pay, deductions (the structure's and the statutory estimate's), net pay, employer contributions, CTC and the
     * variable target. Without a "before" (a first salary) only the after side is filled.
     *
     * @param  array<string, mixed>|null  $afterFigures  the after side's figures when already worked out
     * @return array{currency: string, sections: list<array{key: string, title: string, rows: list<array<string, mixed>>}>, headline: array<string, ?float>, complete: bool, notes: list<string>, first: bool}
     */
    public function compare(Employee $employee, ?CompensationSegment $before, CompensationSegment $after, ?array $afterFigures = null): array
    {
        $a = $this->side($after, $afterFigures ?? $this->figures($employee, $after));
        $b = $before !== null ? $this->side($before, $this->figures($employee, $before)) : null;
        $row = fn (string $label, ?float $old, ?float $new, ?string $code = null, bool $strong = false, ?string $note = null) => [
            'label' => $label, 'code' => $code, 'before' => $old, 'after' => $new, 'strong' => $strong, 'note' => $note,
            'change' => $old !== null && $new !== null ? round($new - $old, 2) : null,
            'percent' => $old !== null && $new !== null ? self::percent($old, $new) : null,
        ];
        $lines = function (string $group) use ($a, $b, $row): array {
            $out = [];
            $codes = array_unique([...array_keys($a[$group]), ...array_keys($b[$group] ?? [])]);
            foreach ($codes as $code) {
                $name = $a[$group][$code]['name'] ?? $b[$group][$code]['name'];
                $out[] = $row($name, $b === null ? null : ($b[$group][$code]['amount'] ?? 0.0), $a[$group][$code]['amount'] ?? 0.0, $code);
            }

            return $out;
        };

        $sections = [
            ['key' => 'earnings', 'title' => 'Earnings (a month)', 'rows' => [...$lines('earnings'), $row('Gross pay', $b['gross'] ?? null, $a['gross'], null, true)]],
        ];
        if ($a['reimbursements'] !== [] || ($b['reimbursements'] ?? []) !== []) {
            $sections[] = ['key' => 'reimbursements', 'title' => 'Reimbursements (a month)', 'rows' => $lines('reimbursements')];
        }
        $sections[] = ['key' => 'deductions', 'title' => 'Deductions (a month)', 'rows' => [
            ...$lines('deductions'),
            $row('Total deductions', $b['deductions_total'] ?? null, $a['deductions_total'], null, true),
            $row('Net pay (take-home, before TDS)', $b['net'] ?? null, $a['net'], null, true, $a['complete'] ? null : 'Before the statutory deductions that have no verified rule.'),
        ]];
        $sections[] = ['key' => 'employer', 'title' => 'Employer contributions (a month)', 'rows' => [
            ...$lines('employer'),
            $row('Total employer contributions', $b['employer_total'] ?? null, $a['employer_total'], null, true),
        ]];
        $sections[] = ['key' => 'ctc', 'title' => 'Cost to company', 'rows' => array_values(array_filter([
            $row('CTC a month', $b['ctc_monthly'] ?? null, $a['ctc_monthly'], null, true),
            $row('CTC a year', $b['ctc_annual'] ?? null, $a['ctc_annual'], null, true),
            ($a['variable'] !== null || ($b['variable'] ?? null) !== null) ? $row('Variable pay target (a year)', $b === null ? null : ($b['variable'] ?? 0.0), $a['variable'] ?? 0.0) : null,
        ]))];

        $notes = [];
        if ($a['complete'] === false) {
            $notes[] = 'Statutory deductions with no verified rule are not estimated; net pay is shown before them.';
        }
        if ($b !== null && $before->compensation->structureId !== $after->compensation->structureId) {
            $notes[] = 'The salary structure changes, so some components appear on one side only.';
        }
        if ($b !== null && $before->compensation->currency !== $after->compensation->currency) {
            $notes[] = 'The currency changes, so percentages compare different currencies.';
        }

        return [
            'currency' => $after->compensation->currency, 'sections' => $sections, 'first' => $b === null,
            'headline' => ['ctc' => $b !== null ? self::percent($b['ctc_annual'], $a['ctc_annual']) : null, 'gross' => $b !== null ? self::percent($b['gross'], $a['gross']) : null,
                'net' => $b !== null ? self::percent($b['net'], $a['net']) : null, 'ctc_annual' => $a['ctc_annual'], 'gross_monthly' => $a['gross'], 'net_monthly' => $a['net'],
                'ctc_before' => $b['ctc_annual'] ?? null, 'gross_before' => $b['gross'] ?? null, 'net_before' => $b['net'] ?? null,
                'gross_change' => round($a['gross'] - ($b['gross'] ?? 0.0), 2)],
            'complete' => $a['complete'], 'notes' => $notes,
        ];
    }

    /**
     * One salary as groups of lines (earnings, reimbursements, deductions, employer contributions: code => name, amount, a
     * month) and its totals (gross, deductions, net, employer, CTC a month and a year, variable target) — the Current
     * salary breakup, and each side of a comparison.
     *
     * @param  array<string, mixed>  $figures  SalaryProposalPreview::figures() or for() of the segment
     * @return array<string, mixed>
     */
    public function breakup(CompensationSegment $segment, array $figures): array
    {
        return $this->side($segment, $figures);
    }

    /**
     * One side of a comparison from its figures: lines by group (code => name, amount) and the totals.
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function side(CompensationSegment $segment, array $figures): array
    {
        $preview = $figures['preview'];
        $estimate = $figures['estimate'];
        $byCode = $segment->components->mapWithKeys(fn ($line) => [$line->component->code => $line->component]);
        $groups = ['earnings' => [], 'reimbursements' => [], 'deductions' => [], 'employer' => []];
        foreach ($preview['rows'] as $row) {
            $component = $byCode[$row['code']] ?? null;
            if ($component === null || $row['monthly'] === null) {
                continue;
            }
            $group = match ($component->type) {
                'earning' => 'earnings', 'reimbursement' => 'reimbursements', 'deduction' => 'deductions', 'employer_contribution' => $component->include_in_ctc ? 'employer' : null, default => null,
            };
            if ($group !== null) {
                $groups[$group][$row['code']] = ['name' => $row['name'], 'amount' => (float) $row['monthly']];
            }
        }
        foreach ($estimate['employee'] ?? [] as $line) {
            $groups['deductions'][$line['code']] = ['name' => $line['name'], 'amount' => (float) $line['amount']];
        }
        $employerPf = collect($estimate['employer'] ?? [])->contains(fn ($l) => $l['code'] === 'PF_ER');
        foreach ($estimate['employer'] ?? [] as $line) {
            $groups['employer'][$line['code']] = ['name' => $line['name'], 'amount' => (float) $line['amount']];
        }
        // The structure's own employer-PF estimate (the part of CTC it balances against) when no verified rule gives it.
        if (! $employerPf && (float) ($preview['totals']['pf_employer'] ?? 0) > 0) {
            $groups['employer']['PF_ER'] = ['name' => 'Employer PF (structure estimate)', 'amount' => (float) $preview['totals']['pf_employer']];
        }
        $gross = (float) ($preview['totals']['gross'] ?? 0);
        $deductions = round(array_sum(array_column($groups['deductions'], 'amount')), 2);
        // The CTC on the salary (a structure built from amounts records the CTC worked out from them).
        $ctcAnnual = $segment->ctcMode === 'components' && $segment->compensation->ctcAnnual <= 0 ? round((float) $preview['totals']['ctc_monthly'] * 12, 2) : $segment->compensation->ctcAnnual;

        return $groups + [
            'gross' => $gross, 'deductions_total' => $deductions, 'net' => round($gross - $deductions, 2),
            'employer_total' => round(array_sum(array_column($groups['employer'], 'amount')), 2),
            'ctc_annual' => $ctcAnnual, 'ctc_monthly' => round($ctcAnnual / 12, 2), 'variable' => $segment->compensation->variableTargetAnnual,
            'complete' => (bool) ($estimate['complete'] ?? false),
        ];
    }

    // --- The salary story ---------------------------------------------------------------------------------------------

    /**
     * Every approved compensation of the employee since joining (oldest first), with the change against the one before it
     * and who acted on it, and the analysis across them.
     *
     * @return array{rows: list<array<string, mixed>>, analysis: array<string, mixed>}
     */
    public function history(Employee $employee): array
    {
        $snapshots = $this->output->history($employee)->values();
        $changes = $this->changesFor($snapshots);
        $structures = SalaryStructure::query()->whereIn('id', $snapshots->pluck('structureId')->unique()->all())->pluck('name', 'id');
        $rows = [];
        foreach ($snapshots as $i => $snapshot) {
            /** @var CompensationSnapshot $snapshot */
            $previous = $snapshots->get($i - 1);
            $comparable = $previous !== null && $previous->currency === $snapshot->currency;
            $change = $changes[$snapshot->assignmentId] ?? null;
            $rows[] = [
                'assignment_id' => $snapshot->assignmentId, 'from' => $snapshot->effectiveFrom, 'to' => $snapshot->effectiveTo,
                'type' => (string) config("peopleos.compensation.change_types.{$snapshot->changeType}", ucfirst(str_replace('_', ' ', $snapshot->changeType))),
                'change_type' => $snapshot->changeType, 'structure' => (string) ($structures[$snapshot->structureId] ?? $snapshot->structureCode),
                'currency' => $snapshot->currency, 'ctc' => $snapshot->ctcAnnual, 'variable' => $snapshot->variableTargetAnnual,
                'previous_ctc' => $previous?->ctcAnnual, 'change' => $comparable ? round($snapshot->ctcAnnual - $previous->ctcAnnual, 2) : null,
                'percent' => $comparable ? self::percent($previous->ctcAnnual, $snapshot->ctcAnnual) : null,
                'months_since_previous' => $previous !== null ? (int) $previous->effectiveFrom->diffInMonths($snapshot->effectiveFrom) : null,
                'in_force' => $snapshot->isInForceOn(today()), 'scheduled' => $snapshot->effectiveFrom->gt(today()),
                'change_id' => $change?->id, 'reference' => $change?->reference, 'source' => $change ? (string) config("peopleos.compensation.sources.{$change->source}", $change->source) : null,
                'reason' => $snapshot->reason,
                'audit' => $change === null ? [] : array_values(array_filter([
                    ['step' => 'Proposed', 'who' => $change->proposer?->name, 'at' => $change->created_at],
                    $change->submitted_at ? ['step' => 'Submitted', 'who' => $change->proposer?->name, 'at' => $change->submitted_at] : null,
                    $change->reviewed_at ? ['step' => 'Reviewed', 'who' => $change->reviewer?->name, 'at' => $change->reviewed_at] : null,
                    $change->approved_at ? ['step' => 'Approved', 'who' => $change->approver?->name, 'at' => $change->approved_at] : null,
                    $change->scheduled_at ? ['step' => 'Executed (scheduled into the salary history)', 'who' => $change->executor?->name, 'at' => $change->scheduled_at] : null,
                    $change->effective_at ? ['step' => 'Took effect', 'who' => null, 'at' => $change->effective_at] : null,
                ])),
            ];
        }

        return ['rows' => $rows, 'analysis' => $this->analysis($employee, $rows)];
    }

    /**
     * The change of each approved compensation against the one before it (the salary history list's "Change" column).
     *
     * @return array<int, array{previous: ?float, change: ?float, percent: ?float}> assignment id => change
     */
    public function changesByAssignment(Employee $employee): array
    {
        $snapshots = $this->output->history($employee)->values();
        $out = [];
        foreach ($snapshots as $i => $snapshot) {
            $previous = $snapshots->get($i - 1);
            $comparable = $previous !== null && $previous->currency === $snapshot->currency;
            $out[$snapshot->assignmentId] = ['previous' => $previous?->ctcAnnual, 'change' => $comparable ? round($snapshot->ctcAnnual - $previous->ctcAnnual, 2) : null,
                'percent' => $comparable ? self::percent($previous->ctcAnnual, $snapshot->ctcAnnual) : null];
        }

        return $out;
    }

    /**
     * One approved compensation against the one before it, both on its effective date (the salary history's "Compare").
     * Null when the row is not part of the employee's approved history.
     *
     * @return array{row: array<string, mixed>, compare: ?array<string, mixed>, failure: ?string}|null
     */
    public function revision(Employee $employee, int $assignmentId): ?array
    {
        $history = $this->history($employee);
        $index = collect($history['rows'])->search(fn (array $row) => $row['assignment_id'] === $assignmentId);
        if ($index === false) {
            return null;
        }
        $snapshots = $this->output->history($employee)->values();
        $after = $this->segmentFor($employee, $snapshots[$index], $snapshots[$index]->effectiveFrom);
        $before = $index > 0 ? $this->segmentFor($employee, $snapshots[$index - 1], $snapshots[$index]->effectiveFrom) : null;
        try {
            $compare = $after !== null ? $this->compare($employee, $before, $after) : null;
        } catch (Throwable $e) {
            report($e);
            $compare = null;
        }

        return ['row' => $history['rows'][$index], 'compare' => $compare,
            'failure' => $compare === null ? 'The breakup of this salary cannot be worked out: its structure has no approved version on '.$snapshots[$index]->effectiveFrom->format('j M Y').'.' : null];
    }

    /**
     * How many times and by how much the salary moved, from the first salary to today (or the exit date).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function analysis(Employee $employee, array $rows): array
    {
        $end = $employee->exit_date !== null && $employee->exit_date->lt(today()) ? $employee->exit_date->copy()->startOfDay() : today();
        $joined = $employee->joining_date?->copy();
        $out = ['count' => count($rows), 'revisions' => max(0, count($rows) - 1), 'increases' => 0, 'decreases' => 0, 'unchanged' => 0,
            'first' => null, 'current' => null, 'total_percent' => null, 'total_change' => null, 'yearly_percent' => null, 'average_percent' => null,
            'highest' => null, 'last_revised' => null, 'months_since_last' => null, 'average_gap_months' => null,
            'joined' => $joined, 'exited' => $employee->exit_date !== null && $employee->exit_date->lte(today()) ? $employee->exit_date->copy() : null,
            'service' => $joined !== null && $joined->lte($end) ? self::span($joined, $end) : null, 'series' => []];
        if ($rows === []) {
            return $out;
        }
        $first = $rows[0];
        $current = collect($rows)->filter(fn (array $row) => $row['from']->lte($end))->last() ?? $first;
        $out['first'] = ['from' => $first['from'], 'ctc' => $first['ctc'], 'currency' => $first['currency']];
        $out['current'] = ['from' => $current['from'], 'ctc' => $current['ctc'], 'currency' => $current['currency']];
        $revisions = array_slice($rows, 1);
        foreach ($revisions as $row) {
            match (true) {
                $row['change'] === null => null,
                $row['change'] > 0 => $out['increases']++,
                $row['change'] < 0 => $out['decreases']++,
                default => $out['unchanged']++,
            };
        }
        if ($first['currency'] === $current['currency'] && $first['ctc'] > 0) {
            $out['total_change'] = round($current['ctc'] - $first['ctc'], 2);
            $out['total_percent'] = self::percent($first['ctc'], $current['ctc']);
            $years = $first['from']->diffInDays($end) / 365.25;
            if ($years >= 1 && $current['ctc'] > 0) {
                $out['yearly_percent'] = round(((($current['ctc'] / $first['ctc']) ** (1 / $years)) - 1) * 100, 2);
            }
        }
        $percents = collect($revisions)->pluck('percent')->filter(fn ($p) => $p !== null)->values();
        if ($percents->isNotEmpty()) {
            $out['average_percent'] = round($percents->avg(), 2);
            $top = collect($revisions)->filter(fn (array $r) => $r['percent'] !== null)->sortByDesc('percent')->first();
            $out['highest'] = ['percent' => $top['percent'], 'from' => $top['from'], 'type' => $top['type']];
        }
        $past = collect($revisions)->filter(fn (array $r) => $r['from']->lte($end));
        if ($past->isNotEmpty()) {
            $out['last_revised'] = $past->last()['from'];
            $out['months_since_last'] = (int) $past->last()['from']->diffInMonths($end);
            $out['average_gap_months'] = round(collect($revisions)->pluck('months_since_previous')->avg(), 1);
        }
        $out['series'] = collect($rows)->filter(fn (array $r) => $r['currency'] === $current['currency'])
            ->map(fn (array $r) => ['label' => $r['from']->format('M Y'), 'ctc' => $r['ctc']])->values()->all();

        return $out;
    }

    /**
     * The compensation changes behind the history rows, with the people who acted on them (read for full readers only by
     * the views that show them).
     *
     * @param  Collection<int, CompensationSnapshot>  $snapshots
     * @return array<int, CompensationChange> assignment id => change
     */
    private function changesFor(Collection $snapshots): array
    {
        $ids = $snapshots->pluck('changeId')->filter()->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $changes = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->whereKey($ids)->with(['proposer:id,name', 'reviewer:id,name', 'approver:id,name', 'executor:id,name'])->get()->keyBy('id');
        $out = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->changeId !== null && $changes->has($snapshot->changeId)) {
                $out[$snapshot->assignmentId] = $changes[$snapshot->changeId];
            }
        }

        return $out;
    }

    // --- Helpers ------------------------------------------------------------------------------------------------------

    /** The change from one amount to another in percent (two decimals), or null without a base. */
    public static function percent(float $from, float $to): ?float
    {
        return $from > 0 ? round(($to - $from) / $from * 100, 2) : null;
    }

    /** "3 years 2 months" between two dates. */
    public static function span(CarbonInterface $from, CarbonInterface $to): string
    {
        $months = (int) $from->diffInMonths($to);
        $years = intdiv($months, 12);
        $rest = $months % 12;

        return trim(($years > 0 ? $years.' '.str('year')->plural($years).' ' : '').($rest > 0 || $years === 0 ? $rest.' '.str('month')->plural($rest) : ''));
    }

    /** The figure a revision is set on for a trial salary: gross or net pay a month, or the CTC a year. */
    private function metric(Employee $employee, CompensationSegment $trial, string $basis): float
    {
        if ($basis === 'ctc' && $trial->ctcMode === 'components') {
            return $this->componentsCtc($employee, $trial);
        }
        $figures = $this->figures($employee, $trial);
        if (($figures['preview']['errors'] ?? []) !== []) {
            throw new CompensationRuleViolation('The salary structure\'s formulas have errors, so the salary cannot be worked out: '.implode(' ', $figures['preview']['errors']));
        }
        $totals = $figures['preview']['totals'];

        return match ($basis) {
            'gross' => (float) $totals['gross'],
            'net' => (float) ($figures['estimate']['take_home'] ?? ((float) $totals['gross'] - (float) $totals['deductions'])),
            default => $trial->ctcMode === 'components' ? round((float) $totals['ctc_monthly'] * 12, 2) : $trial->compensation->ctcAnnual,
        };
    }

    /**
     * The CTC a structure built from component amounts gives these amounts, worked out exactly as CompensationChanges
     * records it on the proposal (StructureFormulas::componentsCtc).
     */
    private function componentsCtc(Employee $employee, CompensationSegment $segment): float
    {
        $employee->loadMissing('statutoryDetail');

        return app(StructureFormulas::class)->componentsCtc(SalaryProposalPreview::lines($segment), $segment->parameters, $segment->compensation->componentValues,
            FormulaVariables::employeeAttributes($employee, $segment->from), $segment->from, (bool) ($employee->statutoryDetail?->pf_applicable ?? true));
    }

    /** The same segment with another CTC and other component amounts (nothing read or stored). */
    private function withAmounts(CompensationSegment $segment, float $ctcAnnual, array $values): CompensationSegment
    {
        $c = $segment->compensation;
        $snapshot = new CompensationSnapshot($c->assignmentId, $c->employeeId, $c->changeId, $c->changeType, $c->structureId, $c->structureVersionId, $c->structureCode,
            $c->currency, $c->payFrequency, $ctcAnnual, $c->variableTargetAnnual, array_map('floatval', $values), $c->effectiveFrom, $c->effectiveTo, $c->approvedAt);

        return new CompensationSegment($snapshot, $segment->from, $segment->to, $segment->components, $segment->parameters, $segment->ctcMode);
    }

    private function sameCurrency(CompensationSnapshot $current, mixed $currency): bool
    {
        return strtoupper((string) $currency) === strtoupper($current->currency);
    }

    private function date(mixed $value): Carbon
    {
        try {
            if (blank($value)) {
                throw new CompensationRuleViolation('A valid effective date is required.');
            }

            return Carbon::parse((string) $value)->startOfDay();
        } catch (CompensationRuleViolation $e) {
            throw $e;
        } catch (Throwable) {
            throw new CompensationRuleViolation('A valid effective date is required.');
        }
    }

    /**
     * The x whose figure comes closest to the target: proportional and secant steps first (salary formulas are piecewise
     * linear, so they settle in a few tries), then halving a bracket around the target (a cliff such as the ESI ceiling
     * has no exact answer; the closest side wins, the higher one on a tie).
     *
     * @param  callable(float): float  $metric
     * @return array{0: float, 1: float} x and its figure
     */
    private static function solve(callable $metric, float $target, float $guess, float $tolerance, float $precision): array
    {
        $points = [];
        $eval = function (float $x) use (&$points, $metric): array {
            $x = max($x, 1e-9);
            $key = sprintf('%.9F', $x);

            return $points[$key] ??= [$x, $metric($x)];
        };
        $done = fn (array $p) => abs($p[1] - $target) <= $tolerance;
        $p0 = $eval($guess > 0 ? $guess : 1.0);
        if ($done($p0)) {
            return $p0;
        }
        $p1 = $eval($p0[1] > 0 ? $p0[0] * $target / $p0[1] : $p0[0] * 2);
        for ($i = 0; $i < 8 && ! $done($p1); $i++) {
            if (abs($p1[1] - $p0[1]) < 1e-9) {
                break;
            }
            $next = $p1[0] + ($target - $p1[1]) * ($p1[0] - $p0[0]) / ($p1[1] - $p0[1]);
            if (! is_finite($next) || $next <= 0) {
                break;
            }
            [$p0, $p1] = [$p1, $eval($next)];
        }
        if ($done($p1)) {
            return $p1;
        }

        $below = collect($points)->filter(fn (array $p) => $p[1] < $target)->sortByDesc(fn (array $p) => $p[0])->first();
        $above = collect($points)->filter(fn (array $p) => $p[1] >= $target)->sortBy(fn (array $p) => $p[0])->first();
        for ($i = 0; $above === null && $i < 40; $i++) {
            $p = $eval(max(array_column($points, 0)) * 2);
            $p[1] >= $target ? $above = $p : $below = $p;
        }
        for ($i = 0; $below === null && $i < 40; $i++) {
            $p = $eval(min(array_column($points, 0)) / 2);
            $p[1] < $target ? $below = $p : $above = $p;
        }
        if ($above === null || $below === null) {
            return collect($points)->sortBy(fn (array $p) => abs($p[1] - $target))->first();
        }
        for ($i = 0; $i < 80 && ! $done($above) && ! $done($below) && abs($above[0] - $below[0]) > $precision; $i++) {
            $p = $eval(($below[0] + $above[0]) / 2);
            $p[1] >= $target ? $above = $p : $below = $p;
        }

        return abs($below[1] - $target) < abs($above[1] - $target) ? $below : $above;
    }
}
