<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewArticle extends ViewRecord
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
            Action::make('acknowledge')->label('I have read and understood')->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(function () {
                    $article = $this->getRecord();
                    $me = ServiceDeskActions::me();

                    return $me && $article->isPublished() && $article->requires_acknowledgement
                        && ! ArticleRead::query()->where('article_id', $article->id)->where('employee_id', $me->id)->where('version', $article->version)->whereNotNull('acknowledged_at')->exists();
                })
                ->requiresConfirmation()->modalDescription('Your acknowledgement of this version is recorded with the date and time.')
                ->action(fn () => ServiceDeskActions::run(fn () => app(KnowledgeBase::class)->acknowledge($this->getRecord(), ServiceDeskActions::me()), 'Acknowledged')),
        ];
    }
}
