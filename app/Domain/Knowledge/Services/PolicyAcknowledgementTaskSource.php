<?php

namespace App\Domain\Knowledge\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Filament\Resources\Articles\ArticleResource;
use Illuminate\Support\Collection;

/** Phase 12: policies whose current version the employee must acknowledge. */
final class PolicyAcknowledgementTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly KnowledgeBase $kb) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null || ! $user->hasPermission('kb.view')) {
            return collect();
        }

        return $this->kb->pendingAcknowledgements($employee)->map(fn (Article $a) => new ExperienceTask('knowledge', 'acknowledge', 'Acknowledge: '.$a->title, 'v'.$a->published_version, null, ArticleResource::getUrl('view', ['record' => $a])));
    }
}
