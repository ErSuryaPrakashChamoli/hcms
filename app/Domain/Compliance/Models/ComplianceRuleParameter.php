<?php

namespace App\Domain\Compliance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 6 §7: coverage of one payload parameter by the evidence of one submission — covered (with
 * the requirement excerpt), not confirmed, or not applicable (with a justification). Append-only;
 * a new submission writes a new set.
 */
#[Fillable(['compliance_rule_id', 'compliance_rule_verification_id', 'parameter', 'status', 'requirement_excerpt', 'note', 'created_at'])]
class ComplianceRuleParameter extends Model
{
    public const UPDATED_AT = null;

    public const COVERED = 'covered';

    public const NOT_CONFIRMED = 'not_confirmed';

    public const NOT_APPLICABLE = 'not_applicable';

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Parameter coverage is append-only.'));
        static::deleting(fn () => throw new RuntimeException('Parameter coverage is append-only.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'compliance_rule_id');
    }
}
