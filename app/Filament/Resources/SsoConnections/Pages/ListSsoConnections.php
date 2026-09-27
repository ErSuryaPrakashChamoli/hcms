<?php

namespace App\Filament\Resources\SsoConnections\Pages;

use App\Filament\Resources\SsoConnections\SsoConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSsoConnections extends ListRecords
{
    protected static string $resource = SsoConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
