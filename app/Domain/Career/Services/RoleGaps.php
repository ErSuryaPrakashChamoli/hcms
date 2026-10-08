<?php

namespace App\Domain\Career\Services;

use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Contracts\CompetencyEvidenceReader;
use App\Domain\Performance\Models\Competency;
use App\Domain\Skills\Models\SkillScaleVersion;
use App\Domain\Skills\Services\SkillProfiles;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 9 skill-, learning- and experience-to-role gaps. Facts only:
 * - skills: required level, the employee's best-evidenced level and its basis (verified, manager,
 *   imported, system, self), the gap, and whether both sit on the same scale (different scales are
 *   never compared blindly);
 * - competencies: the latest finalized rating, if any (Phase 7 contract);
 * - learning and certifications: completed / in progress / missing / expired;
 * - experience: years in the organisation and in the current role.
 * No score, no ranking, no recommendation, and nothing is enrolled or changed.
 */
final class RoleGaps
{
    public function __construct(
        private readonly CareerArchitecture $architecture,
        private readonly SkillProfiles $skills,
        private readonly CompetencyEvidenceReader $competencies,
    ) {}

    /** @return array<string, mixed> */
    public function for(Employee $employee, int $designationId, ?int $organisationNodeId = null, CarbonInterface|string|null $on = null): array
    {
        $requirements = $this->architecture->requirementsFor($designationId, $organisationNodeId, $on);
        if ($requirements === null) {
            return ['requirements' => null, 'skills' => [], 'competencies' => [], 'learning' => [], 'certifications' => [], 'experience' => null];
        }

        $skills = $this->skillGaps($employee, $requirements);

        $evidence = $this->competencies->latestFinalized($employee->id);
        $competencyNames = Competency::query()->whereIn('id', collect($requirements->competencies)->pluck('competency_id'))->pluck('name', 'id');
        $competencies = collect($requirements->competencies)->map(fn ($req) => [
            'competency_id' => (int) $req['competency_id'], 'competency' => $competencyNames[(int) $req['competency_id']] ?? null, 'required' => (bool) ($req['required'] ?? true),
            'required_level' => isset($req['level']) ? (float) $req['level'] : null, 'latest_finalized' => $evidence[(int) $req['competency_id']] ?? null,
        ])->values()->all();

        return [
            'requirements' => ['id' => $requirements->id, 'version' => $requirements->version, 'designation_id' => $requirements->designation_id, 'organisation_node_id' => $requirements->organisation_node_id, 'effective_from' => $requirements->effective_from->toDateString()],
            'skills' => $skills,
            'competencies' => $competencies,
            'learning' => collect($requirements->learning)->map(fn ($req) => ['type' => $req['type'], 'id' => (int) $req['id'], 'required' => (bool) ($req['required'] ?? true)] + ($req['type'] === 'path' ? $this->pathStatus($employee, (int) $req['id']) : $this->courseStatus($employee, (int) $req['id'])))->values()->all(),
            'certifications' => $this->certificationGaps($employee, $requirements),
            'experience' => $this->experience($employee, $requirements->min_experience_years),
        ];
    }

    /**
     * Required skills of one requirement version against the employee's best-evidenced levels (Phase 8
     * SkillProfiles). Levels on a different scale are flagged, never compared. Facts only.
     *
     * @return list<array<string, mixed>>
     */
    public function skillGaps(Employee $employee, RoleRequirementVersion $requirements): array
    {
        $profile = collect($this->skills->profile($employee))->keyBy('skill_id');
        $scaleVersions = SkillScaleVersion::query()->whereIn('id', collect($requirements->skills)->pluck('skill_scale_version_id')->merge($profile->pluck('scale_version_id'))->unique())->get()->keyBy('id');
        $skillNames = Skill::query()->whereIn('id', collect($requirements->skills)->pluck('skill_id'))->pluck('name', 'id');

        return collect($requirements->skills)->map(function ($req) use ($profile, $scaleVersions, $skillNames) {
            $have = $profile->get($req['skill_id']);
            $required = $scaleVersions->get($req['skill_scale_version_id']);
            $held = $have ? $scaleVersions->get($have['scale_version_id']) : null;
            $sameScale = $held === null || ($required && (int) $held->skill_scale_id === (int) $required->skill_scale_id);
            $current = $have['level'] ?? null;

            return [
                'skill_id' => $req['skill_id'], 'skill' => $skillNames[$req['skill_id']] ?? null, 'required' => (bool) $req['required'],
                'required_level' => (float) $req['level'], 'required_label' => $required?->labelFor((float) $req['level']), 'scale_version_id' => $req['skill_scale_version_id'],
                'current_level' => $current, 'basis' => $have['basis'] ?? null, 'verified' => (bool) ($have['verified'] ?? false), 'self_declared' => $have['self_declared'] ?? null,
                'comparable' => $sameScale, 'scale_version_differs' => $held !== null && $required !== null && $held->id !== $required->id,
                'gap' => $sameScale ? max(0.0, (float) $req['level'] - (float) ($current ?? 0)) : null,
            ];
        })->values()->all();
    }

    /**
     * Required certifications of one requirement version: held / expired / in progress / missing,
     * with whether a held certificate is verified. Facts only; nothing is enrolled.
     *
     * @return list<array{course_id: int, title: ?string, status: string, verified: ?bool}>
     */
    public function certificationGaps(Employee $employee, RoleRequirementVersion $requirements): array
    {
        return collect($requirements->certifications)->map(fn ($req) => ['course_id' => (int) $req['course_id']] + $this->certificateStatus($employee, (int) $req['course_id']))->values()->all();
    }

    /** @return array{title: ?string, status: string} */
    public function courseStatus(Employee $employee, int $courseId): array
    {
        $title = Course::query()->whereKey($courseId)->value('title');
        $completion = LearningCompletion::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('course_id', $courseId)->where('status', 'final')->latest('completed_at')->first();
        if ($completion) {
            $expired = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->whereKey($completion->learning_enrolment_id)->value('status') === 'expired';

            return ['title' => $title, 'status' => $expired ? 'expired' : 'completed'];
        }
        $open = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('course_id', $courseId)
            ->whereIn('status', [...LearningEnrolment::OPEN, ...LearningEnrolment::PENDING])->exists();

        return ['title' => $title, 'status' => $open ? 'in_progress' : 'missing'];
    }

    /** @return array{title: ?string, status: string} */
    private function pathStatus(Employee $employee, int $pathId): array
    {
        $path = LearningPath::query()->with('items')->find($pathId);
        $statuses = collect($path?->items ?? [])->where('is_required', true)->map(fn ($item) => $this->courseStatus($employee, (int) $item->course_id)['status']);
        $status = match (true) {
            $statuses->isEmpty() => 'missing',
            $statuses->every(fn ($s) => $s === 'completed') => 'completed',
            $statuses->contains('expired') => 'expired',
            $statuses->contains(fn ($s) => in_array($s, ['completed', 'in_progress'], true)) => 'in_progress',
            default => 'missing',
        };

        return ['title' => $path?->name, 'status' => $status];
    }

    /** @return array{title: ?string, status: string, verified: ?bool} */
    private function certificateStatus(Employee $employee, int $courseId): array
    {
        $certificate = LearningCertificate::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('course_id', $courseId)
            ->where('status', '!=', 'revoked')->orderByDesc('issued_on')->first();
        $title = Course::query()->whereKey($courseId)->value('title');
        if ($certificate === null) {
            return ['title' => $title, 'status' => $this->courseStatus($employee, $courseId)['status'] === 'in_progress' ? 'in_progress' : 'missing', 'verified' => null];
        }

        return ['title' => $title, 'status' => $certificate->status === 'expired' ? 'expired' : 'held', 'verified' => $certificate->verification_status === 'verified'];
    }

    /** @return array{required_years: ?float, years_in_organisation: ?float, years_in_current_role: ?float} */
    private function experience(Employee $employee, $required): array
    {
        $current = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->effectiveOn()->latest('effective_from')->first();
        $since = $current ? EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('designation_id', $current->designation_id)->min('effective_from') : null;

        return [
            'required_years' => $required === null ? null : (float) $required,
            'years_in_organisation' => $employee->joining_date ? round(Carbon::parse($employee->joining_date)->diffInDays(now()) / 365.25, 1) : null,
            'years_in_current_role' => $since ? round(Carbon::parse($since)->diffInDays(now()) / 365.25, 1) : null,
        ];
    }
}
