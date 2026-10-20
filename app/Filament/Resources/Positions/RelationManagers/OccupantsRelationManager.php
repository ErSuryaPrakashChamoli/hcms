<?php

namespace App\Filament\Resources\Positions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Position → Occupancy history: the employment rows that occupied this position (read from the
 * authoritative employee_positions; assign and vacate from the employee's record).
 */
class OccupantsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Occupancy';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee'),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->date()->placeholder('current'),
                TextColumn::make('fte')->label('FTE')->placeholder('position FTE'),
                TextColumn::make('change_type')->badge()->color('gray'),
            ])
            ->defaultSort('effective_from', 'desc')
            ->emptyStateHeading('Never occupied')->emptyStateDescription('Assign an employee from their Employee 360 page ("Transfer / promote" → position).');
    }
}
