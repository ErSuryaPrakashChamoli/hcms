<?php

namespace App\Domain\Configuration\Cloning;

/**
 * Rewrites a source's applicability for one destination company and proves the result stays inside the destinations
 * (FR-12 to FR-16, the boundedness invariant):
 *
 * - Rule conditions: every copied rule is "all of" and starts with `company_id is any of [destination]`; the source's
 *   company conditions are replaced, conditions on values every company shares (designation, grade, level, employment
 *   type, category, work mode, gender, tenure, lifecycle state) are kept exactly, conditions on company-owned units are
 *   mapped to the destination's units (suggested by name, or chosen, or removed by choice), and a rule naming employees
 *   is not copied (dropping the condition would widen it). A rule with no conditions (everyone) gets the company
 *   condition: it is never copied as everyone. "Any of" rules with several conditions cannot be bounded (no nested
 *   groups in the rule engine): the clone stops until they are rewritten as "all of".
 * - A company_id column: the destination (a null source, every company, becomes the destination).
 * - A list of company ids: the destination only.
 *
 * In a variation (the destination is a company the source already reaches) rules that never reach that company are not
 * copied; for another company every rule is carried over.
 */
final class ApplicabilityRewriter
{
    public const COMPANY = 'company_id';

    /** Values every company shares: kept exactly. */
    public const SHARED = ['designation_id', 'level_id', 'grade_id', 'employment_type_id', 'employee_category_id', 'work_mode_id', 'lifecycle_state', 'gender', 'tenure_months'];

    /** People: a rule naming them is not copied. */
    public const PERSON = ['employee_id'];

    public function __construct(private readonly OrgUnitMapper $units) {}

    /** How a rule field is treated: company, unit, shared or person; null = not classified (the plan fails). */
    public static function classify(string $field): ?string
    {
        return match (true) {
            $field === self::COMPANY => 'company',
            OrgUnitMapper::isUnit($field) => 'unit',
            in_array($field, self::SHARED, true) => 'shared',
            in_array($field, self::PERSON, true) => 'person',
            default => null,
        };
    }

    /**
     * The companies the applicability reaches today: null = every company (or no way to tell); [] = nobody.
     *
     * @param  array<string, mixed>  $applicability
     * @param  list<int>  $companies  every company of the tenant
     * @return list<int>|null
     */
    public function reach(ApplicabilityKind $kind, array $applicability, array $companies): ?array
    {
        if ($kind === ApplicabilityKind::CompanyColumn) {
            return ($applicability['company_id'] ?? null) === null ? null : [(int) $applicability['company_id']];
        }
        if ($kind === ApplicabilityKind::CompanyIdList) {
            $ids = array_values(array_unique(array_map('intval', (array) ($applicability['company_ids'] ?? []))));

            return $ids === [] ? null : $ids;
        }
        if ($kind === ApplicabilityKind::None) {
            return null;
        }

        $reach = [];
        foreach ((array) ($applicability['rules'] ?? []) as $rule) {
            $rule = $this->ruleReach((array) $rule, $companies);
            if ($rule === null) {
                return null;
            }
            $reach = [...$reach, ...$rule];
        }
        $reach = array_values(array_unique($reach));
        sort($reach);

        return $reach;
    }

    /**
     * @param  array<string, mixed>  $applicability
     * @param  array<string, array<int|string, int|string|null>>  $choices  field => source unit id => destination unit id | 'drop'
     * @param  list<int>  $companies  every company of the tenant
     * @return array{after: array<string, mixed>, notes: list<string>, mapped: list<array{field: string, source_id: int, target_id: int}>, unresolved: list<array{field: string, source_id: int, name: string}>, blockers: list<Conflict>}
     */
    public function rewrite(ApplicabilityKind $kind, array $applicability, int $companyId, bool $variation, array $choices, ?int $priority, array $companies): array
    {
        $result = ['after' => [], 'notes' => [], 'mapped' => [], 'unresolved' => [], 'blockers' => []];
        if ($kind === ApplicabilityKind::CompanyColumn) {
            return ['after' => ['company_id' => $companyId]] + $result;
        }
        if ($kind === ApplicabilityKind::CompanyIdList) {
            return ['after' => ['company_ids' => [$companyId]]] + $result;
        }
        if ($kind === ApplicabilityKind::None) {
            return ['after' => $applicability] + $result;
        }

        $rules = [];
        foreach ((array) ($applicability['rules'] ?? []) as $rule) {
            $rule = (array) $rule;
            $name = (string) ($rule['name'] ?? 'Rule');
            $conditions = array_values(array_filter((array) ($rule['conditions'] ?? []), 'is_array'));
            $match = count($conditions) <= 1 ? 'all' : (string) ($rule['match'] ?? 'all');

            $unclassified = array_values(array_filter(array_map(fn (array $c) => (string) ($c['field'] ?? ''), $conditions), fn (string $f) => self::classify($f) === null));
            if ($unclassified !== []) {
                $result['blockers'][] = Conflict::blocker('unclassified_condition', "The rule \"{$name}\" tests ".implode(', ', $unclassified).', which a clone cannot carry over yet.', $companyId);

                continue;
            }
            if ($match === 'any') {
                $result['blockers'][] = Conflict::blocker('any_mode', "The rule \"{$name}\" applies when any of its conditions is met, so it cannot be limited to one company. Change it to \"all of\" in the rule editor, then clone again.", $companyId);

                continue;
            }
            if (collect($conditions)->contains(fn (array $c) => self::classify((string) $c['field']) === 'person')) {
                $result['notes'][] = "The rule \"{$name}\" names employees, so it is not copied. Name the employees in the copy if it needs them.";

                continue;
            }
            $ruleReach = $this->ruleReach($rule, $companies);
            if ($variation && $ruleReach !== null && ! in_array($companyId, $ruleReach, true)) {
                $result['notes'][] = "The rule \"{$name}\" does not apply in this company, so it is not copied.";

                continue;
            }

            $after = [['field' => self::COMPANY, 'operator' => 'in', 'value' => [$companyId]]];
            foreach ($conditions as $condition) {
                $field = (string) $condition['field'];
                $kindOf = self::classify($field);
                if ($kindOf === 'company') {
                    continue;
                }
                if ($kindOf === 'shared' || in_array($condition['operator'] ?? '', ['is_empty', 'is_not_empty'], true)) {
                    $after[] = $condition;

                    continue;
                }
                $mapped = $this->mapUnits($field, $condition, $companyId, (array) ($choices[$field] ?? []), $name, $result);
                if ($mapped !== null) {
                    $after[] = $mapped;
                }
            }

            $rules[] = [
                'source_rule_id' => isset($rule['id']) ? (int) $rule['id'] : null,
                'name' => $name,
                'priority' => $priority ?? (int) ($rule['priority'] ?? 100),
                'match' => 'all',
                'conditions' => $after,
                'effective_from' => $rule['effective_from'] ?? null,
                'effective_to' => $rule['effective_to'] ?? null,
                'status' => 'inactive',
            ];
        }
        $result['after'] = ['rules' => $rules];

        return $result;
    }

    /**
     * Refuses anything that could apply outside the destinations: the last check before a copy is written.
     *
     * @param  array<string, mixed>  $after
     * @param  list<int>  $destinations
     */
    public function assertBounded(ApplicabilityKind $kind, array $after, array $destinations): void
    {
        $inside = fn (array $ids) => $ids !== [] && array_diff(array_map('intval', $ids), $destinations) === [];
        $bounded = match ($kind) {
            ApplicabilityKind::CompanyColumn => isset($after['company_id']) && in_array((int) $after['company_id'], $destinations, true),
            ApplicabilityKind::CompanyIdList => $inside((array) ($after['company_ids'] ?? [])),
            ApplicabilityKind::None => true,
            ApplicabilityKind::RuleConditions => collect((array) ($after['rules'] ?? []))->every(function ($rule) use ($inside) {
                $rule = (array) $rule;
                $company = array_values(array_filter((array) ($rule['conditions'] ?? []), fn ($c) => is_array($c) && ($c['field'] ?? null) === self::COMPANY));

                return ($rule['match'] ?? 'all') === 'all' && count($company) === 1 && in_array($company[0]['operator'] ?? '', ['in', 'equals'], true)
                    && $inside((array) ($company[0]['value'] ?? []));
            }),
        };
        if (! $bounded) {
            throw new CloneRefusedException('The copy would apply outside the companies chosen, so nothing was created.');
        }
    }

    /**
     * The companies one rule reaches: its company conditions, narrowed by unit conditions whose units all belong to
     * companies. null = no limit.
     *
     * @param  array<string, mixed>  $rule
     * @param  list<int>  $companies
     * @return list<int>|null
     */
    private function ruleReach(array $rule, array $companies): ?array
    {
        $conditions = array_values(array_filter((array) ($rule['conditions'] ?? []), 'is_array'));
        if (count($conditions) > 1 && ($rule['match'] ?? 'all') === 'any') {
            return null;
        }
        $reach = null;
        $narrow = function (array $ids) use (&$reach): void {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $reach = $reach === null ? $ids : array_values(array_intersect($reach, $ids));
        };
        foreach ($conditions as $condition) {
            $field = (string) ($condition['field'] ?? '');
            $values = array_map('intval', array_filter((array) ($condition['value'] ?? []), fn ($v) => $v !== null && $v !== ''));
            $operator = (string) ($condition['operator'] ?? '');
            if ($field === self::COMPANY) {
                match ($operator) {
                    'equals', 'in' => $narrow($values),
                    'not_equals', 'not_in' => $narrow(array_diff($companies, $values)),
                    'is_empty' => $narrow([]),
                    default => null,
                };
            } elseif (OrgUnitMapper::isUnit($field) && in_array($operator, ['equals', 'in'], true) && $values !== []) {
                $owners = array_map(fn (int $id) => $this->units->companyOf($field, $id), $values);
                if (! in_array(null, $owners, true)) {
                    $narrow(array_filter($owners, 'is_int'));
                }
            }
        }

        return $reach === null ? null : array_values($reach);
    }

    /**
     * A unit condition for the destination: units of the destination or shared by every company are kept; others are
     * mapped (the person's choice, else the same-named unit), removed by choice, or left unresolved (a blocker).
     *
     * @param  array<string, mixed>  $condition
     * @param  array<int|string, int|string|null>  $choices
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null the condition to keep, or null when it was removed
     */
    private function mapUnits(string $field, array $condition, int $companyId, array $choices, string $ruleName, array &$result): ?array
    {
        $operator = (string) ($condition['operator'] ?? 'in');
        $values = array_values(array_filter((array) ($condition['value'] ?? []), fn ($v) => $v !== null && $v !== ''));
        $kept = [];
        $dropped = 0;
        $missing = 0;
        foreach ($values as $value) {
            $owner = $this->units->companyOf($field, $value);
            if ($owner === null || $owner === $companyId) {
                $kept[] = (int) $value;

                continue;
            }
            $choice = $choices[$value] ?? $choices[(string) $value] ?? null;
            if ($choice === CloneRequest::DROP) {
                $dropped++;

                continue;
            }
            $target = filled($choice) ? (int) $choice : $this->units->suggest($field, $value, $companyId);
            if ($target !== null && $target > 0 && in_array($this->units->companyOf($field, $target), [null, $companyId], true)) {
                $kept[] = $target;
                $result['mapped'][] = ['field' => $field, 'source_id' => (int) $value, 'target_id' => $target];

                continue;
            }
            $missing++;
            $label = (string) (config("peopleos.rules.fields.{$field}.label") ?? $field);
            $result['unresolved'][] = ['field' => $field, 'source_id' => (int) $value, 'name' => $this->units->name($field, $value)];
            $result['blockers'][] = Conflict::blocker('unmapped_unit', "{$label} \"{$this->units->name($field, $value)}\" in the rule \"{$ruleName}\" belongs to another company. Choose this company's {$label} or remove it (Applicability).", $companyId);
        }
        $kept = array_values(array_unique($kept));
        if ($kept === []) {
            if ($missing === 0 && $dropped > 0) {
                $label = strtolower((string) (config("peopleos.rules.fields.{$field}.label") ?? $field));
                $result['notes'][] = "The {$label} condition of the rule \"{$ruleName}\" was removed, so the rule reaches every {$label} of this company.";
            }

            return null;
        }
        $negative = in_array($operator, ['not_equals', 'not_in'], true);
        if (count($kept) === 1 && in_array($operator, ['equals', 'not_equals'], true)) {
            return ['field' => $field, 'operator' => $operator, 'value' => $kept[0]];
        }

        return ['field' => $field, 'operator' => $negative ? 'not_in' : 'in', 'value' => $kept];
    }
}
