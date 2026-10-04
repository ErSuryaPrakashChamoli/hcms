<?php

namespace App\Filament\Resources\WorkforceBudgets\Pages;

use App\Filament\Resources\WorkforceBudgets\WorkforceBudgetResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageWorkforceBudgets extends PeopleManageRecords
{
    protected static string $resource = WorkforceBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return WorkforceBudgetResource::headerActions();
    }
}
