<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\CustomFieldValue;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Data import DI-2 (Workforce → Employee other details): values of the organisation's own employee fields (Setup →
 * Custom fields, entity Employee), one row per employee and field ("long" format: employee code, field, value). Each
 * value is read as its field's type (number, date, yes / no, a dropdown option by value or label, several options
 * separated by ; or |) and saved through the custom field engine (CustomFields::store, the path of the employee's edit
 * screen and My profile), which validates it again and audits it. Needs employee.update within organisation scope.
 */
final class CustomFieldValueImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'custom_field_values';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'field' => ['Field', true], 'value' => ['Value', true]];
    }

    public function label(): string
    {
        return 'Employee other details upload';
    }

    protected function module(): string
    {
        return 'configuration';
    }

    protected function entityType(): string
    {
        return CustomFieldValue::class;
    }

    protected function aliases(): array
    {
        return ['field_key' => 'field', 'field_name' => 'field', 'custom_field' => 'field', 'detail' => 'field', 'value' => 'value', 'field_value' => 'value', 'answer' => 'value'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['definitions' => $this->definitions()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        [$field, $value, $problems] = $this->parse($r, $context['definitions']);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null || $field === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.$field->key;
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same field for the same employee is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        if (($refusal = $this->refusal($import, $employee, 'employee.update')) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }

        $stored = CustomFieldValue::query()->where('custom_field_id', $field->id)->where('model_type', $employee->getMorphClass())->where('model_id', $employee->getKey())->first();
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => $stored === null ? ['employee code'] : ['employee code', 'field']];
        if ($stored === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }
        $stored->setRelation('field', $field);

        return $this->same($stored->value(), $value)
            ? ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => [$field->key]], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        [$field, $value, $problems] = $this->parse($r, $this->definitions());
        if ($problems !== [] || $field === null) {
            throw new ProfileChangeRefused(implode(' ', $problems) ?: 'The row could not be read.');
        }
        app(ProfileChangeGuard::class)->authorize($user, $employee, 'employee.update', $this->origin($import));
        try {
            app(CustomFields::class)->store($employee, [$field->key => $value], $this->why($import));
        } catch (ValidationException $e) {
            throw new ProfileChangeRefused(collect($e->errors())->flatten()->first() ?? 'The value is not valid.');
        }

        return "{$field->label} saved for {$employee->employee_code}";
    }

    public function format(): array
    {
        $definitions = $this->definitions();

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Field', 'Yes', 'The field\'s key or label (Setup → Custom fields, entity Employee); the fields are listed below.', $definitions->first()?->key ?? 'blood_donor'],
                ['Value', 'Yes', 'As the field\'s type: text; a number; a date (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY); yes or no; a dropdown option (value or label); several options separated by ; or |.', $this->exampleValue($definitions->first()) ?? 'yes'],
            ],
            'codes' => $definitions->isEmpty() ? null : $definitions->mapWithKeys(fn (CustomField $f) => [$f->key => $f->label.' · '.$this->describe($f)])->all(),
            'codes_title' => 'Employee fields',
            'notes' => [
                'One row per employee and field. A value on file is replaced; a row whose value is already on file is skipped.',
                'Only active employee fields count. A value is checked against its field\'s type, options and rules, as on the employee\'s edit screen.',
                'Changes need employee.update within your organisation scope and are audited.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $definitions = $this->definitions()->take(2)->values();
        if ($definitions->isEmpty()) {
            return [[$code, 'blood_donor', 'yes'], [$code, 'tshirt_size', 'M']];
        }
        $rows = $definitions->map(fn (CustomField $f) => [$code, $f->key, $this->exampleValue($f) ?? ''])->all();

        return count($rows) > 1 ? $rows : [...$rows, [Employee::query()->orderBy('id')->skip(1)->value('employee_code') ?? $code, $rows[0][1], $rows[0][2]]];
    }

    // ---------------------------------------------------------------------------------------

    /** @return Collection<int, CustomField> the live employee fields */
    private function definitions(): Collection
    {
        return app(CustomFields::class)->definitionsFor(Employee::class)->values();
    }

    /**
     * The field a row names (by key or label) and its value read as the field's type, with what is wrong.
     *
     * @param  Collection<int, CustomField>  $definitions
     * @return array{0: ?CustomField, 1: mixed, 2: list<string>}
     */
    private function parse(array $r, Collection $definitions): array
    {
        $errors = [];
        $name = mb_strtolower(trim((string) ($r['field'] ?? '')));
        $field = $name === '' ? null : ($definitions->first(fn (CustomField $f) => mb_strtolower($f->key) === $name) ?? $definitions->first(fn (CustomField $f) => mb_strtolower(trim((string) $f->label)) === $name));
        if ($field === null) {
            return [null, null, [$name === '' ? 'Field is required.' : "Field '{$r['field']}' is not an active employee field."]];
        }
        $cell = trim((string) ($r['value'] ?? ''));
        if ($cell === '') {
            return [$field, null, ['Value is required.']];
        }
        $options = $field->optionMap();
        $value = match ($field->type) {
            'number' => is_numeric($n = str_replace([',', ' '], '', $cell)) ? $n + 0 : $cell,
            'date' => $this->date($cell) ?? $cell,
            'boolean' => $this->yesNo($cell, $field->label, $errors),
            'dropdown' => self::codeIn($options, $cell) ?? $cell,
            'multiselect' => array_values(array_map(fn (string $part) => self::codeIn($options, $part) ?? trim($part), array_filter(preg_split('/[;|]/', $cell) ?: [], fn ($p) => trim($p) !== ''))),
            default => $cell,
        };
        if ($field->type === 'date' && $this->date($cell) === null) {
            $errors[] = "{$field->label} must be a date (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY).";
        }
        if ($field->type === 'multiselect' && ($unknown = array_diff($value, array_map('strval', array_keys($options)))) !== []) {
            $errors[] = "{$field->label}: '".implode("', '", $unknown)."' is not an option (".implode(', ', array_keys($options)).').';
        }
        if ($errors === []) {
            $rules = $field->rules();
            if ($field->type === 'multiselect') {
                $rules = [...$rules, 'min:1'];
            }
            array_push($errors, ...Validator::make([$field->key => $value], [$field->key => $rules], [], [$field->key => $field->label])->errors()->all());
        }

        return [$field, $value, array_values(array_unique($errors))];
    }

    private function same(mixed $stored, mixed $value): bool
    {
        if (is_array($value) || is_array($stored)) {
            $a = array_map('strval', (array) $stored);
            $b = array_map('strval', (array) $value);
            sort($a);
            sort($b);

            return $a === $b;
        }

        return self::sameValue($stored, $value);
    }

    private function describe(CustomField $field): string
    {
        $options = $field->optionMap();

        return match ($field->type) {
            'dropdown', 'multiselect' => ($field->type === 'multiselect' ? 'several of: ' : 'one of: ').implode(', ', array_keys($options)),
            'boolean' => 'yes or no',
            default => $field->type,
        }.($field->is_required ? ' (required)' : '');
    }

    private function exampleValue(?CustomField $field): ?string
    {
        if ($field === null) {
            return null;
        }
        $options = array_keys($field->optionMap());

        return match ($field->type) {
            'number' => '10',
            'date' => now()->startOfMonth()->toDateString(),
            'boolean' => 'yes',
            'dropdown' => (string) ($options[0] ?? ''),
            'multiselect' => implode(';', array_slice(array_map('strval', $options), 0, 2)),
            'email' => 'name@example.com',
            'url' => 'https://example.com',
            default => 'Sample',
        };
    }
}
