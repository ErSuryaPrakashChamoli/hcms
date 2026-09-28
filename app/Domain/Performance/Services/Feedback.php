<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\FeedbackEntry;
use RuntimeException;

/** Continuous feedback (§34). */
final class Feedback
{
    public function give(Employee $about, Employee $author, string $type, string $message, string $visibility = 'manager', ?int $goalId = null, ?int $competencyId = null, ?FeedbackEntry $request = null, bool $anonymous = false): FeedbackEntry
    {
        if (! array_key_exists($visibility, config('peopleos.performance.feedback_visibility'))) {
            throw new RuntimeException("Unknown feedback visibility '{$visibility}'.");
        }
        if (trim($message) === '') {
            throw new RuntimeException('Feedback needs a message.');
        }
        if ($about->id === $author->id) {
            throw new RuntimeException('Feedback is about someone else.');
        }
        if (! in_array($type, ['praise', 'constructive'], true)) {
            throw new RuntimeException('Feedback is praise or constructive.');
        }

        $entry = FeedbackEntry::create([
            'employee_id' => $about->id, 'author_id' => $author->id, 'type' => $type, 'visibility' => $visibility, 'is_anonymous' => $anonymous, 'message' => $message,
            'goal_id' => $goalId, 'competency_id' => $competencyId, 'parent_id' => $request?->id, 'status' => 'given',
        ]);

        $request?->update(['status' => 'answered']);
        // Anonymous feedback never carries the author's name into notifications or webhooks.
        PerformanceEvent::dispatch('performance.feedback.received', $about, $entry, ['type' => $type, 'from' => $anonymous ? 'Anonymous' : $author->person?->full_name, 'anonymous' => $anonymous], [$about->id]);

        return $entry;
    }

    public function request(Employee $about, Employee $from, string $message, ?int $goalId = null, ?Employee $requester = null): FeedbackEntry
    {
        if ($about->id === $from->id) {
            throw new RuntimeException('Ask someone else for feedback.');
        }

        $entry = FeedbackEntry::create([
            'employee_id' => $about->id, 'author_id' => ($requester ?? $about)->id, 'type' => 'request', 'visibility' => 'manager', 'message' => $message,
            'goal_id' => $goalId, 'requested_from_id' => $from->id, 'status' => 'requested',
        ]);

        PerformanceEvent::dispatch('performance.feedback.requested', $about, $entry, ['about' => $about->person?->full_name], [$from->id]);

        return $entry;
    }

    /**
     * The author of an entry as $viewer may see it: always for named feedback and for the author
     * themself; for anonymous feedback only with performance.anonymous_identity, and every reveal is
     * audited with a reason.
     */
    public function authorFor(FeedbackEntry $entry, User $viewer, ?string $reason = null): ?Employee
    {
        if (! $entry->is_anonymous) {
            return $entry->author;
        }
        $author = Employee::query()->withoutGlobalScope(AccessScope::class)->find($entry->author_id);
        if ($author !== null && $author->user_id === $viewer->id) {
            return $author;
        }
        if (! $viewer->hasPermission('performance.anonymous_identity')) {
            return null;
        }
        if ($reason === null || trim($reason) === '') {
            throw new RuntimeException('A reason is required to reveal the author of anonymous feedback.');
        }
        app(AuditRecorder::class)->record(AuditAction::View, 'performance', $entry, [], $reason, actor: $viewer, metadata: ['revealed' => 'feedback_author']);

        return $author;
    }

    /** Entries the viewer may read: own, about direct reports (manager visibility), public, or everything with performance.view. */
    public function visibleTo(Employee $viewer, bool $seesAll)
    {
        $query = FeedbackEntry::query()->with(['employee.person', 'author.person', 'requestedFrom.person']);
        if ($seesAll) {
            return $query;
        }
        $reports = app(PerformanceRelationships::class)->reportIds($viewer);

        return $query->where(function ($q) use ($viewer, $reports) {
            $q->where('employee_id', $viewer->id)
                ->orWhere('author_id', $viewer->id)
                ->orWhere('requested_from_id', $viewer->id)
                ->orWhere('visibility', 'public')
                ->orWhere(fn ($m) => $m->where('visibility', 'manager')->whereIn('employee_id', $reports));
        });
    }
}
