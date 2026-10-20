<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Phase 13: one version of a survey — questions, anonymity mode, response rule, audience (pinned
 * criteria), breakdown dimension, result visibility, reminder policy and dates.
 *
 * Draft → In review → Approved → Scheduled → Open → Closed → Archived. Content is frozen once it
 * leaves draft; a correction is a new version, and responses stay pinned to the version they answered.
 * Never deleted.
 */
#[Fillable([
    'tenant_id', 'survey_id', 'version', 'status', 'intro', 'anonymity_mode', 'response_rule', 'response_period', 'audience_id', 'audience_criteria',
    'breakdown_dimension', 'result_visibility', 'reminder_policy', 'opens_at', 'closes_at', 'prepared_by', 'scope_user_id', 'submitted_at', 'approved_by',
    'approved_at', 'decision_note', 'published_at', 'opened_at', 'closed_at', 'archived_at', 'workflow_instance_id', 'eligible_count', 'checksum', 'operation_id', 'lock_version',
])]
class SurveyVersion extends Model
{
    use Auditable, BelongsToTenant;

    public const CONTENT = ['survey_id', 'version', 'intro', 'anonymity_mode', 'response_rule', 'response_period', 'audience_id', 'audience_criteria', 'breakdown_dimension', 'result_visibility', 'reminder_policy', 'opens_at', 'closes_at'];

    /** Statuses in which the version's content is part of the record (frozen). */
    public const FROZEN = ['in_review', 'approved', 'scheduled', 'open', 'closed', 'archived'];

    protected $attributes = ['status' => 'draft', 'anonymity_mode' => 'anonymous', 'response_rule' => 'once', 'lock_version' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $v): void {
            $content = array_intersect(array_keys($v->getDirty()), self::CONTENT);
            // "Opens on publication" is fixed once, when an approved version is published.
            if ($v->getRawOriginal('opens_at') === null) {
                $content = array_diff($content, ['opens_at']);
            }
            if ($content !== [] && $v->getRawOriginal('status') !== 'draft') {
                throw new RuntimeException('Only a draft survey version is edited; a correction is a new version ('.implode(', ', $content).').');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Survey versions are never deleted; archive them.'));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'audience_criteria' => 'array', 'result_visibility' => 'array', 'reminder_policy' => 'array',
            'opens_at' => 'datetime', 'closes_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime',
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'archived_at' => 'datetime', 'eligible_count' => 'integer', 'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return ($this->survey?->code ?? 'Survey').' v'.$this->version;
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('position')->orderBy('id');
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isAnonymous(): bool
    {
        return $this->anonymity_mode === 'anonymous';
    }

    public function isIdentified(): bool
    {
        return $this->anonymity_mode === 'identified';
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Who may see results: hr / managers (team group) / employees (overall). */
    public function visibleTo(string $audience): bool
    {
        return (bool) (($this->result_visibility ?? ['hr' => true, 'managers' => false, 'employees' => false])[$audience] ?? false);
    }
}
