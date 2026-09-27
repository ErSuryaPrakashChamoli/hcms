<?php

namespace App\Domain\Knowledge\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Knowledge base (§50): publish with versions, audience targeting, read and acknowledgement tracking, search. */
final class KnowledgeBase
{
    public function __construct(private readonly RuleEngine $rules, private readonly EmployeeRuleContext $context, private readonly AuditRecorder $audit) {}

    public function publish(Article $article, ?User $actor = null, ?string $reason = null): Article
    {
        if (trim($article->body) === '') {
            throw new RuntimeException('The article has no content.');
        }

        return DB::transaction(function () use ($article, $actor, $reason) {
            $version = $article->status === 'published' ? $article->version + 1 : $article->version;
            $article->update(['status' => 'published', 'version' => $version, 'published_at' => now()]);
            $article->versions()->create(['version' => $version, 'title' => $article->title, 'body' => $article->body, 'effective_from' => $article->effective_from, 'published_by' => $actor?->id ?? auth()->id(), 'published_at' => now()]);
            $this->audit->record(AuditAction::PolicyPublished, 'kb', $article, [['field' => 'version', 'before' => $version - 1, 'after' => $version]], $reason, actor: $actor);

            if ($article->requires_acknowledgement) {
                $ids = $this->audienceEmployees($article)->pluck('user_id')->filter()->all();
                ServiceDeskEvent::dispatch('kb.article.published', $article, ['title' => $article->title, 'mandatory' => $article->is_mandatory_reading], $ids);
            }

            return $article->refresh();
        });
    }

    public function archive(Article $article, ?User $actor = null, ?string $reason = null): Article
    {
        $article->withAuditReason($reason)->update(['status' => 'archived']);

        return $article;
    }

    public function inAudience(Article $article, Employee $employee): bool
    {
        return empty($article->audience) || $this->rules->matches($article->audience, $this->context->build($employee));
    }

    public function audienceEmployees(Article $article): Collection
    {
        return Employee::query()->with('person')->employed()->get()->filter(fn (Employee $e) => $this->inAudience($article, $e))->values();
    }

    /** Published articles the employee may read, optionally filtered by search term / category. */
    public function visibleTo(Employee $employee, ?string $term = null, ?string $category = null): Collection
    {
        return Article::query()->where('status', 'published')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($term, fn ($q) => $q->where(fn ($s) => $s->where('title', 'like', "%{$term}%")->orWhere('summary', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%")->orWhereJsonContains('tags', $term)))
            ->orderByDesc('is_mandatory_reading')->orderBy('title')->get()
            ->filter(fn (Article $a) => $this->inAudience($a, $employee))->values();
    }

    public function recordRead(Article $article, Employee $employee): ArticleRead
    {
        return ArticleRead::query()->firstOrCreate(['article_id' => $article->id, 'employee_id' => $employee->id, 'version' => $article->version], ['read_at' => now()]);
    }

    public function acknowledge(Article $article, Employee $employee): ArticleRead
    {
        $read = $this->recordRead($article, $employee);
        $read->update(['acknowledged_at' => $read->acknowledged_at ?? now()]);
        $this->audit->record(AuditAction::Approved, 'kb', $article, [], null, metadata: ['acknowledged_by_employee_id' => $employee->id, 'version' => $article->version]);

        return $read;
    }

    /** Published articles needing acknowledgement of the current version by this employee. */
    public function pendingAcknowledgements(Employee $employee): Collection
    {
        return Article::query()->where('status', 'published')->where('requires_acknowledgement', true)->get()
            ->filter(fn (Article $a) => $this->inAudience($a, $employee))
            ->reject(fn (Article $a) => ArticleRead::query()->where('article_id', $a->id)->where('employee_id', $employee->id)->where('version', $a->version)->whereNotNull('acknowledged_at')->exists())
            ->values();
    }

    /** @return array{audience: int, read: int, acknowledged: int} */
    public function stats(Article $article): array
    {
        $audience = $this->audienceEmployees($article)->count();
        $reads = ArticleRead::query()->where('article_id', $article->id)->where('version', $article->version);

        return ['audience' => $audience, 'read' => (clone $reads)->count(), 'acknowledged' => (clone $reads)->whereNotNull('acknowledged_at')->count()];
    }
}
