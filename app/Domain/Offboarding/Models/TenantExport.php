<?php

namespace App\Domain\Offboarding\Models;

use App\Domain\Offboarding\Enums\ExportStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.9: a tenant data export package (TenantExports). The package is an AES-256 encrypted ZIP on a private disk; its
 * passphrase is kept encrypted with the application key and shown only to an authorised downloader (audited).
 */
#[Fillable(['tenant_id', 'offboarding_id', 'reference', 'purpose', 'status', 'in_flight_key', 'requested_by', 'reason', 'checkpoint', 'attempts',
    'heartbeat_at', 'started_at', 'completed_at', 'failed_at', 'failure', 'disk', 'path', 'size_bytes', 'sha256', 'manifest', 'manifest_sha256',
    'manifest_signature', 'passphrase', 'record_count', 'file_count', 'expires_at', 'expired_at', 'download_count', 'last_downloaded_at',
    'delivered_by', 'delivered_at', 'delivery_note'])]
#[Hidden(['passphrase', 'checkpoint'])]
class TenantExport extends Model
{
    use BelongsToTenant;

    public const PURPOSE_OFFBOARDING = 'offboarding';

    public const PURPOSE_CUSTOMER = 'customer';

    protected function casts(): array
    {
        return ['status' => ExportStatus::class, 'checkpoint' => 'array', 'manifest' => 'array', 'passphrase' => 'encrypted',
            'heartbeat_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime', 'expires_at' => 'datetime',
            'expired_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'delivered_at' => 'datetime', 'size_bytes' => 'integer', 'record_count' => 'integer',
            'file_count' => 'integer', 'download_count' => 'integer', 'attempts' => 'integer'];
    }

    public function downloadable(): bool
    {
        return $this->status === ExportStatus::Ready && $this->expires_at !== null && $this->expires_at->isFuture() && $this->path !== null;
    }
}
