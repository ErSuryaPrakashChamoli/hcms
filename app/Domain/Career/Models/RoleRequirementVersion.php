<?php

namespace App\Domain\Career\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9: immutable, effective-dated requirements of a role (a Designation, optionally narrowed to
 * an organisation unit). Skills pin the Phase 8 scale version they are expressed on:
 * skills [{skill_id, level, skill_scale_version_id, required}], competencies [{competency_id,
 * level, required}], certifications [{course_id}], learning [{type: course|path, id, required}].
 */
#[Fillable(['tenant_id', 'designation_id', 'organisation_node_id', 'scope_key', 'version', 'skills', 'competencies', 'min_experience_years', 'certifications', 'learning', 'notes', 'checksum', 'effective_from', 'effective_to', 'published_by', 'published_at'])]
class RoleRequirementVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (self $v) {
            $v->scope_key = (string) ($v->organisation_node_id ?? '');
            $v->checksum = hash('sha256', (string) json_encode([$v->designation_id, $v->scope_key, $v->skills, $v->competencies, $v->min_experience_years, $v->certifications, $v->learning]));
        });
        static::updating(function (self $v) {
            // Only closing the effective window is allowed (when a newer version takes over).
            if (array_diff(array_keys($v->getDirty()), ['effective_to', 'updated_at']) !== [] || $v->getRawOriginal('effective_to') !== null) {
                throw new \RuntimeException('Published role requirements are immutable; publish a new version.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Role requirement versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'skills' => 'array', 'competencies' => 'array', 'certifications' => 'array', 'learning' => 'array', 'min_experience_years' => 'decimal:1', 'effective_from' => 'date', 'effective_to' => 'date', 'published_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'career';
    }

    public function auditLabel(): string
    {
        return 'Role requirements v'.$this->version;
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }
}
