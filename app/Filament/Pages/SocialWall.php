<?php

namespace App\Filament\Pages;

use App\Domain\Recognition\Models\WallComment;
use App\Domain\Recognition\Models\WallPost;
use App\Domain\Recognition\Services\SocialWall as Wall;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity HP-9e (Engage → Social wall): the wall — posts newest first (pinned on top), reactions, comments
 * where allowed, "Post" for HR (wall.post), "Report" for everyone, hide / restore / pin for moderators (wall.moderate).
 * Every rule is SocialWall's; the page only shows what it returns.
 */
class SocialWall extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $navigationLabel = 'Social wall';

    protected static ?string $title = 'Social wall';

    protected static ?string $slug = 'social-wall';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.social-wall';

    public int $limit = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->can('wall.moderate') ? 'Engagement' : 'Me';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(Wall::class)->canView($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        $festive = app(Wall::class)->festiveHoliday(auth()->user());

        return $festive !== null ? 'Happy '.$festive.'!' : 'Recognition, celebrations, announcements and news from around the organisation.';
    }

    /** @return Collection<int, WallPost> */
    public function posts(): Collection
    {
        return app(Wall::class)->feed(auth()->user(), $this->limit);
    }

    /** @return array<int, Collection<int, WallComment>> post id => comments the viewer may read */
    public function commentsFor(Collection $posts): array
    {
        $moderator = app(Wall::class)->moderates(auth()->user());

        return WallComment::query()->with('user')->whereIn('wall_post_id', $posts->pluck('id'))->when(! $moderator, fn ($q) => $q->where('status', 'published'))
            ->orderBy('id')->get()->groupBy('wall_post_id')->all();
    }

    public function commentsAllowed(WallPost $post): bool
    {
        return app(Wall::class)->commentsAllowed($post);
    }

    public function attachmentUrl(WallPost $post, int $index): ?string
    {
        return app(Wall::class)->attachmentsAllowed() ? ParityFileController::url('wall-post', $post, $index) : null;
    }

    public function loadMore(): void
    {
        $this->limit = min(200, $this->limit + 20);
    }

    /** Choosing the reaction one already gave takes it back. */
    public function react(int $post, string $reaction = 'like'): void
    {
        $this->attempt(function () use ($post, $reaction) {
            $record = $this->post($post);
            $mine = $record->reactions()->where('user_id', auth()->id())->value('reaction');
            app(Wall::class)->react($record, auth()->user(), $mine === $reaction ? null : $reaction);
        });
    }

    public function removeComment(int $comment): void
    {
        $this->attempt(function () use ($comment) {
            app(Wall::class)->removeComment(WallComment::query()->findOrFail($comment), auth()->user());
            Notification::make()->success()->title('Comment removed')->send();
        });
    }

    public function pin(int $post, bool $pinned = true): void
    {
        $this->attempt(fn () => app(Wall::class)->pin($this->post($post), auth()->user(), $pinned));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')->label('Post')->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn () => auth()->user()?->can('create', WallPost::class) ?? false)
                ->modalHeading('Write on the social wall')
                ->schema([
                    TextInput::make('title')->required()->maxLength(200),
                    Textarea::make('body')->label('Text')->rows(5)->maxLength(Wall::BODY_MAX),
                    FileUpload::make('attachments')->label('Attachments')->multiple()->maxFiles(3)->disk(config('peopleos.documents.disk', 'local'))
                        ->directory(fn () => Wall::directory())->visibility('private')->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                        ->maxSize((int) config('peopleos.documents.max_kb', 10240))->visible(fn () => app(Wall::class)->attachmentsAllowed()),
                    Toggle::make('pinned')->label('Pin to the top')->visible(fn () => auth()->user()?->can('wall.moderate') ?? false),
                ])
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    app(Wall::class)->post(auth()->user(), (string) $data['title'], $data['body'] ?? null, array_values((array) ($data['attachments'] ?? [])), (bool) ($data['pinned'] ?? false));
                    Notification::make()->success()->title('Posted on the wall')->send();
                })),
        ];
    }

    public function commentAction(): Action
    {
        return Action::make('comment')->label('Comment')->modalHeading('Comment')
            ->schema([Textarea::make('body')->label('Comment')->required()->rows(3)->maxLength(WallComment::MAX)])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(Wall::class)->comment($this->post((int) ($arguments['post'] ?? 0)), auth()->user(), (string) $data['body'])));
    }

    public function reportAction(): Action
    {
        return Action::make('report')->label('Report')->modalHeading('Report to the wall moderators')
            ->modalDescription('The moderators are told and review it. Your report is not shown on the wall.')
            ->schema([Textarea::make('reason')->label('What is wrong?')->rows(3)->maxLength(300)])
            ->action(fn (array $data, array $arguments) => $this->attempt(function () use ($data, $arguments) {
                $item = isset($arguments['comment']) ? WallComment::query()->findOrFail((int) $arguments['comment']) : $this->post((int) ($arguments['post'] ?? 0));
                app(Wall::class)->report($item, auth()->user(), $data['reason'] ?? null);
                Notification::make()->success()->title('Reported to the moderators')->send();
            }));
    }

    public function hideAction(): Action
    {
        return Action::make('hide')->label('Hide')->color('danger')->modalHeading('Hide from the wall')
            ->modalDescription('It stays on record, marked hidden with your reason; you can restore it.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(fn (array $data, array $arguments) => $this->attempt(function () use ($data, $arguments) {
                app(Wall::class)->hide($this->item($arguments), auth()->user(), (string) $data['reason']);
                Notification::make()->success()->title('Hidden from the wall')->send();
            }));
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')->label('Restore')->modalHeading('Restore on the wall')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(fn (array $data, array $arguments) => $this->attempt(function () use ($data, $arguments) {
                app(Wall::class)->restore($this->item($arguments), auth()->user(), (string) $data['reason']);
                Notification::make()->success()->title('Back on the wall')->send();
            }));
    }

    private function post(int $id): WallPost
    {
        return WallPost::query()->findOrFail($id);
    }

    /** @param  array<string, mixed>  $arguments */
    private function item(array $arguments): WallPost|WallComment
    {
        return isset($arguments['comment']) ? WallComment::query()->findOrFail((int) $arguments['comment']) : $this->post((int) ($arguments['post'] ?? 0));
    }

    private function attempt(callable $work): void
    {
        try {
            $work();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
        }
    }
}
