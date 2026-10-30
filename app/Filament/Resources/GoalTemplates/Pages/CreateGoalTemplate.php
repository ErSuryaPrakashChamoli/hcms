<?php

namespace App\Filament\Resources\GoalTemplates\Pages;

use App\Filament\Resources\GoalTemplates\GoalTemplateResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateGoalTemplate extends PeopleCreateRecord
{
    protected static string $resource = GoalTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
