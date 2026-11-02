<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Imports\Concerns\ReadsPerformanceRows;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Services\ImprovementPlanRules;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Performance\Services\PerformanceMasters;
use RuntimeException;

/**
 * Data import DI-35 ("Performance improvement plan", HROne Import → Performance; matrix 27 §27.10 #8; decision D35-8):
 * improvement plans with their dates, reviewer, reasons, objectives and checkpoints, opened through ImprovementPlans::open
 * as drafts with their checkpoints (ImprovementPlans::addCheckpoint). "Start now = yes" then starts the plan through
 * ImprovementPlans::activate, exactly as "Start" on the plan's page: under a PIP policy that asks for approval it waits for
 * it, otherwise it starts with the start checklist and the letter. An employee who already has an undecided plan is
 * skipped; plans are never changed from a file (rows only add plans).
 */
final class ImprovementPlanImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'improvement_plans';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'reviewer_code' => ['Reviewer employee code', false], 'reason' => ['Reason', true],
            'reason_codes' => ['Reason codes', false], 'objectives' => ['Objectives', true], 'start_date' => ['Start date', true], 'end_date' => ['End date', false],
            'checkpoints' => ['Checkpoints', false], 'start' => ['Start now', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Improvement plan upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function entityType(): string
    {
        return ImprovementPlan::class;
    }

    protected function aliases(): array
    {
        return ['reviewer' => 'reviewer_code', 'manager_code' => 'reviewer_code', 'manager' => 'reviewer_code', 'pip_reason' => 'reason_codes', 'reason_code' => 'reason_codes',
            'objective' => 'objectives', 'from_date' => 'start_date', 'to_date' => 'end_date', 'checkpoint' => 'checkpoints'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => $this->uploader($import)];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $plan = $this->plan($r, $context, $employeeId, $errors);
        if ($errors !== [] || $employeeId === null) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        if (isset($context['seen'][$employeeId])) {
            return ['action' => 'error', 'errors' => ['The same employee is in row '.$context['seen'][$employeeId].' (one open plan per employee).'], 'employee_id' => $employeeId];
        }
        $context['seen'][$employeeId] = $row->row_number;
        $open = ImprovementPlan::query()->where('employee_id', $employeeId)->whereIn('status', ImprovementPlan::UNDECIDED)->value('status');
        if ($open !== null) {
            return ['action' => 'skip', 'match' => ['reason' => 'An improvement plan is already open for this employee ('.config("peopleos.performance.pip_statuses.{$open}", $open).').'], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'match' => ['end' => $plan['end']], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = $this->prepare($import);
        $plan = $this->plan($r, $context, (int) $row->employee_id, $errors);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $plans = app(ImprovementPlans::class);
        $opened = $plans->open(Employee::query()->findOrFail($row->employee_id), $plan['reviewer'], trim($r['reason']), $plan['objectives'], $plan['from'], $plan['end'],
            null, $user, draft: true, reasonCodes: $plan['reason_codes']);
        foreach ($plan['checkpoints'] as [$due, $title]) {
            $plans->addCheckpoint($opened, $title, $due, null, $user);
        }
        if ($plan['start']) {
            $opened = $plans->activate($opened, $user);
        }

        return ($plan['start'] ? ($opened->status === 'pending_approval' ? 'Plan waiting for approval' : 'Plan started') : 'Draft plan').' '.$opened->start_date->format('d M Y').' – '.$opened->end_date->format('d M Y')
            .' with '.count($plan['objectives']).' objective(s)'.($plan['checkpoints'] === [] ? '' : ' and '.count($plan['checkpoints']).' checkpoint(s)').($plan['start'] ? '' : '; start it on its page');
    }

    /**
     * The plan the row opens. @param list<string> $errors
     *
     * @return array{reviewer: ?int, reason_codes: list<string>, objectives: list<array{objective: string}>, start: bool, from: ?string, end: ?string, checkpoints: list<array{0: string, 1: string}>}
     */
    private function plan(array $r, array $context, ?int $employeeId, array &$errors): array
    {
        $actor = $context['actor'];
        if ($actor === null || (! $actor->hasPermission('performance.manage') && ! $actor->hasPermission('performance.pip'))) {
            $errors[] = 'Only HR or a manager of this employee manages the improvement plan.';
        }
        if (trim((string) ($r['reason'] ?? '')) === '') {
            $errors[] = 'A plan needs a reason.';
        }
        $reviewer = null;
        if (isset($r['reviewer_code'])) {
            $reviewer = $context['employees'][mb_strtolower($r['reviewer_code'])] ?? null;
            if ($reviewer === null) {
                $errors[] = "Reviewer employee code '{$r['reviewer_code']}' does not exist.";
            } elseif ($reviewer === $employeeId) {
                $errors[] = 'The reviewer is someone other than the employee.';
            } elseif (! Employee::query()->findOrFail($reviewer)->lifecycle_state->isEmployed()) {
                $errors[] = 'The reviewer must work here now.';
            }
        }
        $codes = [];
        if (isset($r['reason_codes'])) {
            try {
                $codes = app(PerformanceMasters::class)->pipReasonCodes(preg_split('/[,;]/', $r['reason_codes']) ?: []);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage().' (Performance → Masters → PIP reasons)';
            }
        }
        $objectives = array_map(fn (string $o) => ['objective' => mb_substr($o, 0, 500)], array_values(array_filter(array_map('trim', explode(';', (string) ($r['objectives'] ?? ''))))));
        if ($objectives === []) {
            $errors[] = 'A plan needs at least one objective.';
        }
        $start = $this->date($r['start_date'] ?? null);
        if ($start === null) {
            $errors[] = 'Start date must be a date (YYYY-MM-DD).';
        }
        $end = isset($r['end_date']) ? $this->date($r['end_date']) : null;
        if (isset($r['end_date']) && $end === null) {
            $errors[] = 'End date must be a date (YYYY-MM-DD).';
        }
        if ($end === null && $start !== null && $employeeId !== null && ! isset($r['end_date'])) {
            $end = app(ImprovementPlanRules::class)->defaultEnd(Employee::query()->find($employeeId), $start)->toDateString();
        }
        if ($start !== null && $end !== null && $end < $start) {
            $errors[] = 'A plan cannot end before it starts.';
        }
        $checkpoints = [];
        foreach (array_filter(array_map('trim', explode(';', (string) ($r['checkpoints'] ?? '')))) as $item) {
            if (! preg_match('/^(\S+)\s+(.+)$/', $item, $m) || ($due = $this->date($m[1])) === null) {
                $errors[] = "Checkpoint '{$item}' must be \"YYYY-MM-DD title\".";

                continue;
            }
            if ($start !== null && $end !== null && ($due < $start || $due > $end)) {
                $errors[] = "Checkpoint '{$item}' must fall within the plan period.";
            }
            $checkpoints[] = [$due, mb_substr(trim($m[2]), 0, 255)];
        }

        $now = false;
        if (isset($r['start'])) {
            $value = mb_strtolower(trim($r['start']));
            in_array($value, ['yes', 'y', '1', 'true'], true) ? $now = true : (in_array($value, ['no', 'n', '0', 'false'], true) ? null : $errors[] = 'Start now must be yes or no.');
        }

        return ['reviewer' => $reviewer, 'reason_codes' => $codes, 'objectives' => $objectives, 'start' => $now, 'from' => $start, 'end' => $end, 'checkpoints' => $checkpoints];
    }

    public function format(): array
    {
        $start = now()->addWeek()->startOfWeek();

        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee the plan is for.', 'EMP0001'],
                ['Reviewer employee code', 'No', 'Who reviews the plan (usually the manager); a current employee.', 'EMP0002'],
                ['Reason', 'Yes', 'Why the plan is opened.', 'Targets missed for two quarters'],
                ['Reason codes', 'No', 'PIP reasons (Performance → Masters → PIP reasons), separated by commas.', implode(',', array_slice(array_keys(PerformanceLookup::codeOptions('pip_reason')), 0, 1))],
                ['Objectives', 'Yes', 'What the employee must achieve, separated by ";".', 'Close 10 renewals a month; Reply to every escalation within a day'],
                ['Start date', 'Yes', 'YYYY-MM-DD.', $start->toDateString()],
                ['End date', 'No', 'YYYY-MM-DD; blank uses the PIP policy\'s duration (two months when none is set).', $start->copy()->addMonths(2)->toDateString()],
                ['Checkpoints', 'No', 'Reviews during the plan, separated by ";", each "YYYY-MM-DD title" within the plan\'s dates.', $start->copy()->addMonth()->toDateString().' Mid-plan review'],
                ['Start now', 'No', 'yes to start the plan at once, as "Start" on its page (it waits for approval when the PIP policy asks for it); no or blank opens a draft.', 'no'],
            ],
            'notes' => [
                'Each row opens a plan in your name with its checkpoints: a draft, or — with Start now = yes — started as on the plan\'s page (the PIP policy\'s approval, the start checklist and the letter follow).',
                'An employee who already has an open plan (draft, waiting, active, extended or awaiting its outcome) is skipped. Plans are not changed from a file.',
            ],
            'codes' => PerformanceLookup::codeOptions('pip_reason'),
            'codes_title' => 'PIP reasons',
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->employed()->orderBy('id')->limit(3)->pluck('employee_code')->all() ?: ['EMP0001'];
        $reason = (string) (array_key_first(PerformanceLookup::codeOptions('pip_reason')) ?? '');
        $start = now()->addWeek()->startOfWeek();
        $second = $codes[1] ?? $codes[0];

        return [
            [$codes[0], $second !== $codes[0] ? $second : '', 'Targets missed for two quarters', $reason, 'Close 10 renewals a month; Reply to every escalation within a day',
                $start->toDateString(), $start->copy()->addMonths(2)->toDateString(), $start->copy()->addMonth()->toDateString().' Mid-plan review', 'no'],
            [$codes[2] ?? $second, '', 'Quality of work below the agreed standard', '', 'No more than 2 reopened tickets a week', $start->toDateString(), '', '', 'yes'],
        ];
    }
}
