<?php

namespace App\Filament\Resources\BulkRuns\RelationManagers;

use App\Domain\Experience\Models\BulkRunItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** HROne parity WO-1: each record of a bulk run and what happened to it (refusals carry the owning service's reason). */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Records';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Record')->placeholder(fn (BulkRunItem $record) => '#'.$record->record_id)->searchable()->wrap(),
                TextColumn::make('outcome')->badge()->formatStateUsing(fn (string $state) => BulkRunItem::OUTCOMES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'done' => 'success', 'refused' => 'warning', default => 'danger',
                    }),
                TextColumn::make('message')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('At')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('outcome')->options(BulkRunItem::OUTCOMES)])
            ->defaultSort('id');
    }
}
