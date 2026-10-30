<?php

namespace App\Filament\Resources\ExpenseLocationGroups\Pages;

use App\Filament\Resources\ExpenseLocationGroups\ExpenseLocationGroupResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageExpenseLocationGroups extends PeopleManageRecords
{
    protected static string $resource = ExpenseLocationGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add location group')->using(fn (array $data) => ExpenseLocationGroupResource::save(null, $data))];
    }
}
