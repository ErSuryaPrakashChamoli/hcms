<?php

namespace App\Filament\Resources\IntegrationSystems\Pages;

use App\Domain\Integration\Services\IntegrationSystems;
use App\Filament\Resources\IntegrationSystems\IntegrationSystemResource;
use App\Filament\Support\ServiceDeskActions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditIntegrationSystem extends EditRecord
{
    protected static string $resource = IntegrationSystemResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(IntegrationSystems::class)->update($record, $data, auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }
}
