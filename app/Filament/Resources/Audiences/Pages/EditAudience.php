<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Domain\Engagement\Services\Audiences;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditAudience extends PeopleEditRecord
{
    protected static string $resource = AudienceResource::class;

    /** Surveys and announcements already submitted keep their pinned copy of the criteria. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(Audiences::class)->update($record, ['criteria' => AudienceCriteriaSchema::clean($data['criteria'] ?? [])] + $data, auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }
}
