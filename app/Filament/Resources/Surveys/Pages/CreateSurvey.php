<?php

namespace App\Filament\Resources\Surveys\Pages;

use App\Domain\Engagement\Services\Surveys;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateSurvey extends PeopleCreateRecord
{
    protected static string $resource = SurveyResource::class;

    /** A survey starts with a draft version 1: add questions and an audience, submit it, and have it approved. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(Surveys::class)->create($data, auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
