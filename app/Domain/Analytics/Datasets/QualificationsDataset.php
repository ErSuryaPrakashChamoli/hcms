<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\People\Models\PersonQualification;
use Illuminate\Database\Eloquent\Builder;

class QualificationsDataset extends Dataset
{
    public function key(): string
    {
        return 'qualifications';
    }

    public function label(): string
    {
        return 'Education and qualifications';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['employee.view'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($q) => $q->person?->employee),
            'qualification' => ['label' => 'Qualification', 'type' => 'string', 'value' => fn ($q) => $q->qualification],
            'specialisation' => ['label' => 'Specialisation', 'type' => 'string', 'value' => fn ($q) => $q->specialisation],
            // HROne setup parity CA-3: the level of the qualification master the entry was picked from.
            'qualification_level' => ['label' => 'Qualification level', 'type' => 'string', 'value' => fn ($q) => $q->master?->level ? config('peopleos.people.qualification_levels.'.$q->master->level, $q->master->level) : null],
            'institution' => ['label' => 'Institution', 'type' => 'string', 'value' => fn ($q) => $q->institution],
            'board_or_university' => ['label' => 'Board / university', 'type' => 'string', 'value' => fn ($q) => $q->board_or_university],
            'year_of_completion' => ['label' => 'Year of completion', 'type' => 'number', 'value' => fn ($q) => $q->year_of_completion !== null ? (int) $q->year_of_completion : null],
            'grade_or_score' => ['label' => 'Grade / score', 'type' => 'string', 'value' => fn ($q) => $q->grade_or_score],
            'is_verified' => ['label' => 'Verified', 'type' => 'boolean', 'value' => fn ($q) => (bool) $q->is_verified],
            'count' => ['label' => 'Qualifications (1 per row)', 'type' => 'number', 'value' => fn ($q) => 1],
        ];
    }

    public function query(): Builder
    {
        // People are not access-scoped; requiring the employee applies the viewer's organisation scope.
        return PersonQualification::query()->whereHas('person.employee')->with([...self::employeeWith('person.employee'), 'master'])->orderBy('person_id');
    }
}
