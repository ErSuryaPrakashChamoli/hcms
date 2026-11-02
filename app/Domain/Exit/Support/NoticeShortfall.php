<?php

namespace App\Domain\Exit\Support;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Exit\Models\ExitCase;

/**
 * HROne parity WO-11 (§23.6.1 #6, #9–10, #18): the notice an employee leaves without serving.
 *
 * Served days run from the resignation date to the last working day; the shortfall is the notice period less the days
 * served and less the notice relaxation days HR granted (0 by default), never below zero. Only resignations carry a
 * shortfall (as the settlement has always worked). The recovery rule comes from the exit policy (`notice_recovery_rule`):
 * blank keeps the organisation setting `exit.notice_recovery` and the built-in line of the full and final settlement;
 * `none` recovers nothing; `policy` recovers through NoticeRecoverySettlementSource on the policy's basis and divisor.
 */
final class NoticeShortfall
{
    /** @return array{notice_days: int, served_days: int, relaxation_days: int, shortfall_days: int}|null null when it does not apply */
    public static function for(ExitCase $case): ?array
    {
        $attributes = $case->getAttributes();
        if (($attributes['type'] ?? null) !== 'resignation' || ($attributes['resignation_date'] ?? null) === null || ($attributes['last_working_day'] ?? null) === null) {
            return null;
        }
        $notice = (int) ($attributes['notice_days'] ?? 0);
        $served = (int) $case->resignation_date->diffInDays($case->last_working_day);
        $relaxation = max(0, (int) ($attributes['notice_relaxation_days'] ?? 0));

        return ['notice_days' => $notice, 'served_days' => $served, 'relaxation_days' => $relaxation, 'shortfall_days' => max(0, $notice - $served - $relaxation)];
    }

    public static function days(ExitCase $case): int
    {
        return self::for($case)['shortfall_days'] ?? 0;
    }

    /**
     * The exit policy's recovery rule for the leaver on the last working day: null (blank: the organisation setting and
     * the built-in line), 'none' or 'policy' (with its basis, divisor and fixed days).
     *
     * @return array{rule: ?string, basis: string, divisor: string, days: ?int}
     */
    public static function rule(ExitCase $case): array
    {
        $employee = $case->relationLoaded('employee') ? $case->employee : $case->employee()->first();
        $policy = $employee !== null ? app(PolicyResolver::class)->resolve('exit', $employee, $case->last_working_day ?? now()) : null;
        $rule = $policy?->setting('notice_recovery_rule');
        $days = $policy?->setting('notice_recovery_days');

        return [
            'rule' => in_array($rule, ['none', 'policy'], true) ? $rule : null,
            'basis' => (string) ($policy?->setting('notice_recovery_basis') ?: 'gross'),
            'divisor' => (string) ($policy?->setting('notice_recovery_divisor') ?: 'fixed'),
            'days' => is_numeric($days) && (int) $days > 0 ? (int) $days : null,
        ];
    }
}
