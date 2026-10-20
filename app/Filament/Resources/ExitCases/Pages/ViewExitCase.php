<?php

namespace App\Filament\Resources\ExitCases\Pages;

use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Support\ExitActions;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewExitCase extends PeopleViewRecord
{
    protected static string $resource = ExitCaseResource::class;

    protected function getHeaderActions(): array
    {
        return ExitActions::forCase();
    }
}
