<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Services\ExportLayouts;
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
 *
 * D7: the checksum records its algorithm. New layouts are sealed with CANONICAL (object keys sorted), because MySQL's
 * json column re-sorts keys on storage. Layouts sealed before that keep checksum_algorithm NULL (the original
 * algorithm, the specification as written) and are verified exactly as before.
 */
#[Fillable(['code', 'version', 'name', 'authority', 'specification', 'checksum', 'status', 'source_url', 'source_title', 'retrieved_at', 'evidence_path', 'evidence_sha256', 'submitted_by', 'submitted_at', 'submission_notes', 'verified_by', 'verified_at', 'verification_notes', 'superseded_by_id', 'notes'])]
class StatutoryExportLayout extends Model
{
    public const STATUSES = ['draft', 'review', 'verified', 'rejected', 'superseded'];

    public const CANONICAL = 'canonical-v2';

    protected static function booted(): void
    {
        static::creating(function (StatutoryExportLayout $layout) {
            $layout->status ??= 'draft';
            $layout->checksum_algorithm = self::CANONICAL;
            $layout->checksum = self::checksumFor($layout->code, (int) $layout->version, (array) $layout->specification);
        });
        static::updating(function (StatutoryExportLayout $layout) {
            $locked = array_intersect(array_keys($layout->getDirty()), ['code', 'version', 'name', 'specification', 'checksum', 'checksum_algorithm', 'authority']);
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

    /**
     * sha256 of [code, version, specification]. CANONICAL: the specification normalised through JSON, object keys
     * sorted at every level and list order kept, so a value read back from any database hashes the same. null: the
     * original algorithm, the specification exactly as written (kept byte-for-byte for layouts sealed with it).
     */
    public static function checksumFor(string $code, int $version, array $specification, ?string $algorithm = self::CANONICAL): string
    {
        return match ($algorithm) {
            null => hash('sha256', (string) json_encode([$code, $version, $specification], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            self::CANONICAL => hash('sha256', json_encode([$code, $version, self::canonical(json_decode(json_encode($specification, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            default => throw new RuntimeException("Unknown export layout checksum algorithm {$algorithm}."),
        };
    }

    /** An unknown algorithm is not intact (it fails closed rather than throwing). */
    public function checksumIntact(): bool
    {
        return in_array($this->checksumAlgorithm(), [null, self::CANONICAL], true)
            && hash_equals((string) $this->checksum, self::checksumFor($this->code, (int) $this->version, (array) $this->specification, $this->checksumAlgorithm()));
    }

    public function sealedWithOriginalAlgorithm(): bool
    {
        return $this->checksumAlgorithm() === null;
    }

    /**
     * The algorithm that sealed this row. A row read from a database without the column (before migration
     * 2026_11_09_100004) was sealed with the original algorithm, so it reads as NULL instead of throwing; a canonical
     * row read without the column fails closed (not intact).
     */
    public function checksumAlgorithm(): ?string
    {
        return $this->getAttributes()['checksum_algorithm'] ?? null;
    }

    /**
     * The seal holds: the checksum reproduces from the stored content, or (D7, decision B) an original-algorithm seal
     * is proven against the layout pack. Read-only; the stored checksum is never rewritten.
     */
    public function sealHolds(): bool
    {
        return $this->checksumIntact() || app(ExportLayouts::class)->integrity($this) === ExportLayouts::INTACT_AGAINST_PACK;
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified' && $this->sealHolds();
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

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
