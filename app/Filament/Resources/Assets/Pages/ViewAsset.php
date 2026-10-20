<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\AssetActions;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\SavesCustomFields;
use Filament\Actions\EditAction;

class ViewAsset extends PeopleViewRecord
{
    use SavesCustomFields;

    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->visible(fn () => auth()->user()->can('asset.manage')), ...AssetActions::forAsset()];
    }
}
