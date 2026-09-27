<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'peopleos:audit:verify {--tenant= : Tenant id or slug; omit to verify every tenant and the platform chain}';

    protected $description = 'Recompute the audit hash chain and report any tampering';

    public function handle(AuditIntegrityVerifier $verifier): int
    {
        $tenantIds = $this->tenantIds();
        $failed = false;

        foreach ($tenantIds as $label => $tenantId) {
            $result = $verifier->verify($tenantId);

            if ($result['valid']) {
                $this->info(sprintf('%s: %d events verified', $label, $result['checked']));

                continue;
            }

            $failed = true;
            $this->error(sprintf('%s: chain broken at event %s after %d events (%s)', $label, $result['broken_event_id'], $result['checked'], $result['reason']));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, ?int> */
    private function tenantIds(): array
    {
        $option = $this->option('tenant');

        if ($option !== null) {
            $tenant = Tenant::query()->where('id', $option)->orWhere('slug', $option)->firstOrFail();

            return [$tenant->slug => $tenant->id];
        }

        $ids = ['platform' => null];

        foreach (Tenant::query()->orderBy('id')->get(['id', 'slug']) as $tenant) {
            $ids[$tenant->slug] = $tenant->id;
        }

        return $ids;
    }
}
