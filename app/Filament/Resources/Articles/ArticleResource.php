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

/**
 * Knowledge base (§50, Phase 12). Writers manage under "Knowledge"; readers browse under "Me".
 *
 * Writers: an article moves Draft → Review (a kb.review holder, never the author, approves or returns
 * it) → Approved → Published (a new immutable version) → Archived. A published article is changed by
 * starting a revision; the published version keeps being served meanwhile.
 *
 * Readers see only the published version of articles addressed to them, never a working copy.
 */
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

    /** What a reader is shown: the published version (writers see the working copy and its status). */
    public static function content(Article $record): array
    {
        if (auth()->user()?->can('kb.manage')) {
            return ['title' => $record->title, 'summary' => $record->summary, 'body' => $record->body, 'label' => 'Working copy · '.(Article::STATUSES[$record->status] ?? $record->status).($record->published_version ? ' · v'.$record->published_version.' published' : '')];
        }
        $version = app(KnowledgeBase::class)->publishedVersion($record);

        return ['title' => $version?->title ?? $record->title, 'summary' => $version?->summary, 'body' => $version?->body ?? '', 'label' => 'Version '.($version?->version ?? '—').($version?->published_at ? ' · published '.$version->published_at->toDateString() : '')];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Article $record) => self::content($record)['title'])->description(fn (Article $record) => config("peopleos.kb.categories.{$record->category}").' · '.self::content($record)['label'].($record->effective_from ? ' · effective '.$record->effective_from->toDateString() : ''))->schema([
                TextEntry::make('summary')->hiddenLabel()->placeholder('')->state(fn (Article $record) => self::content($record)['summary'])->visible(fn (Article $record) => filled(self::content($record)['summary'])),
                TextEntry::make('body')->hiddenLabel()->markdown()->state(fn (Article $record) => self::content($record)['body']),
                TextEntry::make('tags')->badge()->placeholder('')->visible(fn (Article $record) => ! empty($record->tags)),
                TextEntry::make('review_note')->label('Review note')->placeholder('—')->visible(fn (Article $record) => auth()->user()?->can('kb.manage') && filled($record->review_note)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $writer = auth()->user()->can('kb.manage');

        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap()
                    ->state(fn (Article $record) => $writer ? $record->title : self::content($record)['title'])
                    ->description(fn (Article $record) => $writer ? $record->summary : self::content($record)['summary']),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.kb.categories.{$state}", $state)),
                TextColumn::make('published_version')->label('Published')->formatStateUsing(fn ($state) => "v{$state}")->placeholder('—')->visible($writer),
                IconColumn::make('requires_acknowledgement')->label('Ack')->boolean(),
                IconColumn::make('is_mandatory_reading')->label('Mandatory')->boolean(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Article::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'published' => 'success', 'approved' => 'info', 'archived' => 'gray', default => 'warning'
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
                EditAction::make()->visible(fn (Article $record) => $writer && $record->status === 'draft'),
                Action::make('submitReview')->label('Submit for review')->icon('heroicon-m-paper-airplane')
                    ->visible(fn (Article $record) => $writer && $record->status === 'draft')
                    ->requiresConfirmation()->modalDescription('A reviewer (not you) approves or returns it before it can be published.')
                    ->action(fn (Article $record) => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->submitForReview($record, auth()->user()), 'Sent for review')),
                Action::make('review')->label('Review')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (Article $record) => $record->status === 'in_review' && auth()->user()->can('kb.review') && (int) $record->author_id !== (int) auth()->id())
                    ->schema([Toggle::make('approve')->label('Approve for publication')->default(true), Textarea::make('note')->label('Note (required to return)')->rows(2)])
                    ->action(fn (Article $record, array $data) => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->review($record, auth()->user(), (bool) $data['approve'], $data['note'] ?? null), fn ($a) => $a->status === 'approved' ? 'Approved' : 'Returned to the author')),
                Action::make('publish')->label('Publish')->icon('heroicon-m-megaphone')->color('success')
                    ->visible(fn (Article $record) => $writer && $record->status === 'approved')
                    ->requiresConfirmation()->modalDescription('Publishing creates a new immutable version; employees who must acknowledge will be asked again.')
                    ->action(fn (Article $record) => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->publish($record, auth()->user()), fn ($a) => "Published v{$a->published_version}")),
                Action::make('revise')->label('Start a revision')->icon('heroicon-m-pencil')
                    ->visible(fn (Article $record) => $writer && $record->status === 'published')
                    ->requiresConfirmation()->modalDescription('Opens a new draft; readers keep seeing the published version until the revision is approved and published.')
                    ->action(fn (Article $record) => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->startRevision($record, auth()->user()), 'Revision started')),
                Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('gray')
                    ->visible(fn (Article $record) => $writer && $record->status !== 'archived')
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
