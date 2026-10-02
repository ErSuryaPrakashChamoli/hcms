<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 13: a reusable audience definition (criteria over effective-dated employment data). It is
 * resolved in SQL by AudienceQuery within the user's organisation scope. Surveys and announcements pin
 * a copy of the criteria; their snapshot at launch is what counts.
 */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'criteria', 'owner_id', 'status'])]
class Audience extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $a) => $a->code = strtoupper(trim((string) $a->code)));
        static::deleting(fn () => throw new \RuntimeException('Audiences are deactivated, never deleted.'));
    }

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return "Audience {$this->name} ({$this->code})";
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
