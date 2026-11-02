<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sensitive (blueprint §80): identifiers encrypted at rest, masked in audit, viewing audited. */
#[Fillable(['tenant_id', 'employee_id', 'pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number', 'pf_applicable', 'esic_applicable', 'pt_applicable', 'tax_regime', 'pt_state_code', 'metadata'])]
#[Hidden(['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number', 'pan_hash', 'aadhaar_hash'])]
class EmployeeStatutoryDetail extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const SENSITIVE = ['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number'];

    /**
     * HROne parity WO-3: an Aadhaar reference shorter than this (a "last four digits" reference) is too short to identify
     * anyone, so it gets no lookup hash and is never compared.
     */
    public const AADHAAR_MATCH_MIN_LENGTH = 12;

    /** HROne parity WO-3: the lookup hashes follow the encrypted identifiers on every save (never filled from outside). */
    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            if ($detail->isDirty('pan') || ! $detail->exists) {
                $detail->pan_hash = self::panHash($detail->pan);
            }
            if ($detail->isDirty('aadhaar_reference') || ! $detail->exists) {
                $detail->aadhaar_hash = self::aadhaarHash($detail->aadhaar_reference);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'pan' => 'encrypted',
            'aadhaar_reference' => 'encrypted',
            'uan' => 'encrypted',
            'pf_number' => 'encrypted',
            'esic_number' => 'encrypted',
            'pf_applicable' => 'boolean',
            'esic_applicable' => 'boolean',
            'pt_applicable' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return 'Statutory details';
    }

    public function auditSensitiveAttributes(): array
    {
        return self::SENSITIVE;
    }

    /** HROne parity WO-3: the lookup hashes are derived values, never audited. */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'pan_hash', 'aadhaar_hash'];
    }

    /** HROne parity WO-3: the normalised form identifiers are compared in (upper case, no spaces, dots or dashes). */
    public static function normaliseIdentifier(?string $value): string
    {
        return strtoupper(preg_replace('/[\s.\-\/]+/', '', trim((string) $value)) ?? '');
    }

    /** HMAC (app key) of the normalised PAN; null when blank. */
    public static function panHash(?string $pan): ?string
    {
        $value = self::normaliseIdentifier($pan);

        return $value === '' ? null : hash_hmac('sha256', 'pan|'.$value, (string) config('app.key'));
    }

    /** HMAC (app key) of the normalised Aadhaar reference; null when blank or too short to identify anyone. */
    public static function aadhaarHash(?string $reference): ?string
    {
        $value = self::normaliseIdentifier($reference);

        return mb_strlen($value) < self::AADHAAR_MATCH_MIN_LENGTH ? null : hash_hmac('sha256', 'aadhaar|'.$value, (string) config('app.key'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function mask(?string $value, int $keep = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', max(mb_strlen($value) - $keep, 0)).mb_substr($value, -$keep);
    }
}
