<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentProfile;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Talent (Phase 9): pool memberships and the latest review outcome. talent.view within scope; never the employee themself. */
class TalentRelationManager extends RelationManager
{
    protected static string $relationship = 'talentPoolMemberships';

    protected static ?string $title = 'Talent';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new TalentPoolMembership(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $profile = TalentProfile::query()->where('employee_id', $this->getOwnerRecord()->id)->first();

        return $table
            ->description('Latest talent review outcome: '.($profile?->latest_review_outcome ? config("peopleos.talent.review_decisions.{$profile->latest_review_outcome}", $profile->latest_review_outcome) : 'none').'. Confidential notes are not shown here.')
            ->modifyQueryUsing(fn ($query) => $query->with('pool'))
            ->columns([
                TextColumn::make('pool.name')->label('Talent pool'),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->date()->placeholder('—'),
                TextColumn::make('reason')->wrap()->limit(80),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('id', 'desc');
    }
}
