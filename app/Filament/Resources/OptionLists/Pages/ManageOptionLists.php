<?php

namespace App\Filament\Resources\OptionLists\Pages;

use App\Filament\Resources\OptionLists\OptionListResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageOptionLists extends PeopleManageRecords
{
    protected static string $resource = OptionListResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add or rename an option')];
    }
}
