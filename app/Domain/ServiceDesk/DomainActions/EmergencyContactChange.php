<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\People\Actions\ChangeEmergencyContactAction;
use App\Domain\People\Actions\ChangePersonRecordAction;
use App\Domain\People\Models\PersonEmergencyContact;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: Emergency contact update → People's ChangeEmergencyContactAction. */
final class EmergencyContactChange extends PersonRecordChange
{
    public function label(): string
    {
        return 'Emergency contact update (People)';
    }

    protected function action(): ChangePersonRecordAction
    {
        return app(ChangeEmergencyContactAction::class);
    }

    protected function model(): string
    {
        return PersonEmergencyContact::class;
    }

    protected function recordFields(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'relation' => ['label' => 'Relation', 'type' => 'text'],
            'phone' => ['label' => 'Phone', 'type' => 'text', 'required' => true],
            'alternate_phone' => ['label' => 'Alternate phone', 'type' => 'text'],
            'email' => ['label' => 'Email', 'type' => 'email'],
            'priority' => ['label' => 'Priority (1 = first to call)', 'type' => 'number'],
        ];
    }

    protected function recordLabel(Model $record): string
    {
        return 'Emergency contact #'.$record->getAttribute('priority');
    }
}
