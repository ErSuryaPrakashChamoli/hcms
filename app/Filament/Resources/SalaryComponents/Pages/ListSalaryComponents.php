<?php

namespace App\Filament\Resources\SalaryComponents\Pages;

use App\Filament\Resources\SalaryComponents\SalaryComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalaryComponents extends ListRecords
{
    protected static string $resource = SalaryComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
