<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\CareerAspiration;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Models\CareerPathStep;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\Goal;

/** The Career Passport (§36): one view of skills, credentials, performance, aspirations and the gap to the next step. */
final class CareerPassport
{
    public const PROFICIENCY_RANK = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3, 'expert' => 4];

    /** @return array<string, mixed> */
    public function build(Employee $employee): array
    {
        $employee->loadMissing(['person.personSkills.skill', 'person.certifications', 'person.qualifications', 'person.experiences', 'currentPosition.designation']);
        $person = $employee->person;
        $aspiration = CareerAspiration::query()->with(['careerPath.steps.designation', 'targetDesignation'])->where('employee_id', $employee->id)->first();
        $currentDesignation = $employee->currentPosition?->designation;

        $path = $aspiration?->careerPath ?? $this->pathContaining($currentDesignation?->id);
        $path?->loadMissing('steps.designation');
        $nextStep = $this->nextStep($path, $currentDesignation?->id, $aspiration?->target_designation_id);

        $skills = $person->personSkills->map(fn ($ps) => ['skill_id' => $ps->skill_id, 'name' => $ps->skill?->name, 'proficiency' => $ps->proficiency, 'years' => $ps->years_of_experience])->values();

        return [
            'employee' => ['id' => $employee->id, 'code' => $employee->employee_code, 'name' => $person->full_name, 'designation' => $currentDesignation?->name, 'joined' => $employee->joining_date?->toDateString()],
            'skills' => $skills->all(),
            'certifications' => $person->certifications->map(fn ($c) => ['name' => $c->name, 'issuer' => $c->issuing_body, 'expires_on' => $c->expires_on?->toDateString()])->all(),
            'qualifications' => $person->qualifications->map(fn ($q) => ['degree' => $q->qualification, 'institution' => $q->institution, 'year' => $q->year_of_completion])->all(),
            'experience' => $person->experiences->map(fn ($e) => ['employer' => $e->employer, 'title' => $e->designation, 'from' => $e->from_date?->toDateString(), 'to' => $e->to_date?->toDateString()])->all(),
            'performance' => Appraisal::query()->with('cycle')->where('employee_id', $employee->id)->whereIn('status', ['finalized', 'acknowledged'])->get()
                ->sortByDesc(fn ($a) => $a->cycle->period_end)->values()
                ->map(fn ($a) => ['cycle' => $a->cycle->name, 'period_end' => $a->cycle->period_end->toDateString(), 'rating' => (float) $a->final_rating, 'label' => $a->final_label, 'promotion_recommended' => $a->promotion_recommended])->all(),
            'goals' => ['active' => Goal::query()->where('employee_id', $employee->id)->where('status', 'active')->count(), 'completed' => Goal::query()->where('employee_id', $employee->id)->where('status', 'completed')->count()],
            'feedback' => ['praise' => FeedbackEntry::query()->where('employee_id', $employee->id)->where('type', 'praise')->count(), 'constructive' => FeedbackEntry::query()->where('employee_id', $employee->id)->where('type', 'constructive')->count()],
            'aspiration' => $aspiration ? ['target' => $aspiration->targetDesignation?->name, 'path' => $aspiration->careerPath?->name, 'notes' => $aspiration->aspirations, 'interests' => $aspiration->interests, 'open_to_relocation' => $aspiration->open_to_relocation] : null,
            'career_path' => $path ? ['name' => $path->name, 'steps' => $path->steps->map(fn ($s) => ['designation' => $s->designation?->name, 'current' => $s->designation_id === $currentDesignation?->id, 'typical_years' => $s->typical_years])->all()] : null,
            'next_step' => $nextStep ? ['designation' => $nextStep->designation?->name, 'gaps' => $this->gaps($nextStep, $skills)] : null,
        ];
    }

    private function pathContaining(?int $designationId): ?CareerPath
    {
        if ($designationId === null) {
            return null;
        }

        return CareerPath::query()->where('status', 'active')->whereHas('steps', fn ($q) => $q->where('designation_id', $designationId))->first();
    }

    private function nextStep(?CareerPath $path, ?int $currentDesignationId, ?int $targetDesignationId): ?CareerPathStep
    {
        if ($path === null) {
            return null;
        }
        $steps = $path->steps->values();

        if ($targetDesignationId && ($target = $steps->firstWhere('designation_id', $targetDesignationId))) {
            return $target;
        }

        $index = $steps->search(fn ($s) => $s->designation_id === $currentDesignationId);

        return $index === false ? $steps->first() : $steps->get($index + 1);
    }

    /** @return array<int, array{skill: string, required: string, current: ?string, met: bool}> */
    public function gaps(CareerPathStep $step, $skills): array
    {
        $have = collect($skills)->keyBy('skill_id');
        $names = Skill::query()->whereIn('id', collect($step->required_skills ?? [])->pluck('skill_id'))->pluck('name', 'id');

        return collect($step->required_skills ?? [])->map(function ($req) use ($have, $names) {
            $current = $have->get((int) $req['skill_id'])['proficiency'] ?? null;
            $met = $current !== null && (self::PROFICIENCY_RANK[$current] ?? 0) >= (self::PROFICIENCY_RANK[$req['proficiency']] ?? 0);

            return ['skill' => $names[(int) $req['skill_id']] ?? ('#'.$req['skill_id']), 'required' => $req['proficiency'], 'current' => $current, 'met' => $met];
        })->values()->all();
    }
}
