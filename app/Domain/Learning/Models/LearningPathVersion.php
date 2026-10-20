<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 8: immutable snapshot of a learning path — ordered items with prerequisites, and milestones. */
#[Fillable(['tenant_id', 'learning_path_id', 'version', 'name', 'items', 'milestones', 'checksum', 'status', 'published_by', 'published_at'])]
class LearningPathVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->checksum = hash('sha256', (string) json_encode([$v->name, $v->items, $v->milestones])));
        static::updating(function (self $v) {
            if (array_diff(array_keys($v->getDirty()), ['status', 'updated_at']) !== []) {
                throw new \RuntimeException('A published learning path version is immutable; publish a new version.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Learning path versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'items' => 'array', 'milestones' => 'array', 'published_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return "{$this->name} v{$this->version}";
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    /** @return list<int> prerequisite course ids for a course in this version */
    public function prerequisitesFor(int $courseId): array
    {
        return array_map('intval', collect($this->items)->firstWhere('course_id', $courseId)['prerequisite_course_ids'] ?? []);
    }
}
