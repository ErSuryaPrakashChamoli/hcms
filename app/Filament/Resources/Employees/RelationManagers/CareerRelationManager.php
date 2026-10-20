<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Talent\Services\TalentAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Career (Phase 9): career goals, plus current aspirations when the viewer may see them. */
class CareerRelationManager extends RelationManager
{
    protected static string $relationship = 'careerGoals';

    protected static ?string $title = 'Career';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new CareerGoal(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();
        $aspirations = app(TalentAccess::class)->mayViewCareer(auth()->user(), $employee->id, 'aspirations')
            ? CareerAspirationEntry::query()->with('targetDesignation')->where('employee_id', $employee->id)->where('status', 'current')->get()
                ->map(fn ($a) => config("peopleos.career.aspiration_terms.{$a->term}").': '.($a->targetDesignation?->name ?? $a->aspiration))->implode(' · ')
            : null;

        return $table
            ->description($aspirations === null ? 'Aspirations are not shared with you.' : 'Aspirations: '.($aspirations ?: 'none recorded'))
            ->modifyQueryUsing(fn ($query) => $query->with('targetDesignation'))
            ->columns([
                TextColumn::make('title')->wrap(),
                TextColumn::make('goal_type')->label('Type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.career.goal_types.{$state}", $state)),
                TextColumn::make('targetDesignation.name')->label('Target role')->placeholder('—'),
                TextColumn::make('target_date')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.career.goal_statuses.{$state}", $state)),
            ])
            ->defaultSort('id', 'desc');
    }
}
