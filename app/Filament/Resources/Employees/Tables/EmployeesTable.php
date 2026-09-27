<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
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
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('person', fn (Builder $q) => $q
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('preferred_name', 'like', "%{$search}%")))
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->join('people', 'people.id', '=', 'employees.person_id')
                        ->orderBy('people.first_name', $direction)
                        ->select('employees.*'))
                    ->weight('medium'),
                TextColumn::make('currentPosition.designation.name')->label('Designation')->placeholder('—'),
                TextColumn::make('currentPosition.department.name')->label('Department')->placeholder('—'),
                TextColumn::make('currentPosition.company.name')->label('Company')->placeholder('—')->toggleable(),
                TextColumn::make('currentPosition.location.name')->label('Location')->placeholder('—')->toggleable(),
                TextColumn::make('currentManager.manager.person.display_name')->label('Manager')->placeholder('—')->toggleable(),
                TextColumn::make('currentPosition.employmentType.name')->label('Type')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('location')->relationship('currentPosition.location', 'name')->searchable()->preload(),
                SelectFilter::make('designation')->relationship('currentPosition.designation', 'name')->searchable()->preload(),
                SelectFilter::make('grade')->relationship('currentPosition.grade', 'name')->preload(),
                SelectFilter::make('employment_type')->label('Employment type')->relationship('currentPosition.employmentType', 'name')->preload(),
                SelectFilter::make('employee_category')->label('Category')->relationship('currentPosition.employeeCategory', 'name')->preload(),
                SelectFilter::make('work_mode')->label('Work mode')->relationship('currentPosition.workMode', 'name')->preload(),
                SelectFilter::make('manager')
                    ->label('Manager')
                    ->options(fn () => Employee::query()->whereHas('directReports')->with('person')->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->person?->display_name ?? $e->employee_code])->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->whereHas('currentManager', fn (Builder $r) => $r->where('manager_id', $id)))),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->defaultSort('employee_code')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
