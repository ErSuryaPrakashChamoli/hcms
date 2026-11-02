<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Imports\Concerns\ReadsPerformanceRows;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Support\GoalVisibility;

/**
 * Data import DI-35 ("OKR total achievement", HROne Import → Performance; matrix 27 §27.10 #2): the total achievement of
 * an objective given by HR, through Goals::overrideAchievement (GO-4's "actual achievement" with a reason: the final
 * achievement shown and used by KPI rating formulas; the system's own figure and roll-ups are kept). It sits behind
 * performance.goals.achievement_override (Setup → Performance, off by default): while it is off every row is refused, naming
 * the switch. Who may: performance HR, or the manager of the goal's owner — never on one's own goal; never a cancelled goal
 * or one of a closed cycle. The same achievement again is skipped.
 */
final class OkrTotalImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'okr_total';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', false], 'cycle_code' => ['Cycle code', false], 'objective' => ['Objective', true],
            'achievement' => ['Total achievement (%)', true], 'reason' => ['Reason', true]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'OKR total achievement upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function entityType(): string
    {
        return Goal::class;
    }

    protected function aliases(): array
    {
        return ['cycle' => 'cycle_code', 'goal' => 'objective', 'objective_title' => 'objective', 'total_achievement' => 'achievement', 'actual_achievement' => 'achievement',
            'achievement_percent' => 'achievement', 'remarks' => 'reason'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => $this->uploader($import)];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        if (! GoalVisibility::overrideEnabled()) {
            return ['action' => 'error', 'errors' => ['Overriding the calculated achievement is switched off (Setup → Performance → "Override achievement", performance.goals.achievement_override).']];
        }
        $errors = [];
        $employeeId = null;
        if (isset($r['employee_code'])) {
            [$employeeId, $errors] = $this->employeeOf($r, $context);
        }
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors, required: false);
        $achievement = rtrim(trim((string) ($r['achievement'] ?? '')), '%');
        if (! is_numeric($achievement) || (float) $achievement < 0 || (float) $achievement > 1000) {
            $errors[] = 'The actual achievement must be between 0 and 1000 %.';
        }
        if (trim((string) ($r['reason'] ?? '')) === '' || mb_strlen((string) ($r['reason'] ?? '')) > 500) {
            $errors[] = 'Give a reason for overriding the achievement (at most 500 characters).';
        }
        if ($errors !== [] || $context['actor'] === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $goal = $this->goalOf($employeeId, $cycle, (string) $r['objective']);
        if ($goal === null) {
            return ['action' => 'error', 'errors' => ["No objective '{$r['objective']}' you may see is on file for ".($employeeId === null ? 'the organisation' : 'this employee').($cycle ? " in the cycle {$cycle->name}" : '').'.'], 'employee_id' => $employeeId];
        }
        if (isset($context['seen'][$goal->id])) {
            return ['action' => 'error', 'errors' => ['The same objective is in row '.$context['seen'][$goal->id].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$goal->id] = $row->row_number;
        if (! GoalVisibility::mayOverride($context['actor'], $goal)) {
            return ['action' => 'error', 'errors' => ['Only performance HR or the manager of the goal\'s owner may override its achievement, never on their own goal.'], 'employee_id' => $employeeId];
        }
        if ($goal->performance_cycle_id && $goal->cycle()->value('status') === 'closed') {
            return ['action' => 'error', 'errors' => ['This goal belongs to a closed cycle.'], 'employee_id' => $employeeId];
        }
        if ($goal->achievement_override !== null && abs((float) $goal->achievement_override - (float) $achievement) < 0.001) {
            return ['action' => 'skip', 'match' => ['id' => $goal->getKey(), 'reason' => "The actual achievement is already {$achievement} %."], 'employee_id' => $employeeId];
        }

        return ['action' => 'update', 'match' => ['id' => $goal->getKey(), 'system' => $goal->systemAchievement()], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = [];
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors, required: false);
        $goal = $this->goalOf($row->employee_id === null ? null : (int) $row->employee_id, $cycle, (string) $r['objective']);
        if ($goal === null) {
            throw new \RuntimeException('The objective is no longer on file.');
        }
        $done = app(Goals::class)->overrideAchievement($goal, (float) rtrim(trim($r['achievement']), '%'), $r['reason'], $user);

        return $done->title.': achievement '.(float) $done->achievement_override.' % (system '.$done->systemAchievement().' %)';
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'No', 'The objective\'s owner; blank for an organisation objective.', 'EMP0001'],
                ['Cycle code', 'No', 'The performance cycle the objective is in; blank for an objective outside cycles.', 'FY26'],
                ['Objective', 'Yes', 'The objective\'s (goal\'s) title.', 'Delight our customers'],
                ['Total achievement (%)', 'Yes', 'The objective\'s total achievement in per cent, 0 to 1000.', '92'],
                ['Reason', 'Yes', 'Why the achievement is given by hand (at most 500 characters).', 'Year-end review panel'],
            ],
            'notes' => [
                'Works only while overriding the calculated achievement is switched on (Setup → Performance); until then every row is refused.',
                'The achievement given becomes the objective\'s final achievement; the system\'s own figure is kept beside it, and roll-ups to parent goals still use the system figure.',
                'Needs performance HR rights, or being the manager of the goal\'s owner; never on your own goal. Cancelled goals and goals of closed cycles are refused.',
            ],
        ];
    }

    protected function examples(): array
    {
        $goals = Goal::query()->with(['employee', 'cycle'])->whereIn('status', ['draft', 'active', 'completed'])->orderBy('id')->limit(2)->get();
        $row = function (int $i, string $value) use ($goals) {
            $goal = $goals->get($i);
            if ($goal === null) {
                return [(string) (Employee::query()->orderBy('id')->skip($i)->value('employee_code') ?? 'EMP0001'), (string) (PerformanceCycle::query()->orderByDesc('id')->value('code') ?? ''),
                    $i === 0 ? 'Delight our customers' : 'Grow revenue in the north region', $value, 'Year-end review panel'];
            }

            return [(string) ($goal->employee?->employee_code ?? ''), (string) ($goal->cycle?->code ?? ''), $goal->title, $value, 'Year-end review panel'];
        };

        return [$row(0, '92'), $row(1, '105')];
    }
}
