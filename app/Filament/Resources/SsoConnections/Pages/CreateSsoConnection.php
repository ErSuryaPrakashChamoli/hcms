<?php

namespace App\Filament\Resources\SsoConnections\Pages;

use App\Filament\Resources\SsoConnections\SsoConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSsoConnection extends CreateRecord
{
    protected static string $resource = SsoConnectionResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
