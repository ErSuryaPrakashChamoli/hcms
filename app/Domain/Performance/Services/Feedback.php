<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\FeedbackEntry;
use RuntimeException;

/** Continuous feedback (§34). */
final class Feedback
{
    public function give(Employee $about, Employee $author, string $type, string $message, string $visibility = 'manager', ?int $goalId = null, ?int $competencyId = null, ?FeedbackEntry $request = null): FeedbackEntry
    {
        if ($about->id === $author->id) {
            throw new RuntimeException('Feedback is about someone else.');
        }
        if (! in_array($type, ['praise', 'constructive'], true)) {
            throw new RuntimeException('Feedback is praise or constructive.');
        }

        $entry = FeedbackEntry::create([
            'employee_id' => $about->id, 'author_id' => $author->id, 'type' => $type, 'visibility' => $visibility, 'message' => $message,
            'goal_id' => $goalId, 'competency_id' => $competencyId, 'parent_id' => $request?->id, 'status' => 'given',
        ]);

        $request?->update(['status' => 'answered']);
        PerformanceEvent::dispatch('performance.feedback.received', $about, $entry, ['type' => $type, 'from' => $author->person?->full_name], [$about->id]);

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

    /** Entries the viewer may read: own, about direct reports (manager visibility), public, or everything with performance.view. */
    public function visibleTo(Employee $viewer, bool $seesAll)
    {
        $query = FeedbackEntry::query()->with(['employee.person', 'author.person', 'requestedFrom.person']);
        if ($seesAll) {
            return $query;
        }
        $reports = $viewer->directReports()->currentlyEffective()->pluck('employee_id');

        return $query->where(function ($q) use ($viewer, $reports) {
            $q->where('employee_id', $viewer->id)
                ->orWhere('author_id', $viewer->id)
                ->orWhere('requested_from_id', $viewer->id)
                ->orWhere('visibility', 'public')
                ->orWhere(fn ($m) => $m->where('visibility', 'manager')->whereIn('employee_id', $reports));
        });
    }
}
