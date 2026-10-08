<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\ExportStatus;
use App\Domain\Offboarding\Jobs\GenerateTenantExport;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Support\OffboardingAudit;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * SaaS.9: a tenant's data export, from request to expiry.
 *
 * - Who: a platform operator (for any tenant, e.g. the offboarding export), or a person of the tenant holding
 *   `data.export` while the tenant is in use. Checked here, not only on a page. Never an API key.
 * - One export generates at a time per tenant (in_flight_key, unique): a second request, or two at once, returns the one
 *   in flight instead of starting a conflicting copy. Every export is its own record, with its own reference and hashes.
 * - Generation is a queued job (GenerateTenantExport); never in a web request. A worker claims the export with a
 *   conditional update; a worker that stops (heartbeat older than the lease) is replaced and resumes from the checkpoint;
 *   after the maximum attempts, or on an error, the export fails with the reason and keeps its checkpoint for a retry.
 * - A ready package lives on the private export disk until it expires (then the file is deleted; the record, manifest
 *   and hashes stay). It is fetched only through a signed link that expires in minutes and is bound to the person;
 *   every download and every passphrase reveal is audited.
 */
final class TenantExports
{
    public function __construct(
        private readonly OffboardingAudit $audit,
        private readonly TenantContext $tenants,
        private readonly ExportPackager $packager,
    ) {}

    /** Who may request, download and reveal a tenant's export: an operator, or the tenant's own `data.export` holder while it is in use. */
    public function authorize(Tenant $tenant, ?User $actor, string $what = 'export this organisation\'s data'): void
    {
        if ($actor === null) {
            PlatformOperator::assert(null, $what);

            return;
        }
        if ($actor->isPlatformAdmin()) {
            return;
        }
        $allowed = (int) $actor->tenant_id === (int) $tenant->getKey() && $tenant->isAccessible() && $actor->isActive()
            && $this->tenants->runAs($tenant, fn () => $actor->hasPermission('data.export'));
        if (! $allowed) {
            throw new LifecycleRefused("You cannot {$what}.");
        }
    }

    public function request(Tenant $tenant, ?User $actor, string $reason, ?TenantOffboarding $case = null): TenantExport
    {
        $tenant = Tenant::query()->findOrFail($tenant->getKey());
        $this->authorize($tenant, $actor, 'request an export of this organisation\'s data');
        $reason = PlatformOperator::reason($reason);
        if ($case !== null && (int) $case->tenant_id !== (int) $tenant->getKey()) {
            throw new LifecycleRefused('This offboarding belongs to another tenant.');
        }
        $purpose = $actor?->isPlatformAdmin() !== false && $case !== null ? TenantExport::PURPOSE_OFFBOARDING : TenantExport::PURPOSE_CUSTOMER;

        try {
            $export = $this->tenants->runAs($tenant, fn () => TenantExport::query()->create([
                'tenant_id' => $tenant->getKey(), 'offboarding_id' => $case?->getKey(), 'reference' => (string) Str::ulid(), 'purpose' => $purpose,
                'status' => ExportStatus::Queued, 'in_flight_key' => $tenant->getKey(), 'requested_by' => $actor?->getKey(), 'reason' => $reason,
                'disk' => (string) config('peopleos.offboarding.export_disk', 'local'),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Idempotent: one export generates at a time; the request joins it.
            return $this->tenants->bypass(fn () => TenantExport::query()->where('in_flight_key', $tenant->getKey())->firstOrFail());
        }
        $this->audit->record(AuditAction::ExportRequested, $tenant, $export, $reason, $actor, ['export' => $export->reference, 'purpose' => $purpose]);
        GenerateTenantExport::dispatch($export->getKey(), $tenant->getKey())->afterCommit();

        return $export;
    }

    /** The worker's step (GenerateTenantExport). Returns false when another worker holds the export. */
    public function generate(int $exportId): bool
    {
        $lease = now()->subMinutes((int) config('peopleos.offboarding.export_lease_minutes', 30));
        $claimed = $this->tenants->bypass(fn () => TenantExport::query()->whereKey($exportId)
            ->where(fn ($q) => $q->where('status', ExportStatus::Queued->value)
                ->orWhere(fn ($q) => $q->where('status', ExportStatus::Generating->value)->where('heartbeat_at', '<', $lease)))
            ->update(['status' => ExportStatus::Generating->value, 'attempts' => DB::raw('attempts + 1'), 'heartbeat_at' => now(), 'updated_at' => now()]));
        if ($claimed !== 1) {
            return false;
        }
        $this->tenants->bypass(fn () => TenantExport::query()->whereKey($exportId)->whereNull('started_at')->update(['started_at' => now()]));
        $export = $this->find($exportId);
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        if ($export->attempts === 1) {
            $this->audit->record(AuditAction::ExportStarted, $tenant, $export, 'Export started', null, ['export' => $export->reference]);
        }
        if ($export->passphrase === null) {
            $this->tenants->bypass(fn () => $export->forceFill(['passphrase' => Str::password(32, symbols: false)])->save());
        }

        try {
            $result = $this->packager->build($export, $tenant, (string) $export->passphrase, function (array $checkpoint) use ($export) {
                $this->tenants->bypass(fn () => TenantExport::query()->whereKey($export->getKey())->update(['checkpoint' => json_encode($checkpoint), 'heartbeat_at' => now()]));
            });
        } catch (Throwable $e) {
            report($e);
            $this->fail($export, $tenant, class_basename($e).': '.mb_substr($e->getMessage(), 0, 300));

            return true;
        }

        $this->tenants->bypass(fn () => TenantExport::query()->whereKey($export->getKey())->update([
            'status' => ExportStatus::Ready->value, 'in_flight_key' => null, 'path' => $result['path'], 'size_bytes' => $result['size'], 'sha256' => $result['sha256'],
            'manifest' => json_encode($result['manifest']), 'manifest_sha256' => $result['manifest_sha256'], 'manifest_signature' => $result['signature'],
            'record_count' => $result['records'], 'file_count' => $result['files'], 'completed_at' => now(), 'heartbeat_at' => now(),
            'expires_at' => now()->addDays((int) config('peopleos.offboarding.export_expiry_days', 7)), 'failure' => null, 'updated_at' => now(),
        ]));
        $export = $this->find($exportId);
        $this->audit->record(AuditAction::ExportCompleted, $tenant, $export, 'Export ready', null,
            ['export' => $export->reference, 'records' => $export->record_count, 'files' => $export->file_count, 'sha256' => $export->sha256, 'expires_at' => $export->expires_at?->toIso8601String()]);

        return true;
    }

    /** An operator, or the tenant's own holder, starts a failed export again from its checkpoint. */
    public function retry(TenantExport $export, ?User $actor, string $reason): TenantExport
    {
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        $this->authorize($tenant, $actor, 'retry this export');
        $reason = PlatformOperator::reason($reason);
        try {
            $changed = $this->tenants->bypass(fn () => TenantExport::query()->whereKey($export->getKey())->where('status', ExportStatus::Failed->value)
                ->update(['status' => ExportStatus::Queued->value, 'in_flight_key' => $tenant->getKey(), 'attempts' => 0, 'failure' => null, 'failed_at' => null, 'updated_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            throw new LifecycleRefused('Another export of this tenant is generating; retry this one when it is done.');
        }
        if ($changed !== 1) {
            throw new LifecycleRefused('Only a failed export can be retried.');
        }
        $export = $this->find($export->getKey());
        $this->audit->record(AuditAction::ExportRetried, $tenant, $export, $reason, $actor, ['export' => $export->reference]);
        GenerateTenantExport::dispatch($export->getKey(), $tenant->getKey());

        return $export;
    }

    /**
     * The sweep (peopleos:offboarding:tick): an export whose worker stopped is dispatched again (it resumes from its
     * checkpoint), or failed after the maximum attempts; a ready export past its expiry loses its file.
     *
     * @return array{resumed: int, failed: int, expired: int}
     */
    public function sweep(): array
    {
        $lease = now()->subMinutes((int) config('peopleos.offboarding.export_lease_minutes', 30));
        $max = (int) config('peopleos.offboarding.export_max_attempts', 5);
        $out = ['resumed' => 0, 'failed' => 0, 'expired' => 0];
        $stale = $this->tenants->bypass(fn () => TenantExport::query()->where('status', ExportStatus::Generating->value)->where('heartbeat_at', '<', $lease)->get());
        foreach ($stale as $export) {
            if ($export->attempts >= $max) {
                $this->fail($export, Tenant::query()->findOrFail($export->tenant_id), "The export stopped {$export->attempts} times without finishing.");
                $out['failed']++;
            } else {
                GenerateTenantExport::dispatch($export->getKey(), $export->tenant_id);
                $out['resumed']++;
            }
        }
        $queued = $this->tenants->bypass(fn () => TenantExport::query()->where('status', ExportStatus::Queued->value)->where('updated_at', '<', $lease)->get());
        foreach ($queued as $export) {
            GenerateTenantExport::dispatch($export->getKey(), $export->tenant_id);
            $out['resumed']++;
        }
        $due = $this->tenants->bypass(fn () => TenantExport::query()->where('status', ExportStatus::Ready->value)->where('expires_at', '<=', now())->get());
        foreach ($due as $export) {
            $changed = $this->tenants->bypass(fn () => TenantExport::query()->whereKey($export->getKey())->where('status', ExportStatus::Ready->value)
                ->update(['status' => ExportStatus::Expired->value, 'expired_at' => now(), 'updated_at' => now()]));
            if ($changed === 1) {
                if ($export->path !== null) {
                    Storage::disk($export->disk)->delete($export->path);
                }
                $this->audit->record(AuditAction::ExportExpired, Tenant::query()->findOrFail($export->tenant_id), $export, 'Export package expired and deleted', null, ['export' => $export->reference]);
                $out['expired']++;
            }
        }

        return $out;
    }

    /** A link valid for minutes, bound to the person who asked; the controller checks the person and the rights again. */
    public function downloadUrl(TenantExport $export, User $actor): string
    {
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        $this->authorize($tenant, $actor, 'download this export');
        if (! $export->downloadable()) {
            throw new LifecycleRefused('This export is not available for download.');
        }

        return URL::temporarySignedRoute('offboarding.exports.download', now()->addMinutes((int) config('peopleos.offboarding.download_link_minutes', 5)),
            ['reference' => $export->reference, 'u' => $actor->getKey()]);
    }

    /** Records one download (the controller streams the file). A download by the customer marks the export delivered. */
    public function recordDownload(TenantExport $export, User $actor): void
    {
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        $this->authorize($tenant, $actor, 'download this export');
        $delivered = ! $actor->isPlatformAdmin() && $export->delivered_at === null;
        $this->tenants->bypass(fn () => TenantExport::query()->whereKey($export->getKey())->update(array_filter([
            'download_count' => DB::raw('download_count + 1'), 'last_downloaded_at' => now(), 'updated_at' => now(),
            'delivered_at' => $delivered ? now() : null, 'delivery_note' => $delivered ? 'Downloaded by the customer' : null,
        ], fn ($v) => $v !== null)));
        $this->audit->record(AuditAction::ExportDownloaded, $tenant, $export, 'Export downloaded', $actor,
            ['export' => $export->reference, 'by_customer' => ! $actor->isPlatformAdmin(), 'sha256' => $export->sha256]);
    }

    public function revealPassphrase(TenantExport $export, User $actor): string
    {
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        $this->authorize($tenant, $actor, 'see this export\'s passphrase');
        if (! $export->downloadable()) {
            throw new LifecycleRefused('This export is not available.');
        }
        $this->audit->record(AuditAction::ExportPassphraseRevealed, $tenant, $export, 'Export passphrase revealed', $actor, ['export' => $export->reference]);

        return (string) $export->passphrase;
    }

    /** An operator records that the package reached the customer another way (e.g. handed over after an operator download). */
    public function markDelivered(TenantExport $export, ?User $actor, string $note): TenantExport
    {
        $actor = PlatformOperator::assert($actor, 'record an export as delivered');
        $note = PlatformOperator::reason($note);
        $changed = $this->tenants->bypass(fn () => TenantExport::query()->whereKey($export->getKey())->whereIn('status', [ExportStatus::Ready->value, ExportStatus::Expired->value])
            ->whereNull('delivered_at')->update(['delivered_at' => now(), 'delivered_by' => $actor?->getKey(), 'delivery_note' => mb_substr($note, 0, 500), 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('Only a generated export not yet delivered can be recorded as delivered.');
        }
        $export = $this->find($export->getKey());
        $this->audit->record(AuditAction::ExportDelivered, Tenant::query()->findOrFail($export->tenant_id), $export, $note, $actor, ['export' => $export->reference]);

        return $export;
    }

    public function find(int $id): TenantExport
    {
        return $this->tenants->bypass(fn () => TenantExport::query()->findOrFail($id));
    }

    private function fail(TenantExport $export, Tenant $tenant, string $failure): void
    {
        $this->tenants->bypass(fn () => TenantExport::query()->whereKey($export->getKey())->whereIn('status', [ExportStatus::Generating->value, ExportStatus::Queued->value])
            ->update(['status' => ExportStatus::Failed->value, 'in_flight_key' => null, 'failure' => mb_substr($failure, 0, 500), 'failed_at' => now(),
                'checkpoint' => null, 'updated_at' => now()]));
        // A failed export restarts cleanly on retry: its partial files and checkpoint are dropped together.
        $this->packager->discardWork($export);
        $this->audit->record(AuditAction::ExportFailed, $tenant, $export, 'Export failed', null, ['export' => $export->reference, 'failure' => mb_substr($failure, 0, 300)]);
    }
}
