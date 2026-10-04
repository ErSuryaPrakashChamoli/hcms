<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Domain\Assets\Services\Assets;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\SavesCustomFields;
use Illuminate\Database\Eloquent\Model;

class CreateAsset extends PeopleCreateRecord
{
    use SavesCustomFields;

    protected static string $resource = AssetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $this->extractCustomFields($data);
        $asset = app(Assets::class)->receive($data, auth()->user());
        $this->persistCustomFields($asset);

        return $asset;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
