<?php

namespace App\Filament\Resources\EngagementCampaigns\RelationManagers;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Engagement\Models\CampaignItem;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Services\Campaigns;
use App\Domain\Knowledge\Models\Article;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Phase 13: what a campaign groups — references to items each owned by its own module. */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('position')->label('#'),
                TextColumn::make('item_type')->label('Type')->badge()->formatStateUsing(fn (string $state) => CampaignItem::TYPES[$state] ?? $state),
                TextColumn::make('item')->label('Item')->state(fn (CampaignItem $record) => self::label($record->item_type, (int) $record->item_id))->wrap(),
            ])
            ->headerActions([
                Action::make('add')->label('Add item')->icon('heroicon-m-plus')
                    ->visible(fn () => $this->getOwnerRecord()->status === 'draft' && auth()->user()->can('engagement.manage'))
                    ->schema([
                        Select::make('item_type')->options(CampaignItem::TYPES)->required()->live(),
                        Select::make('item_id')->label('Item')->required()->searchable()->options(fn ($get) => self::options((string) $get('item_type'))),
                    ])
                    ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(Campaigns::class)->addItem($this->getOwnerRecord(), $data['item_type'], (int) $data['item_id'], auth()->user()), 'Item added')),
            ])
            ->recordActions([
                Action::make('remove')->label('Remove')->icon('heroicon-m-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn () => $this->getOwnerRecord()->status === 'draft' && auth()->user()->can('engagement.manage'))
                    ->action(fn (CampaignItem $record) => ServiceDeskActions::run(fn () => app(Campaigns::class)->removeItem($record, auth()->user()), 'Item removed')),
            ]);
    }

    /** @return array<int, string> */
    private static function options(string $type): array
    {
        return match ($type) {
            'survey' => Survey::query()->orderBy('name')->pluck('name', 'id')->all(),
            'announcement' => Announcement::query()->whereNotIn('status', ['archived', 'cancelled'])->orderByDesc('id')->limit(200)->pluck('title', 'id')->all(),
            'article' => Article::query()->where('status', 'published')->orderBy('title')->pluck('title', 'id')->all(),
            'service' => ServiceDefinition::query()->orderBy('name')->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private static function label(string $type, int $id): string
    {
        return (string) match ($type) {
            'survey' => Survey::query()->whereKey($id)->value('name'),
            'announcement' => Announcement::query()->whereKey($id)->value('title'),
            'article' => Article::query()->whereKey($id)->value('title'),
            'service' => ServiceDefinition::query()->whereKey($id)->value('name'),
            default => $id,
        };
    }
}
