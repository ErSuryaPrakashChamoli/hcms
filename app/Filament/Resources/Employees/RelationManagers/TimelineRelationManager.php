<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Lifecycle\Support\TimelineCategories;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The People Timeline (blueprint §18). Read-only; entries are written by domain services.
 *
 * Phase 14:
 * - Each entry shows its kind (lifecycle / employment / service / communication / domain event); the
 *   audit trail is separate (Change history / Change Intelligence).
 * - Categories the viewer may not see are filtered out per TimelineCategories, and an exit entry's
 *   description only shows with exit.view.
 */
class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'timelineEntries';

    protected static ?string $title = 'Timeline';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    /** Kept for callers of the Phase 0.3 constant; the full rule set lives in TimelineCategories. */
    public const SENSITIVE_CATEGORIES = TimelineCategories::SENSITIVE;

    public function table(Table $table): Table
    {
        $hidden = TimelineCategories::hiddenFor(auth()->user(), $this->getOwnerRecord());

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->when($hidden !== [], fn (Builder $q) => $q->whereNotIn('category', $hidden)))
            ->columns([
                TextColumn::make('occurred_on')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('kind')->label('Kind')->state(fn ($record) => TimelineCategories::KINDS[TimelineCategories::kind($record->category)])->badge()
                    ->color(fn ($record) => match (TimelineCategories::kind($record->category)) {
                        'lifecycle' => 'success', 'employment' => 'warning', 'service' => 'info', 'communication' => 'primary', default => 'gray'
                    }),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn (string $state) => TimelineCategories::label($state)),
                TextColumn::make('title')->weight('medium')->wrap()
                    ->description(fn ($record) => TimelineCategories::showsDescription(auth()->user(), $record->category) ? $record->description : null),
                TextColumn::make('actor.name')->label('By')->placeholder('System')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(collect(TimelineCategories::CATEGORIES)->except($hidden)->map(fn ($c) => $c[0])->all()),
                SelectFilter::make('kind')->options(TimelineCategories::KINDS)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $kind) => $q->whereIn('category', collect(TimelineCategories::CATEGORIES)->filter(fn ($c) => $c[1] === $kind)->keys()->all()))),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->paginated([10, 25, 50]);
    }
}
