<?php

namespace App\Filament\Resources\Policies\Pages;

use App\Domain\Configuration\Services\Policies;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreatePolicy extends PeopleCreateRecord
{
    protected static string $resource = PolicyResource::class;

    protected function afterCreate(): void
    {
        app(Policies::class)->draft($this->getRecord(), []);
    }

    protected function getRedirectUrl(): string
    {
        return PolicyResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
