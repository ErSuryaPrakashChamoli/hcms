<?php

namespace App\Domain\Succession\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 9: a position designated critical — a Designation, optionally narrowed to an organisation
 * unit. Criticality comes from immutable assessments recorded by people (never computed). One active
 * designation per role and scope (active_key).
 */
#[Fillable(['tenant_id', 'designation_id', 'organisation_node_id', 'scope_key', 'title', 'status', 'active_key', 'review_frequency_months', 'next_review_on', 'effective_from', 'effective_to', 'current_assessment_id', 'created_by'])]
class CriticalPosition extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            $p->scope_key = (string) ($p->organisation_node_id ?? '');
            $p->active_key = $p->status === 'active' ? $p->designation_id.':'.$p->scope_key : null;
        });
        static::updating(function (self $p) {
            if ($p->getRawOriginal('status') === 'retired') {
                throw new \RuntimeException('A retired critical position is read-only.');
            }
            if ($p->isDirty(['designation_id', 'organisation_node_id', 'scope_key', 'effective_from'])) {
                throw new \RuntimeException('A critical position keeps its role and scope; retire it and designate a new one.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Critical positions are retired, never deleted.'));
    }

    protected function casts(): array
    {
        return ['review_frequency_months' => 'integer', 'next_review_on' => 'date', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function auditModule(): string
    {
        return 'succession';
    }

    public function auditLabel(): string
    {
        return 'Critical position: '.$this->title;
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(CriticalPositionAssessment::class)->orderByDesc('id');
    }

    public function currentAssessment(): BelongsTo
    {
        return $this->belongsTo(CriticalPositionAssessment::class, 'current_assessment_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(SuccessionPlan::class);
    }
}
