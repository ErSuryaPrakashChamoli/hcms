<?php

namespace App\Domain\Career\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Performance\Models\CareerPath;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: an immutable, effective-dated snapshot of a career path (scope + ordered designation steps). */
#[Fillable(['tenant_id', 'career_path_id', 'version', 'name', 'scope', 'steps', 'checksum', 'effective_from', 'published_by', 'published_at'])]
class CareerPathVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->checksum = hash('sha256', (string) json_encode([$v->name, $v->scope, $v->steps])));
        static::updating(fn () => throw new \RuntimeException('A published career path version is immutable; publish a new version.'));
        static::deleting(fn () => throw new \RuntimeException('Career path versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'scope' => 'array', 'steps' => 'array', 'effective_from' => 'date', 'published_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'career';
    }

    public function auditLabel(): string
    {
        return "{$this->name} v{$this->version}";
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(CareerPath::class, 'career_path_id');
    }
}
