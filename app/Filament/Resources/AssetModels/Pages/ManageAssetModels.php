<?php

namespace App\Filament\Resources\AssetModels\Pages;

use App\Filament\Resources\AssetModels\AssetModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAssetModels extends ManageRecords
{
    protected static string $resource = AssetModelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
