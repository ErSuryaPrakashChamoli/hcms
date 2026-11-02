<?php

namespace App\Filament\Resources\JvCodes\Pages;

use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\JvCodes;
use App\Filament\Resources\JvCodes\JvCodeResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use RuntimeException;

class ManageJvCodes extends PeopleManageRecords
{
    protected static string $resource = JvCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Set a JV code')->using(function (array $data) {
                $jv = app(JvCodes::class);
                $record = $jv->find((string) $data['dimension'], (string) $data['record_code']);
                try {
                    if ($record === null) {
                        throw new RuntimeException(JvCodes::label((string) $data['dimension'])." code '{$data['record_code']}' does not exist.");
                    }
                    $system = filled($data['integration_system_id'] ?? null) ? IntegrationSystem::query()->findOrFail($data['integration_system_id']) : null;

                    return $jv->set((string) $data['dimension'], $record, (string) $data['code'], $system, 'Set on the JV codes screen');
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    throw new Halt;
                }
            }),
        ];
    }
}
