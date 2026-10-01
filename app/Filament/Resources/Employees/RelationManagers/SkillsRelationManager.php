<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Services\SkillProfiles;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Skills: sourced skill history (verified vs self-declared), gaps on the pinned scale. Evidence stays out of the table. */
class SkillsRelationManager extends RelationManager
{
    protected static string $relationship = 'employeeSkills';

    protected static ?string $title = 'Skills';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new EmployeeSkill(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $gaps = collect(app(SkillProfiles::class)->gaps($this->getOwnerRecord()));

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['skill', 'scaleVersion']))
            ->description($gaps->isEmpty() ? 'No open skill gaps.' : 'Gaps: '.$gaps->map(fn ($g) => $g['skill'].' ('.$g['gap'].')')->implode(', '))
            ->columns([
                TextColumn::make('skill.name')->label('Skill')->searchable(),
                TextColumn::make('current_level')->label('Level')->state(fn (EmployeeSkill $record) => $record->current_level === null ? 'Target only' : $record->scaleVersion?->labelFor((float) $record->current_level)),
                TextColumn::make('target_level')->label('Target')->placeholder('—'),
                TextColumn::make('source')->badge()->formatStateUsing(fn (string $state) => EmployeeSkill::SOURCES[$state] ?? $state),
                IconColumn::make('is_verified')->label('Verified')->boolean(),
                TextColumn::make('valid_from')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'current' ? 'success' : 'gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(['current' => 'Current', 'superseded' => 'History'])->default('current')]);
    }
}
