<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Actions\ChangePersonRecordAction;
use App\Domain\People\Models\PersonAddress;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: Address change → People's ChangeAddressAction. */
final class AddressChange extends PersonRecordChange
{
    public function label(): string
    {
        return 'Address change (People)';
    }

    protected function action(): ChangePersonRecordAction
    {
        return app(ChangeAddressAction::class);
    }

    protected function model(): string
    {
        return PersonAddress::class;
    }

    protected function recordFields(): array
    {
        return [
            'type' => ['label' => 'Address type', 'type' => 'dropdown', 'required' => true, 'options' => config('peopleos.people.address_types', [])],
            'address_line_1' => ['label' => 'Address line 1', 'type' => 'text', 'required' => true],
            'address_line_2' => ['label' => 'Address line 2', 'type' => 'text'],
            'city' => ['label' => 'City', 'type' => 'text'],
            'state_code' => ['label' => 'State code', 'type' => 'text'],
            'postal_code' => ['label' => 'Postal code', 'type' => 'text'],
            'country_code' => ['label' => 'Country code (ISO, 2 letters)', 'type' => 'text', 'required' => true],
            'effective_from' => ['label' => 'Effective from', 'type' => 'date'],
        ];
    }

    protected function recordLabel(Model $record): string
    {
        return config('peopleos.people.address_types.'.$record->getAttribute('type'), (string) $record->getAttribute('type')).' address';
    }
}
