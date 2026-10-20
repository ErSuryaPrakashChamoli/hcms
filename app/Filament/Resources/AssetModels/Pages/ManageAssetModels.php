<?php

namespace App\Filament\Resources\AssetModels\Pages;

use App\Filament\Resources\AssetModels\AssetModelResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageAssetModels extends PeopleManageRecords
{
    protected static string $resource = AssetModelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
