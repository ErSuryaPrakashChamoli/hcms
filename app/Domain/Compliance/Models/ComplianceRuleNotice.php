<?php

namespace App\Domain\Compliance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 6 regulatory change notice: official evidence that named rule versions are out of date (a
 * law changed) or wrongly based, without the replacement parameters being established yet. While
 * open it blocks verification of those versions and, under enforcement, payroll that uses them on or
 * after the effective date. Resolved only by linking a new rule version; content is immutable.
 */
#[Fillable(['jurisdiction', 'code', 'state', 'affects_versions', 'effective_date', 'title', 'summary', 'references', 'retrieved_at', 'checksum', 'status', 'resolved_by_rule_id', 'resolution_note', 'resolved_by', 'resolved_at'])]
class ComplianceRuleNotice extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected static function booted(): void
    {
        static::updating(function (ComplianceRuleNotice $notice) {
            if (array_diff(array_keys($notice->getDirty()), ['status', 'resolved_by_rule_id', 'resolution_note', 'resolved_by', 'resolved_at', 'updated_at']) !== [] || $notice->getOriginal('status') !== self::OPEN) {
                throw new RuntimeException('A regulatory notice is immutable; it can only be resolved once.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Regulatory notices are never deleted.'));
    }

    protected function casts(): array
    {
        return ['affects_versions' => 'array', 'references' => 'array', 'effective_date' => 'date', 'retrieved_at' => 'date', 'resolved_at' => 'datetime'];
    }

    public function affects(ComplianceRule $rule): bool
    {
        return $this->jurisdiction === $rule->jurisdiction && $this->code === $rule->code
            && ($this->state === null || $this->state === $rule->state)
            && in_array((int) $rule->version, array_map('intval', (array) $this->affects_versions), true);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'resolved_by_rule_id');
    }
}
