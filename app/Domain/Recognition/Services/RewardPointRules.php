<?php

namespace App\Domain\Recognition\Services;

use App\Domain\Configuration\Exceptions\ConfigurationException;

/**
 * HROne setup parity HP-9d: checks a reward_points policy before it is published (Policies::publish calls
 * validatePolicySettings): a known credit cycle, points that are not negative, and eligibility rows with a criterion and
 * a range that runs forward.
 */
final class RewardPointRules
{
    /** @param  array<string, mixed>  $settings */
    public function validatePolicySettings(array $settings): void
    {
        $cycle = $settings['credit_cycle'] ?? null;
        if ($cycle !== null && $cycle !== '' && ! array_key_exists((string) $cycle, RewardPoints::CYCLES)) {
            throw new ConfigurationException('Choose the credit cycle: monthly, quarterly, half yearly or yearly.');
        }
        foreach (['points_per_cycle', 'max_per_award', 'max_received_per_cycle', 'expiry_months'] as $key) {
            if (isset($settings[$key]) && $settings[$key] !== '' && (! is_numeric($settings[$key]) || (float) $settings[$key] < 0)) {
                throw new ConfigurationException('Points and months cannot be negative.');
            }
        }
        if (! in_array($settings['eligibility_rules'] ?? null, [true, 1, '1', 'yes'], true)) {
            return;
        }
        $rows = array_values(array_filter((array) ($settings['eligibility'] ?? []), 'is_array'));
        if ($rows === []) {
            throw new ConfigurationException('Add at least one eligibility row, or answer no.');
        }
        foreach ($rows as $row) {
            if (! in_array($row['criterion'] ?? null, ['tenure_months', 'direct_reports'], true) || ! is_numeric($row['points'] ?? null) || (float) $row['points'] < 0) {
                throw new ConfigurationException('Each eligibility row needs a criterion and the points per cycle.');
            }
            $from = is_numeric($row['from'] ?? null) ? (float) $row['from'] : 0.0;
            if (is_numeric($row['to'] ?? null) && (float) $row['to'] < $from) {
                throw new ConfigurationException('An eligibility row ends before it starts.');
            }
        }
    }
}
