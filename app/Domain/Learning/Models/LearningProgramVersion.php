<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8: an immutable program version — dates, rule-engine eligibility, required and optional
 * items ({type: course|path, id}), the completion rule ({min_optional}) and certificate settings.
 */
#[Fillable(['tenant_id', 'learning_program_id', 'version', 'starts_on', 'ends_on', 'eligibility', 'items', 'completion_rule', 'issues_certificate', 'validity_months', 'checksum', 'status', 'published_by', 'published_at'])]
class LearningProgramVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->checksum = hash('sha256', (string) json_encode([$v->starts_on, $v->ends_on, $v->eligibility, $v->items, $v->completion_rule, $v->issues_certificate, $v->validity_months])));
        static::updating(function (self $v) {
            if (array_diff(array_keys($v->getDirty()), ['status', 'updated_at']) !== []) {
                throw new \RuntimeException('A published program version is immutable; publish a new version.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Program versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'eligibility' => 'array', 'items' => 'array', 'completion_rule' => 'array', 'issues_certificate' => 'boolean', 'validity_months' => 'integer', 'published_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return 'Program version v'.$this->version;
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LearningProgram::class, 'learning_program_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(LearningProgramParticipant::class);
    }

    /** @return list<array{type: string, id: int, required: bool}> */
    public function requiredItems(): array
    {
        return array_values(array_filter($this->items ?? [], fn ($i) => ! empty($i['required'])));
    }

    /** @return list<array{type: string, id: int, required: bool}> */
    public function optionalItems(): array
    {
        return array_values(array_filter($this->items ?? [], fn ($i) => empty($i['required'])));
    }
}
