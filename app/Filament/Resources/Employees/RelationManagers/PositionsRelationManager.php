<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Effective-dated position history. Read-only: use "Transfer / promote" on the page header. */
class PositionsRelationManager extends RelationManager
{
    protected static string $relationship = 'positions';

    protected static ?string $title = 'Employment';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effective_from')->label('From')->date()->sortable(),
                TextColumn::make('effective_to')->label('To')->date()->placeholder('Current'),
                TextColumn::make('change_type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.people.position_change_types.{$state}", $state)),
                TextColumn::make('company.name')->label('Company')->placeholder('—'),
                TextColumn::make('designation.name')->label('Designation')->placeholder('—'),
                TextColumn::make('department.name')->label('Department')->placeholder('—'),
                TextColumn::make('location.name')->label('Location')->placeholder('—')->toggleable(),
                TextColumn::make('level.name')->label('Level')->placeholder('—')->toggleable(),
                TextColumn::make('grade.name')->label('Grade')->placeholder('—')->toggleable(),
                TextColumn::make('employmentType.name')->label('Type')->placeholder('—')->toggleable(),
                TextColumn::make('reason')->placeholder('—')->wrap()->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc');
    }
}
