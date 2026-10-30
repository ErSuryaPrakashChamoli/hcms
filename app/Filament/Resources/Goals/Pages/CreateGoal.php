<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Domain\Performance\Services\Goals;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\PerformanceActions;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class CreateGoal extends PeopleCreateRecord
{
    protected static string $resource = GoalResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            // HP-8d: the key results (saved by the form after the goal) are checked against the goal sheet policy first.
            return app(Goals::class)->create($data, [], auth()->user(), array_values((array) ($this->data['keyResults'] ?? [])));
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        app(Goals::class)->keyResultsSaved($this->getRecord()->refresh());
    }

    /** HP-8e: "Use template" — the template's KRAs and KPIs become draft goals instead of typing one goal. */
    protected function getHeaderActions(): array
    {
        return [PerformanceActions::useGoalTemplate()->after(fn () => $this->redirect(static::getResource()::getUrl('index')))];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
