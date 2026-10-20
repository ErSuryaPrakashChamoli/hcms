<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Contracts\DevelopmentNeedsReader;
use App\Domain\Performance\Models\DevelopmentNeed;
use RuntimeException;

/** Phase 7: record development needs found in performance conversations; Learning reads them later. */
final class DevelopmentNeeds implements DevelopmentNeedsReader
{
    public function __construct(private readonly PerformanceRelationships $relationships) {}

    /**
     * Phase 8 adds $details (skill, scale version, current / target level, current / desired state,
     * reason, owner, target date) so Learning & Development can consume the need.
     *
     * @param  array{skill_id?: ?int, skill_scale_version_id?: ?int, current_level?: ?float, target_level?: ?float, current_state?: ?string, desired_state?: ?string, reason?: ?string, owner_employee_id?: ?int, target_date?: ?string}  $details
     */
    public function record(Employee $employee, string $title, string $sourceType = 'manual', ?int $sourceId = null, ?int $competencyId = null, string $priority = 'medium', ?string $description = null, ?User $actor = null, array $details = []): DevelopmentNeed
    {
        $actor ??= auth()->user();
        if (! in_array($sourceType, DevelopmentNeed::SOURCES, true)) {
            throw new RuntimeException("Unknown development-need source '{$sourceType}'.");
        }
        if (! array_key_exists($priority, config('peopleos.performance.development_need_priorities'))) {
            throw new RuntimeException("Unknown priority '{$priority}'.");
        }
        if ($actor !== null && ! $actor->hasPermission('performance.manage') && ! $actor->hasPermission('development.manage')) {
            $me = $this->relationships->forUser($actor);
            if ($me?->id !== $employee->id && ! $this->relationships->manages($me, $employee->id)) {
                throw new RuntimeException('Record development needs for yourself or for employees you manage.');
            }
        }

        return DevelopmentNeed::query()->create([
            'employee_id' => $employee->id, 'title' => $title, 'description' => $description, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'competency_id' => $competencyId, 'priority' => $priority, 'created_by' => $actor?->id,
            ...array_intersect_key($details, array_flip(['skill_id', 'skill_scale_version_id', 'current_level', 'target_level', 'current_state', 'desired_state', 'reason', 'owner_employee_id', 'target_date'])),
        ]);
    }

    public function setStatus(DevelopmentNeed $need, string $status): DevelopmentNeed
    {
        if (! array_key_exists($status, config('peopleos.performance.development_need_statuses'))) {
            throw new RuntimeException("Unknown status '{$status}'.");
        }
        $need->update(['status' => $status]);

        return $need;
    }

    public function openNeedsFor(int $employeeId): array
    {
        return DevelopmentNeed::query()->with('competency')->where('employee_id', $employeeId)->whereIn('status', ['open', 'in_progress'])->orderBy('id')->get()
            ->map(fn (DevelopmentNeed $n) => ['id' => $n->id, 'title' => $n->title, 'competency_code' => $n->competency?->code, 'skill_id' => $n->skill_id, 'current_level' => $n->current_level === null ? null : (float) $n->current_level, 'target_level' => $n->target_level === null ? null : (float) $n->target_level, 'target_date' => $n->target_date?->toDateString(), 'priority' => $n->priority, 'status' => $n->status, 'source_type' => $n->source_type])
            ->all();
    }
}
