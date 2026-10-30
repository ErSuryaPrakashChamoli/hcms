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
use App\Domain\Knowledge\Models\ArticleVersion;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Knowledge base (§50, Phase 12).
 *
 * **Lifecycle:**
 * - An article moves Draft → Review → Approved → Published → Archived.
 * - Authors (kb.manage) submit for review.
 * - A reviewer (kb.review, never the author) approves or returns it.
 * - Only an approved article is published, as a new immutable version (title, summary, body, content
 *   hash, reviewer, approver).
 * - Readers, search and acknowledgements always use that published version, never the working copy.
 *   A revision is a new draft while the published version keeps being served.
 *
 * **Audience:** the rule-engine audience decides who reads an article (tenant and organisation
 * conditions).
 *
 * **Acknowledgements:**
 * - They are recorded per version, with the version's hash, the source and the IP address.
 * - They are locked, so a concurrent repeat is a no-op.
 * - Version 1 never counts for version 2, and the history is never deleted.
 */
final class KnowledgeBase
{
    public function __construct(private readonly RuleEngine $rules, private readonly EmployeeRuleContext $context, private readonly AuditRecorder $audit) {}

    public function submitForReview(Article $article, User $author): Article
    {
        $this->authorise($author, 'kb.manage');

        return $this->transition($article, ['draft'], function (Article $a) use ($author) {
            if (trim((string) $a->body) === '') {
                throw new RuntimeException('The article has no content.');
            }
            $a->update(['status' => 'in_review', 'submitted_for_review_at' => now(), 'author_id' => $a->author_id ?? $author->id, 'review_note' => null]);
            $reviewers = User::query()->forCurrentTenant()->get()->filter(fn (User $u) => $u->isActive() && $u->hasPermission('kb.review') && (int) $u->id !== (int) ($a->author_id ?? $author->id))->take(25)->pluck('id')->all();
            ServiceDeskEvent::dispatch('kb.article.review_requested', $a, ['title' => $a->title], $reviewers);
        });
    }

    /** A second person approves (or returns with a note) an article in review. */
    public function review(Article $article, User $reviewer, bool $approve, ?string $note = null): Article
    {
        $this->authorise($reviewer, 'kb.review');

        return $this->transition($article, ['in_review'], function (Article $a) use ($reviewer, $approve, $note) {
            if (in_array((int) $reviewer->id, array_filter([(int) $a->author_id]), true)) {
                throw new RuntimeException('The author of an article cannot review or approve it.');
            }
            if (! $approve && blank($note)) {
                throw new RuntimeException('Returning an article needs a note.');
            }
            $a->update($approve
                ? ['status' => 'approved', 'reviewer_id' => $reviewer->id, 'reviewed_at' => now(), 'approved_by' => $reviewer->id, 'approved_at' => now(), 'review_note' => $note]
                : ['status' => 'draft', 'reviewer_id' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note, 'submitted_for_review_at' => null]);
            $this->audit->record($approve ? AuditAction::Approved : AuditAction::Rejected, 'kb', $a, [['field' => 'status', 'before' => 'in_review', 'after' => $a->status]], $note, actor: $reviewer);
        });
    }

    /** Publish an approved article as a new immutable version (readers switch to it). */
    public function publish(Article $article, ?User $actor = null, ?string $reason = null): Article
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new RuntimeException('An article is published by a person.');
        }
        $this->authorise($actor, 'kb.manage');

        return $this->transition($article, ['approved'], function (Article $a) use ($actor, $reason) {
            if (trim((string) $a->body) === '') {
                throw new RuntimeException('The article has no content.');
            }
            $number = (int) ArticleVersion::query()->where('article_id', $a->id)->max('version') + 1;
            $version = ArticleVersion::query()->create([
                'article_id' => $a->id, 'version' => $number, 'title' => $a->title, 'summary' => $a->summary, 'category' => $a->category, 'body' => $a->body,
                'body_hash' => ArticleVersion::hash($a->title, (string) $a->body), 'effective_from' => $a->effective_from, 'published_by' => $actor->id,
                'reviewed_by' => $a->reviewer_id, 'approved_by' => $a->approved_by, 'published_at' => now(),
            ]);
            $before = $a->published_version;
            $a->update(['status' => 'published', 'version' => $number, 'published_version' => $number, 'published_at' => now()]);
            $this->audit->record(AuditAction::KnowledgePublished, 'kb', $a, [['field' => 'published_version', 'before' => $before, 'after' => $number]], $reason, actor: $actor, metadata: ['version_hash' => $version->body_hash, 'approved_by' => $a->approved_by]);

            $ids = $a->requires_acknowledgement ? $this->audienceEmployees($a)->pluck('user_id')->filter()->values()->all() : [];
            ServiceDeskEvent::dispatch('kb.article.published', $a, ['title' => $a->title, 'mandatory' => $a->is_mandatory_reading, 'version' => $number], $ids);
        });
    }

    /** Start a new draft of a published article; the published version stays in force meanwhile. */
    public function startRevision(Article $article, User $author): Article
    {
        $this->authorise($author, 'kb.manage');

        return $this->transition($article, ['published'], function (Article $a) use ($author) {
            $a->update(['status' => 'draft', 'author_id' => $author->id, 'reviewer_id' => null, 'reviewed_at' => null, 'approved_by' => null, 'approved_at' => null, 'review_note' => null]);
        });
    }

    public function archive(Article $article, ?User $actor = null, ?string $reason = null): Article
    {
        if ($actor !== null) {
            $this->authorise($actor, 'kb.manage');
        }

        return $this->transition($article, ['draft', 'in_review', 'approved', 'published'], fn (Article $a) => $a->withAuditReason($reason)->update(['status' => 'archived']));
    }

    /** The immutable version readers see (null when nothing is published). */
    public function publishedVersion(Article $article): ?ArticleVersion
    {
        return $article->published_version ? ArticleVersion::query()->where('article_id', $article->id)->where('version', $article->published_version)->first() : null;
    }

    public function inAudience(Article $article, Employee $employee): bool
    {
        return empty($article->audience) || $this->rules->matches($article->audience, $this->context->build($employee));
    }

    public function audienceEmployees(Article $article): Collection
    {
        return Employee::query()->with('person')->employed()->get()->filter(fn (Employee $e) => $this->inAudience($article, $e))->values();
    }

    /** Readable articles: a published version exists and the article is not archived. */
    public function readable(): Builder
    {
        return Article::query()->whereNotNull('published_version')->where('status', '!=', 'archived');
    }

    /**
     * Published articles the employee may read, optionally filtered by term / category. Search runs
     * on the published versions in SQL, never on unpublished drafts. The audience rule is then applied
     * per article (the published, non-archived set is small and tenant-bound).
     */
    public function visibleTo(Employee $employee, ?string $term = null, ?string $category = null): Collection
    {
        return $this->readable()
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when(filled($term), fn ($q) => $q->whereExists(fn ($v) => $v->from('article_versions as av')->whereColumn('av.article_id', 'articles.id')->whereColumn('av.version', 'articles.published_version')
                ->where(fn ($s) => $s->where('av.title', 'like', "%{$term}%")->orWhere('av.summary', 'like', "%{$term}%")->orWhere('av.body', 'like', "%{$term}%"))))
            ->orderByDesc('is_mandatory_reading')->orderBy('title')->get()
            ->filter(fn (Article $a) => $this->inAudience($a, $employee))->values();
    }

    public function recordRead(Article $article, Employee $employee): ArticleRead
    {
        $version = $this->publishedVersion($article) ?? throw new RuntimeException('The article is not published.');
        try {
            return ArticleRead::query()->firstOrCreate(['article_id' => $article->id, 'employee_id' => $employee->id, 'version' => $version->version], ['read_at' => now(), 'article_version_id' => $version->id]);
        } catch (UniqueConstraintViolationException) {
            return ArticleRead::query()->where(['article_id' => $article->id, 'employee_id' => $employee->id, 'version' => $version->version])->firstOrFail();
        }
    }

    /**
     * Acknowledge the published version. The acknowledgement records the version, its hash, the source
     * and the IP address. It is locked: a concurrent repeat records nothing more.
     */
    public function acknowledge(Article $article, Employee $employee, string $source = 'web', ?string $ip = null): ArticleRead
    {
        if (! $article->requires_acknowledgement || $article->status === 'archived') {
            throw new RuntimeException('This article does not ask for an acknowledgement.');
        }
        if (! $this->inAudience($article, $employee)) {
            throw new RuntimeException('This article is not addressed to you.');
        }
        $version = $this->publishedVersion($article) ?? throw new RuntimeException('The article is not published.');
        $this->recordRead($article, $employee);

        return DB::transaction(function () use ($article, $employee, $source, $ip, $version) {
            $read = ArticleRead::query()->where(['article_id' => $article->id, 'employee_id' => $employee->id, 'version' => $version->version])->lockForUpdate()->firstOrFail();
            if ($read->acknowledged_at !== null) {
                return $read;
            }
            $read->update(['acknowledged_at' => now(), 'article_version_id' => $version->id, 'version_hash' => $version->body_hash, 'source' => $source, 'ip_address' => $ip ?? request()?->ip()]);
            $this->audit->record(AuditAction::PolicyAcknowledged, 'kb', $article, [], null, metadata: ['acknowledged_by_employee_id' => $employee->id, 'version' => $version->version, 'version_hash' => $version->body_hash, 'source' => $source]);
            ServiceDeskEvent::dispatch('kb.policy.acknowledged', $article, ['title' => $article->title, 'version' => $version->version], []);

            return $read;
        });
    }

    /** Published articles needing acknowledgement of their current version by this employee. */
    public function pendingAcknowledgements(Employee $employee): Collection
    {
        $articles = $this->readable()->where('requires_acknowledgement', true)->get();
        if ($articles->isEmpty()) {
            return $articles;
        }
        $done = ArticleRead::query()->where('employee_id', $employee->id)->whereNotNull('acknowledged_at')->whereIn('article_id', $articles->pluck('id'))
            ->get(['article_id', 'version'])->map(fn (ArticleRead $r) => $r->article_id.':'.$r->version)->all();

        return $articles->filter(fn (Article $a) => $this->inAudience($a, $employee) && ! in_array($a->id.':'.$a->published_version, $done, true))->values();
    }

    public function hasAcknowledgementsDue(int $days): bool
    {
        return $this->readable()->where('requires_acknowledgement', true)->where('published_at', '<=', Carbon::now()->subDays($days))->exists();
    }

    /**
     * One in-app reminder per article to the audience members who have not acknowledged the current
     * version for `$days` days. Each person is claimed through the caller's idempotent reminder log.
     *
     * @param  Closure(Employee, int): bool  $claim
     */
    public function remindPendingAcknowledgements(int $days, Closure $claim): int
    {
        $sent = 0;
        $this->readable()->where('requires_acknowledgement', true)->where('published_at', '<=', Carbon::now()->subDays($days))->orderBy('id')->get()
            ->each(function (Article $article) use ($claim, &$sent) {
                $acknowledged = ArticleRead::query()->where('article_id', $article->id)->where('version', $article->published_version)->whereNotNull('acknowledged_at')->pluck('employee_id')->flip();
                $users = [];
                Employee::query()->employed()->whereNotNull('user_id')->chunkById(200, function ($chunk) use ($article, $acknowledged, $claim, &$users) {
                    foreach ($chunk as $employee) {
                        if (! $acknowledged->has($employee->id) && $this->inAudience($article, $employee) && $claim($employee, $article->id)) {
                            $users[] = (int) $employee->user_id;
                        }
                    }
                });
                if ($users !== []) {
                    ServiceDeskEvent::dispatch('kb.reminder.acknowledgement', $article, ['title' => $article->title, 'version' => $article->published_version], $users);
                    $sent += count($users);
                }
            });

        return $sent;
    }

    /**
     * HROne setup parity HP-9a: days employees have to acknowledge an article once its version is published
     * (kb.acknowledgement.due_days; 0 = no due date).
     */
    public function acknowledgementDueDays(): int
    {
        return max(0, (int) app(SettingsRepository::class)->get('kb.acknowledgement.due_days', 0));
    }

    /** The day the current version must be acknowledged by (null without a due date). */
    public function acknowledgementDueOn(Article $article): ?CarbonInterface
    {
        $days = $this->acknowledgementDueDays();

        return $days > 0 && $article->published_at !== null ? $article->published_at->copy()->addDays($days)->endOfDay() : null;
    }

    /** Days after publication when reminders start: the due days when set, else the service desk's reminder delay (as before). */
    public function reminderDays(): int
    {
        $due = $this->acknowledgementDueDays();

        return $due > 0 ? $due : (int) config('peopleos.servicedesk.policy_acknowledgement_reminder_days', 7);
    }

    /**
     * HP-9a: who in the audience read and acknowledged the current version (EN-10), for the article's writers. One row per
     * audience member, acknowledged first, then read, then the rest by name.
     *
     * @return Collection<int, array{employee: Employee, read_at: ?CarbonInterface, acknowledged_at: ?CarbonInterface, source: ?string}>
     */
    public function acknowledgementList(Article $article): Collection
    {
        $reads = ArticleRead::query()->where('article_id', $article->id)->where('version', $article->published_version)->get()->keyBy('employee_id');

        return $this->audienceEmployees($article)->loadMissing(['currentPosition.department', 'currentPosition.designation'])
            ->map(fn (Employee $e) => ['employee' => $e, 'read_at' => $reads->get($e->id)?->read_at, 'acknowledged_at' => $reads->get($e->id)?->acknowledged_at, 'source' => $reads->get($e->id)?->source])
            ->sortBy([
                fn (array $a, array $b) => ($a['acknowledged_at'] === null) <=> ($b['acknowledged_at'] === null),
                fn (array $a, array $b) => ($a['read_at'] === null) <=> ($b['read_at'] === null),
                fn (array $a, array $b) => strcmp((string) $a['employee']->person?->display_name, (string) $b['employee']->person?->display_name),
            ])->values();
    }

    /**
     * HP-9a (kb.download_allowed): the published version as a printable page (the browser prints or saves it as PDF),
     * for a writer or a reader in the article's audience; never a working copy. Audited.
     *
     * @return array{content: string, name: string, mime: string}
     */
    public function download(Article $article, User $user, ?Employee $reader): array
    {
        if (! (bool) app(SettingsRepository::class)->get('kb.download_allowed', false)) {
            throw new RuntimeException('Articles cannot be downloaded in this organisation.');
        }
        $version = $article->status === 'archived' ? null : $this->publishedVersion($article);
        if ($version === null) {
            throw new RuntimeException('Only a published article can be downloaded.');
        }
        if (! $user->hasPermission('kb.manage') && ($reader === null || ! $this->inAudience($article, $reader))) {
            throw new RuntimeException('This article is not addressed to you.');
        }
        $this->audit->record(AuditAction::Download, 'kb', $article, [], null, actor: $user, metadata: ['version' => $version->version]);
        $title = e($version->title);
        $body = Str::markdown((string) $version->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $meta = e('Version '.$version->version.' · published '.$version->published_at?->format('d M Y'));

        return [
            'content' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$title}</title><style>body{font-family:Georgia,serif;max-width:720px;margin:40px auto;line-height:1.5;color:#111}header{border-bottom:2px solid #111;margin-bottom:24px;padding-bottom:8px}@media print{body{margin:0 auto}}</style></head><body><header><h1>{$title}</h1><div>{$meta}</div></header>{$body}</body></html>",
            'name' => Str::slug($version->title).'-v'.$version->version.'.html',
            'mime' => 'text/html',
        ];
    }

    /** @return array{audience: int, read: int, acknowledged: int} */
    public function stats(Article $article): array
    {
        $audience = $this->audienceEmployees($article)->count();
        $reads = ArticleRead::query()->where('article_id', $article->id)->where('version', $article->published_version);

        return ['audience' => $audience, 'read' => (clone $reads)->count(), 'acknowledged' => (clone $reads)->whereNotNull('acknowledged_at')->count()];
    }

    /** @param  list<string>  $from */
    private function transition(Article $article, array $from, callable $callback): Article
    {
        return DB::transaction(function () use ($article, $from, $callback) {
            $a = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            if (! in_array($a->status, $from, true)) {
                throw new RuntimeException('This article is '.str_replace('_', ' ', $a->status).'; that step is not available.');
            }
            $callback($a);
            $article->setRawAttributes($a->getAttributes(), true);

            return $article;
        });
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException("This needs {$permission}.");
        }
    }
}
