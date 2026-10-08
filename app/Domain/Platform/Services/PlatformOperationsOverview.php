<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Models\AuditVerificationRun;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Models\TenantDeferredJob;
use App\Support\Observability\HealthChecks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.8 (WS8 core, G-PLAT-2): what platform operators need to run tenants without database access, per tenant and
 * platform-wide. Counts, states and job class names only: no tenant record, person or message content leaves its tenant
 * (an operator who needs to see one enters the tenant through PlatformTenantAccess, audited).
 *
 * Each figure is one grouped query across tenants (the tenant list is small; nothing here is per-row).
 */
final class PlatformOperationsOverview
{
    public function __construct(private readonly TenantContext $tenants, private readonly HealthChecks $health) {}

    /** @return list<array{tenant: Tenant, deferred: int, failed_notifications: int, stuck_notifications: int, failing_webhooks: int, dead_letters: int, invitation_needed: bool, audit: ?AuditVerificationRun, attention: bool}> */
    public function tenants(): array
    {
        $tenants = Tenant::query()->orderBy('name')->get();
        $since = now()->subDays(7);
        $lease = now()->subMinutes((int) config('peopleos.notifications.sending_lease_minutes', 15));

        [$deferred, $failed, $stuck, $webhooks, $dead, $invitees] = $this->tenants->bypass(fn () => [
            $this->grouped(TenantDeferredJob::query()),
            $this->grouped(NotificationDelivery::query()->where('status', 'failed')->where('updated_at', '>=', $since)),
            $this->grouped(NotificationDelivery::query()->where('status', 'sending')->where('updated_at', '<', $lease)),
            $this->grouped(WebhookEndpoint::query()->where('status', 'active')->where('failure_count', '>', 0)),
            $this->grouped(InboundEvent::query()->where('status', 'dead_letter')),
            User::query()->whereNotNull('tenant_id')->where('status', UserStatus::Invited->value)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'tenant-super-admin'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('user_invitations')->whereColumn('user_invitations.user_id', 'users.id')
                    ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now()))
                ->distinct()->pluck('tenant_id')->flip(),
        ]);
        $audit = $this->latestVerifications();

        return $tenants->map(function (Tenant $tenant) use ($deferred, $failed, $stuck, $webhooks, $dead, $invitees, $audit) {
            $row = [
                'tenant' => $tenant,
                'deferred' => (int) ($deferred[$tenant->id] ?? 0),
                'failed_notifications' => (int) ($failed[$tenant->id] ?? 0),
                'stuck_notifications' => (int) ($stuck[$tenant->id] ?? 0),
                'failing_webhooks' => (int) ($webhooks[$tenant->id] ?? 0),
                'dead_letters' => (int) ($dead[$tenant->id] ?? 0),
                'invitation_needed' => $invitees->has($tenant->id),
                'audit' => $audit->get($tenant->id),
            ];
            $row['attention'] = $row['deferred'] > 0 || $row['stuck_notifications'] > 0 || $row['failing_webhooks'] > 0 || $row['dead_letters'] > 0
                || $row['invitation_needed'] || ($row['audit'] !== null && ! $row['audit']->valid);

            return $row;
        })->sortByDesc('attention')->values()->all();
    }

    /** @return array{health: array<string, array<string, mixed>>, failed_jobs: list<array{job: string, count: int, last: string}>, platform_audit: ?AuditVerificationRun} */
    public function platform(): array
    {
        $checks = $this->health->run()['checks'];

        return [
            'health' => array_intersect_key($checks, array_flip(['database', 'cache', 'queue', 'failed_jobs', 'scheduler', 'integrations'])),
            'failed_jobs' => $this->failedJobs(),
            'platform_audit' => $this->latestVerifications()->get('platform'),
        ];
    }

    /** @return Collection<int|string, int> tenant id => count */
    private function grouped($query): Collection
    {
        return $query->select('tenant_id', DB::raw('count(*) as aggregate'))->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');
    }

    /** @return Collection<int|string, AuditVerificationRun> keyed by tenant id, or 'platform' */
    private function latestVerifications(): Collection
    {
        $ids = AuditVerificationRun::query()->select(DB::raw('MAX(id) as id'))->groupBy('chain', 'subject_tenant_id')->pluck('id');

        return AuditVerificationRun::query()->whereKey($ids)->get()->keyBy(fn (AuditVerificationRun $r) => $r->subject_tenant_id ?? 'platform');
    }

    /**
     * Failed jobs by job class (the payload's display name only; never the payload), most recent first.
     *
     * @return list<array{job: string, count: int, last: string}>
     */
    private function failedJobs(): array
    {
        $table = config('queue.failed.table', 'failed_jobs');
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->orderByDesc('id')->limit(1000)->get(['payload', 'failed_at'])
            ->groupBy(fn ($row) => (string) (json_decode((string) $row->payload, true)['displayName'] ?? 'unknown'))
            ->map(fn (Collection $rows, string $job) => ['job' => class_basename($job), 'count' => $rows->count(), 'last' => (string) $rows->first()->failed_at])
            ->sortByDesc('count')->take(10)->values()->all();
    }
}
