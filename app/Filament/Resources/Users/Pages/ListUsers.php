<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\WorkforceBulkWizards;
use Filament\Actions\CreateAction;

class ListUsers extends PeopleListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity WO-16 (§23.9 #7): invite employees who have no sign-in yet (or send reset links), in bulk.
            WorkforceBulkWizards::invite(),
            CreateAction::make(),
        ];
    }
}
