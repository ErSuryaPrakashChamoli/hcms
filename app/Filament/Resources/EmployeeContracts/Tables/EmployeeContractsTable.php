<?php

namespace App\Filament\Resources\EmployeeContracts\Tables;

use App\Domain\Employment\Models\EmployeeContract;
use App\Domain\Partners\Models\Vendor;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeContractsTable
{
    public static function configure(Table $table): Table
    {
        $today = fn () => now()->toDateString();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person', 'vendor']))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee')->label('Employee')->state(fn (EmployeeContract $record) => $record->employee?->person?->full_name),
                TextColumn::make('vendor.name')->label('Agency')->placeholder('Direct'),
                TextColumn::make('contract_number')->label('Contract')->searchable(),
                TextColumn::make('start_date')->label('Starts')->date()->sortable(),
                TextColumn::make('end_date')->label('Ends')->date()->placeholder('Open')->sortable(),
                TextColumn::make('current')->label('Status')->badge()
                    ->state(fn (EmployeeContract $record) => $record->isCurrent() ? 'Current' : ($record->start_date->isFuture() ? 'Upcoming' : 'Ended'))
                    ->color(fn (string $state) => match ($state) {
                        'Current' => 'success', 'Upcoming' => 'info', default => 'gray'
                    }),
            ])
            ->filters([
                SelectFilter::make('vendor_id')->label('Agency')->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('current')->label('Current')
                    ->queries(
                        true: fn (Builder $q) => $q->where('start_date', '<=', $today())->where(fn (Builder $w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $today())),
                        false: fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('start_date', '>', $today())->orWhere('end_date', '<', $today())),
                    ),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No contracts yet')
            ->emptyStateDescription('Record the contracts of contract staff here, or upload them from Data import → Workforce → Contract.');
    }
}
