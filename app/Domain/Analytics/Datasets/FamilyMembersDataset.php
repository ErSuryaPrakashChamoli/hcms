<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\People\Models\PersonFamilyMember;
use Illuminate\Database\Eloquent\Builder;

class FamilyMembersDataset extends Dataset
{
    public function key(): string
    {
        return 'family_members';
    }

    public function label(): string
    {
        return 'Family, dependants and nominees';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['employee.sensitive.view'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($m) => $m->person?->employee),
            'member' => ['label' => 'Family member', 'type' => 'string', 'value' => fn ($m) => $m->name],
            'relation' => ['label' => 'Relation', 'type' => 'string', 'value' => fn ($m) => $m->relation],
            'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date', 'value' => fn ($m) => $m->date_of_birth],
            'age' => ['label' => 'Age', 'type' => 'number', 'value' => fn ($m) => $m->date_of_birth ? (int) $m->date_of_birth->diffInYears(now()) : null],
            'gender' => ['label' => 'Gender', 'type' => 'string', 'value' => fn ($m) => $m->gender],
            'is_dependent' => ['label' => 'Dependant', 'type' => 'boolean', 'value' => fn ($m) => (bool) $m->is_dependent],
            'is_nominee' => ['label' => 'Nominee', 'type' => 'boolean', 'value' => fn ($m) => (bool) $m->is_nominee],
            'nominee_share' => ['label' => 'Nominee share (%)', 'type' => 'number', 'value' => fn ($m) => $m->nominee_share !== null ? (float) $m->nominee_share : null],
            'phone' => ['label' => 'Phone', 'type' => 'string', 'value' => fn ($m) => $m->phone],
            'count' => ['label' => 'Members (1 per row)', 'type' => 'number', 'value' => fn ($m) => 1],
        ];
    }

    public function query(): Builder
    {
        // People are not access-scoped; requiring the employee applies the viewer's organisation scope.
        return PersonFamilyMember::query()->whereHas('person.employee')->with(self::employeeWith('person.employee'))->orderBy('person_id');
    }
}
