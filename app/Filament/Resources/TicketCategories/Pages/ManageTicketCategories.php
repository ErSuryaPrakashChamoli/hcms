<?php

namespace App\Filament\Resources\TicketCategories\Pages;

use App\Filament\Resources\TicketCategories\TicketCategoryResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageTicketCategories extends PeopleManageRecords
{
    protected static string $resource = TicketCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
