<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Domain\Performance\Services\Goals;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\PerformanceActions;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGoal extends EditRecord
{
    protected static string $resource = GoalResource::class;

    protected function getHeaderActions(): array
    {
        return [...PerformanceActions::forGoal(), DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        app(Goals::class)->recompute($this->getRecord()->refresh());
    }
}
