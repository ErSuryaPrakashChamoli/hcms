<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Domain\Performance\Services\Goals;
use App\Filament\Resources\Goals\GoalResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGoal extends CreateRecord
{
    protected static string $resource = GoalResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(Goals::class)->create($data, [], auth()->user());
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        app(Goals::class)->recompute($this->getRecord()->refresh());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
