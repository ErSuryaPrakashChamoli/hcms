<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Services\Goals;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\PerformanceActions;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class EditGoal extends PeopleEditRecord
{
    protected static string $resource = GoalResource::class;

    /** Phase 7: the version the editor loaded; saving refuses if someone else changed it since. */
    public ?int $loadedLockVersion = null;

    /** Owner decision 2026-10-29: whether this save changes the KPIs (a proposal on a system-mapped goal sheet). */
    protected bool $keyResultsChanged = false;

    protected function afterFill(): void
    {
        $this->loadedLockVersion = (int) $this->getRecord()->lock_version;
    }

    /**
     * HP-8b: outside the goal-setting period the goal (and its key results, which the form saves before the goal)
     * cannot change unless the user administers performance.
     */
    protected function beforeValidate(): void
    {
        try {
            app(Goals::class)->assertGoalPeriodOpen($this->getRecord()->performance_cycle_id, auth()->user(), $this->getRecord()->employee_id);
            // HP-8d: KPI field rights and the goal sheet policy's KPI rules (the form saves key results before the goal).
            $goal = $this->getRecord();
            $this->keyResultsChanged = app(Goals::class)->keyResultRowsChanged($goal, array_values((array) ($this->data['keyResults'] ?? [])));
            if ($goal->level === 'employee' && $goal->employee_id) {
                app(Goals::class)->assertKeyResultRows(Employee::query()->find($goal->employee_id), $goal, (float) ($this->data['weight'] ?? $goal->weight), array_values((array) ($this->data['keyResults'] ?? [])), auth()->user());
            }
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(Goals::class)->update($record, $data, $this->loadedLockVersion, auth()->user(), keyResultsChanged: $this->keyResultsChanged);
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
        app(Goals::class)->keyResultsSaved($this->getRecord()->refresh());
    }
}
