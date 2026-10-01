<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Models\LearningPathVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8: publish a learning path as an immutable version — ordered items pinned to their
 * current course versions, prerequisites between items (validated: inside the path, acyclic) and
 * milestones. Enrolments made through a path pin the path version.
 */
final class LearningPaths
{
    public function __construct(private readonly Catalogue $catalogue, private readonly AuditRecorder $audit) {}

    public function publish(LearningPath $path, ?User $actor = null): LearningPathVersion
    {
        $actor ??= auth()->user();
        $items = $path->items()->with('course')->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('A learning path needs at least one item.');
        }

        $courseIds = $items->pluck('course_id')->map(fn ($id) => (int) $id)->all();
        $graph = [];
        foreach ($items as $item) {
            $prerequisites = array_map('intval', $item->prerequisite_course_ids ?? []);
            if (array_diff($prerequisites, $courseIds) !== []) {
                throw new RuntimeException("Prerequisites of {$item->course?->code} must be items of the same path.");
            }
            $graph[(int) $item->course_id] = $prerequisites;
        }
        Catalogue::assertAcyclic($graph, 'Learning path prerequisites');

        foreach ($path->milestones ?? [] as $milestone) {
            if (blank($milestone['title'] ?? null)) {
                throw new RuntimeException('Every milestone needs a title.');
            }
        }

        return DB::transaction(function () use ($path, $items, $actor) {
            LearningPath::query()->whereKey($path->id)->lockForUpdate()->first();
            $snapshot = $items->map(function ($item) {
                $version = $this->catalogue->ensureVersion($item->course);

                return [
                    'course_id' => (int) $item->course_id, 'course_version_id' => $version->id, 'course_code' => $item->course->code, 'title' => $version->title,
                    'type' => $item->course->type, 'sort_order' => (int) $item->sort_order, 'required' => (bool) $item->is_required,
                    'prerequisite_course_ids' => array_map('intval', $item->prerequisite_course_ids ?? []),
                ];
            })->values()->all();

            $version = LearningPathVersion::query()->create([
                'learning_path_id' => $path->id,
                'version' => (int) LearningPathVersion::query()->where('learning_path_id', $path->id)->max('version') + 1,
                'name' => $path->name,
                'items' => $snapshot,
                'milestones' => array_values($path->milestones ?? []),
                'status' => 'published',
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);
            $path->update(['current_version_id' => $version->id]);
            $this->audit->record(AuditAction::Create, 'learning', $version, [], null, actor: $actor, metadata: ['event' => 'learning_path_version_published', 'path' => $path->code, 'version' => $version->version]);

            return $version;
        });
    }

    /** The version enrolments pin; published on first use for paths created before Phase 8. */
    public function ensureVersion(LearningPath $path): LearningPathVersion
    {
        return $path->current_version_id
            ? LearningPathVersion::query()->findOrFail($path->current_version_id)
            : $this->publish($path, null);
    }
}
