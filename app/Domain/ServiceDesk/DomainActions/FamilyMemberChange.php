<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Actions\ChangePersonRecordAction;
use App\Domain\People\Models\PersonFamilyMember;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: Family member change → People's ChangeFamilyMemberAction. */
final class FamilyMemberChange extends PersonRecordChange
{
    public function label(): string
    {
        return 'Family member change (People)';
    }

    protected function action(): ChangePersonRecordAction
    {
        return app(ChangeFamilyMemberAction::class);
    }

    protected function model(): string
    {
        return PersonFamilyMember::class;
    }

    protected function recordFields(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'relation' => ['label' => 'Relation', 'type' => 'dropdown', 'required' => true, 'options' => config('peopleos.people.family_relations', [])],
            'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date'],
            'gender' => ['label' => 'Gender', 'type' => 'dropdown', 'options' => config('peopleos.people.genders', [])],
            'phone' => ['label' => 'Phone', 'type' => 'text'],
            'is_dependent' => ['label' => 'Dependent', 'type' => 'checkbox'],
            'is_nominee' => ['label' => 'Nominee', 'type' => 'checkbox'],
            'nominee_share' => ['label' => 'Nominee share (%)', 'type' => 'number'],
        ];
    }

    protected function recordLabel(Model $record): string
    {
        return config('peopleos.people.family_relations.'.$record->getAttribute('relation'), 'Family member');
    }
}
