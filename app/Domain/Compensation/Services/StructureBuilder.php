<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Support\PlainEnglishRules;
use App\Domain\Compensation\Support\StructureRules;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\FormulaEngine;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The salary structure builder (docs/architecture/salary-structure-builder.md): a structure described as plain
 * rules — "% of CTC", "% of another component", a fixed amount, an amount per employee, whatever is left of the
 * CTC, or a custom formula — picked from a list or written in plain English. The rules are put in calculation
 * order and compiled to the formula overrides payroll already runs, then saved through CompensationStructures,
 * so drafts, approval by another person, effective dating and the payroll calculation are unchanged.
 *
 * Components the rules name that are not in the component list are added to it (with statutory flags set the
 * way a standard Indian payroll treats such a component) by someone who may manage payroll setup.
 */
final class StructureBuilder
{
    /** Ready-made starting points, written the way the plain-English box reads them. */
    public const TEMPLATES = [
        'ctc' => "Basic is 50% of CTC\nHRA is 40% of Basic\nSpecial allowance is whatever is left of the CTC",
        'components' => "Basic is entered for each employee\nHRA is 40% of Basic\nSpecial allowance is entered for each employee",
    ];

    public const MODES = [
        'ctc' => 'Each employee gets a CTC, and this structure splits it into components',
        'components' => 'Each employee gets amounts for the components, and the CTC is added up from them',
    ];

    /** Other names people use for the standard components (compared without "allowance", "salary", …). */
    private const ALIASES = [
        ['basic', 'base', 'basic wage', 'basic wages'],
        ['hra', 'house rent', 'rent'],
        ['conv', 'conveyance', 'transport', 'commute'],
        ['special', 'spl', 'flexi', 'flexible', 'flexible benefit'],
        ['lta', 'leave travel', 'ltc', 'leave travel concession'],
        ['da', 'dearness'],
        ['med', 'medical'],
        ['bonus', 'statutory bonus'],
        ['cea', 'children education', 'child education', 'education'],
        ['phone', 'mobile', 'telephone', 'cell phone'],
        ['food', 'meal', 'meal voucher', 'meal vouchers', 'food coupon', 'food coupons', 'lunch'],
        ['ot', 'overtime'],
        ['adv', 'salary advance', 'advance', 'advance recovery'],
        ['nps', 'national pension', 'nps employer'],
    ];

    private const NOISE = ['allowance', 'allowances', 'component', 'amount', 'salary', 'pay'];

    /** @var Collection<int, SalaryComponent>|null */
    private ?Collection $catalogue = null;

    public function __construct(
        private readonly CompensationStructures $structures,
        private readonly StructureFormulas $formulas,
        private readonly PayrollClosureReader $closure,
        private readonly CurrencyRates $currencies,
    ) {}

    /** @return Collection<int, SalaryComponent> the components a structure can use: active and not statutory */
    public function catalogue(): Collection
    {
        return $this->catalogue ??= SalaryComponent::query()->where('status', 'active')->where('is_statutory', false)->where('calculation_method', '!=', 'statutory')
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return array<string, string> key => name, for the component picker */
    public function componentOptions(): array
    {
        return $this->catalogue()->mapWithKeys(fn (SalaryComponent $c) => [(string) $c->id => $c->name.($c->isInput() ? ' (formula input)' : '')])->all();
    }

    /** The catalogue component a written name means (its code, its name, or a common other name for it), or null. */
    public function match(string $name): ?SalaryComponent
    {
        $forms = self::forms($name);
        if ($forms === []) {
            return null;
        }
        $catalogue = $this->catalogue();
        $byCode = $catalogue->first(fn (SalaryComponent $c) => in_array(strtolower($c->code), [str_replace(' ', '_', $forms[0]), str_replace(' ', '', $forms[0])], true));
        if ($byCode) {
            return $byCode;
        }
        $byName = $catalogue->first(fn (SalaryComponent $c) => array_intersect(self::forms($c->name), $forms) !== []);
        if ($byName) {
            return $byName;
        }
        $groups = self::aliasGroups($forms);

        return $groups === [] ? null : $catalogue->first(fn (SalaryComponent $c) => array_intersect(self::aliasGroups([...self::forms($c->name), strtolower($c->code)]), $groups) !== []);
    }

    /** Whether typed text can name a new component (letters first; no brackets, operators or formula words). */
    public static function isName(string $text): bool
    {
        $text = trim($text);

        return (bool) preg_match('/^\pL[\pL\pN &\/\'.,()-]{1,59}$/u', $text) && ! preg_match('/[a-z_]\(|[*=+<>%]/i', $text) && preg_match_all('/\pL/u', $text) >= 2;
    }

    /** The builder key for a written name: the catalogue component's id, or "new:<Name>". */
    public function keyFor(string $name): string
    {
        $component = $this->match($name);

        return $component ? (string) $component->id : 'new:'.trim($name);
    }

    /** @return array<string, string> key => display name, for every row (new components by the name given) */
    public function names(array $rows): array
    {
        $catalogue = $this->catalogue()->keyBy('id');
        $names = [];
        $name = fn (string $key) => str_starts_with($key, 'new:') ? substr($key, 4) : ($catalogue->get((int) $key)?->name ?? 'A removed component');
        foreach ($rows as $row) {
            $key = (string) ($row['component'] ?? '');
            if ($key !== '') {
                $names[$key] = $name($key);
            }
        }
        // Components a percentage refers to that are not listed themselves, so messages can name them.
        foreach ($rows as $row) {
            $of = (string) ($row['of'] ?? '');
            if ($of !== '' && ! isset($names[$of])) {
                $names[$of] = $name($of);
            }
        }

        return $names;
    }

    /**
     * Plain English → builder rows, with a line-by-line report of what each line was read as.
     *
     * @return array{rows: array<string, array<string, mixed>>, report: list<array{line: string, status: string, text: string}>}
     */
    public function fromEnglish(string $text, string $mode = 'ctc', ?User $actor = null): array
    {
        $rows = [];
        $report = [];
        $parsed = PlainEnglishRules::parse($text);
        $keys = [];
        foreach ($parsed as $i => $line) {
            if ($line['skip'] || $line['error'] || $line['name'] === null) {
                continue;
            }
            $keys[$i] = $this->keyFor($line['name']);
        }
        $names = [];
        foreach ($keys as $i => $key) {
            $names[$key] ??= str_starts_with($key, 'new:') ? substr($key, 4) : $this->catalogue()->firstWhere('id', (int) $key)?->name;
        }

        foreach ($parsed as $i => $line) {
            $entry = ['line' => $line['line'], 'status' => 'ok', 'text' => ''];
            if ($line['skip']) {
                $report[] = ['status' => 'skipped', 'text' => $line['skip']] + $entry;

                continue;
            }
            if ($line['error']) {
                $report[] = ['status' => 'error', 'text' => $line['error']] + $entry;

                continue;
            }
            $key = $keys[$i];
            $component = str_starts_with($key, 'new:') ? null : $this->catalogue()->firstWhere('id', (int) $key);
            $row = ['component' => $key, 'new_type' => $component ? null : ($line['type'] ?? 'earning'), 'how' => $line['how'], 'value' => $line['value'], 'of' => null,
                'min' => $line['min'], 'max' => $line['max'], 'formula' => $line['formula'], 'nature' => $line['nature'], 'frequency' => 'monthly'];

            $problem = match (true) {
                isset(array_column(array_values($rows), 'component', 'component')[$key]) => "{$names[$key]} is already listed above; keep one line for it.",
                $component !== null && $component->isInput() => null,
                $mode === 'components' && in_array($line['how'], StructureRules::NEEDS_CTC, true) => 'This structure adds up the CTC from component amounts, so there is no CTC to '.($line['how'] === 'balance' ? 'balance' : 'take a % of').'. Use a fixed amount, an amount per employee, or a % of another component.',
                $component === null && $actor !== null && ! $actor->hasPermission('payroll.manage') => "{$names[$key]} is not in your component list, and adding components needs payroll setup access. Use an existing name, or ask your payroll administrator to add it.",
                default => null,
            };
            if ($line['how'] === 'percent_of' && $problem === null) {
                $ofKey = $this->keyFor((string) $line['of']);
                if ($ofKey === $key) {
                    $problem = "{$names[$key]} cannot be a % of itself.";
                } elseif (! in_array($ofKey, $keys, true)) {
                    $problem = "{$names[$key]} is a % of {$line['of']}, which is not in your list. Add a line for {$line['of']} too.";
                } else {
                    $row['of'] = $ofKey;
                }
            }
            if ($component !== null && $component->isInput()) {
                $row = ['how' => 'input', 'value' => null, 'min' => null, 'max' => null, 'formula' => null] + $row;
            }
            if ($problem !== null) {
                $report[] = ['status' => 'error', 'text' => $problem] + $entry;

                continue;
            }
            if ($row['how'] === 'formula') {
                $variables = collect($keys)->mapWithKeys(fn (string $k) => [$this->variableName($k) => $k])->all();
                $read = StructureRules::fromFormula($row['formula'], $variables);
                if ($read['how'] !== 'formula' && ! ($mode === 'components' && in_array($read['how'], StructureRules::NEEDS_CTC, true))) {
                    $row = $read + $row;
                }
            }
            $rows[(string) Str::uuid()] = $row;
            $sentence = StructureRules::sentence($row, $names, $row['new_type'] !== 'earning' ? $row['new_type'] : null);
            $report[] = ['status' => $line['note'] ? 'note' : ($component ? 'ok' : 'new'),
                'text' => $sentence.($component ? '' : ' — a new component').($line['note'] ? '. '.$line['note'] : '')] + $entry;
        }

        return ['rows' => $rows, 'report' => $report];
    }

    /** The rows as plain-English lines, one per component (what the plain-English box shows for a structure). */
    public function toEnglish(array $rows): string
    {
        $names = $this->names($rows);

        return collect($rows)->filter(fn ($row) => filled($row['component'] ?? null))
            ->map(fn (array $row) => StructureRules::sentence($row, $names, str_starts_with((string) $row['component'], 'new:') && ($row['new_type'] ?? 'earning') !== 'earning' ? $row['new_type'] : null))
            ->implode("\n");
    }

    /** @return array<string, array<string, mixed>> the rows of the ready-made structure */
    public function template(string $mode): array
    {
        return $this->fromEnglish(self::TEMPLATES[$mode] ?? self::TEMPLATES['ctc'], $mode)['rows'];
    }

    /** The version the builder opens: the draft, else the one waiting for approval, else the latest approved. */
    public function versionFor(SalaryStructure $structure): ?SalaryStructureVersion
    {
        $versions = $structure->versions()->getQuery()->whereNot('status', 'archived')->with('structure')->reorder('version', 'desc')->get();

        return $versions->firstWhere('status', 'draft') ?? $versions->firstWhere('status', 'pending_approval') ?? $versions->first(fn (SalaryStructureVersion $v) => $v->isApproved());
    }

    /**
     * A structure's rules and settings as builder state: company_id, narrow_by / grade_ids / applicability (who it
     * applies to), effective_from, currency, ctc_mode and the rule rows.
     *
     * @return array<string, mixed>
     */
    public function load(SalaryStructure $structure): array
    {
        $version = $this->versionFor($structure);
        $state = ['name' => $structure->name, 'ctc_mode' => 'ctc', 'company_id' => null, 'narrow_by' => [], 'grade_ids' => [], 'applicability' => [], 'rules' => [],
            'currency' => $this->currencies->baseCurrency(), 'effective_from' => $this->defaultStart($structure)];
        if ($version === null) {
            return $state;
        }
        $restrictions = $version->restrictions();

        return ['rules' => $this->rowsOf($version), 'ctc_mode' => $version->formulaOptions()['ctc_mode'], 'currency' => $version->currency,
            'effective_from' => $version->isDraft() ? $version->effective_from->toDateString() : $this->defaultStart($structure),
            'company_id' => $version->company_id, 'narrow_by' => array_keys($restrictions), 'grade_ids' => $restrictions['grade_id'] ?? [],
            'applicability' => array_diff_key($restrictions, ['grade_id' => true])] + $state;
    }

    /** @return array<string, array<string, mixed>> a version's lines as builder rows (keyed by a fresh id each) */
    public function rowsOf(SalaryStructureVersion $version): array
    {
        $lines = $version->components()->with('component')->get()->filter(fn ($line) => $line->component !== null);
        $variables = $lines->mapWithKeys(fn ($line) => [$line->component->variableName() => (string) $line->component->id])->all();
        $balancing = $version->balancingComponentId();
        $rows = [];
        foreach ($lines as $line) {
            $component = $line->component;
            $rule = $component->isInput()
                ? ['how' => 'input', 'value' => null, 'of' => null, 'min' => null, 'max' => null, 'formula' => null]
                : StructureRules::fromFormula($component->formulaFor($line->formula_override), $variables, (array) ($version->parameters ?? []), $balancing === (int) $component->id);
            $rows[(string) Str::uuid()] = ['component' => (string) $component->id, 'new_type' => null, 'nature' => $line->pay_nature ?: 'fixed', 'frequency' => $line->frequency ?: 'monthly'] + $rule;
        }

        return $rows;
    }

    /**
     * The rows in calculation order with the formula each runs, or the problems that stop it, in plain words.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return array{problems: list<string>, lines: list<array{component: SalaryComponent, formula_override: ?string, sort_order: int}>, rows: array<string, array<string, mixed>>, components: array<string, SalaryComponent>, balancing: ?string, names: array<string, string>}
     */
    public function compile(array $rows, string $mode = 'ctc', ?User $actor = null): array
    {
        $listed = collect($rows)->filter(fn ($row) => is_array($row))->values();
        $rows = $listed->mapWithKeys(fn (array $row, int $i) => [(string) ($row['component'] ?? '') !== '' ? (string) $row['component'] : "row:{$i}" => $row])->all();
        $names = $this->names($listed->all());
        $problems = [];
        if ($listed->isEmpty()) {
            $problems[] = 'Add at least one component.';
        }
        $seen = [];
        foreach ($listed as $i => $row) {
            $key = (string) ($row['component'] ?? '');
            if ($key === '') {
                $problems[] = 'Row '.($i + 1).': pick a component.';
            } elseif (isset($seen[$key])) {
                $problems[] = "{$names[$key]} is in the list twice; keep one row for it.";
            }
            $seen[$key] = true;
        }
        $rows = array_filter($rows, fn ($row, $key) => ! str_starts_with((string) $key, 'row:'), ARRAY_FILTER_USE_BOTH);

        $catalogue = $this->catalogue()->keyBy(fn (SalaryComponent $c) => (string) $c->id);
        $taken = SalaryComponent::query()->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all();
        $components = [];
        foreach ($rows as $key => $row) {
            if (str_starts_with($key, 'new:')) {
                if ($actor !== null && ! $actor->hasPermission('payroll.manage')) {
                    $problems[] = "{$names[$key]} is not in your component list, and adding components needs payroll setup access.";
                }
                $components[$key] = $this->newComponent(substr($key, 4), (string) ($row['new_type'] ?: 'earning'), $row, $taken);
                $taken[] = $components[$key]->code;
            } elseif ($catalogue->has($key)) {
                $components[$key] = $catalogue[$key];
            } else {
                $problems[] = "{$names[$key]} is no longer an active component; remove it or pick another.";
            }
        }
        $rows = array_intersect_key($rows, $components);

        $balancing = [];
        foreach ($rows as $key => $row) {
            $name = $names[$key];
            $component = $components[$key];
            $how = (string) ($row['how'] ?? '');
            $value = $row['value'] ?? null;
            if ($component->isInput()) {
                continue;
            }
            if (! StructureFormulas::calculated($component)) {
                $problems[] = "{$name} is paid only now and then (through payroll adjustments), so it cannot be part of a monthly structure.";

                continue;
            }
            if (! array_key_exists($how, StructureRules::HOWS)) {
                $problems[] = "{$name}: choose how it is worked out.";

                continue;
            }
            if ($mode === 'components' && in_array($how, StructureRules::NEEDS_CTC, true)) {
                $problems[] = "{$name}: this structure adds up the CTC from component amounts, so there is no CTC to ".($how === 'balance' ? 'balance' : 'take a % of').'. Use a fixed amount, an amount per employee, or a % of another component.';
            }
            if (in_array($how, StructureRules::PERCENT, true) && (! is_numeric($value) || (float) $value <= 0)) {
                $problems[] = "{$name}: enter the percentage.";
            } elseif ($how === 'percent_ctc' && (float) $value > 100) {
                $problems[] = "{$name}: a share of CTC cannot be more than 100%.";
            } elseif ($how === 'fixed' && (! is_numeric($value) || (float) $value < 0)) {
                $problems[] = "{$name}: enter the monthly amount.";
            }
            if (in_array($how, StructureRules::PERCENT, true)) {
                foreach (['min' => 'minimum', 'max' => 'maximum'] as $field => $label) {
                    if (filled($row[$field] ?? null) && (! is_numeric($row[$field]) || (float) $row[$field] < 0)) {
                        $problems[] = "{$name}: the {$label} must be an amount.";
                    }
                }
                if (is_numeric($row['min'] ?? null) && is_numeric($row['max'] ?? null) && (float) $row['max'] > 0 && (float) $row['min'] > (float) $row['max']) {
                    $problems[] = "{$name}: the minimum is more than the maximum.";
                }
            }
            if ($how === 'percent_of') {
                $of = (string) ($row['of'] ?? '');
                match (true) {
                    $of === '' => $problems[] = "{$name}: choose which component it is a % of.",
                    $of === $key => $problems[] = "{$name} cannot be a % of itself.",
                    ! isset($rows[$of]) => $problems[] = "{$name} is a % of ".($names[$of] ?? 'a component').', which is not in the list.',
                    ($rows[$of]['how'] ?? null) === 'balance' => $problems[] = "{$name} cannot be a % of {$names[$of]}, because {$names[$of]} takes whatever is left.",
                    default => null,
                };
            }
            if ($how === 'per_employee' && $component->exists && $component->calculation_method === 'formula' && filled($component->formula)) {
                $problems[] = "{$name} is worked out by its own formula in the component list, so it cannot be entered per employee. Choose another way, or pick a different component.";
            }
            if ($how === 'formula') {
                if (blank($row['formula'] ?? null)) {
                    $problems[] = "{$name}: write the formula.";
                } else {
                    try {
                        app(FormulaEngine::class)->validate((string) $row['formula']);
                    } catch (Throwable $e) {
                        $problems[] = "{$name}: ".$e->getMessage();
                    }
                }
            }
            if ($how === 'balance') {
                $balancing[] = $key;
                if (! $component->isEarning() || ! $component->include_in_ctc) {
                    $problems[] = "{$name} cannot take what is left of the CTC: choose an earning that is part of CTC (usually Special allowance).";
                }
            }
        }
        if (count($balancing) > 1) {
            $problems[] = 'Only one component can take whatever is left of the CTC; '.collect($balancing)->map(fn ($k) => $names[$k])->join(', ', ' and ').' all do.';
        }

        [$order, $cycle] = $this->order($rows, $components, $balancing);
        if ($cycle !== null) {
            $problems[] = collect($cycle)->map(fn ($k) => $names[$k])->join(', ', ' and ').' depend on each other; one of them has to be worked out first.';
        }

        $lines = [];
        if ($problems === []) {
            foreach ($order as $i => $key) {
                $row = $rows[$key];
                $of = ($row['how'] ?? null) === 'percent_of' ? $components[(string) $row['of']]->variableName() : null;
                $formula = in_array($key, $balancing, true) || $components[$key]->isInput() ? null : StructureRules::formula($row, $of);
                $lines[] = ['component' => $components[$key], 'formula_override' => $formula, 'sort_order' => ($i + 1) * 10, 'key' => $key];
            }
            foreach ($lines as $i => $line) {
                if (in_array($line['key'], $balancing, true)) {
                    $lines[$i]['formula_override'] = $this->formulas->balancingFormula($lines, $line['component']);
                }
            }
            foreach ($this->formulas->check($lines, [], ['ctc_mode' => $mode])['errors'] as $error) {
                $problems[] = $this->plain($error, $components, $names);
            }
        }

        return ['problems' => array_values(array_unique($problems)), 'lines' => $problems === [] ? $lines : [], 'rows' => $rows, 'components' => $components,
            'balancing' => $balancing[0] ?? null, 'names' => $names];
    }

    /**
     * The monthly breakup for a sample employee, with what to look at, in plain words.
     *
     * @param  array{ctc?: mixed, pf?: bool, amounts?: array<string, mixed>, on?: mixed}  $sample  amounts by builder key
     * @return array{problems: list<string>, rows: list<array<string, mixed>>, totals: array<string, mixed>, warnings: list<string>}
     */
    public function preview(array $rows, string $mode, array $sample, ?User $actor = null): array
    {
        $compiled = $this->compile($rows, $mode, $actor);
        if ($compiled['problems'] !== []) {
            return ['problems' => $compiled['problems'], 'rows' => [], 'totals' => [], 'warnings' => []];
        }
        $values = [];
        foreach ((array) ($sample['amounts'] ?? []) as $key => $amount) {
            if (isset($compiled['components'][$key]) && is_numeric($amount)) {
                $values[$compiled['components'][$key]->code] = (float) $amount;
            }
        }
        $on = rescue(fn () => Carbon::parse($sample['on'] ?? now()), now(), false)->max(now())->startOfDay();
        $result = $this->formulas->preview($compiled['lines'], [], ['ctc_mode' => $mode, 'ctc_annual' => (float) ($sample['ctc'] ?? 0), 'values' => $values, 'days' => 30,
            'pf_applies' => (bool) ($sample['pf'] ?? true), 'pf_restrict_to_ceiling' => true, 'on' => $on]);

        $byCode = collect($compiled['lines'])->keyBy(fn ($line) => $line['component']->code);
        $out = [];
        foreach ($result['rows'] as $row) {
            $key = $byCode[$row['code']]['key'];
            $out[] = ['key' => $key, 'name' => $row['name'], 'type' => $row['type'], 'how' => StructureRules::short($compiled['rows'][$key], $compiled['names']),
                'monthly' => $row['monthly'], 'annual' => $row['annual'], 'nature' => $compiled['rows'][$key]['nature'] ?? 'fixed',
                'missing' => ($compiled['rows'][$key]['how'] ?? null) === 'per_employee' && ! array_key_exists($row['code'], $values)];
        }
        $t = $result['totals'];
        $warnings = [];
        if ($mode === 'ctc' && $t['ctc_monthly'] > 0 && abs($t['difference']) >= 1) {
            $warnings[] = $t['difference'] > 0
                ? 'The components add up to '.StructureRules::money($t['breakup']).' a month, '.StructureRules::money($t['difference']).' less than the monthly CTC of '.StructureRules::money($t['ctc_monthly']).'. Let one component take whatever is left of the CTC (usually Special allowance).'
                : 'The components add up to '.StructureRules::money($t['breakup']).' a month, '.StructureRules::money(-$t['difference']).' more than the monthly CTC of '.StructureRules::money($t['ctc_monthly']).'. Lower a percentage or an amount.';
        }
        foreach ($result['warnings'] as $warning) {
            if (preg_match('/^(\w+) works out to (-?[\d,.]+) and is paid as 0/', $warning, $m) && $byCode->has($m[1])) {
                $warnings[] = $byCode[$m[1]]['component']->name.' works out below zero ('.$m[2].') for this CTC, so it would be paid as 0.';
            } elseif (str_starts_with($warning, 'Basic + DA is')) {
                $warnings[] = 'Basic (with DA) is '.$t['wage_share'].'% of gross pay. Under the Code on Wages, allowances above half of pay count as wages for PF, gratuity and bonus; confirm this split with your compliance adviser.';
            } elseif (! str_contains($warning, 'monthly CTC')) {
                $warnings[] = $warning;
            }
        }

        return ['problems' => [], 'rows' => $out, 'totals' => $t, 'warnings' => $warnings];
    }

    /**
     * Saves the structure: a new structure with a draft version 1, or the structure's draft (a new draft version
     * when the latest is approved). With $submit the draft goes for approval by another person.
     *
     * @param  array<string, mixed>  $state  the builder's form state
     */
    public function save(array $state, ?SalaryStructure $structure, User $actor, bool $submit = false): SalaryStructureVersion
    {
        if (! $actor->hasPermission('compensation.configure')) {
            throw new CompensationRuleViolation('Building salary structures needs compensation.configure.');
        }
        $name = trim((string) ($state['name'] ?? ''));
        if ($name === '') {
            throw new CompensationRuleViolation('Give the structure a name.');
        }
        $mode = ($state['ctc_mode'] ?? 'ctc') === 'components' ? 'components' : 'ctc';
        $compiled = $this->compile((array) ($state['rules'] ?? []), $mode, $actor);
        if ($compiled['problems'] !== []) {
            throw new CompensationRuleViolation(implode(' ', $compiled['problems']));
        }
        if ($problem = $this->dateProblem($structure, $state['effective_from'] ?? null)) {
            throw new CompensationRuleViolation($problem);
        }

        return DB::transaction(function () use ($state, $structure, $actor, $submit, $name, $mode, $compiled) {
            // A component the rules add carries this structure's formula as its own, so it reads the same anywhere.
            $next = (int) SalaryComponent::query()->max('sort_order');
            foreach ($compiled['lines'] as $line) {
                $component = $line['component'];
                if (! $component->exists) {
                    $next += 10;
                    $component->sort_order = $next;
                    $component->formula = $component->calculation_method === 'formula' ? $line['formula_override'] : null;
                    $component->save();
                }
            }
            $rows = collect($compiled['lines'])->map(fn (array $line) => [
                'salary_component_id' => $line['component']->id, 'formula_override' => $line['formula_override'], 'sort_order' => $line['sort_order'],
                'pay_nature' => ($compiled['rows'][$line['key']]['nature'] ?? 'fixed') === 'variable' ? 'variable' : 'fixed',
                'frequency' => array_key_exists((string) ($compiled['rows'][$line['key']]['frequency'] ?? ''), (array) config('peopleos.compensation.component_frequencies')) ? $compiled['rows'][$line['key']]['frequency'] : 'monthly',
            ])->all();
            $data = self::applicability($state) + [
                'effective_from' => $state['effective_from'] ?? now()->toDateString(), 'currency' => $state['currency'] ?? $this->currencies->baseCurrency(), 'pay_frequency' => 'monthly',
                'ctc_mode' => $mode, 'balancing_component_id' => $compiled['balancing'] !== null ? $compiled['components'][$compiled['balancing']]->id : null,
                'parameters' => [], 'components' => $rows,
            ];

            if ($structure === null) {
                $structure = $this->structures->create(['name' => $name, 'code' => $this->structureCode($name), 'change_note' => 'Set up with the salary structure builder.'] + $data, $actor);
                $version = $structure->versions()->getQuery()->reorder('version', 'desc')->firstOrFail();
            } else {
                $current = $this->versionFor($structure);
                if ($current?->status === 'pending_approval') {
                    throw new CompensationRuleViolation('This structure has a version waiting for approval. It can be changed again once it is approved or sent back.');
                }
                if ($structure->name !== $name) {
                    $structure->update(['name' => $name]);
                }
                $approved = $structure->versions()->getQuery()->whereIn('status', SalaryStructureVersion::APPROVED)->reorder('version', 'desc')->first();
                $before = $approved ? $this->toEnglish($this->rowsOf($approved)) : '';
                $version = $current?->isDraft() ? $current : $this->structures->newVersion($structure, $actor, $data['effective_from']);
                $note = self::changeNote($before, $this->toEnglish($compiled['rows']));
                $version = $this->structures->updateDraft($version, $data + ['change_note' => $note], $actor);
            }
            if ($submit) {
                $version = $this->structures->submit($version, $actor);
            }

            return $version->refresh();
        });
    }

    /** A plain reason the start date cannot be used (payroll already closed, or not after the version in force), or null. */
    public function dateProblem(?SalaryStructure $structure, mixed $date): ?string
    {
        try {
            $from = Carbon::parse((string) $date)->startOfDay();
        } catch (Throwable) {
            return 'Pick the date this structure starts from.';
        }
        $closed = $this->closure->latestClosedPeriodEnd();
        if ($closed !== null && $from->lte($closed)) {
            return 'Payroll is already finalized up to '.Carbon::parse($closed)->format('j M Y').'; pick a start date after that.';
        }
        $latest = $structure?->versions()->getQuery()->whereIn('status', SalaryStructureVersion::APPROVED)->reorder('effective_from', 'desc')->first();
        if ($latest !== null && $from->lte($latest->effective_from)) {
            return 'The version in use started on '.$latest->effective_from->format('j M Y').'; changes must start after that date.';
        }

        return null;
    }

    /** The first date a new structure, or a change to this one, can start: this month, after closed payroll and the version in force. */
    public function defaultStart(?SalaryStructure $structure = null): string
    {
        $start = Carbon::today()->startOfMonth();
        if ($closed = $this->closure->latestClosedPeriodEnd()) {
            $start = $start->max(Carbon::parse($closed)->addDay()->startOfDay());
        }
        if ($latest = $structure?->versions()->getQuery()->whereIn('status', SalaryStructureVersion::APPROVED)->reorder('effective_from', 'desc')->first()) {
            $start = $start->max($latest->effective_from->copy()->addDay()->startOfDay());
            // A change usually starts with a payroll month.
            if ($start->day !== 1) {
                $start = $start->addMonthNoOverflow()->startOfMonth();
            }
        }

        return $start->toDateString();
    }

    /** "Changed: HRA is 40% of Basic → HRA is 50% of Basic. Added: …" — the version's change note, for the approver. */
    public static function changeNote(string $before, string $after): string
    {
        $old = array_values(array_filter(array_map('trim', explode("\n", $before))));
        $new = array_values(array_filter(array_map('trim', explode("\n", $after))));
        $subject = fn (string $line) => strtolower(trim((string) preg_split('/\s(?:is|=)\s/', $line, 2)[0]));
        $oldBy = collect($old)->keyBy($subject);
        $newBy = collect($new)->keyBy($subject);
        $parts = [];
        $changed = $newBy->filter(fn ($line, $k) => $oldBy->has($k) && $oldBy[$k] !== $line)->map(fn ($line, $k) => $oldBy[$k].' → '.$line)->values();
        $added = $newBy->diffKeys($oldBy)->values();
        $removed = $oldBy->diffKeys($newBy)->values();
        if ($changed->isNotEmpty()) {
            $parts[] = 'Changed: '.$changed->implode('; ');
        }
        if ($added->isNotEmpty()) {
            $parts[] = 'Added: '.$added->implode('; ');
        }
        if ($removed->isNotEmpty()) {
            $parts[] = 'Removed: '.$removed->implode('; ');
        }

        return Str::limit($parts === [] ? 'Updated with the salary structure builder (same components).' : implode('. ', $parts).'.', 2000);
    }

    /** The variable name a row's component has in formulas. */
    private function variableName(string $key): string
    {
        return str_starts_with($key, 'new:') ? strtolower($this->newCode(substr($key, 4), [])) : (string) $this->catalogue()->firstWhere('id', (int) $key)?->variableName();
    }

    /**
     * Calculation order: each component after the ones it uses, otherwise as listed; whatever-is-left last.
     *
     * @return array{0: list<string>, 1: ?list<string>} the order, and the components of a dependency loop (null = none)
     */
    private function order(array $rows, array $components, array $balancing): array
    {
        $byVariable = collect($components)->mapWithKeys(fn (SalaryComponent $c, string $key) => [$c->variableName() => $key])->all();
        $deps = [];
        foreach ($rows as $key => $row) {
            $deps[$key] = match ($row['how'] ?? null) {
                'percent_of' => isset($rows[(string) ($row['of'] ?? '')]) ? [(string) $row['of']] : [],
                'formula' => array_values(array_filter(array_map(fn ($name) => $byVariable[$name] ?? null, StructureRules::namesIn((string) ($row['formula'] ?? ''))), fn ($k) => $k !== null && $k !== $key)),
                default => [],
            };
            $deps[$key] = array_values(array_diff($deps[$key], $balancing));
        }
        $order = [];
        $state = [];
        $cycle = null;
        $visit = function (string $key, array $path) use (&$visit, &$order, &$state, &$cycle, $deps): void {
            if (($state[$key] ?? null) === 'done' || $cycle !== null) {
                return;
            }
            if (($state[$key] ?? null) === 'active') {
                $cycle = array_slice($path, array_search($key, $path, true));

                return;
            }
            $state[$key] = 'active';
            foreach ($deps[$key] ?? [] as $dep) {
                $visit($dep, [...$path, $key]);
            }
            $state[$key] = 'done';
            $order[] = $key;
        };
        foreach (array_keys($rows) as $key) {
            if (! in_array($key, $balancing, true)) {
                $visit($key, []);
            }
        }

        return [[...$order, ...array_values(array_intersect($balancing, array_keys($rows)))], $cycle !== null ? array_values(array_unique($cycle)) : null];
    }

    /** An unsaved component for a name the rules introduce, flagged the way a standard Indian payroll treats it. */
    private function newComponent(string $name, string $type, array $row, array $taken): SalaryComponent
    {
        $type = in_array($type, SalaryComponent::TYPES, true) ? $type : 'earning';
        $lower = strtolower($name);
        $variable = ($row['nature'] ?? 'fixed') === 'variable';
        $classification = match (true) {
            $type === 'deduction' => 'other_deduction',
            $type === 'reimbursement' || str_contains($lower, 'reimburs') => 'reimbursement',
            str_contains($lower, 'gratuity') => 'gratuity',
            (bool) preg_match('/\bbasic\b/', $lower) => 'basic',
            (bool) preg_match('/\b(dearness|da)\b/', $lower) => 'da',
            (bool) preg_match('/\b(hra|house rent)\b/', $lower) => 'hra',
            (bool) preg_match('/\bbonus\b/', $lower) => 'bonus',
            $variable || (bool) preg_match('/\b(incentive|commission|performance)\b/', $lower) => 'incentive',
            $type === 'earning' => 'allowance',
            default => 'other',
        };
        $earning = $type === 'earning';
        $perEmployee = ($row['how'] ?? null) === 'per_employee';

        return new SalaryComponent([
            'name' => Str::limit($name, 120, ''), 'code' => $this->newCode($name, $taken), 'type' => $type, 'classification' => $classification,
            'calculation_method' => $perEmployee ? 'fixed' : 'formula', 'formula' => null,
            'taxable' => $earning, 'pf_applicable' => in_array($classification, ['basic', 'da'], true), 'esi_applicable' => $earning && ! $variable,
            'include_in_ctc' => $type !== 'deduction', 'include_in_gross' => in_array($type, ['earning', 'reimbursement'], true),
            'is_recurring' => true, 'is_proratable' => $earning && ! $variable, 'is_arrear_eligible' => $earning, 'pay_nature' => $variable ? 'variable' : 'fixed',
            'is_statutory' => false, 'status' => 'active',
        ]);
    }

    /** MEDICAL_ALLOWANCE for "Medical allowance": unique, not a formula word or a statutory code. */
    private function newCode(string $name, array $taken): string
    {
        $base = strtoupper(Str::limit(trim((string) preg_replace('/_+/', '_', preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($name))), '_'), 24, ''));
        $base = rtrim($base, '_');
        if ($base === '' || ! preg_match('/^[A-Z]/', $base)) {
            $base = 'C_'.$base;
        }
        $blocked = [...array_map('strtoupper', FormulaVariables::reserved()), ...SalaryComponent::STATUTORY_CODES, ...array_map('strtoupper', $taken)];
        $code = $base;
        for ($n = 2; in_array($code, $blocked, true); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }

    /** A unique structure code from its name: "Staff – Mumbai" → STAFF_MUMBAI. */
    private function structureCode(string $name): string
    {
        $base = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($name)), '_')) ?: 'STRUCTURE';
        $base = rtrim(substr($base, 0, 26), '_');
        $code = $base;
        for ($n = 2; SalaryStructure::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }

    /** A structure check message with codes put back as names (the builder hides codes). */
    private function plain(string $error, array $components, array $names): string
    {
        foreach ($components as $key => $component) {
            $error = preg_replace('/\b'.preg_quote($component->code, '/').'\b/', $names[$key] ?? $component->name, $error);
        }

        return $error;
    }

    /**
     * Who the structure is for, as CompensationStructures takes it: the company, and the narrowing of only the
     * dimensions ticked in narrow_by (an unticked dimension is cleared even if values are still in the state).
     *
     * @return array{company_id: mixed, grade_ids: list<int>, applicability: array<string, list<int>>}
     */
    private static function applicability(array $state): array
    {
        $ticked = (array) ($state['narrow_by'] ?? []);
        $narrowing = (array) ($state['applicability'] ?? []);

        return [
            'company_id' => filled($state['company_id'] ?? null) ? $state['company_id'] : null,
            'grade_ids' => in_array('grade_id', $ticked, true) ? array_values((array) ($state['grade_ids'] ?? [])) : [],
            'applicability' => collect(SalaryStructureVersion::dimensions())->except('grade_id')
                ->map(fn (string $label, string $column) => in_array($column, $ticked, true) ? array_values((array) ($narrowing[$column] ?? [])) : [])->all(),
        ];
    }

    /** "Medical Allowance" → ['medical allowance', 'medical']: the name, and without words like "allowance". @return list<string> */
    private static function forms(string $name): array
    {
        // "Leave travel allowance (LTA)" is known by both: the words outside the brackets and the ones inside.
        if (preg_match('/^(.+?)\s*\(([^()]+)\)\s*$/', $name, $m)) {
            return array_values(array_unique([...self::forms($m[1]), ...self::forms($m[2])]));
        }
        $plain = trim((string) preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', str_replace('&', ' and ', strtolower(Str::ascii($name))))));
        if ($plain === '') {
            return [];
        }
        $words = array_values(array_filter(explode(' ', $plain), fn ($w) => ! in_array($w, self::NOISE, true)));
        $stripped = implode(' ', $words);

        return array_values(array_unique(array_filter([$plain, $stripped])));
    }

    /** @return list<int> the alias groups a set of name forms belongs to */
    private static function aliasGroups(array $forms): array
    {
        $groups = [];
        foreach (self::ALIASES as $i => $group) {
            if (array_intersect($group, $forms) !== []) {
                $groups[] = $i;
            }
        }

        return $groups;
    }
}
