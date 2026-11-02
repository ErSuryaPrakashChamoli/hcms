<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use Carbon\CarbonInterface;

/**
 * HROne parity MS-4 (matrix 22 §22.2.8 #3, #4): the gratuity and leave-encashment exemptions an employee already used at a
 * previous employer, declared on the investment declaration (`previous_employer.gratuity_exempt`,
 * `previous_employer.leave_encashment_exempt`).
 *
 * The exemption limits are statutory values, so they are NEVER in code or config: they are read only from the income-tax
 * rule version in force (code `TDS`, parameters `gratuity_exemption_limit` / `leave_encashment_exemption_limit`) through
 * VerifiedThresholds — verified, checksum-intact, no open notice. Without such a rule the declared amounts are kept and
 * shown, and have no effect on tax ("not counted"). With one, the exemption still available here is the limit less what
 * was used before (never below zero), for the settlement / payout tax (BP-10) to use; monthly salary TDS exempts no
 * gratuity or leave encashment, so the monthly tax itself does not change.
 */
final class PriorExemptions
{
    /** kind => [declaration key, TDS rule parameter, label] */
    public const KINDS = [
        'gratuity' => ['key' => 'gratuity_exempt', 'parameter' => 'gratuity_exemption_limit', 'label' => 'Gratuity exemption used at the previous employer'],
        'leave_encashment' => ['key' => 'leave_encashment_exempt', 'parameter' => 'leave_encashment_exemption_limit', 'label' => 'Leave encashment exemption used at the previous employer'],
    ];

    public const RULE_CODE = 'TDS';

    public function __construct(private readonly VerifiedThresholds $thresholds) {}

    /**
     * The declared prior exemptions (only those above zero) and whether a verified rule counts them.
     *
     * @return array<string, array{declared: float, counted: bool, limit: ?float, remaining: ?float, rule: ?string}>
     */
    public function for(?EmployeeTaxDeclaration $declaration, CarbonInterface $on): array
    {
        if ($declaration === null) {
            return [];
        }
        $out = [];
        foreach (self::KINDS as $kind => $definition) {
            $declared = round((float) ($declaration->previousEmployer($definition['key']) ?? 0), 2);
            if ($declared <= 0) {
                continue;
            }
            $verified = $this->thresholds->value(self::RULE_CODE, $definition['parameter'], $on);
            $out[$kind] = [
                'declared' => $declared,
                'counted' => $verified !== null,
                'limit' => $verified['value'] ?? null,
                'remaining' => $verified === null ? null : round(max(0.0, $verified['value'] - $declared), 2),
                'rule' => $verified['rule'] ?? null,
            ];
        }

        return $out;
    }

    /** Whether a verified rule in force on $on carries the limit for $kind (screens say "not counted" otherwise). */
    public function counted(string $kind, CarbonInterface $on): bool
    {
        return isset(self::KINDS[$kind]) && $this->thresholds->value(self::RULE_CODE, self::KINDS[$kind]['parameter'], $on) !== null;
    }
}
