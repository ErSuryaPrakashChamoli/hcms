<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Domain\Assets\Services\Assets;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\AssetActions;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\SavesCustomFields;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateAsset extends PeopleCreateRecord
{
    use SavesCustomFields;

    protected static string $resource = AssetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $this->extractCustomFields($data);
        try {
            $asset = app(Assets::class)->receive($data, auth()->user());
        } catch (RuntimeException $e) {
            // e.g. a serial number already used in a sub type that does not allow duplicates (HROne setup parity AS-A).
            AssetActions::refuse($e->getMessage());

            throw new Halt;
        }
        $this->persistCustomFields($asset);

        return $asset;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
