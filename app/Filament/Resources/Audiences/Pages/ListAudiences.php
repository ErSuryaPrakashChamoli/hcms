<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListAudiences extends PeopleListRecords
{
    protected static string $resource = AudienceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
