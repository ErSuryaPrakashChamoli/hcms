<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Services\Assets;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\AssetActions;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Assets: everything ever in this person's custody; active rows are the exit-clearance list. */
class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assetAssignments';

    protected static ?string $title = 'Assets';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('asset.view') || $user->can('asset.assign') || $user->can('asset.manage') || ($user->can('asset.own') && $ownerRecord->user_id === $user->id));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset.category']))
            ->columns([
                TextColumn::make('asset.asset_tag')->label('Tag'),
                TextColumn::make('asset.name')->label('Asset')->description(fn (AssetAssignment $record) => $record->asset->category?->name),
                TextColumn::make('asset.serial_number')->label('Serial')->placeholder('—'),
                TextColumn::make('assigned_on')->date(),
                TextColumn::make('returned_on')->date()->placeholder('In custody'),
                TextColumn::make('acknowledged_at')->label('Acknowledged')->dateTime()->placeholder('Pending'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'info' : 'gray'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('acknowledge')->label('Acknowledge')->icon('heroicon-m-hand-thumb-up')->color('success')
                    ->visible(fn (AssetAssignment $record) => $record->isActive() && $record->acknowledged_at === null && $this->getOwnerRecord()->user_id === auth()->id())
                    ->action(fn (AssetAssignment $record) => AssetActions::run(fn () => app(Assets::class)->acknowledge($record), 'Receipt acknowledged')),
                Action::make('open')->label('Open')->url(fn (AssetAssignment $record) => AssetResource::getUrl('view', ['record' => $record->asset_id]))->visible(fn () => auth()->user()->can('asset.view') || auth()->user()->can('asset.manage') || auth()->user()->can('asset.assign')),
            ]);
    }
}
