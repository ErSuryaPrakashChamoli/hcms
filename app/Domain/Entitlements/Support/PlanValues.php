<?php

namespace App\Domain\Entitlements\Support;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;

/**
 * SaaS.5: one vocabulary for what a plan version says about a capability, used by Platform › Plans, the Entitlements
 * page and the explain command, so the screens say what the engine decides:
 *
 * | Plan content                                   | Label                      | Engine (tenant on the plan)          |
 * |------------------------------------------------|----------------------------|--------------------------------------|
 * | module or feature true                          | included                   | ALLOW, ENTITLED                      |
 * | module or feature false                         | excluded                   | DENY, NOT_ENTITLED                   |
 * | module or feature absent                        | not in plan                | DENY, NOT_IN_PLAN                    |
 * | limit N                                         | N with its unit            | ALLOW within / DENY exceeded / UNKNOWN unmeasured |
 * | limit null                                      | unlimited                  | ALLOW, UNLIMITED                     |
 * | limit absent                                    | not set (no agreed limit)  | UNKNOWN, LIMIT_NOT_CONFIGURED        |
 * | limit whose module is not included              | not included (module …)    | DENY, MODULE_NOT_ENTITLED            |
 */
final class PlanValues
{
    /** @param  array<string, bool|int|null>  $values  the version's content: capability key => value */
    public static function label(Capability $capability, array $values): string
    {
        if (! $capability->commercial()) {
            return '';
        }
        if ($capability->followsModule() && ($values[$capability->module()->value] ?? null) !== true) {
            return "not included ({$capability->module()->value} not in plan)";
        }
        if (! array_key_exists($capability->value, $values)) {
            return $capability->type() === CapabilityType::Limit ? 'not set (no agreed limit)' : 'not in plan';
        }
        $value = $values[$capability->value];

        return match (true) {
            $capability->type() === CapabilityType::Limit && $value === null => 'unlimited',
            $capability->type() === CapabilityType::Limit => self::amount($capability, (int) $value),
            (bool) $value => 'included',
            default => 'excluded',
        };
    }

    /** A limit value in its unit: "1,200 employees", "50 GiB (53,687,091,200 bytes)". */
    public static function amount(Capability $capability, int $value): string
    {
        if ($capability->unit() !== 'bytes') {
            return number_format($value).' '.$capability->unit();
        }
        foreach (['TiB' => 1024 ** 4, 'GiB' => 1024 ** 3, 'MiB' => 1024 ** 2, 'KiB' => 1024] as $unit => $size) {
            if ($value >= $size) {
                return rtrim(rtrim(number_format($value / $size, 2), '0'), '.')." {$unit} (".number_format($value).' bytes)';
            }
        }

        return number_format($value).' bytes';
    }
}
