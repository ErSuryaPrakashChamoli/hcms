<?php

namespace App\Filament\Resources\Kras\Pages;

use App\Filament\Resources\Kras\KraResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageKras extends ManageRecords
{
    protected static string $resource = KraResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
