<?php

namespace App\Filament\Resources\SsoConnections\Pages;

use App\Filament\Resources\SsoConnections\SsoConnectionResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSsoConnection extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = SsoConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
