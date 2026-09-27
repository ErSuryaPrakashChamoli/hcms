<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only verification history of a statutory rule version (Phase 5 Part G): who moved it,
 * from which status to which, against which official source, with which requirement text and
 * payload mapping, and the rule checksum at that moment. Platform-level; never updated or deleted.
 */
#[Fillable(['compliance_rule_id', 'action', 'from_status', 'to_status', 'authority', 'source_url', 'source_title', 'source_published_date', 'effective_date', 'requirement_text', 'mapping', 'evidence_reference', 'evidence_checksum', 'rule_checksum', 'actor_id', 'actor_label', 'notes', 'created_at'])]
class ComplianceRuleVerification extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['created', 'submitted', 'verified', 'rejected', 'superseded'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Rule verification history is append-only.'));
        static::deleting(fn () => throw new RuntimeException('Rule verification history is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'source_published_date' => 'date',
            'effective_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'compliance_rule_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
