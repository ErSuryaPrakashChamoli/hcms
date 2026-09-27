<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Part L: Form No. 130 (earlier Form 16) prepared as an immutable snapshot of the verified annual
 * ledger. Part A of the certificate is issued through TRACES and is not generated here. A correction
 * is a new version that supersedes the earlier one.
 */
#[Fillable(['tenant_id', 'legal_entity_id', 'employee_id', 'tds_financial_year_id', 'financial_year', 'code', 'legacy_code', 'version', 'status', 'certificate_number', 'snapshot', 'ledger_checksum', 'snapshot_checksum', 'supersedes_id', 'generated_by', 'generated_at', 'issued_by', 'issued_at', 'reason'])]
class TdsCertificate extends Model
{
    use BelongsToTenant, ScopedByEmployee;

    protected static function booted(): void
    {
        static::creating(fn (TdsCertificate $c) => $c->snapshot_checksum = hash('sha256', (string) json_encode($c->snapshot)));
        static::updating(function (TdsCertificate $certificate) {
            if (array_diff(array_keys($certificate->getDirty()), ['status', 'certificate_number', 'issued_by', 'issued_at', 'updated_at']) !== []) {
                throw new RuntimeException('A TDS certificate snapshot is immutable; generate a new version.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('TDS certificates are never deleted.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'generated_at' => 'datetime', 'issued_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
