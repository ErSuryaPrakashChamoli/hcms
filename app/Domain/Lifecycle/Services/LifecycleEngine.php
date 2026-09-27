<?php

namespace App\Domain\Lifecycle\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Blueprint §19–§20: validated state transitions that fan out to log, audit, timeline, event. */
final class LifecycleEngine
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
    ) {}

    private static int $mutating = 0;

    /** True while the engine (or an explicitly unguarded block) is changing lifecycle_state. */
    public static function isMutating(): bool
    {
        return self::$mutating > 0;
    }

    /** Run a callback that may set lifecycle_state directly (data migrations, tests, provisioning). Audited like any update. */
    public static function unguarded(\Closure $callback): mixed
    {
        self::$mutating++;

        try {
            return $callback();
        } finally {
            self::$mutating--;
        }
    }

    public function transition(
        Employee $employee,
        LifecycleState $to,
        CarbonInterface|string|null $effectiveDate = null,
        ?string $reason = null,
        array $metadata = [],
    ): Employee {
        $from = $employee->lifecycle_state;
        $date = Carbon::parse($effectiveDate ?? now())->startOfDay();

        if (! $from->canTransitionTo($to)) {
            throw InvalidLifecycleTransitionException::between($from, $to);
        }

        return DB::transaction(function () use ($employee, $from, $to, $date, $reason, $metadata) {
            $attributes = ['lifecycle_state' => $to];

            $attributes += match ($to) {
                LifecycleState::Joined, LifecycleState::Probation => $employee->joining_date ? [] : ['joining_date' => $date],
                LifecycleState::Confirmed => ['confirmation_date' => $date],
                LifecycleState::Exited => ['exit_date' => $date],
                default => [],
            };

            self::unguarded(fn () => $employee->withAuditReason($reason)->update($attributes));

            $transition = EmployeeLifecycleTransition::create([
                'employee_id' => $employee->getKey(),
                'from_state' => $from,
                'to_state' => $to,
                'effective_date' => $date,
                'reason' => $reason,
                'actor_id' => auth()->id(),
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            $this->audit->record(
                action: $to->auditAction(),
                module: 'lifecycle',
                entity: $employee,
                changes: [['field' => 'lifecycle_state', 'before' => $from, 'after' => $to]],
                reason: $reason,
                effectiveDate: $date,
                metadata: ['from' => $from->value, 'to' => $to->value] + $metadata,
            );

            $this->timeline->record(
                $employee,
                'lifecycle',
                $this->titleFor($to),
                $date,
                $reason,
                $transition,
                ['from' => $from->value, 'to' => $to->value],
            );

            EmployeeLifecycleChanged::dispatch($employee, $from, $to, $date, $reason);

            return $employee;
        });
    }

    private function titleFor(LifecycleState $state): string
    {
        return match ($state) {
            LifecycleState::Joined => 'Joined company',
            LifecycleState::Probation => 'Started probation',
            LifecycleState::Confirmed => 'Confirmed',
            LifecycleState::Active => 'Active',
            LifecycleState::OnLeave => 'Went on leave',
            LifecycleState::Suspended => 'Suspended',
            LifecycleState::NoticePeriod => 'Resignation / notice period started',
            LifecycleState::Exited => 'Exited company',
            LifecycleState::Alumni => 'Became alumni',
            default => $state->getLabel(),
        };
    }
}
