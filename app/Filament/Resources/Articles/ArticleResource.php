<?php

namespace App\Filament\Resources\Articles;

use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\Pages\ViewArticle;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Knowledge base (§50). Writers manage under "Knowledge"; readers browse published articles under "Me". */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->can('kb.manage') ? 'Knowledge' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->can('kb.manage') ? 'Articles' : 'Knowledge base';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'summary', 'tags'];
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('kb.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('author');
        if (auth()->user()->can('kb.manage')) {
            return $query;
        }
        $me = ServiceDeskActions::me();
        $ids = $me ? app(KnowledgeBase::class)->visibleTo($me)->pluck('id') : collect();

        return $query->whereIn('id', $ids);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Article')->columns(3)->schema([
                TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
                Select::make('category')->options(config('peopleos.kb.categories'))->default('other')->required(),
                TextInput::make('slug')->maxLength(191)->helperText('Leave empty to derive from the title'),
                DatePicker::make('effective_from')->native(false),
                TagsInput::make('tags'),
                Textarea::make('summary')->rows(2)->maxLength(500)->columnSpanFull(),
                MarkdownEditor::make('body')->required()->columnSpanFull(),
            ]),
            Section::make('Audience & acknowledgement')->columns(2)->schema([
                Toggle::make('requires_acknowledgement')->label('Employees must acknowledge'),
                Toggle::make('is_mandatory_reading')->label('Mandatory reading (shows in Needs Attention)'),
                RuleConditionsSchema::repeater()->statePath('audience')->label('Audience (empty = everyone)')->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Article $record) => $record->title)->description(fn (Article $record) => config("peopleos.kb.categories.{$record->category}").' · v'.$record->version.($record->effective_from ? ' · effective '.$record->effective_from->toDateString() : '').($record->published_at ? ' · published '.$record->published_at->toDateString() : ''))->schema([
                TextEntry::make('summary')->hiddenLabel()->placeholder('')->visible(fn (Article $record) => filled($record->summary)),
                TextEntry::make('body')->hiddenLabel()->markdown(),
                TextEntry::make('tags')->badge()->placeholder('')->visible(fn (Article $record) => ! empty($record->tags)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $writer = auth()->user()->can('kb.manage');

        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->description(fn (Article $record) => $record->summary)->wrap(),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.kb.categories.{$state}", $state)),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}")->visible($writer),
                IconColumn::make('requires_acknowledgement')->label('Ack')->boolean(),
                IconColumn::make('is_mandatory_reading')->label('Mandatory')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'published' => 'success', 'archived' => 'gray', default => 'warning'
                })->visible($writer),
                TextColumn::make('reads')->label('Read / ack')->state(function (Article $record) {
                    $s = app(KnowledgeBase::class)->stats($record);

                    return "{$s['read']} / {$s['acknowledged']} of {$s['audience']}";
                })->visible($writer),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([SelectFilter::make('category')->options(config('peopleos.kb.categories')), SelectFilter::make('status')->options(Article::STATUSES)->visible($writer)])
            ->recordActions([
                ViewAction::make()->label('Read'),
                EditAction::make()->visible($writer),
                Action::make('publish')->label(fn (Article $record) => $record->status === 'published' ? 'Publish new version' : 'Publish')->icon('heroicon-m-paper-airplane')->color('success')
                    ->visible(fn (Article $record) => $writer && $record->status !== 'archived')
                    ->requiresConfirmation()->modalDescription('Publishing creates a new version; employees who must acknowledge will be asked again.')
                    ->action(fn (Article $record) => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->publish($record, auth()->user()), fn ($a) => "Published v{$a->version}")),
                Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('gray')
                    ->visible(fn (Article $record) => $writer && $record->status === 'published')
                    ->requiresConfirmation()
                    ->action(fn (Article $record) => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->archive($record, auth()->user()), 'Archived')),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'view' => ViewArticle::route('/{record}'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
