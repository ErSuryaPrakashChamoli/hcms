<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Compensation\Services\CompensationDefaults;
use App\Domain\Compliance\Services\StatutoryEngine;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Support\FormulaVariables;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Design-time help for salary structures (§30): the check that catches formula mistakes when a draft
 * is saved or submitted (instead of at the payroll run), and the breakup preview for a sample CTC.
 *
 * Both follow PayrollCalculator's rules: lines run in sort order (ties in the order given), a line's
 * formula is SalaryComponent::formulaFor(override), a fixed line takes the amount on the employee's
 * compensation, results are rounded and never negative, and statutory and one-off lines are not
 * calculated here. Statutory deductions stay with the compliance engine; only the employer-PF estimate
 * used for balancing to CTC (pf_employer) is shown.
 *
 * A line is ['component' => SalaryComponent, 'formula_override' => ?string, 'sort_order' => int].
 */
final class StructureFormulas
{
    public function __construct(private readonly FormulaEngine $formulas, private readonly StatutoryEngine $statutory) {}

    /**
     * HROne setup parity BP-2: `$options` carries the version's `ctc_mode` (ctc, the default, or components) and
     * `balancing_component_id` (null = none). Input components are read anywhere in the structure and never calculated;
     * a balancing component must be the last CTC line and balance to ctc_monthly; a structure built from components may
     * not use CTC in its formulas. Without inputs or options the check is as before.
     *
     * @param  list<array{component: SalaryComponent, formula_override?: ?string, sort_order?: int}>  $lines
     * @param  array<string, float|int|string>  $parameters
     * @param  array{ctc_mode?: ?string, balancing_component_id?: int|string|null}  $options
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(array $lines, array $parameters = [], array $options = []): array
    {
        $errors = [];
        $warnings = [];
        $ordered = self::ordered($lines);
        $position = [];
        $inputs = [];
        foreach ($ordered as $i => $line) {
            $position[$line['component']->variableName()] = $i;
            if ($line['component']->isInput()) {
                $inputs[$line['component']->variableName()] = true;
                if (filled($line['formula_override'] ?? null)) {
                    $errors[] = "{$line['component']->code} is an input taken from each employee's compensation; remove its formula override.";
                }
            }
        }
        $componentsMode = ($options['ctc_mode'] ?? 'ctc') === 'components';

        foreach ($parameters as $name => $value) {
            $name = (string) $name;
            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
                $errors[] = "Parameter “{$name}”: use lowercase letters, digits and _, starting with a letter.";
            } elseif (in_array($name, FormulaVariables::reserved(), true) || in_array($name, FormulaVariables::statutory(), true)) {
                $errors[] = "Parameter “{$name}” is a reserved name; choose another.";
            } elseif (isset($position[$name])) {
                $errors[] = "Parameter “{$name}” has the same name as the component ".strtoupper($name).'; choose another.';
            }
            if (! is_numeric($value)) {
                $errors[] = "Parameter “{$name}” must be a number.";
            }
        }

        $catalogue = SalaryComponent::query()->pluck('code')->map(fn ($code) => strtolower((string) $code))->all();
        $known = [...array_keys(FormulaVariables::PAY), ...array_keys(FormulaVariables::EMPLOYEE), ...array_map('strval', array_keys($parameters)), ...array_keys($position)];

        foreach ($ordered as $i => $line) {
            $component = $line['component'];
            $code = $component->code;
            $formula = self::calculated($component) ? $component->formulaFor($line['formula_override'] ?? null) : null;
            if ($formula === null) {
                continue;
            }
            try {
                $this->formulas->validate($formula);
            } catch (RuntimeException $e) {
                $errors[] = "{$code}: {$e->getMessage()}";

                continue;
            }

            $names = $this->formulas->variablesIn($formula);
            foreach ($names as $name) {
                if ($componentsMode && in_array($name, ['ctc_annual', 'ctc_monthly', 'pf_employer'], true)) {
                    $errors[] = "{$code} uses {$name}: this structure is built from component amounts, and its CTC is worked out from them. Use amounts, inputs or parameters instead.";

                    continue;
                }
                if (isset(FormulaVariables::PAY[$name]) || isset(FormulaVariables::EMPLOYEE[$name]) || array_key_exists($name, $parameters) || isset($inputs[$name])) {
                    continue;
                }
                if ($name === $component->variableName()) {
                    $errors[] = "{$code} uses itself.";
                } elseif (isset($position[$name])) {
                    $other = $ordered[$position[$name]]['component'];
                    if (! self::calculated($other)) {
                        $errors[] = "{$code} uses {$other->code}, which ".($other->is_statutory ? 'is statutory' : 'is paid through adjustments').' and is not calculated by formulas.';
                    } elseif ($position[$name] > $i) {
                        $errors[] = "{$code} uses {$other->code}, which is calculated after it. Give {$other->code} a lower sort order than {$code}.";
                    }
                } elseif (in_array($name, FormulaVariables::statutory(), true)) {
                    $errors[] = "{$code} uses ".strtoupper($name).': statutory amounts are worked out after earnings by the compliance engine and cannot be used in formulas (pf_employer is available for balancing to CTC).';
                } elseif (in_array($name, $catalogue, true)) {
                    $errors[] = "{$code} uses ".strtoupper($name).", which is not in this structure. Add it above {$code}.";
                } else {
                    $hint = self::closest($name, $known);
                    $errors[] = "{$code} uses an unknown name “{$name}”".($hint ? "; did you mean “{$hint}”?" : '.').' A formula can use the variables in the formula guide, the structure parameters and the components above it.';
                }
            }

            if (in_array('pf_employer', $names, true)) {
                $later = collect($ordered)->slice($i + 1)
                    ->filter(fn ($l) => self::calculated($l['component']) && $l['component']->pf_applicable && $l['component']->type === 'earning')
                    ->map(fn ($l) => $l['component']->code)->values();
                if ($later->isNotEmpty()) {
                    $warnings[] = "{$code} uses pf_employer, which counts only the PF wages above it; {$later->implode(', ')} ".($later->count() === 1 ? 'is' : 'are').' PF wages and come'.($later->count() === 1 ? 's' : '').' later.';
                }
            }
        }

        if (filled($options['balancing_component_id'] ?? null)) {
            $errors = [...$errors, ...$this->balancingErrors($ordered, (int) $options['balancing_component_id'], $componentsMode)];
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * HROne setup parity BP-2 (CTC balancing component): the remainder formula for a balancing component — CTC less every
     * other line of the structure that is part of CTC, and less the employer PF estimate when the structure has PF wages.
     *
     * @param  list<array{component: SalaryComponent, formula_override?: ?string, sort_order?: int}>  $lines
     */
    public function balancingFormula(array $lines, SalaryComponent $balancing): string
    {
        $ordered = self::ordered($lines);
        $others = collect($ordered)->filter(fn ($l) => self::calculated($l['component']) && $l['component']->include_in_ctc && $l['component']->code !== $balancing->code)
            ->map(fn ($l) => $l['component']->variableName())->values()->all();
        $pf = collect($ordered)->contains(fn ($l) => self::calculated($l['component']) && $l['component']->pf_applicable && $l['component']->type === 'earning');

        return 'max(0, '.implode(' - ', ['ctc_monthly', ...$others, ...($pf ? ['pf_employer'] : [])]).')';
    }

    /**
     * HROne setup parity BP-2 ("disable CTC"): the annual CTC a structure built from component amounts gives an employee:
     * twelve times the monthly lines that are part of CTC plus the employer PF estimate on their PF wages (when PF
     * applies), under the statutory rules in force on the date.
     *
     * @param  list<array{component: SalaryComponent, formula_override?: ?string, sort_order?: int}>  $lines
     * @param  array<string, float|int|string>  $parameters
     * @param  array<string, mixed>  $values  monthly amounts and inputs by component code
     * @param  array<string, mixed>  $attributes  the employee's formula values (FormulaVariables::EMPLOYEE)
     */
    public function componentsCtc(array $lines, array $parameters, array $values, array $attributes, CarbonInterface $on, bool $pfApplies): float
    {
        $preview = $this->preview($lines, $parameters, ['ctc_mode' => 'components', 'values' => $values, 'attributes' => $attributes, 'on' => $on, 'pf_applies' => $pfApplies, 'pf_restrict_to_ceiling' => true]);
        if ($preview['errors'] !== []) {
            throw new RuntimeException(implode(' ', $preview['errors']));
        }

        return round($preview['totals']['ctc_monthly'] * 12, 2);
    }

    /** @return list<string> */
    private function balancingErrors(array $ordered, int $componentId, bool $componentsMode): array
    {
        if ($componentsMode) {
            return ['A structure built from component amounts has no CTC to balance; leave the balancing component empty.'];
        }
        $index = collect($ordered)->search(fn ($l) => (int) $l['component']->id === $componentId);
        if ($index === false) {
            return ['The balancing component must be one of the structure\'s components.'];
        }
        $component = $ordered[$index]['component'];
        if (! self::calculated($component) || ! $component->include_in_ctc || ! $component->isEarning()) {
            return ["{$component->code} cannot balance to CTC: choose an earning that is part of CTC and calculated every month."];
        }
        $errors = [];
        $formula = $component->formulaFor($ordered[$index]['formula_override'] ?? null);
        if ($formula === null || array_intersect($this->safeVariables($formula), ['ctc_monthly', 'ctc_annual']) === []) {
            $errors[] = "{$component->code} balances to CTC, so its formula must work from ctc_monthly, e.g. ".$this->balancingFormula($ordered, $component).'.';
        }
        $after = collect($ordered)->slice($index + 1)->filter(fn ($l) => self::calculated($l['component']) && $l['component']->include_in_ctc)->map(fn ($l) => $l['component']->code)->values();
        if ($after->isNotEmpty()) {
            $errors[] = "{$after->implode(', ')} ".($after->count() === 1 ? 'is' : 'are')." part of CTC but calculated after the balancing component {$component->code}; give {$component->code} the highest sort order.";
        }

        return $errors;
    }

    /** @return list<string> */
    private function safeVariables(string $formula): array
    {
        try {
            return $this->formulas->variablesIn($formula);
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * The monthly breakup a structure gives for a sample CTC.
     *
     * @param  list<array{component: SalaryComponent, formula_override?: ?string, sort_order?: int}>  $lines
     * @param  array<string, float|int|string>  $parameters
     * @param  array{ctc_annual?: float|int|string|null, variable_target_annual?: float|int|string|null, values?: array<string, mixed>, attributes?: array<string, mixed>, days?: int|string|null, pf_applies?: bool, pf_restrict_to_ceiling?: bool, on?: mixed}  $inputs
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, errors: list<string>, warnings: list<string>}
     */
    public function preview(array $lines, array $parameters, array $inputs): array
    {
        $componentsMode = ($inputs['ctc_mode'] ?? 'ctc') === 'components';
        ['errors' => $errors, 'warnings' => $warnings] = $this->check($lines, $parameters, ['ctc_mode' => $inputs['ctc_mode'] ?? null, 'balancing_component_id' => $inputs['balancing_component_id'] ?? null]);
        $ctcAnnual = max(0.0, (float) ($inputs['ctc_annual'] ?? 0));
        $ctcMonthly = round($ctcAnnual / 12, 2);
        $days = max(1, (int) ($inputs['days'] ?? 30));
        $on = Carbon::parse($inputs['on'] ?? now())->startOfDay();
        $pfApplies = (bool) ($inputs['pf_applies'] ?? true);
        $restrict = (bool) ($inputs['pf_restrict_to_ceiling'] ?? true);
        $values = [];
        foreach ((array) ($inputs['values'] ?? []) as $code => $amount) {
            if (filled($code) && is_numeric($amount)) {
                $values[strtoupper(trim((string) $code))] = (float) $amount;
            }
        }
        $attributes = [];
        foreach ((array) ($inputs['attributes'] ?? []) as $name => $value) {
            $name = strtolower(trim((string) $name));
            if (isset(FormulaVariables::EMPLOYEE[$name])) {
                $attributes[$name] = FormulaVariables::EMPLOYEE[$name][0] === 'number' ? (float) $value : trim((string) $value);
            }
        }

        $ordered = self::ordered($lines);
        $inputNames = collect($ordered)->filter(fn ($l) => $l['component']->isInput())->mapWithKeys(fn ($l) => [$l['component']->variableName() => true])->all();
        $pfWages = array_values(collect($ordered)->filter(fn ($l) => self::calculated($l['component']) && $l['component']->pf_applicable && $l['component']->type === 'earning')->map(fn ($l) => $l['component']->variableName())->all());
        $full = [];
        $pfEmployer = function () use (&$full, $pfWages, $pfApplies, $on, $restrict): float {
            return $pfApplies ? $this->statutory->employerPfOn(array_sum(array_intersect_key($full, array_flip($pfWages))), $on, $restrict) : 0.0;
        };
        $resolve = function (string $name) use (&$full, $pfEmployer, $ctcAnnual, $ctcMonthly, $inputs, $days, $attributes, $parameters, $values, $inputNames) {
            return match (true) {
                array_key_exists($name, $full) => $full[$name],
                isset($inputNames[$name]) => $values[strtoupper($name)] ?? 0.0,
                $name === 'ctc_annual' => $ctcAnnual,
                $name === 'ctc_monthly' => $ctcMonthly,
                $name === 'variable_target_annual' => (float) ($inputs['variable_target_annual'] ?? 0),
                in_array($name, ['days_in_period', 'paid_days'], true) => (float) $days,
                in_array($name, ['lop_days', 'overtime_minutes', 'overtime_hours'], true) => 0.0,
                $name === 'pf_employer' => $pfEmployer(),
                isset(FormulaVariables::EMPLOYEE[$name]) => $attributes[$name] ?? (FormulaVariables::EMPLOYEE[$name][0] === 'number' ? 0.0 : ''),
                array_key_exists($name, $parameters) => (float) $parameters[$name],
                array_key_exists(strtoupper($name), $values) => $values[strtoupper($name)],
                default => null,
            };
        };

        $rows = [];
        $usesPf = false;
        foreach ($ordered as $line) {
            $component = $line['component'];
            $row = ['code' => $component->code, 'name' => $component->name, 'type' => $component->type, 'sort_order' => (int) ($line['sort_order'] ?? 0),
                'source' => null, 'formula' => null, 'monthly' => null, 'annual' => null, 'error' => null, 'note' => null];
            if ($component->isInput() && ! $component->is_statutory) {
                $rows[] = ['source' => 'input', 'note' => 'Each employee’s own value, read by formulas as '.$component->variableName().'; never paid'
                    .(array_key_exists($component->code, $values) ? ' (sample '.number_format($values[$component->code], 2).')' : '; enter a sample value to try it')] + $row;

                continue;
            }
            if (! self::calculated($component)) {
                $rows[] = ['source' => $component->is_statutory ? 'statutory' : 'one_off', 'note' => $component->is_statutory ? 'Worked out by the compliance engine in payroll' : 'Paid through adjustments, not every month'] + $row;

                continue;
            }

            $formula = $component->formulaFor($line['formula_override'] ?? null);
            $row['formula'] = $formula;
            $row['source'] = $formula === null ? 'fixed' : (filled($line['formula_override'] ?? null) ? 'override' : 'formula');
            try {
                $amount = $formula !== null ? $this->formulas->evaluate($formula, $resolve) : ($values[$component->code] ?? 0.0);
                $usesPf = $usesPf || ($formula !== null && in_array('pf_employer', $this->formulas->variablesIn($formula), true));
            } catch (RuntimeException $e) {
                $row['error'] = $e->getMessage();
                if (! collect($errors)->contains(fn (string $error) => str_starts_with($error, $component->code.' ') || str_starts_with($error, $component->code.':'))) {
                    $errors[] = "{$component->code}: {$e->getMessage()}";
                }
                $amount = 0.0;
            }
            if ($amount < 0) {
                $warnings[] = "{$component->code} works out to ".number_format($amount, 2).' and is paid as 0: payroll never pays a negative component. Wrap the formula in max(0, …) if that is intended.';
            }
            $amount = max(0.0, round($amount, 2));
            $full[$component->variableName()] = $amount;
            if ($formula === null && ! array_key_exists($component->code, $values)) {
                $row['note'] = 'Fixed amount set on each employee’s compensation; enter a sample value to include it';
            }
            $rows[] = ['monthly' => $amount, 'annual' => round($amount * 12, 2)] + $row;
        }

        $calculated = collect($ordered)->filter(fn ($l) => self::calculated($l['component']))->keyBy(fn ($l) => $l['component']->code);
        $sum = fn (callable $where) => round(collect($rows)->filter(fn ($r) => $r['monthly'] !== null && $where($calculated[$r['code']]['component']))->sum('monthly'), 2);
        $gross = $sum(fn (SalaryComponent $c) => $c->isEarning() && $c->include_in_gross);
        $pf = $pfEmployer();
        // HROne setup parity BP-2: a structure built from component amounts has its CTC worked out from them (with the
        // employer PF estimate), so there is nothing to balance.
        $breakup = round($sum(fn (SalaryComponent $c) => $c->include_in_ctc) + ($usesPf || $componentsMode ? $pf : 0.0), 2);
        if ($componentsMode) {
            $ctcMonthly = $breakup;
        }
        $difference = round($ctcMonthly - $breakup, 2);

        if ($ctcMonthly > 0 && abs($difference) >= 1) {
            $warnings[] = $difference > 0
                ? 'The breakup comes to '.number_format($breakup, 2).' a month, '.number_format($difference, 2).' less than monthly CTC ('.number_format($ctcMonthly, 2).'). Add a balancing component last, e.g. max(0, ctc_monthly - basic - hra - … - pf_employer), or change the formulas.'
                : 'The breakup comes to '.number_format($breakup, 2).' a month, '.number_format(-$difference, 2).' more than monthly CTC ('.number_format($ctcMonthly, 2).'). Reduce a component, cap it with min(…), or check the balancing component.';
        }

        $share = null;
        $guardrail = config('peopleos.payroll.guardrails.wage_share');
        if (is_array($guardrail) && $gross > 0) {
            $share = round($sum(fn (SalaryComponent $c) => $c->isEarning() && in_array($c->classification, (array) $guardrail['classifications'], true)) / $gross * 100, 1);
            if ($share < (float) $guardrail['min_percent']) {
                $warnings[] = "Basic + DA is {$share}% of gross pay. Under the Code on Wages, 2019, the part of allowances above {$guardrail['min_percent']}% of total remuneration counts as wages (for PF, gratuity and bonus). PeopleOS does not add that back yet, so confirm this structure with your compliance adviser.";
            }
        }

        return [
            'rows' => $rows,
            'totals' => ['ctc_monthly' => $ctcMonthly, 'gross' => $gross, 'deductions' => $sum(fn (SalaryComponent $c) => $c->type === 'deduction'),
                'employer' => $sum(fn (SalaryComponent $c) => $c->type === 'employer_contribution' && $c->include_in_ctc), 'pf_employer' => $pf, 'pf_in_ctc' => $usesPf,
                'breakup' => $breakup, 'difference' => $difference, 'wage_share' => $share] + ($componentsMode ? ['ctc_mode' => 'components'] : []),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * The formula guide's worked example: the Standard structure as shipped (not the tenant's edits, so
     * the guide reads the same everywhere) previewed for a sample CTC under today's statutory rules, with
     * each formula worked through, the employee PF and ESI payroll deducts, and a month with loss of pay.
     *
     * @param  array<string, float>  $values  fixed amounts by component code
     * @return array<string, mixed>
     */
    public function example(float $ctcAnnual = 600000, array $values = ['CONV' => 1600], int $days = 30, int $lopDays = 2): array
    {
        $defaults = collect(config('peopleos.payroll.defaults'))->keyBy('code');
        $components = collect(CompensationDefaults::STANDARD_COMPONENTS)->filter(fn (string $code) => $defaults->has($code))
            ->mapWithKeys(fn (string $code) => [$code => new SalaryComponent($defaults[$code] + ['is_recurring' => true])]);
        $lines = $components->values()->map(fn (SalaryComponent $component, int $i) => ['component' => $component, 'formula_override' => null, 'sort_order' => ($i + 1) * 10])->all();
        $on = now()->startOfDay();
        $preview = $this->preview($lines, [], ['ctc_annual' => $ctcAnnual, 'values' => $values, 'days' => $days, 'pf_applies' => true, 'pf_restrict_to_ceiling' => true, 'on' => $on]);

        $rows = collect($preview['rows'])->filter(fn (array $row) => $row['monthly'] !== null);
        $known = ['ctc_annual' => $ctcAnnual, 'ctc_monthly' => $preview['totals']['ctc_monthly'], 'days_in_period' => $days, 'paid_days' => $days, 'pf_employer' => $preview['totals']['pf_employer']]
            + $rows->mapWithKeys(fn (array $row) => [strtolower($row['code']) => $row['monthly']])->all();
        $plain = fn (float $value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        $working = $rows->filter(fn (array $row) => $row['formula'] !== null)->mapWithKeys(fn (array $row) => [$row['code'] => preg_replace_callback(
            '/\b[a-z_][a-z0-9_]*\b(?!\s*\()/',
            fn (array $m) => array_key_exists($m[0], $known) ? $plain((float) $known[$m[0]]) : $m[0],
            $row['formula'],
        )])->all();

        $wageRows = fn (string $flag) => $rows->filter(fn (array $row) => $components[$row['code']]->isEarning() && $components[$row['code']]->{$flag});
        $contributions = $this->statutory->contributionsOn(round($wageRows('pf_applicable')->sum('monthly'), 2), round($wageRows('esi_applicable')->sum('monthly'), 2), $on, true);
        $deducted = ($contributions['pf']['employee'] ?? 0.0) + ($contributions['esi']['employee'] ?? 0.0);

        $paidDays = $days - $lopDays;
        $prorated = $rows->filter(fn (array $row) => $components[$row['code']]->is_proratable)->mapWithKeys(fn (array $row) => [$row['code'] => round($row['monthly'] * $paidDays / $days, 2)]);

        return [
            'on' => $on, 'ctc_annual' => $ctcAnnual, 'values' => $values, 'days' => $days, 'preview' => $preview, 'working' => $working,
            'pf' => $contributions['pf'], 'pf_components' => $wageRows('pf_applicable')->pluck('name')->all(), 'esi' => $contributions['esi'], 'take_home' => round($preview['totals']['gross'] - $preview['totals']['deductions'] - $deducted, 2),
            'lop' => ['lop_days' => $lopDays, 'paid_days' => $paidDays, 'amounts' => $prorated->all(),
                'gross' => round($prorated->filter(fn ($amount, string $code) => $components[$code]->isEarning() && $components[$code]->include_in_gross)->sum(), 2)],
        ];
    }

    /** Whether payroll calculates the component from the structure (statutory lines and one-offs are not). */
    public static function calculated(SalaryComponent $component): bool
    {
        return ! $component->is_statutory && $component->calculation_method !== 'statutory' && ! $component->isInput() && $component->is_recurring && in_array($component->type, SalaryComponent::TYPES, true);
    }

    /** Lines in calculation order: sort order, ties in the order given (as the structure's lines are read). */
    private static function ordered(array $lines): array
    {
        $indexed = array_values($lines);
        $keys = array_keys($indexed);
        usort($keys, fn (int $a, int $b) => [(int) ($indexed[$a]['sort_order'] ?? 0), $a] <=> [(int) ($indexed[$b]['sort_order'] ?? 0), $b]);

        return array_map(fn (int $k) => $indexed[$k], $keys);
    }

    private static function closest(string $name, array $known): ?string
    {
        $best = null;
        $distance = PHP_INT_MAX;
        foreach (array_unique($known) as $candidate) {
            $d = levenshtein($name, $candidate);
            if ($d < $distance) {
                [$best, $distance] = [$candidate, $d];
            }
        }

        return $distance <= max(2, intdiv(strlen($name), 4)) ? $best : null;
    }
}
