<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditVerifications;
use Illuminate\Console\Command;

/** Recomputes the audit hash chains. SaaS.8: scheduled daily, and every result is recorded (audit_verification_runs). */
class VerifyAuditChain extends Command
{
    protected $signature = 'peopleos:audit:verify {--tenant= : Tenant id or slug; omit to verify every tenant and the platform chain} {--scheduled : Record the run as the scheduled one}';

    protected $description = 'Recompute the audit hash chain, record the result and report any tampering';

    public function handle(AuditVerifications $verifications): int
    {
        $option = $this->option('tenant');
        $ids = $option === null ? null : [$verifications->tenantId((string) $option)];
        $failed = false;

        // Each chain is verified in isolation by the service (a failure is recorded, the next chain still runs).
        foreach ($verifications->verify($ids, $this->option('scheduled') ? 'schedule' : 'command') as $run) {
            $label = $run->subject_tenant_id === null ? 'platform' : $run->subjectTenant?->slug;
            if ($run->valid) {
                $this->info(sprintf('%s: %d events verified', $label, $run->checked));

                continue;
            }
            $failed = true;
            $this->error(sprintf('%s: chain broken at event %s after %d events (%s)', $label, $run->broken_event_id, $run->checked, $run->reason));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
