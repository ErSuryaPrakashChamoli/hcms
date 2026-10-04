<?php

namespace App\Filament\Resources\Surveys\Pages;

use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\Pages\PeopleEditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSurvey extends PeopleEditRecord
{
    protected static string $resource = SurveyResource::class;

    /** Only the survey's name, category and description change here; everything else lives on versions. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(array_intersect_key($data, array_flip(['name', 'category', 'description'])));

        return $record;
    }
}
