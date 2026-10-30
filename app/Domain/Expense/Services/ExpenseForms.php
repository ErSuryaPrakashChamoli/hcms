<?php

namespace App\Domain\Expense\Services;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;

/**
 * HROne setup parity EX-A (Expense → Expense form, Travel plan form): which fields an expense receipt and a travel
 * request show, which are mandatory, and what they are called.
 *
 * - Expense form: the standard fields (peopleos.expense.form_fields) and the custom fields of the `expense_receipt`
 *   entity (HROne's "Field one … Field ten"), each visible and mandatory per section — amount, mileage, accommodation
 *   and travel details — with a label (a custom field keeps its own label). Setting `expense.form.fields`.
 * - Travel form: the standard travel request fields (peopleos.expense.travel_form_fields) and the custom fields of the
 *   `travel_request` entity, each visible and mandatory, with a label. Setting `expense.travel_form.fields`.
 *
 * Only the administrator's choices are stored (through SettingsRepository, so each save is audited with its reason);
 * a field never saved follows its default. Receipts (EX-C) and travel requests (EX-F) check missing() on the server.
 */
final class ExpenseForms
{
    public const SETTING = 'expense.form.fields';

    public const TRAVEL_SETTING = 'expense.travel_form.fields';

    public const CUSTOM = 'custom:';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, string> section => label */
    public static function sections(): array
    {
        return (array) config('peopleos.expense.form_sections', []);
    }

    /**
     * Every expense form field with its current placement.
     *
     * @return array<string, array{key: string, label: string, default_label: string, visible: list<string>, mandatory: list<string>, custom: bool}>
     */
    public function expenseFields(): array
    {
        $stored = (array) ($this->settings->get(self::SETTING) ?? []);
        $sections = array_keys(self::sections());
        $fields = [];
        foreach ((array) config('peopleos.expense.form_fields', []) as $key => $default) {
            $fields[$key] = ['key' => $key, 'label' => (string) $default['label'], 'default_label' => (string) $default['label'], 'visible' => $default['visible'], 'mandatory' => $default['mandatory'], 'custom' => false];
        }
        foreach ($this->customFields('expense_receipt') as $field) {
            $key = self::CUSTOM.$field->key;
            $fields[$key] = ['key' => $key, 'label' => $field->label, 'default_label' => $field->label, 'visible' => $sections, 'mandatory' => $field->is_required ? $sections : [], 'custom' => true];
        }
        foreach ($fields as $key => $field) {
            $saved = $stored[$key] ?? null;
            if (is_array($saved)) {
                $fields[$key]['visible'] = array_values(array_intersect($sections, (array) ($saved['visible'] ?? [])));
                $fields[$key]['mandatory'] = array_values(array_intersect($sections, (array) ($saved['mandatory'] ?? [])));
                if (! $field['custom'] && filled($saved['label'] ?? null)) {
                    $fields[$key]['label'] = (string) $saved['label'];
                }
            }
        }

        return $fields;
    }

    /**
     * Every travel form field with its current placement.
     *
     * @return array<string, array{key: string, label: string, default_label: string, visible: bool, mandatory: bool, custom: bool}>
     */
    public function travelFields(): array
    {
        $stored = (array) ($this->settings->get(self::TRAVEL_SETTING) ?? []);
        $fields = [];
        foreach ((array) config('peopleos.expense.travel_form_fields', []) as $key => $default) {
            $fields[$key] = ['key' => $key, 'label' => (string) $default['label'], 'default_label' => (string) $default['label'], 'visible' => (bool) $default['visible'], 'mandatory' => (bool) $default['mandatory'], 'custom' => false];
        }
        foreach ($this->customFields('travel_request') as $field) {
            $key = self::CUSTOM.$field->key;
            $fields[$key] = ['key' => $key, 'label' => $field->label, 'default_label' => $field->label, 'visible' => true, 'mandatory' => (bool) $field->is_required, 'custom' => true];
        }
        foreach ($fields as $key => $field) {
            $saved = $stored[$key] ?? null;
            if (is_array($saved)) {
                $fields[$key]['visible'] = (bool) ($saved['visible'] ?? false);
                $fields[$key]['mandatory'] = (bool) ($saved['mandatory'] ?? false);
                if (! $field['custom'] && filled($saved['label'] ?? null)) {
                    $fields[$key]['label'] = (string) $saved['label'];
                }
            }
        }

        return $fields;
    }

    /**
     * Saves the expense form matrix.
     *
     * @param  list<array{key: string, label?: ?string, visible?: list<string>, mandatory?: list<string>}>  $rows
     */
    public function saveExpense(array $rows, string $reason, User $actor): void
    {
        $this->authorise($actor, $reason);
        $known = $this->expenseFields();
        $sections = self::sections();
        $stored = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            $field = $known[$key] ?? throw new ExpenseRuleViolation("The expense form has no field {$key}.");
            $visible = array_values(array_unique(array_map('strval', (array) ($row['visible'] ?? []))));
            $mandatory = array_values(array_unique(array_map('strval', (array) ($row['mandatory'] ?? []))));
            if (array_diff([...$visible, ...$mandatory], array_keys($sections)) !== []) {
                throw new ExpenseRuleViolation("{$field['label']}: choose among ".implode(', ', $sections).'.');
            }
            if (($hidden = array_diff($mandatory, $visible)) !== []) {
                throw new ExpenseRuleViolation("{$field['label']} is mandatory on ".implode(', ', array_map(fn ($s) => $sections[$s], $hidden)).' but not shown there: a field can be mandatory only where it is shown.');
            }
            $stored[$key] = ['label' => $field['custom'] ? null : $this->label($row['label'] ?? null, $field), 'visible' => array_values(array_intersect(array_keys($sections), $visible)), 'mandatory' => array_values(array_intersect(array_keys($sections), $mandatory))];
        }
        $this->settings->set(self::SETTING, $stored + (array) ($this->settings->get(self::SETTING) ?? []), $reason);
    }

    /**
     * Saves the travel form matrix.
     *
     * @param  list<array{key: string, label?: ?string, visible?: bool, mandatory?: bool}>  $rows
     */
    public function saveTravel(array $rows, string $reason, User $actor): void
    {
        $this->authorise($actor, $reason);
        $known = $this->travelFields();
        $stored = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            $field = $known[$key] ?? throw new ExpenseRuleViolation("The travel form has no field {$key}.");
            $visible = (bool) ($row['visible'] ?? false);
            $mandatory = (bool) ($row['mandatory'] ?? false);
            if ($mandatory && ! $visible) {
                throw new ExpenseRuleViolation("{$field['label']} is mandatory but not shown: a field can be mandatory only when it is shown.");
            }
            $stored[$key] = ['label' => $field['custom'] ? null : $this->label($row['label'] ?? null, $field), 'visible' => $visible, 'mandatory' => $mandatory];
        }
        $this->settings->set(self::TRAVEL_SETTING, $stored + (array) ($this->settings->get(self::TRAVEL_SETTING) ?? []), $reason);
    }

    public function visible(string $field, string $section): bool
    {
        return in_array($section, $this->expenseFields()[$field]['visible'] ?? [], true);
    }

    public function mandatory(string $field, string $section): bool
    {
        return in_array($section, $this->expenseFields()[$field]['mandatory'] ?? [], true);
    }

    /**
     * The labels of the expense form fields mandatory in the section that $values leaves blank (custom fields by
     * `custom:<key>`).
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function missing(string $section, array $values): array
    {
        $missing = [];
        foreach ($this->expenseFields() as $key => $field) {
            if (in_array($section, $field['mandatory'], true) && blank($values[$key] ?? null)) {
                $missing[] = $field['label'];
            }
        }

        return $missing;
    }

    /**
     * The labels of the travel form fields that are mandatory and blank in $values.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function travelMissing(array $values): array
    {
        return array_values(array_map(fn (array $f) => $f['label'], array_filter($this->travelFields(), fn (array $f, string $key) => $f['mandatory'] && blank($values[$key] ?? null), ARRAY_FILTER_USE_BOTH)));
    }

    /** @return iterable<CustomField> */
    private function customFields(string $entity): iterable
    {
        return CustomField::query()->forEntity($entity)->currentlyEffective()->get();
    }

    private function label(mixed $label, array $field): string
    {
        $label = trim((string) $label);
        if (mb_strlen($label) > 60) {
            throw new ExpenseRuleViolation("{$field['default_label']}: a label can be at most 60 characters.");
        }

        return $label === '' ? $field['default_label'] : $label;
    }

    private function authorise(User $actor, string $reason): void
    {
        if (! $actor->can('expense.manage')) {
            throw new ExpenseRuleViolation('You cannot change the expense forms.');
        }
        if (trim($reason) === '') {
            throw new ExpenseRuleViolation('Give the reason for the change.');
        }
    }
}
