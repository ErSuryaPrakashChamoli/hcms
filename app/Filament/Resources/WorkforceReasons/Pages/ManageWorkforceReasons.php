<?php

namespace App\Filament\Resources\WorkforceReasons\Pages;

use App\Filament\Resources\WorkforceReasons\WorkforceReasonResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageWorkforceReasons extends PeopleManageRecords
{
    protected static string $resource = WorkforceReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
