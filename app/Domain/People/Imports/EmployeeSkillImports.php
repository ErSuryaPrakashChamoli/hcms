<?php

namespace App\Domain\People\Imports;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Actions\ChangeProfileSkillAction;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Models\SkillScaleVersion;
use App\Domain\Skills\Services\SkillProfiles;
use App\Domain\Skills\Support\SkillProficiencies;
use Illuminate\Support\Facades\Validator;

/**
 * Data import DI-2 (Workforce → Employee skills): one row per skill of an employee.
 *
 * - The profile skill (My profile → Skills, the career passport, skill analytics): skill, level on the organisation's
 *   profile scale, years and last use, written through ChangeProfileSkillAction (organisation scope, audit, timeline). A
 *   skill is on a profile once: a row for a skill on file updates it, any other row adds it.
 * - Optionally an assessed level and / or a target on the skill's own scale (the Employee 360 Skills tab and its gaps),
 *   recorded through SkillProfiles::import as an "Imported" entry of the skill history (never verified; needs
 *   skills.manage). History is never rewritten: a different level adds a new entry.
 *
 * Blank cells keep what is on file; nothing is removed.
 */
final class EmployeeSkillImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'employee_skills';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'skill' => ['Skill', true], 'proficiency' => ['Level', false], 'years_of_experience' => ['Years of experience', false],
            'last_used_on' => ['Last used on', false], 'assessed_level' => ['Assessed level', false], 'target_level' => ['Target level', false],
        ];
    }

    public function label(): string
    {
        return 'Employee skills upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonSkill::class;
    }

    protected function aliases(): array
    {
        return ['skill_name' => 'skill', 'skill_code' => 'skill', 'proficiency' => 'proficiency', 'skill_level' => 'proficiency', 'rating' => 'proficiency',
            'experience' => 'years_of_experience', 'years' => 'years_of_experience', 'experience_years' => 'years_of_experience', 'last_used' => 'last_used_on',
            'assessed' => 'assessed_level', 'current_level' => 'assessed_level', 'target' => 'target_level', 'expected_level' => 'target_level'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['skills' => Skill::query()->where('status', 'active')->get()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        [$skill, $profile, $history, $problems] = $this->parse($r, $context['skills']);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null || $skill === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.$skill->id;
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same skill is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $onFile = $this->onFile($employee, $skill);
        $profileChanges = $onFile === null ? array_keys($profile) : array_keys(array_filter($profile, fn ($value, $field) => ! self::sameValue($onFile->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH));
        $historyChanges = $history !== [] && ! $this->sameHistory($employee, $skill, $history);
        $refusals = array_filter([
            $onFile === null || $profileChanges !== [] ? $this->refusal($import, $employee, $onFile === null ? 'employee.create' : 'employee.update') : null,
            $historyChanges && ! ($this->uploader($import)?->hasPermission('skills.manage') ?? false) ? 'An assessed or target level needs skills.manage.' : null,
        ]);
        if ($refusals !== []) {
            return ['action' => 'error', 'errors' => array_values($refusals), 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => $onFile === null ? ['employee code'] : ['employee code', 'skill']];
        if ($onFile === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }

        return $profileChanges === [] && ! $historyChanges
            ? ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => [...$profileChanges, ...($historyChanges ? array_keys($history) : [])]], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        [$skill, $profile, $history, $problems] = $this->parse($r, Skill::query()->where('status', 'active')->get());
        if ($problems !== [] || $skill === null) {
            throw new ProfileChangeRefused(implode(' ', $problems) ?: 'The row could not be read.');
        }
        $onFile = $this->onFile($employee, $skill);
        $action = app(ChangeProfileSkillAction::class);
        $done = [];
        if ($onFile === null) {
            $action->add($employee, ['skill_id' => $skill->id] + $profile, $user, $this->why($import), $this->origin($import));
            $done[] = 'added to the profile';
        } elseif (array_filter($profile, fn ($value, $field) => ! self::sameValue($onFile->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH) !== []) {
            $action->update($employee, $onFile, ['skill_id' => $skill->id] + $profile, $user, $this->why($import), $this->origin($import));
            $done[] = 'profile updated';
        }
        if ($history !== [] && ! $this->sameHistory($employee, $skill, $history)) {
            app(SkillProfiles::class)->import($employee, (int) $skill->id, $history['assessed_level'] ?? null, $history['target_level'] ?? null, $user, $this->why($import));
            $done[] = 'level recorded';
        }

        return "{$skill->name} for {$employee->employee_code}: ".(implode(', ', $done) ?: 'already on file');
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Skill', 'Yes', 'An active skill of the skill library (Core → Skill master), by code or name.', 'PHP'],
                ['Level', 'No', 'The profile level: one of '.implode(', ', array_map(fn ($code, $label) => "{$code} ({$label})", array_keys(SkillProficiencies::options()), SkillProficiencies::options())).'.', SkillProficiencies::default() ?? 'intermediate'],
                ['Years of experience', 'No', 'Whole years, 0 to 60.', '4'],
                ['Last used on', 'No', 'YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY; not in the future.', ''],
                ['Assessed level', 'No', 'A level of the skill\'s own scale (its value or label), shown on the Employee 360 Skills tab as "Imported". Needs skills.manage.', '3'],
                ['Target level', 'No', 'The level the employee should reach, on the same scale; gaps are worked out from it. Needs skills.manage.', '4'],
            ],
            'notes' => [
                'A skill is on a profile once: a row for a skill already on the employee\'s profile updates it; any other row adds it.',
                'Blank cells keep what is on file. Nothing is removed by an upload.',
                'An assessed or target level adds an "Imported" entry to the skill history (never verified; a manager\'s or a verified level still counts first). The same level again changes nothing.',
                'Profile changes need employee.create (adding) or employee.update (changing) within your organisation scope; they are audited and shown on the employee\'s timeline.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $skills = Skill::query()->where('status', 'active')->orderBy('id')->limit(2)->pluck('code')->all();
        $levels = array_keys(SkillProficiencies::options());

        return [
            [$code, $skills[0] ?? 'PHP', $levels[1] ?? ($levels[0] ?? 'intermediate'), '4', '', '', ''],
            [$code, $skills[1] ?? 'SQL', $levels[2] ?? ($levels[0] ?? 'advanced'), '6', now()->subMonth()->startOfMonth()->toDateString(), '', ''],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The skill, the profile fields (only those given) and the history levels (only those given), with what is wrong.
     *
     * @param  iterable<Skill>  $skills
     * @return array{0: ?Skill, 1: array<string, mixed>, 2: array<string, float>, 3: list<string>}
     */
    private function parse(array $r, iterable $skills): array
    {
        $errors = [];
        $value = mb_strtolower(trim((string) ($r['skill'] ?? '')));
        $skills = collect($skills);
        $skill = $value === '' ? null : ($skills->first(fn (Skill $s) => mb_strtolower((string) $s->code) === $value) ?? $skills->first(fn (Skill $s) => mb_strtolower(trim((string) $s->name)) === $value));
        if ($skill === null) {
            $errors[] = $value === '' ? 'Skill is required.' : "Skill '{$r['skill']}' is not an active skill of the skill library.";
        }
        $profile = [];
        if (isset($r['proficiency'])) {
            $level = self::codeIn(SkillProficiencies::options(), $r['proficiency']) ?? (is_numeric($r['proficiency']) ? self::codeIn(SkillProficiencies::options(), rtrim(rtrim(number_format((float) $r['proficiency'], 2, '.', ''), '0'), '.')) : null);
            $level === null ? $errors[] = 'Level must be one of: '.implode(', ', array_keys(SkillProficiencies::options())).'.' : $profile['proficiency'] = $level;
        }
        if (isset($r['years_of_experience'])) {
            preg_match('/^\d{1,2}$/', trim($r['years_of_experience'])) ? $profile['years_of_experience'] = (int) $r['years_of_experience'] : $errors[] = 'Years of experience must be a whole number from 0 to 60.';
        }
        if (($last = $this->dateCell($r['last_used_on'] ?? null, 'Last used on', $errors)) !== null) {
            $profile['last_used_on'] = $last;
        }
        if ($errors === [] && $profile !== []) {
            $rules = array_intersect_key(app(ChangeProfileSkillAction::class)->rules(), $profile);
            array_push($errors, ...Validator::make($profile, $rules, [], ['years_of_experience' => 'years of experience', 'last_used_on' => 'last used on'])->errors()->all());
        }
        $history = [];
        if ($skill !== null && (isset($r['assessed_level']) || isset($r['target_level']))) {
            $levels = $this->scaleLevels($skill);
            foreach (['assessed_level' => 'Assessed level', 'target_level' => 'Target level'] as $field => $label) {
                if (! isset($r[$field])) {
                    continue;
                }
                $level = $levels === null ? null : $this->scaleValue($levels, $r[$field]);
                if ($levels === null) {
                    $errors[] = "The scale of {$skill->name} has no published levels.";
                } elseif ($level === null) {
                    $errors[] = "{$label} must be a level of {$skill->name}'s scale: ".implode(', ', array_map(fn ($v, $l) => "{$v} ({$l})", array_keys($levels), $levels)).'.';
                } else {
                    $history[$field] = $level;
                }
            }
        }

        return [$skill, $profile, $history, array_values(array_unique($errors))];
    }

    private function onFile(Employee $employee, Skill $skill): ?PersonSkill
    {
        return PersonSkill::query()->where('person_id', $employee->person_id)->where('skill_id', $skill->id)->first();
    }

    /** Whether the skill history already holds these levels (the current imported entry, or the current target). */
    private function sameHistory(Employee $employee, Skill $skill, array $history): bool
    {
        $current = EmployeeSkill::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->where('skill_id', $skill->id)
            ->where('status', 'current')->orderByDesc('id')->get();
        $entry = isset($history['assessed_level'])
            ? $current->first(fn (EmployeeSkill $s) => $s->source === 'imported' && $s->current_level !== null)
            : $current->first(fn (EmployeeSkill $s) => $s->current_level === null);
        if ($entry === null) {
            return false;
        }

        return (! isset($history['assessed_level']) || abs((float) $entry->current_level - $history['assessed_level']) < 0.001)
            && (! isset($history['target_level']) || ($entry->target_level !== null && abs((float) $entry->target_level - $history['target_level']) < 0.001));
    }

    /**
     * The levels of the skill's scale (value => label), read without creating anything; null when the scale has none.
     *
     * @return array<string, string>|null
     */
    private function scaleLevels(Skill $skill): ?array
    {
        if ($skill->skill_scale_id === null) {
            $scale = SkillScale::query()->where('code', (string) config('peopleos.skills.default_scale.code'))->first();
            $levels = $scale?->current_version_id ? SkillScaleVersion::query()->find($scale->current_version_id)?->levels : config('peopleos.skills.default_scale.levels');
        } else {
            $scale = SkillScale::query()->find($skill->skill_scale_id);
            $levels = $scale?->current_version_id ? SkillScaleVersion::query()->find($scale->current_version_id)?->levels : null;
        }
        if (! is_array($levels) || $levels === []) {
            return null;
        }

        return collect($levels)->mapWithKeys(fn (array $l) => [self::number((float) $l['value']) => (string) $l['label']])->all();
    }

    /** @param array<string, string> $levels */
    private function scaleValue(array $levels, string $cell): ?float
    {
        $cell = trim($cell);
        if (is_numeric($cell) && array_key_exists(self::number((float) $cell), $levels)) {
            return (float) $cell;
        }
        $code = self::codeIn(array_combine(array_map('strval', array_keys($levels)), $levels), $cell);

        return $code === null ? null : (float) $code;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
