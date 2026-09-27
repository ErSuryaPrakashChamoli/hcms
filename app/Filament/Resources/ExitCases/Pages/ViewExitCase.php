<?php

namespace App\Filament\Resources\ExitCases\Pages;

use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Support\ExitActions;
use Filament\Resources\Pages\ViewRecord;

class ViewExitCase extends ViewRecord
{
    protected static string $resource = ExitCaseResource::class;

    protected function getHeaderActions(): array
    {
        return ExitActions::forCase();
    }
}
