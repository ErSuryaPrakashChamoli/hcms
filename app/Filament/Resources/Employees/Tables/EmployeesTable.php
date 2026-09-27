<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Domain\Lifecycle\Enums\LifecycleState;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_code')->label('ID')->searchable()->sortable(),
                TextColumn::make('person.display_name')
                    ->label('Name')
                    ->searchable(['people.first_name', 'people.last_name', 'people.preferred_name'])
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->join('people', 'people.id', '=', 'employees.person_id')
                        ->orderBy('people.first_name', $direction)
                        ->select('employees.*'))
                    ->weight('medium'),
                TextColumn::make('currentPosition.designation.name')->label('Designation')->placeholder('—'),
                TextColumn::make('currentPosition.department.name')->label('Department')->placeholder('—'),
                TextColumn::make('currentPosition.company.name')->label('Company')->placeholder('—')->toggleable(),
                TextColumn::make('lifecycle_state')->badge()->label('Status'),
                TextColumn::make('joining_date')->date()->sortable()->toggleable(),
                TextColumn::make('work_email')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('lifecycle_state')->label('Status')->options(LifecycleState::class)->multiple(),
                SelectFilter::make('company')
                    ->relationship('currentPosition.company', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('department')
                    ->relationship('currentPosition.department', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('employee_code')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
