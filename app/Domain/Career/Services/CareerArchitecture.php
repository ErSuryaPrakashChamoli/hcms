<?php

namespace App\Domain\Career\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Career\Events\CareerEvent;
use App\Domain\Career\Models\CareerPathVersion;
use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Models\Competency;
use App\Domain\Skills\Services\SkillScales;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 career architecture: effective-dated, immutable career path versions and role requirement
 * versions. A role is a Designation, optionally narrowed to an organisation unit; skill requirements
 * pin the Phase 8 scale version they are expressed on. Requires career.manage.
 */
final class CareerArchitecture
{
    public function __construct(private readonly SkillScales $scales, private readonly AuditRecorder $audit) {}

    public function publishPath(CareerPath $path, CarbonInterface|string|null $effectiveFrom = null, ?User $actor = null): CareerPathVersion
    {
        $this->authorise($actor);
        $steps = $path->steps()->with('designation')->get();
        if ($steps->isEmpty()) {
            throw new RuntimeException('A career path needs at least one step.');
        }
        $from = Carbon::parse($effectiveFrom ?? now())->startOfDay();

        return DB::transaction(function () use ($path, $steps, $from, $actor) {
            CareerPath::query()->whereKey($path->id)->lockForUpdate()->first();
            $version = CareerPathVersion::query()->create([
                'career_path_id' => $path->id,
                'version' => (int) CareerPathVersion::query()->where('career_path_id', $path->id)->lockForUpdate()->max('version') + 1,
                'name' => $path->name,
                'scope' => ['career_track_id' => $path->career_track_id, 'job_family_id' => $path->job_family_id, 'business_unit_id' => $path->business_unit_id, 'organisation_node_id' => $path->organisation_node_id],
                'steps' => $steps->map(fn ($s) => ['designation_id' => $s->designation_id, 'designation' => $s->designation?->name, 'sort_order' => $s->sort_order, 'typical_years' => $s->typical_years, 'required_skills' => $s->required_skills, 'required_competencies' => $s->required_competencies])->values()->all(),
                'effective_from' => $from->toDateString(),
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);
            $path->forceFill(['current_version_id' => $version->id, 'effective_from' => $path->effective_from ?? $from])->save();
            $this->audit->record(AuditAction::Create, 'career', $version, [], null, actor: $actor, metadata: ['event' => 'career_path_published', 'path' => $path->code]);
            CareerEvent::dispatch('career.path.published', null, $version, ['path' => $path->name, 'version' => $version->version]);

            return $version;
        });
    }

    /**
     * Publish the requirements of a role from an effective date. The previous open version of the
     * same role and scope is closed the day before; history stays reproducible.
     *
     * @param  array{skills?: list<array{skill_id: int, level: float, required?: bool}>, competencies?: list<array{competency_id: int, level?: ?float, required?: bool}>, min_experience_years?: ?float, certifications?: list<array{course_id: int}>, learning?: list<array{type: string, id: int, required?: bool}>, notes?: ?string}  $data
     */
    public function publishRequirements(Designation $designation, ?int $organisationNodeId, array $data, CarbonInterface|string|null $effectiveFrom = null, ?User $actor = null): RoleRequirementVersion
    {
        $this->authorise($actor);
        $from = Carbon::parse($effectiveFrom ?? now())->startOfDay();
        $skills = [];
        foreach ($data['skills'] ?? [] as $row) {
            $skill = Skill::query()->find($row['skill_id'] ?? null) ?? throw new RuntimeException('Unknown skill in the requirements.');
            $version = $this->scales->versionForSkill($skill->id);
            if (! $version->has((float) $row['level'])) {
                throw new RuntimeException("Level {$row['level']} is not on the scale of {$skill->name} (allowed: ".implode(', ', $version->values()).').');
            }
            $skills[] = ['skill_id' => $skill->id, 'level' => (float) $row['level'], 'skill_scale_version_id' => $version->id, 'required' => (bool) ($row['required'] ?? true)];
        }
        if (count(array_unique(array_column($skills, 'skill_id'))) !== count($skills)) {
            throw new RuntimeException('Each skill appears once in the requirements.');
        }
        foreach ($data['competencies'] ?? [] as $row) {
            if (! Competency::query()->whereKey($row['competency_id'] ?? null)->exists()) {
                throw new RuntimeException('Unknown competency in the requirements.');
            }
        }
        foreach ($data['certifications'] ?? [] as $row) {
            if (! Course::query()->whereKey($row['course_id'] ?? null)->exists()) {
                throw new RuntimeException('Unknown certification course in the requirements.');
            }
        }
        foreach ($data['learning'] ?? [] as $row) {
            $exists = ($row['type'] ?? null) === 'path' ? LearningPath::query()->whereKey($row['id'] ?? null)->exists() : (($row['type'] ?? null) === 'course' && Course::query()->whereKey($row['id'] ?? null)->exists());
            if (! $exists) {
                throw new RuntimeException('Learning requirements are existing courses or learning paths.');
            }
        }
        if (isset($data['min_experience_years']) && $data['min_experience_years'] !== null && (float) $data['min_experience_years'] < 0) {
            throw new RuntimeException('Experience cannot be negative.');
        }

        return DB::transaction(function () use ($designation, $organisationNodeId, $data, $skills, $from, $actor) {
            Designation::query()->whereKey($designation->id)->lockForUpdate()->first();
            $scopeKey = (string) ($organisationNodeId ?? '');
            $previous = RoleRequirementVersion::query()->where('designation_id', $designation->id)->where('scope_key', $scopeKey)->lockForUpdate()->orderByDesc('version')->first();
            if ($previous && $previous->effective_from->gte($from)) {
                throw new RuntimeException('A newer version must start after '.$previous->effective_from->toDateString().'.');
            }
            if ($previous && $previous->effective_to === null) {
                $previous->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            }
            $version = RoleRequirementVersion::query()->create([
                'designation_id' => $designation->id, 'organisation_node_id' => $organisationNodeId,
                'version' => (int) ($previous?->version ?? 0) + 1,
                'skills' => $skills, 'competencies' => array_values($data['competencies'] ?? []), 'min_experience_years' => $data['min_experience_years'] ?? null,
                'certifications' => array_values($data['certifications'] ?? []), 'learning' => array_values($data['learning'] ?? []), 'notes' => $data['notes'] ?? null,
                'effective_from' => $from->toDateString(), 'published_by' => $actor?->id, 'published_at' => now(),
            ]);
            $this->audit->record(AuditAction::Create, 'career', $version, [], null, actor: $actor, metadata: ['event' => 'role_requirements_published', 'designation' => $designation->code]);

            return $version;
        });
    }

    /** The requirements in force for a role on a date: the unit-specific version first, else the role-wide one. */
    public function requirementsFor(int $designationId, ?int $organisationNodeId = null, CarbonInterface|string|null $on = null): ?RoleRequirementVersion
    {
        $on = Carbon::parse($on ?? now())->toDateString();
        foreach (array_unique([(string) ($organisationNodeId ?? ''), '']) as $scopeKey) {
            $version = RoleRequirementVersion::query()->where('designation_id', $designationId)->where('scope_key', $scopeKey)
                ->whereDate('effective_from', '<=', $on)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
                ->orderByDesc('version')->first();
            if ($version) {
                return $version;
            }
        }

        return null;
    }

    private function authorise(?User $actor): void
    {
        if ($actor !== null && ! $actor->hasPermission('career.manage')) {
            throw new RuntimeException('Career architecture is maintained with career.manage.');
        }
    }
}
