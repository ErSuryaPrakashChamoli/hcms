<?php

namespace App\Domain\People\Actions;

use App\Domain\People\Models\PersonEmergencyContact;

/** Phase 12: the one write path for a person's emergency contacts. */
final class ChangeEmergencyContactAction extends ChangePersonRecordAction
{
    protected function model(): string
    {
        return PersonEmergencyContact::class;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'relation' => ['nullable', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'alternate_phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9'],
        ];
    }

    protected function event(): string
    {
        return 'employee.emergency_contact_changed';
    }

    protected function label(): string
    {
        return 'Emergency contact';
    }
}
