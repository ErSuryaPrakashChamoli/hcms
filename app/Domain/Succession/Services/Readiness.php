<?php

namespace App\Domain\Succession\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Succession\Events\SuccessionEvent;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 readiness: a configured label (Ready now, < 1 year, 1–2 years, Longer term, Not assessed)
 * recorded by an authorised person (succession.assess within scope, never about themself) for an
 * employee and a target — a critical position or a role — with reason, evidence and an effective
 * window. Immutable: a new assessment supersedes the current one for the same target under a row
 * lock. A label, not a prediction.
 */
final class Readiness
{
    public function __construct(private readonly TalentAccess $access) {}

    public function assess(Employee $employee, ?int $criticalPositionId, ?int $designationId, string $level, string $reason, ?string $evidence, User $actor, ?Successor $successor = null, ?string $effectiveFrom = null): ReadinessAssessment
    {
        if (! $actor->hasPermission('succession.assess') || ! $this->access->inScope($actor, $employee->id) || $this->access->self($actor, $employee->id)) {
            throw new RuntimeException('Readiness is assessed with succession.assess, within scope, never about yourself.');
        }
        if ($criticalPositionId === null && $designationId === null) {
            throw new RuntimeException('Readiness is assessed for a critical position or a role.');
        }
        if ($successor && (int) $successor->employee_id !== (int) $employee->id) {
            throw new RuntimeException('That successor entry belongs to another employee.');
        }
        $from = now()->parse($effectiveFrom ?? now())->toDateString();
        $to = now()->parse($from)->addMonths((int) config('peopleos.talent.readiness_validity_months', 12))->toDateString();
        $key = ReadinessAssessment::targetKey($criticalPositionId, $designationId);

        return DB::transaction(function () use ($employee, $criticalPositionId, $designationId, $level, $reason, $evidence, $actor, $successor, $from, $to, $key) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->id)->lockForUpdate()->first();
            ReadinessAssessment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('target_key', $key)->where('status', 'current')->lockForUpdate()->get()
                ->each(fn (ReadinessAssessment $r) => $r->update(['status' => 'superseded', 'superseded_at' => now()]));
            $assessment = ReadinessAssessment::query()->create([
                'employee_id' => $employee->id, 'critical_position_id' => $criticalPositionId, 'designation_id' => $designationId, 'successor_id' => $successor?->id,
                'target_key' => $key, 'readiness_level' => $level, 'reason' => $reason, 'evidence' => $evidence,
                'assessed_by' => $actor->id, 'assessed_at' => now(), 'effective_from' => $from, 'effective_to' => $to,
            ]);
            SuccessionEvent::dispatch('succession.readiness.assessed', $employee, $assessment, ['level' => $level], [], [$actor->id]);

            return $assessment;
        });
    }

    public function current(Employee $employee, ?int $criticalPositionId, ?int $designationId): ?ReadinessAssessment
    {
        return ReadinessAssessment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->where('target_key', ReadinessAssessment::targetKey($criticalPositionId, $designationId))->where('status', 'current')->latest('id')->first();
    }
}
