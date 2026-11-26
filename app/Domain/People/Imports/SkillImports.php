<?php

namespace App\Domain\People\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\SkillScale;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Core → Skill master): the skill library by name (the code is optional), with category, type and the
 * proficiency scale a skill is measured on, by name (blank: the default scale), as on the Skills screen.
 */
final class SkillImports extends MasterImports
{
    use ReadsMasterRows;

    public const TYPE = 'skills';

    public static function fields(): array
    {
        return ['code' => ['Code', false], 'name' => ['Name', true], 'category' => ['Category', false], 'skill_type' => ['Type', false], 'scale_code' => ['Proficiency scale', false],
            'description' => ['Description', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Skill master upload';
    }

    protected function module(): string
    {
        return 'skills';
    }

    protected function model(): string
    {
        return Skill::class;
    }

    protected function aliases(): array
    {
        return ['skill_code' => 'code', 'skill_name' => 'name', 'skill' => 'name', 'skill_category' => 'category', 'skill_type' => 'skill_type', 'scale' => 'scale_code', 'skill_scale' => 'scale_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $code = trim((string) ($r['code'] ?? ''));
        if (mb_strlen($code) > 64 || preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            $errors[] = 'Code is up to 64 letters, digits, dashes or underscores.';
        }

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'category' => $this->text($r['category'] ?? null, 64, 'Category', $errors),
            'skill_type' => $this->choice($r['skill_type'] ?? null, (array) config('peopleos.skills.types', []), 'Type', $errors),
            'skill_scale_id' => $this->lookup($context, SkillScale::class, $r['scale_code'] ?? null, 'Proficiency scale', $errors),
            'description' => $this->text($r['description'] ?? null, 2000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'No', 'Optional. Blank: the skill is found by its name, and a new one gets a code made from the name. Give the code to rename a skill or to choose its code (up to 64 letters, digits, - and _).', ''],
                ['Name', 'Yes', 'The skill name.', 'Python'],
                ['Category', 'No', 'Your grouping, e.g. technical, behavioural, domain (up to 64 characters).', 'technical'],
                ['Type', 'No', 'One of: '.implode(', ', array_keys((array) config('peopleos.skills.types', []))).'.', 'technical'],
                ['Proficiency scale', 'No', ReferenceGuide::name('proficiency scale', 'Learning → Skill scales', 'Blank: the default scale.'), ''],
                ['Description', 'No', 'What the skill covers.', 'Python programming and its standard library'],
                ['Status', 'No', 'active or inactive. Blank for a new skill is active.', 'active'],
            ],
            'notes' => [
                ReferenceGuide::OWN_CODE_NOTE,
                ReferenceGuide::NOTE,
                'A row whose values are already on file is skipped.',
                'Blank cells keep what is on file. Changes to an existing skill follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [['', 'Python', 'technical', 'technical', '', 'Python programming and its standard library', 'active'],
            ['', 'Negotiation', 'behavioural', 'behavioural', '', 'Reaching agreements that work for both sides', 'active']];
    }
}
