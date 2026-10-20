<?php

namespace App\Domain\Talent\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Talent\Events\TalentEvent;
use App\Domain\Talent\Models\TalentAssessment;
use App\Domain\Talent\Models\TalentAssessmentModel;
use App\Domain\Talent\Models\TalentAssessmentModelVersion;
use App\Domain\Talent\Models\TalentProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 talent assessments on configurable, versioned models (a 9-box is one possible model).
 * Ratings are a person's judgement per dimension; they are never combined into a score. Final
 * assessments are immutable; corrections supersede. Requires talent.assess within scope.
 */
final class TalentAssessments
{
    public function __construct(private readonly TalentAccess $access, private readonly AuditRecorder $audit) {}

    /** @param  list<array{key: string, label: string, levels: list<array{value: int|float, label: string}>}>  $dimensions */
    public function publishModel(TalentAssessmentModel $model, array $dimensions, ?User $actor = null): TalentAssessmentModelVersion
    {
        if ($actor !== null && ! $actor->hasPermission('talent.manage')) {
            throw new RuntimeException('Assessment models are configured with talent.manage.');
        }
        if ($dimensions === []) {
            throw new RuntimeException('A model needs at least one dimension.');
        }
        $keys = [];
        foreach ($dimensions as $d) {
            if (blank($d['key'] ?? null) || blank($d['label'] ?? null) || count($d['levels'] ?? []) < 2) {
                throw new RuntimeException('Every dimension needs a key, a label and at least two levels.');
            }
            $keys[] = $d['key'];
        }
        if (count(array_unique($keys)) !== count($keys)) {
            throw new RuntimeException('Dimension keys must be unique.');
        }

        return DB::transaction(function () use ($model, $dimensions, $actor) {
            TalentAssessmentModel::query()->whereKey($model->id)->lockForUpdate()->first();
            $version = TalentAssessmentModelVersion::query()->create([
                'talent_assessment_model_id' => $model->id,
                'version' => (int) TalentAssessmentModelVersion::query()->where('talent_assessment_model_id', $model->id)->lockForUpdate()->max('version') + 1,
                'dimensions' => array_values($dimensions), 'published_by' => $actor?->id, 'published_at' => now(),
            ]);
            $model->update(['current_version_id' => $version->id]);

            return $version;
        });
    }

    public function defaultModelVersion(): TalentAssessmentModelVersion
    {
        $config = config('peopleos.talent.default_model');
        $model = TalentAssessmentModel::query()->firstOrCreate(['code' => $config['code']], ['name' => $config['name']]);

        return $model->current_version_id ? TalentAssessmentModelVersion::query()->findOrFail($model->current_version_id) : $this->publishModel($model, $config['dimensions']);
    }

    /** @param  array<string, float|int>  $ratings */
    public function draft(Employee $employee, TalentAssessmentModelVersion $version, array $ratings, ?string $rationale, ?string $confidentialNotes, User $actor, ?int $reviewItemId = null, ?TalentAssessment $corrects = null, ?string $correctionReason = null): TalentAssessment
    {
        if (! $actor->hasPermission('talent.assess') || ! $this->access->inScope($actor, $employee->id) || $this->access->self($actor, $employee->id)) {
            throw new RuntimeException('Talent assessments are recorded with talent.assess, within scope, never about yourself.');
        }
        $allowed = $version->allowed();
        if (array_diff(array_keys($ratings), array_keys($allowed)) !== [] || array_diff(array_keys($allowed), array_keys($ratings)) !== []) {
            throw new RuntimeException('Rate every dimension of the model: '.implode(', ', array_keys($allowed)).'.');
        }
        foreach ($ratings as $key => $value) {
            if (! in_array((float) $value, $allowed[$key], true)) {
                throw new RuntimeException("{$key} must be one of ".implode(', ', $allowed[$key]).'.');
            }
        }

        return TalentAssessment::query()->create([
            'employee_id' => $employee->id, 'talent_assessment_model_version_id' => $version->id, 'talent_review_item_id' => $reviewItemId,
            'ratings' => array_map('floatval', $ratings), 'rationale' => $rationale, 'confidential_notes' => $confidentialNotes,
            'assessed_by' => $actor->id, 'status' => 'draft', 'corrects_assessment_id' => $corrects?->id, 'correction_reason' => $correctionReason,
        ]);
    }

    public function finalize(TalentAssessment $assessment, User $actor): TalentAssessment
    {
        if ((int) $assessment->assessed_by !== (int) $actor->id && ! $actor->hasPermission('talent.manage')) {
            throw new RuntimeException('Only the assessor or a talent administrator finalizes this assessment.');
        }

        return DB::transaction(function () use ($assessment, $actor) {
            $current = TalentAssessment::query()->withoutGlobalScope(AccessScope::class)->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw new RuntimeException('This talent assessment is already final.');
            }
            if ($current->corrects_assessment_id) {
                $original = TalentAssessment::query()->withoutGlobalScope(AccessScope::class)->whereKey($current->corrects_assessment_id)->lockForUpdate()->firstOrFail();
                if ($original->status !== 'final') {
                    throw new RuntimeException('The assessment being corrected is no longer current.');
                }
                $original->update(['status' => 'superseded']);
            }
            $assessment->setRawAttributes($current->getAttributes(), true);
            $assessment->update(['status' => 'final', 'assessed_at' => now()]);
            $this->audit->record(AuditAction::Approved, 'talent', $assessment, [], $assessment->correction_reason, actor: $actor, metadata: ['event' => 'talent_assessment_completed']);
            TalentEvent::dispatch('talent.assessment.completed', $assessment->employee()->withoutGlobalScope(AccessScope::class)->first(), $assessment, []);

            return $assessment;
        });
    }

    public function correct(TalentAssessment $original, array $ratings, string $reason, User $actor): TalentAssessment
    {
        if ($original->status !== 'final' || trim($reason) === '') {
            throw new RuntimeException('Only a final assessment is corrected, with a reason.');
        }

        return $this->draft($original->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), $original->modelVersion()->firstOrFail(), $ratings, $original->rationale, null, $actor, $original->talent_review_item_id, $original, $reason);
    }

    /** @param  array<string, mixed>  $data */
    public function updateProfile(Employee $employee, array $data, ?int $expectedVersion, User $actor): TalentProfile
    {
        if (! $this->access->mayManageTalent($actor, $employee->id)) {
            throw new RuntimeException('Talent profiles are maintained with talent.manage within scope.');
        }

        return DB::transaction(function () use ($employee, $data, $expectedVersion, $actor) {
            TalentProfile::query()->withoutGlobalScope(AccessScope::class)->firstOrCreate(['employee_id' => $employee->id]);
            $profile = TalentProfile::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && (int) $profile->lock_version !== $expectedVersion) {
                throw new RuntimeException('The talent profile was changed meanwhile. Reload and try again.');
            }
            $profile->update([...array_intersect_key($data, array_flip(['career_track_id', 'mobility', 'critical_role_interest', 'development_priorities', 'confidential_notes'])), 'updated_by' => $actor->id]);

            return $profile;
        });
    }
}
