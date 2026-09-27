<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Onboarding\Models\OnboardingTask;
use App\Filament\Support\OnboardingTaskActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** The employee's current onboarding plan as a checklist grouped by phase. */
class OnboardingRelationManager extends RelationManager
{
    protected static string $relationship = 'onboardingPlans';

    protected static ?string $title = 'Onboarding';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('onboarding.view') || auth()->user()?->can('onboarding.act');
    }

    public function getRelationship(): Relation|Builder
    {
        $plan = $this->getOwnerRecord()->onboardingPlan;

        return $plan ? $plan->tasks() : OnboardingTask::query()->whereRaw('1 = 0');
    }

    public function table(Table $table): Table
    {
        $plan = $this->getOwnerRecord()->onboardingPlan;

        return $table
            ->heading($plan ? sprintf('%s · %d%% complete · %s', $plan->template?->name ?? 'Onboarding', $plan->progress, OnboardingTask::STATUSES[$plan->status] ?? ucfirst($plan->status)) : 'No onboarding plan yet')
            ->columns([
                TextColumn::make('phase')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.onboarding.phases.{$state}.label", $state)),
                TextColumn::make('title')->weight('medium')->description(fn (OnboardingTask $record) => $record->description)->wrap(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.onboarding.item_types.{$state}", $state)),
                TextColumn::make('owner')->label('Owner')->state(fn (OnboardingTask $record) => $record->ownerLabel()),
                TextColumn::make('due_on')->date()->placeholder('—')->color(fn (OnboardingTask $record) => $record->isOverdue() ? 'danger' : null)->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'completed' => 'success', 'skipped' => 'gray', default => 'warning',
                }),
                TextColumn::make('note')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('completer.name')->label('By')->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(OnboardingTask::STATUSES)])
            ->defaultSort('sort_order')
            ->recordActions(OnboardingTaskActions::forTable())
            ->emptyStateHeading('No onboarding plan')
            ->emptyStateDescription('Start one from the Life events menu, or add an onboarding template that matches this employee.');
    }
}
