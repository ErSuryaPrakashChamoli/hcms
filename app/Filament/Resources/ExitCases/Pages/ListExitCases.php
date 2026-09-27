<?php

namespace App\Filament\Resources\ExitCases\Pages;

use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Support\ExitActions;
use Filament\Resources\Pages\ListRecords;

class ListExitCases extends ListRecords
{
    protected static string $resource = ExitCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [ExitActions::initiate(), ExitActions::resign()];
    }
}
