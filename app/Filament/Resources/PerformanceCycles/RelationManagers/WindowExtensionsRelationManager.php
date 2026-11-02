<?php

namespace App\Filament\Resources\PerformanceCycles\RelationManagers;

use App\Domain\Performance\Models\PerformanceWindowExtension;
use App\Domain\Performance\Services\PerformanceWindows;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity GO-2: the window extensions of a cycle (who got more time for which stage, until when, why, by whom) —
 * read-only and append-only; "Extend window" on the cycle adds one. For performance HR.
 */
class WindowExtensionsRelationManager extends RelationManager
{
    protected static string $relationship = 'windowExtensions';

    protected static ?string $title = 'Window extensions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('performance.view') || $user->can('performance.manage'));
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'creator']))
            ->columns([
                TextColumn::make('stage')->label('Window')->badge()->color('gray')->formatStateUsing(fn (string $state) => PerformanceWindows::stageLabel($state)),
                TextColumn::make('employee.person.full_name')->label('For')->placeholder('Everyone in the cycle')
                    ->description(fn (PerformanceWindowExtension $record) => $record->employee?->employee_code),
                TextColumn::make('previous_ends_on')->label('Was until')->date()->placeholder('—'),
                TextColumn::make('ends_on')->label('Now until')->date(),
                TextColumn::make('reason')->wrap()->limit(120),
                TextColumn::make('creator.name')->label('By')->placeholder('—'),
                TextColumn::make('created_at')->label('On')->dateTime(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('stage')->label('Window')->options(collect(PerformanceWindows::STAGES)->mapWithKeys(fn ($s) => [$s => PerformanceWindows::stageLabel($s)])->all())]);
    }
}
