<?php

namespace App\Filament\Resources\CompanyEvents\Pages;

use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListCompanyEvents extends PeopleListRecords
{
    protected static string $resource = CompanyEventResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add event')];
    }
}
