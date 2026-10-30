<?php

namespace App\Filament\Resources\ExpenseGroups\Pages;

use App\Filament\Resources\ExpenseGroups\ExpenseGroupResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageExpenseGroups extends PeopleManageRecords
{
    protected static string $resource = ExpenseGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add expense group')];
    }
}
