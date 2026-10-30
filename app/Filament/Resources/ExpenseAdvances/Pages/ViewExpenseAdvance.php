<?php

namespace App\Filament\Resources\ExpenseAdvances\Pages;

use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewExpenseAdvance extends PeopleViewRecord
{
    protected static string $resource = ExpenseAdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [ExpenseAdvanceResource::dispenseAction()];
    }
}
