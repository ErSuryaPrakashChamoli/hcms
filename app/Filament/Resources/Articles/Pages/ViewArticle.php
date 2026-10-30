<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ViewArticle extends PeopleViewRecord
{
    protected static string $resource = ArticleResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (($me = ServiceDeskActions::me()) && $this->getRecord()->isPublished()) {
            app(KnowledgeBase::class)->recordRead($this->getRecord(), $me);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne setup parity HP-9a (kb.download_allowed): the published version as a printable page.
            Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => (bool) app(SettingsRepository::class)->get('kb.download_allowed', false) && $this->getRecord()->isPublished())
                ->action(function () {
                    try {
                        ['content' => $content, 'name' => $name, 'mime' => $mime] = app(KnowledgeBase::class)->download($this->getRecord(), auth()->user(), ServiceDeskActions::me());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Cannot download')->body($e->getMessage())->send();

                        return null;
                    }

                    return response()->streamDownload(fn () => print ($content), $name, ['Content-Type' => $mime]);
                }),
            Action::make('acknowledge')->label('I have read and understood')->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(function () {
                    $article = $this->getRecord();
                    $me = ServiceDeskActions::me();

                    return $me && $article->isPublished() && $article->requires_acknowledgement
                        && ! ArticleRead::query()->where('article_id', $article->id)->where('employee_id', $me->id)->where('version', $article->published_version)->whereNotNull('acknowledged_at')->exists();
                })
                ->requiresConfirmation()->modalDescription(fn () => 'Your acknowledgement of version '.$this->getRecord()->published_version.' is recorded with the date, time and source.')
                ->action(fn () => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->acknowledge($this->getRecord(), ServiceDeskActions::me(), 'web', request()->ip()), 'Acknowledged')),
        ];
    }
}
