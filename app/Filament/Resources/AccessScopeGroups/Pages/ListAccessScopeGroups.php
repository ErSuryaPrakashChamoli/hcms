<?php

namespace App\Filament\Resources\AccessScopeGroups\Pages;

use App\Filament\Resources\AccessScopeGroups\AccessScopeGroupResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListAccessScopeGroups extends PeopleListRecords
{
    protected static string $resource = AccessScopeGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New data access group')];
    }
}
