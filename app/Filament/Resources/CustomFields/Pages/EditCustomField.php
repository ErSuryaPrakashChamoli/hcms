<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditCustomField extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = CustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
