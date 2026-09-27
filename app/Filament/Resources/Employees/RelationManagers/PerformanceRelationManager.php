<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Filament\Resources\Appraisals\AppraisalResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Performance: appraisal history plus a goals summary in the description. */
class PerformanceRelationManager extends RelationManager
{
    protected static string $relationship = 'appraisals';

    protected static ?string $title = 'Performance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new Appraisal(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();
        $goals = Goal::query()->where('employee_id', $employee->id);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['cycle', 'manager.person', 'reviews']))
            ->description(sprintf('Goals: %d active, %d completed · average progress %.0f%%', (clone $goals)->where('status', 'active')->count(), (clone $goals)->where('status', 'completed')->count(), (clone $goals)->where('status', 'active')->avg('progress') ?? 0))
            ->columns([
                TextColumn::make('cycle.name')->label('Cycle'),
                TextColumn::make('cycle.period_end')->label('Period end')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state) => AppraisalResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.performance.appraisal_statuses.{$state}", $state)),
                TextColumn::make('manager_rating')->label('Manager')->placeholder('—'),
                TextColumn::make('final_rating')->label('Final')->placeholder('—')->weight('bold'),
                TextColumn::make('final_label')->label('Label')->placeholder('—'),
                TextColumn::make('promotion_recommended')->label('Promotion')->formatStateUsing(fn ($state) => $state ? 'Recommended' : '—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([Action::make('open')->label('Open')->url(fn (Appraisal $record) => AppraisalResource::getUrl('view', ['record' => $record]))]);
    }
}
