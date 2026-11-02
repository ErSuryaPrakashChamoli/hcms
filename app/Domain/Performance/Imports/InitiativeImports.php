<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Imports\Concerns\ReadsPerformanceRows;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Services\Initiatives;

/**
 * Data import DI-35 ("Initiatives", HROne Import → Performance; matrix 27 §27.10 #6): initiatives employees take part in,
 * through Initiatives::create / update as Performance → Initiatives does (who may assign or change them, employees working
 * here now, the initiative type, a goal of the employee's own, dates, progress). An open initiative of the same employee and
 * title is updated (its progress moves a planned one to in progress); any other row creates a planned initiative assigned
 * by the uploader. Completing or cancelling an initiative is done on its page.
 */
final class InitiativeImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'initiatives';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'title' => ['Initiative', true], 'type' => ['Initiative type code', false], 'description' => ['Description', false],
            'goal' => ['Goal', false], 'start_date' => ['Start date', false], 'due_date' => ['Due date', false], 'progress' => ['Progress (%)', false]];
    }

    public function label(): string
    {
        return 'Initiative upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function entityType(): string
    {
        return Initiative::class;
    }

    protected function aliases(): array
    {
        return ['initiative_name' => 'title', 'name' => 'title', 'initiative_type' => 'type', 'linked_goal' => 'goal', 'goal_title' => 'goal', 'end_date' => 'due_date'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => $this->uploader($import),
            'types' => PerformanceLookup::query()->ofKind('initiative_type')->where('status', 'active')->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $data = $employeeId === null ? [] : $this->data($r, $employeeId, $context, $errors);
        if ($errors !== [] || $employeeId === null || $context['actor'] === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.mb_strtolower(trim($r['title']));
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same initiative is in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        $service = app(Initiatives::class);
        $existing = $this->existing($employeeId, $r['title']);
        if ($existing === null) {
            $employee = Employee::query()->findOrFail($employeeId);
            if (! $service->mayAssignTo($context['actor'], $employee)) {
                return ['action' => 'error', 'errors' => ['You cannot create an initiative for this employee.'], 'employee_id' => $employeeId];
            }
            if (! $employee->lifecycle_state->isEmployed()) {
                return ['action' => 'error', 'errors' => ['Initiatives are for employees working here now.'], 'employee_id' => $employeeId];
            }

            return ['action' => 'create', 'employee_id' => $employeeId];
        }
        if (! $service->mayEdit($context['actor'], $existing)) {
            return ['action' => 'error', 'errors' => ['You cannot change this initiative.'], 'employee_id' => $employeeId];
        }
        unset($data['title']); // how the initiative was matched: it keeps the title on file
        $changes = array_keys(array_filter($data, fn ($value, $field) => (string) ($existing->getRawOriginal($field) === null ? '' : $existing->getRawOriginal($field)) !== (string) $value
            && ! (in_array($field, ['start_date', 'due_date'], true) && str_starts_with((string) $existing->getRawOriginal($field), (string) $value)), ARRAY_FILTER_USE_BOTH));

        return $changes === []
            ? ['action' => 'skip', 'match' => ['id' => $existing->getKey(), 'reason' => 'Already on file with these values.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => ['id' => $existing->getKey(), 'changes' => $changes], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = $this->prepare($import);
        $data = $this->data($r, (int) $row->employee_id, $context, $errors);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }
        $service = app(Initiatives::class);
        $existing = $this->existing((int) $row->employee_id, $r['title']);
        if ($existing !== null) {
            unset($data['title']);
            $updated = $service->update($existing, $data, $user);

            return 'Updated '.$updated->title.' ('.$updated->status.', '.$updated->progress.'%)';
        }
        unset($data['progress']);
        $initiative = $service->create(Employee::query()->findOrFail($row->employee_id), $data, $user);
        if (isset($r['progress']) && (int) $r['progress'] > 0) {
            $initiative = $service->update($initiative, ['progress' => $r['progress']], $user);
        }

        return 'Created '.$initiative->title.' ('.$initiative->status.')';
    }

    /** @param list<string> $errors @return array<string, mixed> the initiative's data as the service takes it */
    private function data(array $r, int $employeeId, array $context, array &$errors): array
    {
        $data = [];
        $title = trim((string) ($r['title'] ?? ''));
        $title === '' || mb_strlen($title) > 200 ? $errors[] = 'An initiative needs a title of up to 200 characters.' : $data['title'] = $title;
        if (isset($r['type'])) {
            $type = $context['types'][mb_strtolower($r['type'])] ?? null;
            $type === null ? $errors[] = "Initiative type code '{$r['type']}' is not an active initiative type (Performance → Masters)." : $data['initiative_type_id'] = $type;
        } elseif ($context['types'] !== [] && $this->existing($employeeId, $title) === null) {
            $errors[] = 'Choose the initiative type (Initiative type code).';
        }
        if (isset($r['description'])) {
            $data['description'] = $r['description'];
        }
        if (isset($r['goal'])) {
            $goals = Goal::query()->where('employee_id', $employeeId)->where('status', '!=', 'cancelled')->whereRaw('lower(trim(title)) = ?', [mb_strtolower(trim($r['goal']))])->pluck('id');
            $goals->count() === 1 ? $data['goal_id'] = (int) $goals->first() : $errors[] = $goals->isEmpty() ? "The employee has no goal '{$r['goal']}'." : "The employee has more than one goal '{$r['goal']}'.";
        }
        foreach (['start_date' => 'Start date', 'due_date' => 'Due date'] as $field => $label) {
            if (isset($r[$field])) {
                $date = $this->date($r[$field]);
                $date === null ? $errors[] = "{$label} must be a date (YYYY-MM-DD)." : $data[$field] = $date;
            }
        }
        if (isset($data['start_date'], $data['due_date']) && $data['due_date'] < $data['start_date']) {
            $errors[] = 'An initiative cannot be due before it starts.';
        }
        if (isset($r['progress'])) {
            ctype_digit($r['progress']) && (int) $r['progress'] <= 100 ? $data['progress'] = (int) $r['progress'] : $errors[] = 'Progress is between 0 and 100.';
        }

        return $data;
    }

    private function existing(int $employeeId, string $title): ?Initiative
    {
        return Initiative::query()->where('employee_id', $employeeId)->whereIn('status', Initiative::OPEN)->whereRaw('lower(trim(title)) = ?', [mb_strtolower(trim($title))])->orderBy('id')->first();
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee taking part.', 'EMP0001'],
                ['Initiative', 'Yes', 'The initiative\'s title (up to 200 characters); with the employee it identifies an open initiative.', 'Run the customer feedback programme'],
                ['Initiative type code', 'No', 'An initiative type (Performance → Masters); needed for a new initiative while types are set up.', ''],
                ['Description', 'No', 'What it is about.', ''],
                ['Goal', 'No', 'The title of one of the employee\'s own goals it supports.', ''],
                ['Start date', 'No', 'YYYY-MM-DD.', now()->toDateString()],
                ['Due date', 'No', 'YYYY-MM-DD, not before the start date.', now()->addMonths(3)->toDateString()],
                ['Progress (%)', 'No', '0 to 100; progress on a planned initiative starts it.', '0'],
            ],
            'notes' => [
                'An open initiative (planned or in progress) with the same employee and title is updated; otherwise a planned one is created and assigned in your name.',
                'Complete or cancel initiatives on their page. Only employees working here now are given new initiatives.',
            ],
            'codes' => PerformanceLookup::codeOptions('initiative_type'),
            'codes_title' => 'Initiative types',
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all() ?: ['EMP0001'];
        $type = (string) (array_key_first(PerformanceLookup::codeOptions('initiative_type')) ?? '');

        return [
            [$codes[0], 'Run the customer feedback programme', $type, 'Quarterly conversations with key accounts', '', now()->toDateString(), now()->addMonths(3)->toDateString(), '0'],
            [$codes[1] ?? $codes[0], 'Automate the month-end reports', $type, '', '', now()->toDateString(), now()->addMonths(2)->toDateString(), '10'],
        ];
    }
}
