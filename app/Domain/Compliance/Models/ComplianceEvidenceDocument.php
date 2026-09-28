<?php

namespace App\Domain\Compliance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 6 §6/§28: an official evidence document for a statutory rule version, stored privately with
 * its SHA-256 and retrieval date. Append-only: a document is never replaced or removed, and none can
 * be attached once the version is VERIFIED (a correction is a new version).
 */
#[Fillable(['compliance_rule_id', 'filename', 'path', 'mime', 'size', 'sha256', 'source_url', 'retrieved_at', 'uploaded_by', 'uploaded_label', 'created_at'])]
class ComplianceEvidenceDocument extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Evidence documents are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Evidence documents are never deleted.'));
    }

    protected function casts(): array
    {
        return ['retrieved_at' => 'date', 'created_at' => 'datetime', 'size' => 'integer'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'compliance_rule_id');
    }
}
