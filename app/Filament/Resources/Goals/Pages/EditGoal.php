<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Domain\Performance\Services\Goals;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\PerformanceActions;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGoal extends EditRecord
{
    protected static string $resource = GoalResource::class;

    /** Phase 7: the version the editor loaded; saving refuses if someone else changed it since. */
    public ?int $loadedLockVersion = null;

    protected function afterFill(): void
    {
        $this->loadedLockVersion = (int) $this->getRecord()->lock_version;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(Goals::class)->update($record, $data, $this->loadedLockVersion, auth()->user());
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [...PerformanceActions::forGoal(), DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        app(Goals::class)->recompute($this->getRecord()->refresh());
    }
}
