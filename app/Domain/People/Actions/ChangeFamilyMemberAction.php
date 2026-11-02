<?php

namespace App\Domain\People\Actions;

use App\Domain\Configuration\Services\OptionLists;
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
            // HROne setup parity CA-3: the organisation's relationship list (built-in codes, renamed or hidden, plus its own).
            'relation' => ['required', 'in:'.implode(',', array_keys(app(OptionLists::class)->labels('family_relations')))],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'in:'.implode(',', array_keys(config('peopleos.people.genders', [])))],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_dependent' => ['nullable', 'boolean'],
            'is_nominee' => ['nullable', 'boolean'],
            'nominee_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // HROne parity MS-4: a child at school / in a hostel (for allowance rules that may read them; no amount here), remarks.
            'in_school' => ['nullable', 'boolean'],
            'in_hostel' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
            // Data import DI-2: what the family member does (HROne's family details carry it).
            'occupation' => ['nullable', 'string', 'max:120'],
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
