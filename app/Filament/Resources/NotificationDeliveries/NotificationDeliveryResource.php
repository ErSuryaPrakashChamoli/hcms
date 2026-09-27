<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Domain\Notifications\Models\NotificationDelivery;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Delivery -> Tracking (§47): every message that left the system. */
class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Delivery log';

    protected static ?string $modelLabel = 'delivery';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('user.name')->label('To')->placeholder('—'),
            TextEntry::make('channel')->badge(),
            TextEntry::make('event')->placeholder('—'),
            TextEntry::make('status')->badge(),
            TextEntry::make('subject')->columnSpanFull(),
            TextEntry::make('body')->columnSpanFull(),
            TextEntry::make('sent_at')->dateTime()->placeholder('—'),
            TextEntry::make('error')->placeholder('—')->color('danger'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('To')->searchable()->placeholder('—'),
                TextColumn::make('channel')->badge()->color('gray'),
                TextColumn::make('event')->badge()->placeholder('—'),
                TextColumn::make('subject')->searchable()->limit(60),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'sent', 'read' => 'success', 'failed' => 'danger', default => 'warning',
                }),
            ])
            ->filters([
                SelectFilter::make('channel')->options(collect(config('peopleos.notifications.channels'))->map(fn ($c) => $c['label'])->all()),
                SelectFilter::make('status')->options(NotificationDelivery::STATUSES),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationDeliveries::route('/')];
    }
}
