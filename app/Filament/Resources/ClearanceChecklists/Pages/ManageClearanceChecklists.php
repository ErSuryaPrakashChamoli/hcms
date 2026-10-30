<?php

namespace App\Filament\Resources\ClearanceChecklists\Pages;

use App\Filament\Resources\ClearanceChecklists\ClearanceChecklistResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageClearanceChecklists extends PeopleManageRecords
{
    protected static string $resource = ClearanceChecklistResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
