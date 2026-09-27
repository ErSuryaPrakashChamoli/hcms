<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Bgv\Models\BgvCase;
use App\Filament\Resources\BgvCases\BgvCaseResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BgvRelationManager extends RelationManager
{
    protected static string $relationship = 'bgvCases';

    protected static ?string $title = 'Verification';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('bgv.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Case'),
                TextColumn::make('provider')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.bgv.providers.{$state}.label", $state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => BgvCase::STATUSES[$state] ?? $state),
                TextColumn::make('overall_result')->label('Result')->badge()->color(fn (string $state) => match ($state) {
                    'clear' => 'success', 'discrepancy' => 'warning', 'failed' => 'danger', default => 'gray',
                })->formatStateUsing(fn (string $state) => BgvCase::RESULTS[$state] ?? $state),
                TextColumn::make('checks')->label('Checks')->state(fn (BgvCase $record) => $record->checks()->get()->map(fn ($c) => config("peopleos.bgv.check_types.{$c->type}", $c->type).': '.$c->status)->all())->listWithLineBreaks(),
                TextColumn::make('initiated_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()->url(fn (BgvCase $record) => BgvCaseResource::getUrl('view', ['record' => $record]))]);
    }
}
