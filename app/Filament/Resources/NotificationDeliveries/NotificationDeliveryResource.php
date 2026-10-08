<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\NotificationRecovery;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
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
            TextEntry::make('resend_count')->label('Sent again')->visible(fn (NotificationDelivery $record) => $record->resend_count > 0),
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
            ->recordActions([
                ViewAction::make(),
                // SaaS.8: a failed delivery sent again, with a reason (audited; once, even if two people click at once).
                Action::make('resend')
                    ->label('Send again')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (NotificationDelivery $record) => auth()->user()?->can('resend', $record) ?? false)
                    ->modalDescription(fn (NotificationDelivery $record) => $record->error === NotificationRecovery::UNKNOWN_OUTCOME
                        ? 'The first attempt may have been received: the worker stopped while sending. Send again only if the recipient confirms they did not get it.'
                        : 'Sends this message again to the same recipient on the same channel.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)])
                    ->action(function (NotificationDelivery $record, array $data, NotificationRecovery $recovery) {
                        try {
                            $recovery->resend($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Sent again')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationDeliveries::route('/')];
    }
}
