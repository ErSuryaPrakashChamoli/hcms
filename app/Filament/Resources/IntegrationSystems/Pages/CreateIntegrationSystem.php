<?php

namespace App\Filament\Resources\IntegrationSystems\Pages;

use App\Domain\Integration\Services\IntegrationSystems;
use App\Filament\Resources\IntegrationSystems\IntegrationSystemResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateIntegrationSystem extends PeopleCreateRecord
{
    protected static string $resource = IntegrationSystemResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $created = app(IntegrationSystems::class)->create($data, auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
        Notification::make()->success()->title('Signing secret (copy it now; it is not shown again)')->body($created['secret'])->persistent()->send();

        return $created['system'];
    }
}
