<?php

namespace App\Filament\Resources\Announcements;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Services\Communications;
use App\Domain\Knowledge\Models\Article;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Communication centre (§51): compose and publish to a targeted audience. Readers use the Announcements feed page. */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('communication.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')->columns(3)->schema([
                TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
                Select::make('type')->options(config('peopleos.communication.types'))->default('announcement')->required(),
                MarkdownEditor::make('body')->required()->columnSpanFull(),
                Select::make('article_id')->label('Link a knowledge article')->placeholder('—')->searchable()->options(fn () => Article::query()->where('status', 'published')->orderBy('title')->pluck('title', 'id')->all()),
                DateTimePicker::make('publish_at')->native(false)->placeholder('Immediately on publish'),
                DateTimePicker::make('expires_at')->native(false)->placeholder('Never'),
            ]),
            Section::make('Audience & options')->columns(2)->schema([
                Toggle::make('is_pinned')->label('Pin to the top'),
                Toggle::make('requires_acknowledgement')->label('Employees must acknowledge'),
                RuleConditionsSchema::repeater()->statePath('audience')->label('Audience (empty = everyone)')->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.communication.types.{$state}", $state)),
                TextColumn::make('audience')->label('Audience')->state(fn (Announcement $record) => empty($record->audience) ? 'Everyone' : RuleConditionsSchema::describe($record->audience))->wrap(),
                IconColumn::make('is_pinned')->label('Pinned')->boolean(),
                IconColumn::make('requires_acknowledgement')->label('Ack')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'published' => 'success', 'archived' => 'gray', default => 'warning'
                }),
                TextColumn::make('reads')->label('Read / ack')->state(function (Announcement $record) {
                    $s = app(Communications::class)->stats($record);

                    return "{$s['read']} / {$s['acknowledged']} of {$s['audience']}";
                }),
                TextColumn::make('publish_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder('Never')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(Announcement::STATUSES), SelectFilter::make('type')->options(config('peopleos.communication.types'))])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')->label('Publish')->icon('heroicon-m-paper-airplane')->color('success')
                    ->visible(fn (Announcement $record) => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->action(fn (Announcement $record) => ServiceDeskActions::run(fn () => app(Communications::class)->publish($record, auth()->user()), 'Published')),
                Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('gray')
                    ->visible(fn (Announcement $record) => $record->status === 'published')
                    ->requiresConfirmation()
                    ->action(fn (Announcement $record) => ServiceDeskActions::run(fn () => app(Communications::class)->archive($record, auth()->user()), 'Archived')),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
