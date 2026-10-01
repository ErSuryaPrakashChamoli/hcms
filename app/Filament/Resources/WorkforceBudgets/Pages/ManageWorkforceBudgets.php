<?php

namespace App\Filament\Resources\WorkforceBudgets\Pages;

use App\Filament\Resources\WorkforceBudgets\WorkforceBudgetResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkforceBudgets extends ManageRecords
{
    protected static string $resource = WorkforceBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return WorkforceBudgetResource::headerActions();
    }
}
