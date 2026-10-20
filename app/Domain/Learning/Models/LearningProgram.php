<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 8: a program groups courses and paths into one development experience (e.g. New Manager). */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'status', 'current_version_id'])]
class LearningProgram extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'retired' => 'Retired', 'archived' => 'Archived'];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(fn (self $p) => $p->code = strtoupper(trim((string) $p->code)));
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LearningProgramVersion::class)->orderBy('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(LearningProgramVersion::class, 'current_version_id');
    }
}
