<?php

namespace App\Domain\People\Actions;

use App\Domain\People\Models\PersonAddress;

/** Phase 12: the one write path for a person's addresses (current, permanent, correspondence). */
final class ChangeAddressAction extends ChangePersonRecordAction
{
    protected function model(): string
    {
        return PersonAddress::class;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', array_keys(config('peopleos.people.address_types', [])))],
            'country_code' => ['required', 'string', 'size:2'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state_code' => ['nullable', 'string', 'max:16'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    protected function event(): string
    {
        return 'employee.address_changed';
    }

    protected function label(): string
    {
        return 'Address';
    }
}
