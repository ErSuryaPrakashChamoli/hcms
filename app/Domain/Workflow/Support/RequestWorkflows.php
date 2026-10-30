<?php

namespace App\Domain\Workflow\Support;

use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Models\WorkflowVersion;
use App\Domain\Workflow\Services\ApproverResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne setup parity P5 (Workflow per request type): when a workflow run decides the request it runs on. A workflow
 * decides a request when it is set to run on that request type ("Runs on"), or when it runs on any record and was
 * started by one of the request's own approval triggers introduced with P5 (loan, claim, variable pay, compensation
 * change, tax declaration, resignation). Workflows on the earlier triggers of regularisations and letters decided
 * nothing; they keep deciding nothing unless they are set to run on that request type, so existing workflows behave
 * as before. While a deciding run is in flight the Approval Center leaves the request to the workflow task.
 *
 * HROne parity RQ-2 (request chrome): preview() is a READ-ONLY dry run of the route a request would take if raised now —
 * the workflows that would start on its triggers (as WorkflowTrigger::fire picks them: active, published, running on any
 * record or on that request type, by priority when only the first match starts), their start conditions checked against
 * what is known before the request exists (the employee's rule context; a condition on the request's own details makes
 * the workflow "may apply"), and each approval step's approvers resolved through ApproverResolver on an UNSAVED run whose
 * subject is the employee. It never starts a run, never creates a task and writes nothing. decidingRuns() lists the runs
 * that decide a request (for the requester's timeline and "waiting with").
 */
final class RequestWorkflows
{
    /** Request class => the P5 triggers whose runs decide it even when the workflow runs on any record. */
    public const DECIDING_TRIGGERS = [
        'App\\Domain\\Loans\\Models\\Loan' => ['payroll.loan_requested'],
        'App\\Domain\\Claims\\Models\\ReimbursementClaim' => ['payroll.claim_submitted'],
        'App\\Domain\\Payroll\\Models\\VariablePayRequest' => ['payroll.variable_pay_requested'],
        'App\\Domain\\Compensation\\Models\\CompensationChange' => ['compensation.change.submitted', 'compensation.change.reviewed'],
        'App\\Domain\\Compliance\\Models\\EmployeeTaxDeclaration' => ['payroll.tax_declaration_submitted'],
        'App\\Domain\\Exit\\Models\\ExitCase' => ['exit.resignation_submitted'],
        // HROne parity MS-3: self-service profile changes.
        'App\\Domain\\People\\Models\\ProfileChangeRequest' => ['profile.change_requested'],
    ];

    /**
     * RQ-2: request classes whose Approval Center leaves a request to a run only when the run decides it (P5). For every
     * other request class the Approval Center steps aside for any run in flight on the request (leave, time office …).
     */
    public const STRICT = [
        'App\\Domain\\Attendance\\Models\\AttendanceRegularisation',
        'App\\Domain\\Letters\\Models\\Letter',
    ];

    /** Nodes walked at most per workflow in a preview (a definition with loops still ends). */
    private const PREVIEW_NODES = 100;

    /** Whether this run decides its subject (see the class comment). */
    public static function decides(WorkflowInstance $instance, Model $subject): bool
    {
        $workflow = $instance->relationLoaded('workflow') ? $instance->workflow : $instance->workflow()->first();
        if ($workflow === null) {
            return false;
        }
        $runsOn = $workflow->getAttributes()['subject_type'] ?? null;
        if ($runsOn !== null) {
            return $runsOn === $subject::class;
        }

        return in_array($instance->contextValue('trigger'), self::DECIDING_TRIGGERS[$subject::class] ?? [], true);
    }

    /**
     * Ids among $ids of requests of this class with a deciding run in flight (running, waiting or sent back).
     *
     * @param  iterable<int|string>  $ids
     * @return list<int>
     */
    public static function inFlight(Model $prototype, iterable $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return WorkflowInstance::query()->with('workflow')->where('subject_type', $prototype->getMorphClass())->whereIn('subject_id', $ids->all())
            ->whereIn('status', InstanceStatus::inFlight())->get()
            ->filter(fn (WorkflowInstance $i) => self::decides($i, $prototype))
            ->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** The person who completed the run's last task (the outcome is applied in their name), or null. */
    public static function lastCompleter(WorkflowInstance $instance): ?User
    {
        $id = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->whereNotNull('completed_by')
            ->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by');

        return $id ? User::query()->find($id) : null;
    }

    /** The note recorded on the request: which workflow decided it, and the last note given. */
    public static function note(WorkflowInstance $instance): string
    {
        return 'Workflow "'.$instance->workflow->name.'" run #'.$instance->id.($instance->contextValue('last_note') ? ': '.$instance->contextValue('last_note') : '');
    }

    /** RQ-2: whether only deciding runs (not any run in flight) take a request of this class out of the Approval Center. */
    public static function isStrict(string $class): bool
    {
        return in_array($class, self::STRICT, true) || array_key_exists($class, self::DECIDING_TRIGGERS);
    }

    /** RQ-2: whether a workflow started by $trigger on a request of $class would decide that request. */
    public static function wouldDecide(Workflow $workflow, string $trigger, string $class): bool
    {
        $runsOn = $workflow->getAttributes()['subject_type'] ?? null;
        if ($runsOn !== null) {
            return $runsOn === $class;
        }

        return ! self::isStrict($class) || in_array($trigger, self::DECIDING_TRIGGERS[$class] ?? [], true);
    }

    /**
     * RQ-2: the runs that decide these records (any status), oldest first — for strict classes only runs that decide them
     * (decides()), for the others every run on them (the Approval Center steps aside for any run in flight).
     *
     * @return Collection<int, WorkflowInstance>
     */
    public static function decidingRuns(Model ...$records): Collection
    {
        $runs = collect();
        foreach ($records as $record) {
            if (! $record->exists) {
                continue;
            }
            $found = WorkflowInstance::query()->with('workflow')->whereIn('subject_type', array_values(array_unique([$record::class, $record->getMorphClass()])))
                ->where('subject_id', $record->getKey())->orderBy('id')->get()
                ->filter(fn (WorkflowInstance $run) => ! self::isStrict($record::class) || self::decides($run, $record));
            $runs = $runs->merge($found);
        }

        return $runs->unique('id')->sortBy('id')->values();
    }

    /**
     * RQ-2: who would approve a request of $class raised now for $employee by $requester (see the class comment). Read only.
     * The deciding workflows that would start (first match: the first one that surely applies, after any that only may),
     * each with its approval steps in order; empty: no workflow decides it (the module's own approvers do).
     *
     * @param  list<string>  $triggers  the events the module dispatches when the request is raised
     * @return list<array{workflow: string, conditional: bool, steps: list<array{label: string, approvers: list<string>, mode: string, conditional: bool, hours: ?float}>}>
     */
    public static function preview(string $class, array $triggers, ?Employee $employee, ?User $requester): array
    {
        if ($triggers === []) {
            return [];
        }
        $firstOnly = (bool) app(SettingsRepository::class)->get('workflow.first_match_only', false);
        $known = $employee !== null ? app(EmployeeRuleContext::class)->build($employee) : [];
        $workflows = Workflow::query()->with('published')->whereIn('trigger_event', $triggers)->where('status', 'active')->whereHas('published')
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', $class))
            ->when($firstOnly, fn ($q) => $q->orderBy('priority'))->orderBy('id')->get();

        $routes = [];
        foreach ($workflows as $workflow) {
            $trigger = (string) $workflow->trigger_event;
            $applies = self::conditionsApply((array) ($workflow->start_conditions ?? []), $known, 'all');
            if ($applies === false) {
                continue;
            }
            $deciding = self::wouldDecide($workflow, $trigger, $class);
            if ($deciding) {
                $routes[] = ['workflow' => (string) $workflow->name, 'conditional' => $applies === null,
                    'steps' => self::previewSteps($workflow->published, $known, $employee, $requester)];
            }
            // Only the first match starts: one that surely applies ends the search (one that only may lets later ones in).
            if ($firstOnly && $applies === true) {
                break;
            }
        }

        return $routes;
    }

    /**
     * True: the conditions hold for what is known; false: a known value fails them; null: they depend on something not
     * known before the request exists (its own details).
     *
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<string, mixed>  $known
     */
    private static function conditionsApply(array $conditions, array $known, string $match): ?bool
    {
        if ($conditions === []) {
            return true;
        }
        $engine = app(RuleEngine::class);
        $results = [];
        foreach ($conditions as $condition) {
            $field = (string) ($condition['field'] ?? '');
            $results[] = array_key_exists($field, $known) ? rescue(fn () => $engine->evaluate($condition, $known), false, false) : null;
        }
        if ($match === 'any') {
            return in_array(true, $results, true) ? true : (in_array(null, $results, true) ? null : false);
        }

        return in_array(false, $results, true) ? false : (in_array(null, $results, true) ? null : true);
    }

    /**
     * The approval steps of a published definition in the order a run meets them. A condition that can be decided from
     * what is known is followed; one that cannot is followed both ways, and what lies behind it is marked conditional.
     *
     * @param  array<string, mixed>  $known
     * @return list<array{label: string, approvers: list<string>, mode: string, conditional: bool, hours: ?float}>
     */
    private static function previewSteps(?WorkflowVersion $version, array $known, ?Employee $employee, ?User $requester): array
    {
        $start = $version?->startNodeId();
        if ($version === null || $start === null) {
            return [];
        }
        // A run that is never saved: the employee is its subject, the requester its initiator, nothing in its context.
        $dry = new WorkflowInstance;
        $dry->forceFill(['started_by' => $requester?->getKey(), 'context' => []]);
        $dry->setRelation('subject', $employee);
        $steps = [];
        $queue = [[$start, false]];
        $seen = [];
        while ($queue !== [] && count($seen) < self::PREVIEW_NODES) {
            [$id, $conditional] = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $node = $version->node((string) $id);
            if ($node === null) {
                continue;
            }
            $edges = $version->edgesFrom((string) $id);
            $next = fn (?string $label) => array_values(array_map(fn (array $e) => (string) $e['to'], array_filter($edges, fn (array $e) => ($e['label'] ?? null) === $label)));
            switch ($node['type'] ?? null) {
                case 'end':
                    break;
                case 'condition':
                    $config = $node['config'] ?? [];
                    $result = self::conditionsApply((array) ($config['conditions'] ?? []), $known, (string) ($config['match'] ?? 'all'));
                    if ($result === null) {
                        foreach ([...$next('yes'), ...$next('no')] as $to) {
                            $queue[] = [$to, true];
                        }
                    } else {
                        foreach ($next($result ? 'yes' : 'no') as $to) {
                            $queue[] = [$to, $conditional];
                        }
                    }
                    break;
                case 'approval':
                    $steps[] = self::describeApproval($node, $dry, $conditional);
                    $approved = $next('approved');
                    foreach ($approved !== [] ? $approved : $next(null) as $to) {
                        $queue[] = [$to, $conditional];
                    }
                    break;
                default:
                    foreach ($next(null) !== [] ? $next(null) : array_map(fn (array $e) => (string) $e['to'], $edges) as $to) {
                        $queue[] = [$to, $conditional];
                    }
            }
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{label: string, approvers: list<string>, mode: string, conditional: bool, hours: ?float}
     */
    private static function describeApproval(array $node, WorkflowInstance $dry, bool $conditional): array
    {
        $config = $node['config'] ?? [];
        $mode = (string) ($config['mode'] ?? 'single');
        $specs = $mode === 'single' ? [$config['approver'] ?? []] : (array) ($config['approvers'] ?? []);
        $resolver = app(ApproverResolver::class);
        $approvers = [];
        foreach ($specs as $spec) {
            $spec = (array) $spec;
            if (($spec['type'] ?? null) === 'field') {
                $approvers[] = 'Chosen from the details of the request';

                continue;
            }
            try {
                ['user' => $user, 'role' => $role] = $resolver->resolve($spec, $dry);
                if ($user === null && $role === null && ! empty($config['fallback'])) {
                    ['user' => $user, 'role' => $role] = $resolver->resolve((array) $config['fallback'], $dry);
                }
                $user ??= $role === null ? $resolver->reliever($spec) : null;
            } catch (Throwable $e) {
                report($e);
                $user = $role = null;
            }
            $approvers[] = $user !== null ? (string) $user->name : ($role !== null ? 'Role: '.$role->name : 'No approver found (HR is asked to fix the workflow)');
        }
        $hours = $config['sla_hours'] ?? config('peopleos.workflows.default_sla_hours');

        return [
            'label' => (string) ($node['name'] ?? $config['title'] ?? 'Approval'),
            'approvers' => array_values(array_unique($approvers)),
            'mode' => $mode,
            'conditional' => $conditional,
            'hours' => is_numeric($hours) ? (float) $hours : null,
        ];
    }
}
