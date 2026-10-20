<?php

namespace App\Domain\Skills\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Services\DevelopmentNeeds;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Skills\Events\SkillEvent;
use App\Domain\Skills\Models\SkillAssessment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8 skill assessments. Who may assess: the employee (self, skills.self), a manager through a
 * configured relationship (skills.assess), skills administrators (formal / certification,
 * skills.manage). Draft → finalized under a row lock (immutable), which records the level in the
 * skill profile on the pinned scale version. A correction is a new assessment that supersedes the
 * original when it is finalized. Private notes: the assessor, or skills.private_notes (audited);
 * never the assessed employee.
 */
final class SkillAssessments
{
    public function __construct(
        private readonly SkillScales $scales,
        private readonly SkillProfiles $profiles,
        private readonly PerformanceRelationships $relationships,
        private readonly DevelopmentNeeds $needs,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{level: float, target_level?: ?float, evidence?: ?string, comments?: ?string, private_notes?: ?string, valid_until?: ?string, assessed_on?: ?string}  $data
     */
    public function draft(Employee $employee, int $skillId, string $type, array $data, User $actor, ?SkillAssessment $corrects = null, ?string $correctionReason = null): SkillAssessment
    {
        if (! array_key_exists($type, SkillAssessment::TYPES)) {
            throw new RuntimeException("Unknown assessment type '{$type}'.");
        }
        $this->assertMayAssess($actor, $employee, $type);
        $version = $corrects ? $corrects->scaleVersion()->firstOrFail() : $this->scales->versionForSkill($skillId);
        foreach (['level', 'target_level'] as $field) {
            if (isset($data[$field]) && $data[$field] !== null && ! $version->has((float) $data[$field])) {
                throw new RuntimeException("The {$field} is not on the skill scale (allowed: ".implode(', ', $version->values()).').');
            }
        }
        if (! isset($data['level'])) {
            throw new RuntimeException('An assessment needs a level.');
        }

        $assessment = SkillAssessment::query()->create([
            'employee_id' => $employee->id, 'skill_id' => $skillId, 'skill_scale_version_id' => $version->id, 'assessment_type' => $type,
            'assessor_user_id' => $actor->id, 'assessor_employee_id' => $this->relationships->forUser($actor)?->id,
            'assessed_on' => $data['assessed_on'] ?? now()->toDateString(), 'level' => $data['level'], 'target_level' => $data['target_level'] ?? null,
            'evidence' => $data['evidence'] ?? null, 'comments' => $data['comments'] ?? null, 'private_notes' => $data['private_notes'] ?? null,
            'valid_until' => $data['valid_until'] ?? null, 'status' => 'draft',
            'corrects_assessment_id' => $corrects?->id, 'correction_reason' => $correctionReason,
        ]);

        return $assessment;
    }

    /** Finalize (immutable from then on). Optionally records a development need for a positive gap. */
    public function finalize(SkillAssessment $assessment, User $actor, bool $recordNeed = false): SkillAssessment
    {
        if ((int) $assessment->assessor_user_id !== (int) $actor->id && ! $actor->hasPermission('skills.manage')) {
            throw new RuntimeException('Only the assessor or a skills administrator finalizes this assessment.');
        }

        $assessment = DB::transaction(function () use ($assessment, $actor) {
            $current = SkillAssessment::query()->withoutGlobalScope(AccessScope::class)->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw new RuntimeException('This assessment is already finalized.');
            }
            if ($current->corrects_assessment_id) {
                $original = SkillAssessment::query()->withoutGlobalScope(AccessScope::class)->whereKey($current->corrects_assessment_id)->lockForUpdate()->firstOrFail();
                if ($original->status !== 'finalized') {
                    throw new RuntimeException('The assessment being corrected is no longer current.');
                }
                $original->update(['status' => 'superseded']);
            }
            $assessment->setRawAttributes($current->getAttributes(), true);
            $assessment->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $actor->id]);
            $this->profiles->recordFromAssessment($assessment, $actor);
            $this->audit->record(AuditAction::Approved, 'skills', $assessment, [['field' => 'status', 'before' => 'draft', 'after' => 'finalized']], $assessment->correction_reason, actor: $actor, metadata: ['event' => $assessment->corrects_assessment_id ? 'skill_assessment_corrected' : 'skill_assessment_finalized']);
            SkillEvent::dispatch('skill.assessed', $assessment->employee()->withoutGlobalScope(AccessScope::class)->first(), $assessment, ['type' => $assessment->assessment_type], [$assessment->employee_id]);

            return $assessment;
        });

        if ($recordNeed && $assessment->target_level !== null && (float) $assessment->target_level > (float) $assessment->level) {
            $skill = $assessment->skill()->first();
            $this->needs->record($assessment->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), 'Develop '.$skill?->name, 'skill_assessment', $assessment->id, null, 'medium', null, $actor, [
                'skill_id' => $assessment->skill_id, 'skill_scale_version_id' => $assessment->skill_scale_version_id,
                'current_level' => (float) $assessment->level, 'target_level' => (float) $assessment->target_level, 'reason' => 'Gap found in a skill assessment',
            ]);
        }

        return $assessment;
    }

    /** Start a correction of a finalized assessment: a new draft that supersedes it once finalized. */
    public function correct(SkillAssessment $original, float $level, string $reason, User $actor, array $data = []): SkillAssessment
    {
        if ($original->status !== 'finalized') {
            throw new RuntimeException('Only a finalized assessment is corrected.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A correction needs a reason.');
        }

        return $this->draft($original->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), (int) $original->skill_id, $original->assessment_type, ['level' => $level, 'target_level' => $original->target_level, ...$data], $actor, $original, $reason);
    }

    public function privateNotesFor(SkillAssessment $assessment, User $viewer): ?string
    {
        if ($this->relationships->forUser($viewer)?->id === $assessment->employee_id) {
            return null; // never the assessed employee
        }
        if ((int) $assessment->assessor_user_id === (int) $viewer->id) {
            return $assessment->private_notes;
        }
        if (! $viewer->hasPermission('skills.private_notes')) {
            return null;
        }
        if (filled($assessment->private_notes)) {
            $this->audit->record(AuditAction::View, 'skills', $assessment, [], null, actor: $viewer, metadata: ['field' => 'private_notes']);
        }

        return $assessment->private_notes;
    }

    private function assertMayAssess(User $actor, Employee $employee, string $type): void
    {
        $self = $this->relationships->forUser($actor)?->id === $employee->id;
        $allowed = match ($type) {
            'self' => $self && $actor->hasPermission('skills.self'),
            'manager' => ! $self && $this->profiles->mayAssess($actor, $employee),
            default => ! $self && ($actor->hasPermission('skills.manage') || ($type === 'certification' && $actor->hasPermission('learning.certificates'))),
        };
        if (! $allowed) {
            throw new RuntimeException('You are not allowed to make this kind of assessment for this employee.');
        }
    }
}
