<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Organisation tax settings (HROne parity PY-12, PY-13, CF-12), all off by default so existing behaviour holds:
 * the declaration window, the proof window, the date from which TDS counts only verified proofs, whether the regime
 * is locked once submitted, landlord PAN and the number of landlords per rent declaration.
 */
final class TaxSettings
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** Employees may change their declaration today (payroll always may): within the dates and the days of the month. */
    public function declarationOpen(?CarbonInterface $on = null): bool
    {
        $day = (int) ($on ?? now())->day;
        $from = (int) ($this->settings->get('payroll.tax.declaration_day_from') ?? 0);
        $to = (int) ($this->settings->get('payroll.tax.declaration_day_to') ?? 0);
        $inDays = $from <= 0 || $to <= 0 || ($from <= $to ? ($day >= $from && $day <= $to) : ($day >= $from || $day <= $to));

        return $inDays && $this->within('payroll.tax.declaration_from', 'payroll.tax.declaration_to', $on);
    }

    /** HROne setup parity (PS-2): whether payroll may change an employee's submitted declaration (else verify or send back only). */
    public function payrollMayEdit(): bool
    {
        return (bool) $this->settings->get('payroll.tax.payroll_may_edit', true);
    }

    /** never | after_submit | always — when Form 12BB can be printed. */
    public function form12bbVisibility(): string
    {
        return (string) $this->settings->get('payroll.tax.form12bb_visibility', 'always');
    }

    public function showEstimator(): bool
    {
        return (bool) $this->settings->get('payroll.tax.show_estimator', false);
    }

    public function form16SelfService(): bool
    {
        return (bool) $this->settings->get('payroll.tax.form16_self_service', false);
    }

    /** Employees may submit investment proofs today. */
    public function proofOpen(?CarbonInterface $on = null): bool
    {
        return $this->within('payroll.tax.proof_from', 'payroll.tax.proof_to', $on);
    }

    public function declarationWindow(): string
    {
        return $this->describe('payroll.tax.declaration_from', 'payroll.tax.declaration_to');
    }

    public function proofWindow(): string
    {
        return $this->describe('payroll.tax.proof_from', 'payroll.tax.proof_to');
    }

    /** From this payment date, TDS counts verified proof amounts instead of declared amounts. */
    public function verifiedProofsApply(CarbonInterface $paymentDate): bool
    {
        $from = $this->date('payroll.tax.verified_proofs_from');

        return $from !== null && $paymentDate->toDateString() >= $from;
    }

    public function verifiedProofsFrom(): ?string
    {
        return $this->date('payroll.tax.verified_proofs_from');
    }

    public function regimeLockedAfterSubmit(): bool
    {
        return (bool) $this->settings->get('payroll.tax.regime_locked_after_submit', false);
    }

    public function landlordPanRequired(): bool
    {
        return (bool) $this->settings->get('payroll.tax.landlord_pan_required', false);
    }

    public function maxLandlords(): int
    {
        return max(1, min(5, (int) $this->settings->get('payroll.tax.max_landlords', 1)));
    }

    private function within(string $fromKey, string $toKey, ?CarbonInterface $on): bool
    {
        $day = ($on ?? now())->toDateString();
        $from = $this->date($fromKey);
        $to = $this->date($toKey);

        return ($from === null || $day >= $from) && ($to === null || $day <= $to);
    }

    private function describe(string $fromKey, string $toKey): string
    {
        $from = $this->date($fromKey);
        $to = $this->date($toKey);
        $format = fn (string $d) => Carbon::parse($d)->format('j M Y');

        return match (true) {
            $from !== null && $to !== null => 'from '.$format($from).' to '.$format($to),
            $from !== null => 'from '.$format($from),
            $to !== null => 'until '.$format($to),
            default => 'at any time',
        };
    }

    private function date(string $key): ?string
    {
        $value = $this->settings->get($key);

        return filled($value) ? substr((string) $value, 0, 10) : null;
    }
}
