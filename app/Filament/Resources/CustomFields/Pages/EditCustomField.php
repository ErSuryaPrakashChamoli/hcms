<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomField extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = CustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
