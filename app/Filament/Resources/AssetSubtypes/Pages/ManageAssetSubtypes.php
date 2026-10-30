<?php

namespace App\Filament\Resources\AssetSubtypes\Pages;

use App\Filament\Resources\AssetSubtypes\AssetSubtypeResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageAssetSubtypes extends PeopleManageRecords
{
    protected static string $resource = AssetSubtypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add asset sub type')];
    }
}
