<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Imports\Concerns\ReadsPerformanceRows;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Goals;
use Illuminate\Support\Facades\Gate;

/**
 * Data import DI-35 ("OKR final achievement", HROne Import → Performance; matrix 27 §27.10 #1–2): the final achieved value
 * of each key result of an objective, recorded as a check-in through Goals::checkIn (source "import") as a key result's
 * "Check in" does: the key result's value and progress, the objective's weighted roll-up and its parents'. Only goals the
 * uploader may see and check in on (GoalPolicy::checkIn) are matched — an employee's objective by employee + cycle + title,
 * an organisation objective (no employee code) by cycle + title; open goals only, never one locked in a closed cycle. The
 * same value again is skipped. Rows only change key results already on file.
 */
final class OkrFinalImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'okr_final';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', false], 'cycle_code' => ['Cycle code', false], 'objective' => ['Objective', true], 'key_result' => ['Key result', true],
            'value' => ['Achieved value', true], 'note' => ['Note', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'OKR final achievement upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function entityType(): string
    {
        return GoalCheckIn::class;
    }

    protected function aliases(): array
    {
        return ['cycle' => 'cycle_code', 'goal' => 'objective', 'objective_title' => 'objective', 'kr' => 'key_result', 'key_result_title' => 'key_result',
            'achievement' => 'value', 'actual' => 'value', 'actual_value' => 'value', 'final_value' => 'value', 'remarks' => 'note', 'comment' => 'note'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => $this->uploader($import)];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $errors = [];
        $employeeId = null;
        if (isset($r['employee_code'])) {
            [$employeeId, $errors] = $this->employeeOf($r, $context);
        }
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors, required: false);
        $value = str_replace(',', '', (string) ($r['value'] ?? ''));
        if (! is_numeric($value)) {
            $errors[] = 'Achieved value must be a number.';
        }
        if ($errors !== [] || $context['actor'] === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        [$goal, $keyResult, $problem] = $this->target($employeeId, $cycle, $r, $context['actor']);
        if ($problem !== null) {
            return ['action' => 'error', 'errors' => [$problem], 'employee_id' => $employeeId];
        }
        $key = $keyResult->getKey();
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same key result is in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        if ($keyResult->current_value !== null && abs((float) $keyResult->current_value - (float) $value) < 0.0001) {
            return ['action' => 'skip', 'match' => ['id' => $key, 'reason' => 'The key result already shows this value.'], 'employee_id' => $employeeId];
        }

        return ['action' => 'update', 'match' => ['id' => $key, 'goal_id' => $goal->getKey()], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = [];
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors, required: false);
        [$goal, $keyResult, $problem] = $this->target($row->employee_id === null ? null : (int) $row->employee_id, $cycle, $r, $user);
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
        app(Goals::class)->checkIn($keyResult, (float) str_replace(',', '', $r['value']), $r['note'] ?? 'Final achievement ('.$this->label().' #'.$import->getKey().')', null, $user, 'import',
            idempotencyKey: "import-{$import->getKey()}-{$row->row_number}");
        $keyResult->refresh();

        return $keyResult->title.': '.(float) $keyResult->current_value.' ('.(float) $keyResult->progress.'%); '.$goal->title.' now '.(float) $goal->refresh()->progress.'%';
    }

    /**
     * The objective and key result a row names, or why not. @return array{0: ?Goal, 1: ?KeyResult, 2: ?string}
     */
    private function target(?int $employeeId, ?PerformanceCycle $cycle, array $r, User $actor): array
    {
        $goal = $this->goalOf($employeeId, $cycle, (string) ($r['objective'] ?? ''));
        if ($goal === null) {
            return [null, null, "No objective '".($r['objective'] ?? '')."' you may see is on file for ".($employeeId === null ? 'the organisation' : 'this employee').($cycle ? " in the cycle {$cycle->name}" : '').'.'];
        }
        $keyResults = KeyResult::query()->where('goal_id', $goal->id)->whereRaw('lower(trim(title)) = ?', [mb_strtolower(trim((string) ($r['key_result'] ?? '')))])->get();
        if ($keyResults->count() !== 1) {
            return [$goal, null, $keyResults->isEmpty() ? "The objective has no key result '".($r['key_result'] ?? '')."'." : 'The objective has more than one key result of that title.'];
        }
        if (! Gate::forUser($actor)->allows('checkIn', $goal)) {
            return [$goal, null, 'You cannot check in on this objective.'];
        }
        if (! $goal->isOpen()) {
            return [$goal, null, 'This goal is closed.'];
        }
        if ($goal->is_locked && $goal->performance_cycle_id && $goal->cycle()->value('status') === 'closed') {
            return [$goal, null, 'This goal belongs to a closed cycle.'];
        }

        return [$goal, $keyResults->first(), null];
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'No', 'The objective\'s owner; blank for an organisation objective (company, business, department or team).', 'EMP0001'],
                ['Cycle code', 'No', 'The performance cycle the objective is in; blank for an objective outside cycles.', 'FY26'],
                ['Objective', 'Yes', 'The objective\'s (goal\'s) title.', 'Delight our customers'],
                ['Key result', 'Yes', 'The key result\'s title under that objective.', 'Raise NPS to 60'],
                ['Achieved value', 'Yes', 'The final value reached, in the key result\'s own measure (e.g. 58 for an NPS of 58).', '58'],
                ['Note', 'No', 'Kept on the check-in.', 'Year-end survey'],
            ],
            'notes' => [
                'Each row is a check-in on the key result (source: import): its value and progress change, and the objective\'s progress is worked out again from its key results.',
                'Only objectives you may see and check in on are matched; closed goals and goals locked in a closed cycle are refused. The same value again is skipped.',
                'To give an objective\'s total achievement by hand, use the OKR total achievement upload.',
            ],
        ];
    }

    protected function examples(): array
    {
        $results = KeyResult::query()->with(['goal.employee', 'goal.cycle'])->whereHas('goal', fn ($q) => $q->whereIn('status', ['draft', 'active']))->orderBy('id')->limit(2)->get();
        $row = function (int $i, string $value) use ($results) {
            $kr = $results->get($i);
            if ($kr === null) {
                return [(string) (Employee::query()->orderBy('id')->skip($i)->value('employee_code') ?? 'EMP0001'), (string) (PerformanceCycle::query()->orderByDesc('id')->value('code') ?? ''),
                    'Delight our customers', $i === 0 ? 'Raise NPS to 60' : 'Answer every ticket within a day', $value, 'Year-end figures'];
            }

            return [(string) ($kr->goal->employee?->employee_code ?? ''), (string) ($kr->goal->cycle?->code ?? ''), $kr->goal->title, $kr->title, $value, 'Year-end figures'];
        };

        return [$row(0, '58'), $row(1, '95')];
    }
}
