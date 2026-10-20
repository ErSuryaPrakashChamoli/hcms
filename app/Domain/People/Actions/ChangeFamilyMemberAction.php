<?php

namespace App\Domain\People\Actions;

use App\Domain\People\Models\PersonFamilyMember;

/** Phase 12: the one write path for a person's family members (dependents, nominees). */
final class ChangeFamilyMemberAction extends ChangePersonRecordAction
{
    protected function model(): string
    {
        return PersonFamilyMember::class;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'relation' => ['required', 'in:'.implode(',', array_keys(config('peopleos.people.family_relations', [])))],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'in:'.implode(',', array_keys(config('peopleos.people.genders', [])))],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_dependent' => ['nullable', 'boolean'],
            'is_nominee' => ['nullable', 'boolean'],
            'nominee_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    protected function event(): string
    {
        return 'employee.family_member_changed';
    }

    protected function label(): string
    {
        return 'Family member';
    }
}
