<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Services\Assets;
use App\Domain\Employment\Models\Employee;
use App\Filament\Support\AssetActions;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Custody history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'assigner']))
            ->columns([
                TextColumn::make('assigned_on')->date(),
                TextColumn::make('returned_on')->date()->placeholder('Current'),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('condition_out')->label('Out')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('condition_in')->label('In')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('acknowledged_at')->label('Acknowledged')->dateTime()->placeholder('Pending'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'info' : 'gray'),
                TextColumn::make('note')->placeholder('—')->limit(40),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('acknowledge')->label('Acknowledge receipt')->icon('heroicon-m-hand-thumb-up')->color('success')
                    ->visible(fn (AssetAssignment $record) => $record->isActive() && $record->acknowledged_at === null && Employee::query()->where('user_id', auth()->id())->where('id', $record->employee_id)->exists())
                    ->action(fn (AssetAssignment $record) => AssetActions::run(fn () => app(Assets::class)->acknowledge($record), 'Receipt acknowledged')),
            ]);
    }
}
