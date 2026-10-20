<?php

namespace App\Filament\Resources\SsoConnections\Pages;

use App\Filament\Resources\SsoConnections\SsoConnectionResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateSsoConnection extends PeopleCreateRecord
{
    protected static string $resource = SsoConnectionResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
