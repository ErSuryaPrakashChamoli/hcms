<?php

namespace App\Filament\Resources\CompensationBudgets\Pages;

use App\Filament\Resources\CompensationBudgets\CompensationBudgetResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageCompensationBudgets extends PeopleManageRecords
{
    protected static string $resource = CompensationBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationBudgetResource::headerActions();
    }
}
