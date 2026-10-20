<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Configuration\Models\FormVersion;
use App\Domain\Employment\Models\Employee;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12: a service's request form is made of:
 * - the fields of its published Configuration Form version (reused, not re-implemented);
 * - the fields its domain action needs.
 *
 * Each field is classified by the service version's field security (standard / sensitive / restricted,
 * employee-visible or HR-only); a domain action's own classification wins when it is stricter. Only
 * defined fields are kept: arbitrary keys never reach the request.
 */
final class ServiceForms
{
    private const RANK = ['standard' => 0, 'sensitive' => 1, 'restricted' => 2];

    public function __construct(private readonly DomainActions $actions) {}

    /** @return array<string, array{label: string, type: string, required: bool, options: array<string, string>, class: string, employee_visible: bool, help: ?string, source: string, view_permission?: string}> */
    public function fields(ServiceDefinitionVersion $version, ?Employee $employee = null): array
    {
        $fields = [];
        foreach (($version->formVersion?->fields ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $fields[$key] = [
                'label' => (string) ($field['label'] ?? $key), 'type' => (string) ($field['type'] ?? 'text'), 'required' => (bool) ($field['required'] ?? false),
                'options' => FormVersion::optionMap($field), 'class' => $version->fieldClass($key), 'employee_visible' => $version->fieldVisibleToEmployee($key),
                'help' => $field['help_text'] ?? null, 'source' => 'form',
            ];
        }
        $handler = $this->actions->find($version->domain_action);
        if ($handler !== null) {
            foreach ($handler->fields($employee) as $key => $field) {
                $fields[$key] = [
                    'label' => $field['label'], 'type' => $field['type'], 'required' => (bool) ($field['required'] ?? false), 'options' => $field['options'] ?? [],
                    'class' => self::stricter($field['class'] ?? 'standard', $version->fieldClass($key)),
                    'employee_visible' => ($field['employee_visible'] ?? true) && $version->fieldVisibleToEmployee($key),
                    'help' => $field['help'] ?? null, 'source' => 'action', 'view_permission' => $handler->viewPermission(),
                ];
            }
        }

        return $fields;
    }

    /** The fields of the version a request is pinned to (none for legacy category tickets). */
    public function fieldsFor(Ticket $ticket): array
    {
        $version = $ticket->relationLoaded('serviceVersion') ? $ticket->serviceVersion : $ticket->serviceVersion()->with('formVersion')->first();

        return $version ? $this->fields($version, null) : [];
    }

    /**
     * Validate submitted data: Configuration Form rules for form fields, the domain action's rules for
     * its fields. Unknown keys are dropped.
     *
     * @return array<string, mixed>
     */
    public function validate(ServiceDefinitionVersion $version, Employee $employee, array $data): array
    {
        $fields = $this->fields($version, $employee);
        $data = array_intersect_key($data, $fields);
        $formRules = array_intersect_key($version->formVersion?->rules() ?? [], array_filter($fields, fn (array $f) => $f['source'] === 'form'));
        try {
            $clean = $formRules === [] ? [] : Validator::make($data, $formRules)->validate();
        } catch (ValidationException $e) {
            throw new ServiceDeskRuleViolation(collect($e->errors())->flatten()->first() ?? 'The request form is not valid.');
        }
        $handler = $this->actions->find($version->domain_action);
        if ($handler !== null) {
            $actionData = array_intersect_key($data, array_filter($fields, fn (array $f) => $f['source'] === 'action'));
            $clean += array_filter($handler->validate($employee, $actionData), fn ($v) => $v !== null && $v !== '');
        }

        return $clean;
    }

    public static function stricter(string $a, string $b): string
    {
        return (self::RANK[$a] ?? 0) >= (self::RANK[$b] ?? 0) ? $a : $b;
    }
}
