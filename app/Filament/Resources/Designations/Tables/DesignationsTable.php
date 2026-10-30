<?php

namespace App\Filament\Resources\Designations\Tables;

use App\Domain\Organisation\Enums\ActiveStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DesignationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('level.name')->label('Level')->sortable()->toggleable()->placeholder('—'),
                TextColumn::make('grade.name')->label('Grade')->sortable()->toggleable()->placeholder('—'),
                TextColumn::make('jobFamily.name')->label('Job family')->sortable()->toggleable()->placeholder('—'),
                TextColumn::make('department.name')->label('Department')->sortable()->toggleable()->placeholder('—'),
                TextColumn::make('defaultReportingLevel.name')->label('Default reporting level')->sortable()->toggleable()->placeholder('—'),
                // HROne setup parity CA-2: the designation card's experience range, wage skill class and hazardous flag.
                TextColumn::make('experience')->label('Experience (years)')->toggleable()->placeholder('—')
                    ->state(fn ($record) => $record->min_experience_years === null && $record->max_experience_years === null ? null
                        : (string) (float) ($record->min_experience_years ?? 0).' – '.($record->max_experience_years === null ? 'any' : (string) (float) $record->max_experience_years)),
                TextColumn::make('wage_skill_category')->label('Wage skill category')->toggleable(isToggledHiddenByDefault: true)->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => config("peopleos.compliance.wage_skill_categories.{$state}", $state)),
                IconColumn::make('is_hazardous')->label('Hazardous')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge(),
                TextColumn::make('effective_from')->date()->sortable()->placeholder('Open')->toggleable(),
                TextColumn::make('effective_to')->date()->sortable()->placeholder('Open')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
