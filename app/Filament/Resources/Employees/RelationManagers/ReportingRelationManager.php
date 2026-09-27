<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Reporting lines over time. Read-only: use "Change manager" on the page header. */
class ReportingRelationManager extends RelationManager
{
    protected static string $relationship = 'reportingRelationships';

    protected static ?string $title = 'Organisation';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('manager.person'))
            ->columns([
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.people.reporting_types.{$state}", $state)),
                TextColumn::make('manager.employee_code')->label('Manager')->formatStateUsing(fn ($state, $record) => $record->manager?->auditLabel()),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Current'),
                TextColumn::make('reason')->placeholder('—')->wrap()->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc');
    }
}
