<?php

namespace App\Domain\Skills\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Skills\Events\SkillEvent;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Models\SkillAssessment;
use App\Domain\Skills\Models\SkillScaleVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8 employee skill profile: an append-only history of levels on pinned scale versions, kept
 * per source class — verified (assessment, certification, learning), manager, self, imported,
 * system — plus targets. A new entry supersedes only the current entry of the same skill and
 * class, so a self-declaration never overwrites a verified level. Gaps use the best-evidenced
 * level: verified, then manager, imported, system, and self last.
 */
final class SkillProfiles
{
    public const PRECEDENCE = ['verified', 'manager', 'imported', 'system', 'self'];

    public function __construct(private readonly SkillScales $scales, private readonly PerformanceRelationships $relationships) {}

    /** The employee declares their own level (never verified). */
    public function declare(Employee $employee, int $skillId, float $level, ?string $evidence = null, ?User $actor = null, ?string $confidence = null): EmployeeSkill
    {
        $actor ??= auth()->user();
        if ($actor !== null && ($this->relationships->forUser($actor)?->id !== $employee->id || ! $actor->hasPermission('skills.self'))) {
            throw new RuntimeException('Only the employee declares their own skill level.');
        }

        return $this->record($employee, $skillId, $level, null, 'self', false, ['evidence' => $evidence, 'confidence' => $confidence], $actor);
    }

    /** A target level: by the employee for themself, a manager (skills.assess) or skills.manage. */
    public function setTarget(Employee $employee, int $skillId, float $target, ?User $actor = null): EmployeeSkill
    {
        $actor ??= auth()->user();
        $self = $actor !== null && $this->relationships->forUser($actor)?->id === $employee->id;
        if ($actor !== null && ! $self && ! $this->mayAssess($actor, $employee)) {
            throw new RuntimeException('Targets are set by the employee, their manager or skills administrators.');
        }

        return $this->record($employee, $skillId, null, $target, $self ? 'self' : 'manager', false, [], $actor);
    }

    public function recordFromAssessment(SkillAssessment $assessment, ?User $actor = null): EmployeeSkill
    {
        $source = match ($assessment->assessment_type) {
            'self' => 'self', 'manager' => 'manager', 'certification' => 'certification', default => 'assessment'
        };

        return $this->record($assessment->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), (int) $assessment->skill_id, (float) $assessment->level, $assessment->target_level === null ? null : (float) $assessment->target_level, $source, in_array($source, EmployeeSkill::VERIFIABLE, true), [
            'skill_assessment_id' => $assessment->id, 'evidence' => $assessment->evidence, 'assessed_on' => $assessment->assessed_on, 'valid_to' => $assessment->valid_until,
            'skill_scale_version_id' => $assessment->skill_scale_version_id, 'assessed_by' => $assessment->assessor_user_id,
        ], $actor);
    }

    /** A course version's declared skill outcome, evidenced by a finalized completion. */
    public function recordFromLearning(Employee $employee, int $skillId, float $level, LearningCompletion $completion, ?User $actor = null): EmployeeSkill
    {
        return $this->record($employee, $skillId, $level, null, 'learning', true, ['learning_completion_id' => $completion->id, 'assessed_on' => now()->toDateString()], $actor);
    }

    /** @return list<array{skill_id: int, skill: ?string, scale_version_id: int, level: ?float, label: ?string, basis: ?string, verified: bool, self_declared: ?float, target: ?float, gap: ?float}> */
    public function profile(Employee $employee): array
    {
        $entries = EmployeeSkill::query()->withoutGlobalScope(AccessScope::class)->with(['skill', 'scaleVersion'])->where('employee_id', $employee->id)->where('status', 'current')->orderByDesc('id')->get();

        return $entries->groupBy('skill_id')->map(function ($rows, $skillId) {
            $byClass = $rows->groupBy(fn (EmployeeSkill $s) => self::classOf($s));
            $best = collect(self::PRECEDENCE)->map(fn ($c) => $byClass->get($c)?->first())->filter()->first();
            $target = $byClass->get('target')?->first() ?? $rows->first(fn (EmployeeSkill $s) => $s->target_level !== null);
            $level = $best?->current_level === null ? null : (float) $best->current_level;
            $targetLevel = $target?->target_level === null ? null : (float) $target->target_level;

            return [
                'skill_id' => (int) $skillId, 'skill' => $rows->first()->skill?->name,
                'scale_version_id' => (int) ($best ?? $rows->first())->skill_scale_version_id,
                'level' => $level, 'label' => $best?->scaleVersion?->labelFor($level), 'basis' => $best ? self::classOf($best) : null,
                'verified' => (bool) $best?->is_verified,
                'self_declared' => ($s = $byClass->get('self')?->first()) && $s->current_level !== null ? (float) $s->current_level : null,
                'target' => $targetLevel,
                'gap' => $targetLevel === null ? null : max(0.0, $targetLevel - ($level ?? 0.0)),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> skills with a positive gap */
    public function gaps(Employee $employee): array
    {
        return array_values(array_filter($this->profile($employee), fn ($row) => ($row['gap'] ?? 0) > 0));
    }

    public function mayAssess(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission('skills.manage')
            || ($actor->hasPermission('skills.assess') && $this->relationships->manages($this->relationships->forUser($actor), $employee->id));
    }

    public static function classOf(EmployeeSkill $skill): string
    {
        if ($skill->current_level === null) {
            return 'target';
        }

        return $skill->is_verified ? 'verified' : $skill->source;
    }

    private function record(Employee $employee, int $skillId, ?float $level, ?float $target, string $source, bool $verified, array $extra, ?User $actor): EmployeeSkill
    {
        $version = isset($extra['skill_scale_version_id']) ? SkillScaleVersion::query()->findOrFail($extra['skill_scale_version_id']) : $this->scales->versionForSkill($skillId);
        foreach (array_filter([$level, $target], fn ($v) => $v !== null) as $value) {
            if (! $version->has((float) $value)) {
                throw new RuntimeException('Level '.$value.' is not on the skill scale (allowed: '.implode(', ', $version->values()).').');
            }
        }

        return DB::transaction(function () use ($employee, $skillId, $level, $target, $source, $verified, $extra, $actor, $version) {
            $entry = new EmployeeSkill([
                'employee_id' => $employee->id, 'skill_id' => $skillId, 'skill_scale_version_id' => $version->id,
                'current_level' => $level, 'target_level' => $target, 'source' => $source, 'is_verified' => $verified,
                'valid_from' => now()->toDateString(), 'created_by' => $actor?->id ?? auth()->id(),
                ...array_intersect_key($extra, array_flip(['evidence', 'confidence', 'skill_assessment_id', 'learning_completion_id', 'assessed_on', 'valid_to', 'assessed_by'])),
            ]);
            $class = self::classOf($entry);
            // Supersede the current entry of the same skill and class (row lock against concurrent writes).
            EmployeeSkill::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('skill_id', $skillId)->where('status', 'current')->lockForUpdate()->get()
                ->filter(fn (EmployeeSkill $s) => self::classOf($s) === $class)
                ->each(fn (EmployeeSkill $s) => $s->update(['status' => 'superseded', 'superseded_at' => now(), 'valid_to' => $s->valid_to ?? now()->toDateString()]));
            $entry->save();
            SkillEvent::dispatch('skill.recorded', $employee, $entry, ['source' => $source, 'verified' => $verified]);

            return $entry;
        });
    }
}
