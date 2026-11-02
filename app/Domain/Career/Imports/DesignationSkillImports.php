<?php

namespace App\Domain\Career\Imports;

use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Models\SkillScaleVersion;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Data import DI-1 (Core → Designation wise skills): the skills each designation needs, with the level expected, as
 * the role requirements of Talent → Role requirements (RoleRequirementVersion). Requirements are immutable versions
 * per designation, so the rows of one designation in a file are published together through CareerArchitecture as ONE
 * new role-wide version from the Effective from date: it keeps the skills (and competencies, experience,
 * certifications, learning and notes) of the version on file and adds or changes the skills in the file. A skill is
 * never removed by an upload. A version starts after the one on file (a second upload the same day needs a later
 * Effective from). The level is a value (or its label) on the skill's proficiency scale.
 */
final class DesignationSkillImports extends RowImports
{
    public const TYPE = 'designation_skills';

    /** Who maintains role requirements (CareerArchitecture checks it again). */
    public const PERMISSION = 'career.manage';

    /** @var array<int, array<string, array{version: int, row: int}>> import id => designation code => version published by a row */
    private array $published = [];

    public static function fields(): array
    {
        return [
            'designation_code' => ['Designation code', true], 'skill_code' => ['Skill code', true], 'level' => ['Expected level', true],
            'required' => ['Required', false], 'primary' => ['Primary skill', false], 'effective_from' => ['Effective from', false],
        ];
    }

    public function label(): string
    {
        return 'Designation wise skills upload';
    }

    protected function module(): string
    {
        return 'career';
    }

    protected function entityType(): string
    {
        return RoleRequirementVersion::class;
    }

    protected function aliases(): array
    {
        return ['designation' => 'designation_code', 'skill' => 'skill_code', 'level' => 'level', 'proficiency' => 'level', 'expected_proficiency' => 'level', 'mandatory' => 'required',
            'is_required' => 'required', 'primary' => 'primary', 'is_primary' => 'primary', 'from' => 'effective_from', 'effective_date' => 'effective_from'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        $codes = fn (string $class) => $class::query()->withoutGlobalScope(AccessScope::class)->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();

        return ['designations' => $codes(Designation::class), 'skills' => $codes(Skill::class), 'seen' => [], 'dates' => [], 'latest' => [], 'levels' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $errors = [];
        $designationId = $context['designations'][mb_strtolower($r['designation_code'] ?? '')] ?? null;
        if ($designationId === null) {
            $errors[] = "Designation code '".($r['designation_code'] ?? '')."' does not exist.";
        }
        $skillId = $context['skills'][mb_strtolower($r['skill_code'] ?? '')] ?? null;
        if ($skillId === null) {
            $errors[] = "Skill code '".($r['skill_code'] ?? '')."' does not exist.";
        }
        $from = $this->effectiveFrom($r, $errors);
        [$required, $primary] = [$this->yesNo($r['required'] ?? null, 'Required', $errors), $this->yesNo($r['primary'] ?? null, 'Primary skill', $errors)];
        $level = $skillId === null ? null : $this->level($skillId, (string) ($r['level'] ?? ''), $context, $errors);
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors];
        }

        $designationKey = mb_strtolower($r['designation_code']);
        $key = $designationKey.'|'.$skillId;
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same designation and skill are in row '.$context['seen'][$key].'.']];
        }
        $context['seen'][$key] = $row->row_number;
        if (isset($context['dates'][$designationKey]) && $context['dates'][$designationKey]['date'] !== $from) {
            return ['action' => 'error', 'errors' => ["Every row of designation {$r['designation_code']} needs the same Effective from (row {$context['dates'][$designationKey]['row']} has {$context['dates'][$designationKey]['date']})."]];
        }
        $context['dates'][$designationKey] ??= ['date' => $from, 'row' => $row->row_number];

        $latest = $context['latest'][$designationId] ??= $this->latest($designationId);
        $current = collect($latest?->skills ?? [])->firstWhere('skill_id', $skillId);
        $wanted = ['level' => $level, 'required' => $required ?? (bool) ($current['required'] ?? true), 'primary' => $primary ?? ! empty($current['primary'])];
        if ($current !== null && (float) $current['level'] === $wanted['level'] && (bool) ($current['required'] ?? true) === $wanted['required'] && ! empty($current['primary']) === $wanted['primary']) {
            return ['action' => 'skip', 'match' => ['reason' => 'The designation already needs this skill at this level.']];
        }
        if ($latest !== null && $latest->effective_from->toDateString() >= $from) {
            return ['action' => 'error', 'errors' => ["The role requirements of {$r['designation_code']} on file start on {$latest->effective_from->toDateString()}: set Effective from to a later date."]];
        }

        return ['action' => $current === null ? 'create' : 'update', 'match' => $current === null ? null : ['level' => (float) $current['level'], 'version' => $latest->version]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $designationKey = mb_strtolower($r['designation_code']);
        if (isset($this->published[$import->getKey()][$designationKey])) {
            $done = $this->published[$import->getKey()][$designationKey];

            return "Published with row {$done['row']} in role requirements version {$done['version']}";
        }
        $designation = Designation::query()->withoutGlobalScope(AccessScope::class)->whereRaw('lower(code) = ?', [$designationKey])->firstOrFail();
        $latest = $this->latest((int) $designation->getKey());
        $skills = collect($latest?->skills ?? [])->map(fn (array $s) => ['skill_id' => (int) $s['skill_id'], 'level' => (float) $s['level'], 'required' => (bool) ($s['required'] ?? true), 'primary' => ! empty($s['primary'])])
            ->keyBy('skill_id')->all();

        // Every row of this designation that is to be applied goes into the one new version.
        $context = ['levels' => []];
        $errors = [];
        $changed = 0;
        foreach ($import->rows()->whereIn('action', ['create', 'update'])->orderBy('row_number')->get() as $other) {
            $o = $this->recordFor($other, $import->mapping ?? []) + $this->defaults($import);
            if (mb_strtolower($o['designation_code'] ?? '') !== $designationKey) {
                continue;
            }
            $skill = Skill::query()->whereRaw('lower(code) = ?', [mb_strtolower($o['skill_code'])])->firstOrFail();
            $level = $this->level((int) $skill->getKey(), (string) $o['level'], $context, $errors);
            if ($errors !== []) {
                throw new RuntimeException(implode(' ', $errors));
            }
            $current = $skills[$skill->getKey()] ?? null;
            $primary = $this->yesNo($o['primary'] ?? null, 'Primary skill', $errors) ?? ($current['primary'] ?? false);
            if ($primary) {
                $skills = array_map(fn (array $s) => ['primary' => false] + $s, $skills);
            }
            $skills[$skill->getKey()] = ['skill_id' => (int) $skill->getKey(), 'level' => $level, 'required' => $this->yesNo($o['required'] ?? null, 'Required', $errors) ?? ($current['required'] ?? true), 'primary' => $primary];
            $changed++;
        }

        $version = app(CareerArchitecture::class)->publishRequirements($designation, null, [
            'skills' => array_values($skills),
            'competencies' => $latest?->competencies ?? [],
            'min_experience_years' => $latest?->min_experience_years,
            'certifications' => $latest?->certifications ?? [],
            'learning' => $latest?->learning ?? [],
            'notes' => $latest?->notes,
        ], $this->effectiveFrom($r, $errors), $user);
        $this->published[$import->getKey()][$designationKey] = ['version' => (int) $version->version, 'row' => (int) $row->row_number];

        return "Published role requirements version {$version->version} of {$designation->code} from {$version->effective_from->toDateString()} ({$changed} skill(s) added or changed)";
    }

    /** The newest role-wide requirements version of a designation (the one a new version follows). */
    private function latest(int $designationId): ?RoleRequirementVersion
    {
        return RoleRequirementVersion::query()->where('designation_id', $designationId)->where('scope_key', '')->orderByDesc('version')->first();
    }

    /** @param list<string> $errors */
    private function effectiveFrom(array $r, array &$errors): string
    {
        if (($r['effective_from'] ?? '') === '') {
            return now()->toDateString();
        }
        $date = $this->date($r['effective_from']);
        if ($date === null) {
            $errors[] = 'Effective from must be a date (YYYY-MM-DD).';

            return now()->toDateString();
        }

        return Carbon::parse($date)->toDateString();
    }

    /** @param list<string> $errors */
    private function yesNo(?string $value, string $what, array &$errors): ?bool
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['yes', 'y', '1', 'true'], true)) {
            return true;
        }
        if (in_array($v, ['no', 'n', '0', 'false'], true)) {
            return false;
        }
        $errors[] = "{$what} must be yes or no.";

        return null;
    }

    /**
     * The level value on the skill's scale, from its number or its label. Read without creating anything: a skill on
     * the default scale before that scale is first used reads the configured default levels.
     *
     * @param  list<string>  $errors
     */
    private function level(int $skillId, string $value, array &$context, array &$errors): ?float
    {
        $levels = $context['levels'][$skillId] ??= $this->levelsOf($skillId);
        $value = trim($value);
        foreach ($levels as $level) {
            if ((is_numeric($value) && (float) $value === (float) $level['value']) || mb_strtolower($value) === mb_strtolower((string) ($level['label'] ?? ''))) {
                return (float) $level['value'];
            }
        }
        $errors[] = "Expected level '{$value}' is not on the skill's scale (".implode(', ', array_map(fn (array $l) => $l['value'].' '.($l['label'] ?? ''), $levels)).').';

        return null;
    }

    /** @return list<array{value: float|int, label?: string}> */
    private function levelsOf(int $skillId): array
    {
        $scaleId = Skill::query()->whereKey($skillId)->value('skill_scale_id');
        $scale = $scaleId !== null ? SkillScale::query()->find($scaleId) : SkillScale::query()->where('code', config('peopleos.skills.default_scale.code'))->first();
        if ($scale?->current_version_id !== null) {
            return array_values(SkillScaleVersion::query()->find($scale->current_version_id)?->levels ?? []);
        }

        return $scaleId === null ? array_values((array) config('peopleos.skills.default_scale.levels', [])) : [];
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Designation code', 'Yes', 'Code of the designation (People setup → Designations).', 'SE'],
                ['Skill code', 'Yes', 'Code of the skill (Learning → Skills, or the Skill master upload).', 'PYTHON'],
                ['Expected level', 'Yes', 'The level needed, as a value or its name on the skill\'s proficiency scale (default scale: 1 Beginner, 2 Intermediate, 3 Advanced, 4 Expert).', '3'],
                ['Required', 'No', 'yes (default) or no: a nice-to-have skill.', 'yes'],
                ['Primary skill', 'No', 'yes for the designation\'s one primary skill (it replaces the primary skill on file).', 'no'],
                ['Effective from', 'No', 'Date the requirements apply from (YYYY-MM-DD); blank is today. The same for every row of a designation, and after the requirements on file.', now()->toDateString()],
            ],
            'notes' => [
                'The skills of a designation are its role requirements (Talent → Role requirements), kept as dated versions. All rows of one designation in a file become ONE new version: the skills on file stay, the skills in the file are added or get the new level.',
                'A skill is never removed by an upload; competencies, experience, certifications and learning of the version on file are carried over. A row whose skill is already needed at that level is skipped.',
                'A new version starts after the version on file: a second upload for the same designation on the same day needs a later Effective from.',
            ],
        ];
    }

    protected function examples(): array
    {
        $designation = Designation::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('code') ?? 'SE';
        $skills = Skill::query()->orderBy('id')->limit(2)->pluck('code')->all() + [0 => 'PYTHON', 1 => 'NEGOTIATION'];

        return [[$designation, $skills[0], '3', 'yes', 'yes', ''], [$designation, $skills[1], '2', 'no', 'no', '']];
    }
}
