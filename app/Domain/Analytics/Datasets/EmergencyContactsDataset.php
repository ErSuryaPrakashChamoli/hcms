<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\People\Models\PersonEmergencyContact;
use Illuminate\Database\Eloquent\Builder;

class EmergencyContactsDataset extends Dataset
{
    public function key(): string
    {
        return 'emergency_contacts';
    }

    public function label(): string
    {
        return 'Emergency contacts';
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
            ...$this->employeeFields(fn ($c) => $c->person?->employee),
            'contact' => ['label' => 'Contact name', 'type' => 'string', 'value' => fn ($c) => $c->name],
            'relation' => ['label' => 'Relation', 'type' => 'string', 'value' => fn ($c) => $c->relation],
            'phone' => ['label' => 'Phone', 'type' => 'string', 'value' => fn ($c) => $c->phone],
            'alternate_phone' => ['label' => 'Alternate phone', 'type' => 'string', 'value' => fn ($c) => $c->alternate_phone],
            'email' => ['label' => 'Email', 'type' => 'string', 'value' => fn ($c) => $c->email],
            'priority' => ['label' => 'Priority', 'type' => 'number', 'value' => fn ($c) => $c->priority !== null ? (int) $c->priority : null],
        ];
    }

    public function query(): Builder
    {
        // People are not access-scoped; requiring the employee applies the viewer's organisation scope.
        return PersonEmergencyContact::query()->whereHas('person.employee')->with(self::employeeWith('person.employee'))->orderBy('person_id')->orderBy('priority');
    }
}
