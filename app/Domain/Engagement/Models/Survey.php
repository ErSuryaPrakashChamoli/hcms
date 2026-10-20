<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 13: one survey (identity only). Its content, audience, privacy and dates live on its versions. */
#[Fillable(['tenant_id', 'code', 'name', 'category', 'survey_type', 'description', 'owner_id', 'status'])]
class Survey extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'category' => 'engagement', 'survey_type' => 'engagement'];

    protected static function booted(): void
    {
        static::saving(fn (self $s) => $s->code = strtoupper(trim((string) $s->code)));
        static::deleting(fn () => throw new \RuntimeException('Surveys are never deleted; archive their versions.'));
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return "Survey {$this->name} ({$this->code})";
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SurveyVersion::class)->orderByDesc('version');
    }
}
