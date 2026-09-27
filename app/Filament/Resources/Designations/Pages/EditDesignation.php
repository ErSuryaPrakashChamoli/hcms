<?php

namespace App\Filament\Resources\Designations\Pages;

use App\Filament\Resources\Designations\DesignationResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDesignation extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = DesignationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
