<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionReason;
use App\Domain\Entitlements\Support\Decision;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS.3: the one contract HCM code uses for commercial entitlements.
 *
 *   app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.calculate');
 *
 * It answers "is THIS TENANT entitled to the capability on this business date?". It never answers whether a user
 * may act (permissions, scopes and policies do, unchanged), never replaces a permission check, and in SaaS.3 never
 * blocks anything:
 * - every method returns a Decision and never throws: a technical failure is an UNKNOWN decision with reason
 *   EVALUATION_FAILED, logged and observable, and the HCM action continues (fail open, commercial only;
 *   authorisation stays fail-closed);
 * - Decision::enforced() is always false; there is no enforcing mode to switch on.
 *
 * Modes (peopleos.entitlements.mode): `shadow` (evaluate and record) or `off` (evaluate on request only; observe()
 * records nothing). Any other value is treated as `shadow`.
 */
final class Entitlements
{
    public function __construct(private readonly TenantContext $tenants, private readonly EntitlementEvaluator $evaluator) {}

    public function mode(): string
    {
        return config('peopleos.entitlements.mode') === 'off' ? 'off' : 'shadow';
    }

    /** The bound tenant's decision for a capability on a business date (default: today, application time zone). */
    public function evaluate(Capability $capability, CarbonInterface|string|null $at = null, ?int $usage = null): Decision
    {
        $tenantId = $this->tenants->id();
        try {
            $day = $this->day($at);
        } catch (Throwable) {
            return Decision::unknown($capability, DecisionReason::EvaluationFailed, $tenantId, $this->day(null));
        }
        if ($tenantId === null) {
            return Decision::unknown($capability, DecisionReason::NoTenantContext, null, $day);
        }

        return $this->decideFor($tenantId, $capability, $day, $usage);
    }

    /**
     * Platform diagnostics only (operators, console): another tenant's decision. Reads that tenant's state in its
     * own tenant context; HCM code never calls this.
     */
    public function evaluateFor(int $tenantId, Capability $capability, CarbonInterface|string|null $at = null, ?int $usage = null): Decision
    {
        try {
            $day = $this->day($at);
        } catch (Throwable) {
            return Decision::unknown($capability, DecisionReason::EvaluationFailed, $tenantId, $this->day(null));
        }

        return $this->decideFor($tenantId, $capability, $day, $usage);
    }

    /**
     * Evaluate for the bound tenant and record the shadow observation for this surface (an HCM operation such as
     * `payroll.run.calculate`). The caller must not act on the result.
     */
    public function observe(Capability $capability, string $surface, ?int $usage = null): Decision
    {
        if ($this->mode() === 'off') {
            return Decision::unknown($capability, DecisionReason::ShadowDisabled, $this->tenants->id(), $this->day(null), $surface);
        }
        $decision = $this->evaluate($capability, null, $usage)->withSurface($surface);
        $this->record($decision);

        return $decision;
    }

    /**
     * A limit observation whose usage is measured only when a finite limit applies today, so tenants without a
     * configured limit never pay for the count.
     *
     * @param  Closure(): int  $usage
     */
    public function observeLimit(Capability $capability, string $surface, Closure $usage): Decision
    {
        if ($this->mode() === 'off') {
            return Decision::unknown($capability, DecisionReason::ShadowDisabled, $this->tenants->id(), $this->day(null), $surface);
        }
        $decision = $this->evaluate($capability);
        if ($decision->reason === DecisionReason::UsageUnavailable) {
            try {
                $decision = $this->evaluate($capability, null, $usage());
            } catch (Throwable $e) {
                Log::warning('entitlements.usage_failed', ['capability' => $capability->value, 'tenant_id' => $decision->tenantId, 'error' => $e::class]);
            }
        }
        $decision = $decision->withSurface($surface);
        $this->record($decision);

        return $decision;
    }

    private function decideFor(int $tenantId, Capability $capability, string $day, ?int $usage): Decision
    {
        try {
            return $this->evaluator->decide(app(EntitlementStateStore::class)->for($tenantId), $capability, $day, $usage);
        } catch (Throwable $e) {
            Log::warning('entitlements.evaluation_failed', ['tenant_id' => $tenantId, 'capability' => $capability->value, 'error' => $e::class]);

            return Decision::unknown($capability, DecisionReason::EvaluationFailed, $tenantId, $day);
        }
    }

    private function record(Decision $decision): void
    {
        try {
            app(ShadowRecorder::class)->record($decision);
        } catch (Throwable $e) {
            Log::warning('entitlements.shadow_record_failed', ['capability' => $decision->capability->value, 'error' => $e::class]);
        }
    }

    private function day(CarbonInterface|string|null $at): string
    {
        return Carbon::parse($at ?? now())->toDateString();
    }
}
