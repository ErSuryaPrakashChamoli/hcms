<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Domain\Engagement\Services\Audiences;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\ServiceDeskActions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateAudience extends CreateRecord
{
    protected static string $resource = AudienceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(Audiences::class)->create(['criteria' => AudienceCriteriaSchema::clean($data['criteria'] ?? [])] + $data, auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
