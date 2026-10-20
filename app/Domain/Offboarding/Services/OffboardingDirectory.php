<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Enums\ExportStatus;
use App\Domain\Offboarding\Enums\LegalHoldStatus;
use App\Domain\Offboarding\Models\LegalHold;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SaaS.9: read models for platform operators (Platform › Offboarding). Read-only, cross-tenant by design (each list is
 * filtered by the tenant concerned); states, references and counts only, never a tenant's records.
 */
final class OffboardingDirectory
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<array{tenant: Tenant, case: ?TenantOffboarding, export: ?TenantExport, holds: int, deletion: ?TenantDeletion}> */
    public function overview(): array
    {
        // The latest of each per tenant, and of exports and deletions only their summary columns (their manifests and plans
        // can be megabytes, which MySQL will not sort and the overview does not need).
        $cases = $this->tenants->bypass(fn () => TenantOffboarding::query()->whereIn('id', TenantOffboarding::query()->selectRaw('max(id)')->groupBy('tenant_id'))
            ->get()->keyBy('tenant_id'));
        $exports = $this->tenants->bypass(fn () => TenantExport::query()->select(TenantExport::SUMMARY_COLUMNS)
            ->whereIn('id', TenantExport::query()->selectRaw('max(id)')->groupBy('tenant_id'))->get()->keyBy('tenant_id'));
        $holds = $this->tenants->bypass(fn () => LegalHold::query()->whereIn('status', [LegalHoldStatus::Active->value, LegalHoldStatus::ReleaseRequested->value])
            ->select('tenant_id', DB::raw('count(*) as aggregate'))->groupBy('tenant_id')->pluck('aggregate', 'tenant_id'));
        $deletions = $this->tenants->bypass(fn () => TenantDeletion::query()->select(TenantDeletion::SUMMARY_COLUMNS)
            ->whereIn('id', TenantDeletion::query()->selectRaw('max(id)')->groupBy('tenant_id'))->get()->keyBy('tenant_id'));

        return Tenant::query()->orderBy('name')->get()->map(fn (Tenant $tenant) => [
            'tenant' => $tenant, 'case' => $cases->get($tenant->id), 'export' => $exports->get($tenant->id),
            'holds' => (int) ($holds[$tenant->id] ?? 0), 'deletion' => $deletions->get($tenant->id),
        ])->all();
    }

    /** @return Collection<int, TenantExport> */
    public function exports(int $tenantId): Collection
    {
        return $this->tenants->bypass(fn () => TenantExport::query()->select(TenantExport::SUMMARY_COLUMNS)->where('tenant_id', $tenantId)->orderByDesc('id')->limit(20)->get());
    }

    /** @return Collection<int, LegalHold> */
    public function holds(int $tenantId): Collection
    {
        return $this->tenants->bypass(fn () => LegalHold::query()->where('tenant_id', $tenantId)->orderByDesc('id')->get());
    }

    /** @return Collection<int, TenantDeletion> */
    public function deletions(int $tenantId): Collection
    {
        // With their plans and certificates (shown on the page): a tenant has a handful, sorted here rather than by MySQL.
        return $this->tenants->bypass(fn () => TenantDeletion::query()->where('tenant_id', $tenantId)->get()->sortByDesc('id')->values());
    }

    /** @return Collection<int, TenantDeletionStep> */
    public function steps(int $deletionId): Collection
    {
        return $this->tenants->bypass(fn () => TenantDeletionStep::query()->where('tenant_deletion_id', $deletionId)->orderBy('position')->get());
    }

    public function export(int $id): ?TenantExport
    {
        return $this->tenants->bypass(fn () => TenantExport::query()->find($id));
    }

    public function hold(int $id): ?LegalHold
    {
        return $this->tenants->bypass(fn () => LegalHold::query()->find($id));
    }

    public function deletion(int $id): ?TenantDeletion
    {
        return $this->tenants->bypass(fn () => TenantDeletion::query()->find($id));
    }

    public function step(int $id): ?TenantDeletionStep
    {
        return $this->tenants->bypass(fn () => TenantDeletionStep::query()->find($id));
    }

    public function lastCase(int $tenantId): ?TenantOffboarding
    {
        return $this->tenants->bypass(fn () => TenantOffboarding::query()->where('tenant_id', $tenantId)->orderByDesc('id')->first());
    }

    public function exportByReference(string $reference): ?TenantExport
    {
        return $this->tenants->bypass(fn () => TenantExport::query()->where('reference', $reference)->first());
    }

    /** The deletion currently in flight for a tenant (planned, approved, running or failed), if any. */
    public function inFlightDeletion(int $tenantId): ?TenantDeletion
    {
        return $this->tenants->bypass(fn () => TenantDeletion::query()->where('in_flight_key', $tenantId)->first());
    }

    public static function exportLabel(TenantExport $export): string
    {
        return match ($export->status) {
            ExportStatus::Ready => $export->downloadable() ? 'Ready' : 'Expired',
            default => ucfirst(str_replace('_', ' ', $export->status->value)),
        };
    }

    public static function deletionLabel(TenantDeletion $deletion): string
    {
        return match ($deletion->status) {
            DeletionStatus::Approved => $deletion->scheduled_for?->isFuture() ? 'Approved (cooling-off until '.$deletion->scheduled_for->toDateString().')' : 'Approved (due)',
            default => ucfirst(str_replace('_', ' ', $deletion->status->value)),
        };
    }
}
