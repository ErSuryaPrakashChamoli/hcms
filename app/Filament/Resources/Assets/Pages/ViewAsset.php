<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\AssetActions;
use App\Filament\Support\SavesCustomFields;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAsset extends ViewRecord
{
    use SavesCustomFields;

    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->visible(fn () => auth()->user()->can('asset.manage')), ...AssetActions::forAsset()];
    }
}
