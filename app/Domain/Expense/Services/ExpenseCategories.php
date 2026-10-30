<?php

namespace App\Domain\Expense\Services;

use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseCategoryLimit;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * HROne setup parity EX-A: creates and changes expense categories and their limit rows in one audited transaction, after
 * checking every rule of HROne's category form (server side, whatever the screen sent). Fields that do not apply to the
 * category's type or choices are cleared, so a category never carries a rule it does not use: a mileage category has a
 * rate per km and distance limits; an amount category has amount or percentage-of-CTC limits, hourly bands and tax.
 *
 * Limit rows: one per (value of the category's limit dimension, location group); null in either means "every other".
 */
final class ExpenseCategories
{
    private const FLAGS = ['odometer_required', 'from_location_required', 'limit_exception_allowed', 'hourly_constraints_enabled', 'auto_fill_worked_hours',
        'description_required', 'receipt_required', 'reimbursable', 'tax_applicable', 'before_joining_allowed', 'declaration_required'];

    /**
     * @param  array<string, mixed>  $data  category attributes, and `limits`: list of {dimension_value_id, expense_location_group_id, amount, percent, km_limit, period}
     */
    public function save(?ExpenseCategory $category, array $data, ?string $reason = null): ExpenseCategory
    {
        // A change may name only what changes: everything else keeps its current value.
        $data += $category !== null ? $this->current($category) : [];
        $attributes = $this->attributes($category, $data);
        $limits = $this->limitRows($attributes, (array) ($data['limits'] ?? []));

        return DB::transaction(function () use ($category, $attributes, $limits, $reason) {
            $category ??= new ExpenseCategory;
            $category->fill($attributes);
            if (! $category->exists || $category->isDirty()) {
                $category->withAuditReason($reason)->save();
            }
            $this->syncLimits($category, $limits, $reason);

            return $category->refresh();
        });
    }

    /**
     * Values of an employee dimension a limit can be set for.
     *
     * @return array<int, string>
     */
    public static function dimensionOptions(?string $dimension): array
    {
        if ($dimension === null || ! isset(EmployeePosition::DIMENSIONS[$dimension])) {
            return [];
        }
        $related = (new EmployeePosition)->{EmployeePosition::DIMENSIONS[$dimension][0]}()->getRelated();

        return $related->newQuery()->orderBy('name')->pluck('name', $related->getKeyName())->all();
    }

    /** @return array<string, string> the employee dimensions limits can vary by */
    public static function dimensions(): array
    {
        return array_map(fn (array $d) => $d[1], EmployeePosition::DIMENSIONS);
    }

    /**
     * A category's settings and limit rows in the shape save() takes.
     *
     * @return array<string, mixed>
     */
    public function current(ExpenseCategory $category): array
    {
        $values = [];
        foreach ($category->getFillable() as $key) {
            $values[$key] = $category->getAttribute($key);
        }
        $values['type'] = $category->getAttributes()['type'];
        $values['limits'] = $category->limits()->get()
            ->map(fn (ExpenseCategoryLimit $l) => $l->only(['dimension_value_id', 'expense_location_group_id', 'amount', 'percent', 'km_limit', 'period']))->all();

        return $values;
    }

    /** @return array<string, mixed> */
    private function attributes(?ExpenseCategory $category, array $data): array
    {
        $vocabulary = (array) config('peopleos.expense');
        $out = [];

        $out['name'] = trim((string) ($data['name'] ?? $category?->name ?? ''));
        if ($out['name'] === '' || mb_strlen($out['name']) > 120) {
            throw new ExpenseRuleViolation('Give the expense category a name (at most 120 characters).');
        }
        $code = strtoupper(trim((string) ($data['code'] ?? $category?->code ?? '')));
        if (! preg_match('/^[A-Z0-9_-]{1,32}$/', $code)) {
            throw new ExpenseRuleViolation('The expense code may use letters, numbers, dashes and underscores (at most 32).');
        }
        if (ExpenseCategory::query()->where('code', $code)->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists()) {
            throw new ExpenseRuleViolation("Another expense category already uses the code {$code}.");
        }
        $out['code'] = $code;

        $type = (string) ($category !== null ? $category->getAttributes()['type'] : ($data['type'] ?? ''));
        if (isset($data['type']) && $data['type'] !== $type) {
            throw new ExpenseRuleViolation('The expense type (amount or mileage) cannot change once the category exists: add a new category instead.');
        }
        if (! array_key_exists($type, $vocabulary['types'])) {
            throw new ExpenseRuleViolation('Choose the expense type: amount or mileage.');
        }
        $out['type'] = $type;
        $mileage = $type === ExpenseCategory::MILEAGE;

        $out['expense_group_id'] = self::id($data['expense_group_id'] ?? null);
        if ($out['expense_group_id'] !== null && ! ExpenseGroup::query()->whereKey($out['expense_group_id'])->exists()) {
            throw new ExpenseRuleViolation('Choose an existing expense group.');
        }
        $out['description'] = filled($data['description'] ?? null) ? trim((string) $data['description']) : null;
        foreach (self::FLAGS as $flag) {
            $out[$flag] = (bool) ($data[$flag] ?? ($flag === 'reimbursable'));
        }

        // Mileage details.
        if ($mileage) {
            $rate = $data['rate_per_km'] ?? null;
            if (! is_numeric($rate) || (float) $rate <= 0) {
                throw new ExpenseRuleViolation('A mileage category needs a rate per km above 0.');
            }
            $out['rate_per_km'] = round((float) $rate, 4);
            $out['location_input'] = $this->choice($data['location_input'] ?? 'none', $vocabulary['location_inputs'], 'location input type');
            $out['from_location_required'] = false;
        } else {
            $out['rate_per_km'] = null;
            $out['odometer_required'] = false;
            $out['location_input'] = 'none';
        }

        // Limits.
        $out['limit_basis'] = $this->choice($data['limit_basis'] ?? 'none', $vocabulary['limit_bases'][$type], 'limit type');
        $limited = $out['limit_basis'] !== 'none';
        $dimension = filled($data['limit_dimension'] ?? null) ? (string) $data['limit_dimension'] : null;
        if ($dimension !== null && ! isset(EmployeePosition::DIMENSIONS[$dimension])) {
            throw new ExpenseRuleViolation('Limits can vary by an employee dimension such as grade, designation or level only.');
        }
        $out['limit_dimension'] = $limited ? $dimension : null;
        $out['ctc_component_id'] = $out['limit_basis'] === 'percent_of_ctc' ? self::id($data['ctc_component_id'] ?? null) : null;
        if ($out['ctc_component_id'] !== null && ! SalaryComponent::query()->whereKey($out['ctc_component_id'])->exists()) {
            throw new ExpenseRuleViolation('Choose an existing CTC pay component (or none for the total CTC).');
        }
        $out['limit_exception_allowed'] = $limited && $out['limit_exception_allowed'];
        $out['hourly_constraints_enabled'] = ! $mileage && $out['hourly_constraints_enabled'];
        $out['hourly_constraints'] = $out['hourly_constraints_enabled'] ? $this->hourlyBands((array) ($data['hourly_constraints'] ?? [])) : null;
        $out['auto_fill_worked_hours'] = ! $mileage && $out['auto_fill_worked_hours'];

        // Claim rules.
        $above = $data['receipt_required_above'] ?? null;
        if ($out['receipt_required'] && filled($above) && (! is_numeric($above) || (float) $above < 0)) {
            throw new ExpenseRuleViolation('The minimum amount needing a receipt must be 0 or more.');
        }
        $out['receipt_required_above'] = $out['receipt_required'] && filled($above) ? round((float) $above, 2) : null;
        $period = filled($data['claim_frequency_period'] ?? null) ? (string) $data['claim_frequency_period'] : null;
        $count = filled($data['claim_frequency_count'] ?? null) ? $data['claim_frequency_count'] : null;
        if (($period === null) !== ($count === null)) {
            throw new ExpenseRuleViolation('Give both the claim frequency and how many claims it allows, or neither.');
        }
        if ($period !== null && ($period === 'per_claim' || ! array_key_exists($period, $vocabulary['periods']))) {
            throw new ExpenseRuleViolation('Choose a claim frequency period such as daily, weekly, monthly or per trip.');
        }
        if ($count !== null && (! is_numeric($count) || (int) $count < 1 || (int) $count != $count)) {
            throw new ExpenseRuleViolation('The claim frequency count must be a whole number of 1 or more.');
        }
        $out['claim_frequency_period'] = $period;
        $out['claim_frequency_count'] = $count === null ? null : (int) $count;
        $out['tax_applicable'] = ! $mileage && $out['tax_applicable'];
        $out['gst_supply_type'] = $out['tax_applicable'] && filled($data['gst_supply_type'] ?? null) ? $this->choice($data['gst_supply_type'], $vocabulary['gst_supply_types'], 'GST type') : null;

        // Travel.
        $out['travel_link'] = $this->choice($data['travel_link'] ?? 'none', $vocabulary['travel_links'], 'pre-travel request link');
        $linked = $out['travel_link'] !== 'none';
        $out['travel_form'] = $linked ? $this->choice($data['travel_form'] ?? null, $vocabulary['travel_forms'], 'travel plan form (travel or accommodation booking)') : null;
        $out['booking_service_id'] = $linked ? self::id($data['booking_service_id'] ?? null) : null;
        if ($out['booking_service_id'] !== null && ! ServiceDefinition::query()->whereKey($out['booking_service_id'])->exists()) {
            throw new ExpenseRuleViolation('Choose an existing helpdesk service for travel booking tasks.');
        }
        $out['booked_by'] = $linked ? $this->choice($data['booked_by'] ?? 'employee', $vocabulary['booked_by'], 'who books travel') : 'employee';

        // Eligibility.
        $out['attendance_rule'] = $this->attendanceRule($data['attendance_rule'] ?? null);
        $window = $data['submission_window_days'] ?? null;
        if (is_array($window) && (filled($window['from'] ?? null) || filled($window['to'] ?? null))) {
            $from = $window['from'] ?? null;
            $to = $window['to'] ?? null;
            if (! is_numeric($from) || ! is_numeric($to) || (int) $from < 0) {
                throw new ExpenseRuleViolation('Give the submission window as From and To days after the expense date.');
            }
            if ((int) $to <= (int) $from) {
                throw new ExpenseRuleViolation('"To Days" must be greater than "From Days".');
            }
            $out['submission_window_days'] = ['from' => (int) $from, 'to' => (int) $to];
        } else {
            $out['submission_window_days'] = null;
        }
        $text = trim((string) ($data['declaration_text'] ?? ''));
        if ($out['declaration_required'] && ($text === '' || mb_strlen($text) > 2000)) {
            throw new ExpenseRuleViolation('Write the declaration employees accept (at most 2,000 characters).');
        }
        $out['declaration_text'] = $out['declaration_required'] ? $text : null;

        $out['sort_order'] = max(0, (int) ($data['sort_order'] ?? $category?->sort_order ?? 0));
        $status = $data['status'] ?? $category?->status ?? ActiveStatus::Active;
        $out['status'] = $status instanceof ActiveStatus ? $status : (ActiveStatus::tryFrom((string) $status) ?? throw new ExpenseRuleViolation('Choose active or inactive.'));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function limitRows(array $category, array $rows): array
    {
        $basis = $category['limit_basis'];
        if ($basis === 'none') {
            return [];
        }
        $column = ['amount' => 'amount', 'percent_of_ctc' => 'percent', 'km' => 'km_limit'][$basis];
        $periods = (array) config('peopleos.expense.periods');
        $dimension = $category['limit_dimension'];
        $values = $dimension !== null ? self::dimensionOptions($dimension) : [];
        $out = [];
        foreach ($rows as $row) {
            $valueId = self::id($row['dimension_value_id'] ?? null);
            if ($valueId !== null && ($dimension === null || ! array_key_exists($valueId, $values))) {
                throw new ExpenseRuleViolation($dimension === null ? 'Choose who the limits are for (an employee dimension) before giving limits per value.' : 'A limit names a '.strtolower(EmployeePosition::DIMENSIONS[$dimension][1]).' that does not exist.');
            }
            $groupId = self::id($row['expense_location_group_id'] ?? null);
            if ($groupId !== null && ! ExpenseLocationGroup::query()->whereKey($groupId)->exists()) {
                throw new ExpenseRuleViolation('A limit names a location group that does not exist.');
            }
            $value = $row[$column] ?? null;
            $valid = is_numeric($value) && match ($column) {
                'amount' => (float) $value >= 0,
                'percent' => (float) $value > 0 && (float) $value <= 100,
                default => (float) $value > 0,
            };
            if (! $valid) {
                throw new ExpenseRuleViolation(match ($column) {
                    'amount' => 'Every limit needs an amount of 0 or more.',
                    'percent' => 'Every limit needs a percentage above 0 and at most 100.',
                    default => 'Every distance limit needs a number of km above 0.',
                });
            }
            $period = (string) ($row['period'] ?? 'per_claim') ?: 'per_claim';
            if (! array_key_exists($period, $periods)) {
                throw new ExpenseRuleViolation('Choose the limit time period.');
            }
            $key = ExpenseCategoryLimit::matchKey($valueId, $groupId);
            if (isset($out[$key])) {
                throw new ExpenseRuleViolation('Two limits are for the same employees and location group: keep one.');
            }
            $out[$key] = ['dimension_value_id' => $valueId, 'expense_location_group_id' => $groupId, 'amount' => null, 'percent' => null, 'km_limit' => null, 'period' => $period];
            $out[$key][$column] = round((float) $value, 2);
        }

        return array_values($out);
    }

    private function syncLimits(ExpenseCategory $category, array $rows, ?string $reason): void
    {
        $existing = $category->limits()->get()->keyBy(fn (ExpenseCategoryLimit $l) => $l->getAttributes()['match_key']);
        $wanted = collect($rows)->keyBy(fn (array $r) => ExpenseCategoryLimit::matchKey($r['dimension_value_id'], $r['expense_location_group_id']));
        foreach ($existing->diffKeys($wanted) as $limit) {
            $limit->withAuditReason($reason)->delete();
        }
        foreach ($wanted as $key => $row) {
            $limit = $existing->get($key) ?? new ExpenseCategoryLimit(['expense_category_id' => $category->id]);
            $limit->fill($row);
            if (! $limit->exists || $limit->isDirty()) {
                $limit->withAuditReason($reason)->save();
            }
        }
    }

    /** @return list<array{from_minutes: int, to_minutes: int, percent: float}> */
    private function hourlyBands(array $bands): array
    {
        $out = [];
        foreach ($bands as $band) {
            $from = $band['from_minutes'] ?? null;
            $to = $band['to_minutes'] ?? null;
            $percent = $band['percent'] ?? null;
            if (! is_numeric($from) || ! is_numeric($to) || ! is_numeric($percent) || (int) $from < 0) {
                throw new ExpenseRuleViolation('Every hourly band needs From and To hours and a percentage.');
            }
            if ((int) $to <= (int) $from) {
                throw new ExpenseRuleViolation('In an hourly band, To must be after From.');
            }
            if ((float) $percent < 0 || (float) $percent > 100) {
                throw new ExpenseRuleViolation('An hourly band\'s percentage must be between 0 and 100.');
            }
            $out[] = ['from_minutes' => (int) $from, 'to_minutes' => (int) $to, 'percent' => round((float) $percent, 2)];
        }
        if ($out === []) {
            throw new ExpenseRuleViolation('Give at least one hourly band, or turn hourly constraints off.');
        }
        usort($out, fn (array $a, array $b) => $a['from_minutes'] <=> $b['from_minutes']);
        for ($i = 1; $i < count($out); $i++) {
            if ($out[$i]['from_minutes'] < $out[$i - 1]['to_minutes']) {
                throw new ExpenseRuleViolation('Hourly bands must not overlap.');
            }
        }

        return $out;
    }

    /** @return array{mode: string, min_minutes: ?int, on_duty: ?string, on_duty_types: list<string>}|null */
    private function attendanceRule(mixed $rule): ?array
    {
        if (! is_array($rule) || blank($rule['mode'] ?? null) || $rule['mode'] === 'none') {
            return null;
        }
        $mode = $this->choice($rule['mode'], (array) config('peopleos.expense.attendance_modes'), 'attendance restriction');
        $minutes = null;
        if ($mode === 'min_hours') {
            $minutes = $rule['min_minutes'] ?? null;
            if (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > 1440) {
                throw new ExpenseRuleViolation('Give the minimum working hours and minutes (up to 24 hours).');
            }
            $minutes = (int) $minutes;
        }
        $onDuty = null;
        $types = [];
        if ($mode === 'on_duty') {
            $onDuty = $this->choice($rule['on_duty'] ?? 'any', (array) config('peopleos.expense.on_duty_kinds'), 'on-duty type');
            $types = $onDuty === 'specific' ? array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($rule['on_duty_types'] ?? [])), fn ($t) => $t !== ''))) : [];
            if ($onDuty === 'specific' && $types === []) {
                throw new ExpenseRuleViolation('Name the on-duty types that allow the expense.');
            }
        }

        return ['mode' => $mode, 'min_minutes' => $minutes, 'on_duty' => $onDuty, 'on_duty_types' => $types];
    }

    private function choice(mixed $value, array $options, string $what): string
    {
        if (! is_string($value) || ! array_key_exists($value, $options)) {
            throw new ExpenseRuleViolation("Choose the {$what}.");
        }

        return $value;
    }

    private static function id(mixed $value): ?int
    {
        if ($value instanceof Model) {
            return (int) $value->getKey();
        }

        return filled($value) && is_numeric($value) ? (int) $value : null;
    }
}
