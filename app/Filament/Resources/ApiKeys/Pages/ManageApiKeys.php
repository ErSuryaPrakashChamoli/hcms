<?php

namespace App\Filament\Resources\ApiKeys\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageApiKeys extends PeopleManageRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [ApiKeyResource::issueAction()];
    }
}
