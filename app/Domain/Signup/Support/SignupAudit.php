<?php

namespace App\Domain\Signup\Support;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Signup\Models\SignupApplication;
use Illuminate\Support\Collection;

/**
 * SaaS.10: every signup transition and e-mail on Markedge's platform chain, and once a tenant exists on its chain too.
 * The metadata names the application by reference, its country, plan and states; never the applicant's e-mail, name or
 * company details (the application row holds them, the platform chain does not). An applicant's own steps are
 * recorded anonymously (no IP address or user agent on the chain); operators are named as actors.
 */
final class SignupAudit
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * An application's events on the platform chain (newest first), for the operators' page: platform rows only
     * (tenant_id null), filtered by the application's reference.
     *
     * @return Collection<int, AuditEvent>
     */
    public function timeline(SignupApplication $application, int $limit = 40): Collection
    {
        return AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('module', 'signup')
            ->where('metadata->reference', $application->reference)->with('actor:id,name')->latest('id')->limit($limit)->get();
    }

    /** @param  array<string, mixed>  $metadata */
    public function record(AuditAction $action, SignupApplication $application, ?string $from, ?User $actor, ?string $reason = null, array $metadata = [], bool $tenantChain = false): void
    {
        $metadata = ['reference' => $application->reference, 'from' => $from, 'to' => $application->status?->value, 'country' => $application->country_code,
            'plan' => $application->plan_code] + $metadata;
        $anonymous = $actor === null && ! app()->runningInConsole();
        $this->audit->record($action, 'signup', null, [], $reason, entityLabel: "Signup {$application->reference}", actor: $actor, metadata: $metadata,
            anonymous: $anonymous, platform: true);
        if ($tenantChain && $application->provisioned_tenant_id !== null) {
            $this->audit->record($action, 'signup', null, [], $reason, entityLabel: "Signup {$application->reference}", tenantId: (int) $application->provisioned_tenant_id,
                actor: $actor, metadata: $metadata + ['subject_tenant_id' => (int) $application->provisioned_tenant_id], anonymous: $anonymous);
        }
    }
}
