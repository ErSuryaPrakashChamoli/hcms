<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningEvidence;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Phase 8: learners upload evidence (private disk, type and size checked, hashed); L&D accepts or rejects it once. */
final class LearningEvidenceService
{
    public function __construct(private readonly PerformanceRelationships $relationships, private readonly AuditRecorder $audit) {}

    public function upload(LearningEnrolment $enrolment, string $contents, string $originalName, string $mime, User $actor): LearningEvidence
    {
        if ($this->relationships->forUser($actor)?->id !== $enrolment->employee_id && ! $actor->hasPermission('learning.manage')) {
            throw new RuntimeException('Evidence is uploaded by the learner or L&D.');
        }
        if (! in_array($mime, config('peopleos.learning.evidence_mimes', []), true)) {
            throw new RuntimeException('Evidence must be a PDF or an image.');
        }
        if (strlen($contents) > (int) config('peopleos.learning.evidence_max_kb', 10240) * 1024) {
            throw new RuntimeException('The evidence file is too large.');
        }
        if (in_array($enrolment->status, ['cancelled', 'rejected', 'withdrawn'], true)) {
            throw new RuntimeException('This enrolment is closed.');
        }
        $path = sprintf('learning/evidence/%d/%d/%s-%s', $enrolment->tenant_id, $enrolment->id, Str::ulid(), preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName)));
        Storage::disk('local')->put($path, $contents);

        return LearningEvidence::query()->create([
            'employee_id' => $enrolment->employee_id, 'learning_enrolment_id' => $enrolment->id, 'disk' => 'local', 'path' => $path,
            'original_name' => basename($originalName), 'mime' => $mime, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents),
            'status' => 'submitted', 'uploaded_by' => $actor->id,
        ]);
    }

    public function review(LearningEvidence $evidence, bool $accept, ?string $note, User $actor): LearningEvidence
    {
        if (! $actor->hasPermission('learning.certificates') && ! $actor->hasPermission('learning.manage')) {
            throw new RuntimeException('Reviewing evidence needs learning.certificates.');
        }
        if ($this->relationships->forUser($actor)?->id === $evidence->employee_id) {
            throw new RuntimeException('Learners cannot review their own evidence.');
        }
        $evidence->update(['status' => $accept ? 'accepted' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);

        return $evidence;
    }

    public function contents(LearningEvidence $evidence, User $viewer): string
    {
        $this->audit->record(AuditAction::Download, 'learning', $evidence, [], null, actor: $viewer, metadata: ['event' => 'evidence_downloaded']);

        return (string) Storage::disk($evidence->disk)->get($evidence->path);
    }
}
