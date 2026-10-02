<?php

namespace App\Filament\Resources\CompensationBudgets\Pages;

use App\Filament\Resources\CompensationBudgets\CompensationBudgetResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCompensationBudgets extends ManageRecords
{
    protected static string $resource = CompensationBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationBudgetResource::headerActions();
    }
}
