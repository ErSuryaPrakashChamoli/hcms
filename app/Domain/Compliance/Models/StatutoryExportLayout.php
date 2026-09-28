<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 6.2: one version of a statutory export layout (field order, formats, required fields,
 * encoding, structure). Platform-owned. The specification is immutable and checksummed; a change is a
 * new version. Status moves DRAFT → REVIEW → VERIFIED (or REJECTED) through ExportLayouts, with the
 * verifier never the submitter; a verified layout's evidence cannot change.
 */
#[Fillable(['code', 'version', 'name', 'authority', 'specification', 'checksum', 'status', 'source_url', 'source_title', 'retrieved_at', 'evidence_path', 'evidence_sha256', 'submitted_by', 'submitted_at', 'submission_notes', 'verified_by', 'verified_at', 'verification_notes', 'superseded_by_id', 'notes'])]
class StatutoryExportLayout extends Model
{
    public const STATUSES = ['draft', 'review', 'verified', 'rejected', 'superseded'];

    protected static function booted(): void
    {
        static::creating(function (StatutoryExportLayout $layout) {
            $layout->status ??= 'draft';
            $layout->checksum = self::checksumFor($layout->code, (int) $layout->version, (array) $layout->specification);
        });
        static::updating(function (StatutoryExportLayout $layout) {
            $locked = array_intersect(array_keys($layout->getDirty()), ['code', 'version', 'name', 'specification', 'checksum', 'authority']);
            if ($locked !== []) {
                throw new RuntimeException('Export layout versions are immutable ('.implode(', ', $locked).'); publish a new version.');
            }
            if ($layout->getOriginal('status') === 'verified' && $layout->isDirty(['source_url', 'source_title', 'retrieved_at', 'evidence_path', 'evidence_sha256', 'verified_by', 'verified_at', 'submitted_by'])) {
                throw new RuntimeException('The evidence of a verified layout cannot be changed.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Export layouts are never deleted.'));
    }

    protected function casts(): array
    {
        return ['specification' => 'array', 'retrieved_at' => 'date', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'version' => 'integer'];
    }

    public static function checksumFor(string $code, int $version, array $specification): string
    {
        return hash('sha256', (string) json_encode([$code, $version, $specification], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function checksumIntact(): bool
    {
        return hash_equals((string) $this->checksum, self::checksumFor($this->code, (int) $this->version, (array) $this->specification));
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified' && $this->checksumIntact();
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_column((array) ($this->specification['fields'] ?? []), 'name');
    }

    public function label(): string
    {
        return "{$this->code} v{$this->version}";
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
