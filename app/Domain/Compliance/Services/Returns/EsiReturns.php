<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EsiReturnEntry;
use App\Domain\Compliance\Models\EsiReturnRun;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ESI monthly contribution return (Phase 5 Part I) per establishment. Contribution periods come
 * from the ESI rule version (April–September, October–March per esic.gov.in). The file is prepared
 * for upload on the ESIC portal; nothing is submitted from here.
 *
 * Known payroll limitation surfaced as a warning: payroll decides ESI coverage month by month; ESI
 * coverage continues to the end of a contribution period once an employee is covered.
 */
final class EsiReturns extends PayrollLineReturns
{
    public const TYPE = 'ESI';

    public function type(): string
    {
        return self::TYPE;
    }

    protected function formCode(): string
    {
        return 'ESI_MC';
    }

    protected function formatCode(): string
    {
        return 'ESI_MC';
    }

    protected function registrationType(): string
    {
        return 'esic_employer_code';
    }

    protected function lineCodes(): array
    {
        return ['ESI_EE', 'ESI_ER'];
    }

    protected function runModel(): string
    {
        return EsiReturnRun::class;
    }

    protected function entryModel(): string
    {
        return EsiReturnEntry::class;
    }

    protected function runForeignKey(): string
    {
        return 'esi_return_run_id';
    }

    protected function runAttributes(StatutoryReturn $return, Establishment $establishment): array
    {
        return [
            'statutory_registration_id' => $this->registrations->forEstablishment($establishment, 'esic_employer_code', $return->period_end)?->getKey(),
            'contribution_month' => $return->period_start->toDateString(),
            'contribution_period' => $this->contributionPeriod($return->period_start, $this->rules->resolve('ESI', $return->period_end)),
        ];
    }

    protected function entryAttributes(StatutoryReturn $return, PayrollEntry $payroll, ?ComplianceRule $rule): array
    {
        $ee = $payroll->lines->firstWhere('code', 'ESI_EE');
        $er = $payroll->lines->firstWhere('code', 'ESI_ER');
        $wages = (float) ($ee?->basis['wages'] ?? 0);

        return [
            ...$this->identifier('ip_number', $payroll->employee?->statutoryDetail?->esic_number),
            'contribution_period' => $this->contributionPeriod($return->period_start, $rule),
            'calc_days' => (float) $payroll->paid_days,
            'calc_wages' => $wages,
            'calc_ee_contribution' => (float) ($ee?->amount ?? 0),
            'calc_er_contribution' => (float) ($er?->amount ?? 0),
            'export_days' => (int) floor((float) $payroll->paid_days),
            'export_wages' => round($wages, 2),
        ];
    }

    protected function reconciledColumns(): array
    {
        return ['ESI_EE' => 'calc_ee_contribution', 'ESI_ER' => 'calc_er_contribution'];
    }

    protected function exportRow(Model $entry): array
    {
        /** @var EsiReturnEntry $entry */
        return [$entry->ip_number, $entry->member_name, $entry->export_days, number_format((float) $entry->export_wages, 2, '.', ''), '', ''];
    }

    protected function extraValidation(StatutoryReturn $return, Collection $entries, callable $flag, callable $add): void
    {
        $pattern = config('peopleos.compliance.formats.ESI_MC.ip_pattern');
        $duplicates = $entries->whereNotNull('ip_number_hash')->groupBy('ip_number_hash')->filter(fn ($g) => $g->count() > 1);

        foreach ($entries as $entry) {
            if ($entry->ip_number === null) {
                $flag($entry, 'missing_ip', 'blocking', 'no ESI insurance (IP) number on file.');
            } elseif ($pattern && ! preg_match($pattern, $entry->ip_number)) {
                $flag($entry, 'invalid_ip', 'blocking', 'the IP number does not match the expected format.');
            }
            if (($entry->ip_number_hash && $duplicates->has($entry->ip_number_hash)) || ($entry->issues['duplicate_identifier'] ?? false)) {
                $flag($entry, 'duplicate_ip', 'blocking', 'the same IP number appears more than once.');
            }
            if ((float) $entry->calc_days <= 0 && (float) $entry->calc_wages > 0) {
                $flag($entry, 'invalid_days', 'blocking', 'wages are reported with zero days.');
            }
            if ((float) $entry->calc_wages <= 0) {
                $flag($entry, 'missing_wages', 'blocking', 'ESI wages are zero although ESI was deducted.');
            }

            $rule = $entry->compliance_rule_id ? ComplianceRule::query()->find($entry->compliance_rule_id) : null;
            $threshold = $rule?->param('employee_exempt_daily_average_wage');
            if ($threshold === null) {
                $flag($entry, 'low_wage_exemption_not_checked', 'warning', 'the ESI rule version carries no daily-average-wage exemption threshold, so the employee-contribution exemption was not checked.');
            } elseif ((float) $entry->calc_days > 0 && (float) $entry->calc_wages / (float) $entry->calc_days <= (float) $threshold && (float) $entry->calc_ee_contribution > 0) {
                $flag($entry, 'low_wage_exemption', 'blocking', 'daily average wage is within the exemption threshold, but an employee contribution was deducted.');
            }
        }

        // Coverage continuity: covered earlier in the contribution period but not deducted now.
        $period = $entries->first()?->contribution_period ?? $this->contributionPeriod($return->period_start, $this->rules->resolve('ESI', $return->period_end));
        $earlierIds = EsiReturnEntry::query()->withoutGlobalScope(AccessScope::class)->where('contribution_period', $period)
            ->whereIn('statutory_return_id', StatutoryReturn::query()->where('return_type', self::TYPE)->where('establishment_id', $return->establishment_id)
                ->where('period_start', '<', $return->period_start->toDateString())->where('status', '!=', StatutoryReturn::CANCELLED)->pluck('id'))
            ->pluck('employee_id')->unique();
        $missing = $earlierIds->diff($entries->pluck('employee_id'));
        foreach ($missing as $employeeId) {
            $add('contribution_period_continuity', 'warning', 'An employee covered earlier in this contribution period has no ESI this month; coverage continues to the end of the contribution period (payroll decides coverage month by month).', (int) $employeeId);
        }
    }

    public function present(Model $entry, bool $unmasked): array
    {
        /** @var EsiReturnEntry $entry */
        return [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'member_name' => $entry->member_name,
            'ip_number' => $unmasked ? $entry->ip_number : $entry->maskedIp(),
            'contribution_period' => $entry->contribution_period,
            'calculated' => ['days' => (float) $entry->calc_days, 'wages' => (float) $entry->calc_wages, 'ee_contribution' => (float) $entry->calc_ee_contribution, 'er_contribution' => (float) $entry->calc_er_contribution],
            'export' => ['days' => $entry->export_days, 'wages' => (float) $entry->export_wages],
            'rule' => ['id' => $entry->compliance_rule_id, 'version' => $entry->rule_version, 'checksum' => $entry->rule_checksum],
            'status' => $entry->status,
            'issues' => $entry->issues['list'] ?? [],
        ];
    }

    /** "2026-04..2026-09" from the rule's contribution periods ([[startMonth, endMonth], ...]). */
    private function contributionPeriod(Carbon $month, ?ComplianceRule $rule): string
    {
        foreach ((array) $rule?->param('contribution_periods', []) as [$from, $to]) {
            $inside = $from <= $to ? ($month->month >= $from && $month->month <= $to) : ($month->month >= $from || $month->month <= $to);
            if ($inside) {
                $startYear = $month->month >= $from ? $month->year : $month->year - 1;
                $start = Carbon::create($startYear, $from, 1);
                $end = $from <= $to ? Carbon::create($startYear, $to, 1) : Carbon::create($startYear + 1, $to, 1);

                return $start->format('Y-m').'..'.$end->format('Y-m');
            }
        }

        return 'unknown';
    }
}
