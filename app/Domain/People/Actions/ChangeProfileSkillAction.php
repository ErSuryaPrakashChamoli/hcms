<?php

namespace App\Domain\People\Actions;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Support\SkillProficiencies;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity MS-3: the one write path for a person's profile skills (`person_skills`: skill, proficiency on the
 * organisation's profile scale, years). Profile skills had no edit screen; the Skills domain's assessed skill profile is
 * separate and unchanged. A skill is on a profile once (the table's unique index): adding it again is refused.
 */
final class ChangeProfileSkillAction extends ChangePersonRecordAction
{
    protected function model(): string
    {
        return PersonSkill::class;
    }

    public function rules(): array
    {
        return [
            'skill_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! Skill::query()->whereKey((int) $value)->where('status', 'active')->exists()) {
                    $fail('Choose a skill from the list.');
                }
            }],
            'proficiency' => ['nullable', 'in:'.implode(',', array_keys(SkillProficiencies::options()))],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
            'last_used_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function add(Employee $employee, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): Model
    {
        $this->assertNotOnProfile($employee, (int) ($data['skill_id'] ?? 0));

        return parent::add($employee, $data, $actor, $reason, $origin);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Employee $employee, Model $record, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): Model
    {
        $this->assertNotOnProfile($employee, (int) ($data['skill_id'] ?? 0), (int) $record->getKey());

        return parent::update($employee, $record, $data, $actor, $reason, $origin);
    }

    protected function event(): string
    {
        return 'employee.profile_skill_changed';
    }

    protected function label(): string
    {
        return 'Profile skill';
    }

    private function assertNotOnProfile(Employee $employee, int $skillId, ?int $except = null): void
    {
        if ($skillId > 0 && PersonSkill::query()->where('person_id', $employee->person_id)->where('skill_id', $skillId)->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw new ProfileChangeRefused('That skill is already on the profile; change its level instead.');
        }
    }
}
