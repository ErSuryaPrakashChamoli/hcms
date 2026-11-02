<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\ComplianceRule;
use Carbon\CarbonInterface;

/**
 * HROne parity MS-9 / MS-10: a statutory threshold for a self-service page (wage share, minimum wage, PF / ESI ceilings)
 * only from a rule version that is in force on the date (and state), VERIFIED, checksum-intact, free of an open regulatory
 * notice and carrying a positive numeric parameter — the same test as the payroll readiness panel (PO-3). Otherwise null:
 * the page says "No verified rule" and never falls back to a number in code or config.
 */
final class VerifiedThresholds
{
    /** @var array<string, ComplianceRule|null> lookups of this request */
    private array $memo = [];

    public function __construct(private readonly ComplianceRules $rules) {}

    /**
     * The usable rule version for the parameter, or null. HROne parity PO-2: with no parameter, the rule version itself
     * (verified, intact, no open notice) — for estimates that read a rule's whole parameter set, such as PT slabs.
     */
    public function rule(string $code, ?string $parameter, CarbonInterface $on, ?string $state = null): ?ComplianceRule
    {
        $key = $code.'|'.$parameter.'|'.$state.'|'.$on->toDateString();
        if (! array_key_exists($key, $this->memo)) {
            $rule = $this->rules->resolve($code, $on, $state);
            $usable = $rule !== null && $rule->isVerified() && $rule->checksumIntact() && $this->rules->pendingNotice($rule, $on) === null
                && ($parameter === null || (is_numeric($rule->param($parameter)) && (float) $rule->param($parameter) > 0));
            $this->memo[$key] = $usable ? $rule : null;
        }

        return $this->memo[$key];
    }

    /** @return array{value: float, rule: string}|null the verified figure and the rule it comes from */
    public function value(string $code, string $parameter, CarbonInterface $on, ?string $state = null): ?array
    {
        $rule = $this->rule($code, $parameter, $on, $state);

        return $rule === null ? null : ['value' => (float) $rule->param($parameter), 'rule' => $rule->label()];
    }

    /** Whether a specific rule version (as recorded on a payroll line) is usable for the parameter today. */
    public function recorded(?int $ruleId, string $parameter, CarbonInterface $on): ?array
    {
        if ($ruleId === null) {
            return null;
        }
        $rule = ComplianceRule::query()->find($ruleId);
        $usable = $rule !== null && $rule->isVerified() && $rule->checksumIntact() && $this->rules->pendingNotice($rule, $on) === null
            && is_numeric($rule->param($parameter)) && (float) $rule->param($parameter) > 0;

        return $usable ? ['value' => (float) $rule->param($parameter), 'rule' => $rule->label()] : null;
    }
}
