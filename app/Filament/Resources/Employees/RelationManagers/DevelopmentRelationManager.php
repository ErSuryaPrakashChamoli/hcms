<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Performance\Contracts\DevelopmentNeedsReader;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Development: plans (never private notes) and open development needs from the Performance boundary. */
class DevelopmentRelationManager extends RelationManager
{
    protected static string $relationship = 'developmentPlans';

    protected static ?string $title = 'Development';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new DevelopmentPlan(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $needs = collect(app(DevelopmentNeedsReader::class)->openNeedsFor($this->getOwnerRecord()->id));

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['items', 'items as open_items_count' => fn ($q) => $q->where('status', 'open')]))
            ->description('Open development needs: '.($needs->isEmpty() ? 'none' : $needs->pluck('title')->implode(', ')))
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => DevelopmentPlan::STATUSES[$state] ?? $state),
                TextColumn::make('items')->label('Items done')->state(fn (DevelopmentPlan $record) => ($record->items_count - $record->open_items_count).' / '.$record->items_count),
                TextColumn::make('target_date')->date()->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }
}
