<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ProfessionalTaxReturn;
use App\Domain\Compliance\Models\ProfessionalTaxReturnEntry;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * State-aware professional tax return (Phase 5 Part J): Employee → Establishment → State →
 * PT profile → PT rule version. The state is the establishment's, never the company's. Filing
 * frequency differs by state; the rule version must say so or the return warns.
 */
final class ProfessionalTaxReturns extends PayrollLineReturns
{
    public const TYPE = 'PT';

    public function type(): string
    {
        return self::TYPE;
    }

    public function generate(Establishment $establishment, int $year, int $month, User $actor, ?string $reason = null, string $source = 'ui'): StatutoryReturn
    {
        if (blank($establishment->state)) {
            throw new RuntimeException("{$establishment->name} has no state; professional tax is state-specific.");
        }

        return parent::generate($establishment, $year, $month, $actor, $reason, $source);
    }

    protected function stateFor(Establishment $establishment): ?string
    {
        return $establishment->state;
    }

    protected function formCode(): string
    {
        return 'PT';
    }

    protected function formatCode(): string
    {
        return 'PT_RETURN';
    }

    protected function registrationType(): string
    {
        return 'pt_registration_certificate';
    }

    protected function lineCodes(): array
    {
        return ['PT'];
    }

    protected function runModel(): string
    {
        return ProfessionalTaxReturn::class;
    }

    protected function entryModel(): string
    {
        return ProfessionalTaxReturnEntry::class;
    }

    protected function runForeignKey(): string
    {
        return 'professional_tax_return_id';
    }

    protected function runAttributes(StatutoryReturn $return, Establishment $establishment): array
    {
        return [
            'statutory_registration_id' => $this->registrations->forEstablishment($establishment, 'pt_registration_certificate', $return->period_end)?->getKey(),
            'state_code' => $establishment->state,
            'return_month' => $return->period_start->toDateString(),
        ];
    }

    protected function entryAttributes(StatutoryReturn $return, PayrollEntry $payroll, ?ComplianceRule $rule): array
    {
        $line = $payroll->lines->firstWhere('code', 'PT');

        return [
            'state_code' => $line?->basis['state'] ?? $rule?->state,
            'state_source' => $line?->basis['state_source'] ?? 'unknown',
            'calc_gross' => (float) ($line?->basis['gross'] ?? $payroll->gross),
            'calc_pt_amount' => (float) ($line?->amount ?? 0),
            'export_pt_amount' => round((float) ($line?->amount ?? 0), 2),
        ];
    }

    protected function reconciledColumns(): array
    {
        return ['PT' => 'calc_pt_amount'];
    }

    protected function exportRow(Model $entry): array
    {
        /** @var ProfessionalTaxReturnEntry $entry */
        return [$entry->member_name, $entry->state_code, number_format((float) $entry->calc_gross, 2, '.', ''), number_format((float) $entry->export_pt_amount, 2, '.', '')];
    }

    protected function extraValidation(StatutoryReturn $return, Collection $entries, callable $flag, callable $add): void
    {
        foreach ($entries as $entry) {
            if ($entry->state_code !== $return->state_code) {
                $flag($entry, 'state_mismatch', 'blocking', "professional tax was deducted for state {$entry->state_code}, but the establishment is in {$return->state_code}.");
            }
            match ($entry->state_source) {
                'establishment' => null,
                'employee_override' => $flag($entry, 'state_from_employee_override', 'warning', 'the PT state came from the employee record, not the establishment; assign the employee to an establishment in that state.'),
                'legacy_company_profile' => $flag($entry, 'state_from_company_profile', 'blocking', 'the PT state came from the legacy company profile; payroll must resolve it from the establishment.'),
                default => $flag($entry, 'state_source_unknown', 'blocking', 'the payroll line does not say where its PT state came from (calculated before Phase 5); recalculate on engine payroll-2.1.'),
            };
        }

        $rule = $this->rules->resolve('PT', $return->period_end, $return->state_code);
        if ($rule === null) {
            $add('statutory_rule_missing', 'blocking', "No professional tax rule version for {$return->state_code} is effective in {$return->period_key}.");
        } elseif ($rule->param('return_frequency') === null) {
            $add('filing_frequency_unverified', 'warning', "The PT rule for {$return->state_code} does not state the return frequency; confirm monthly / quarterly / annual filing with the state authority.");
        }
    }

    public function present(Model $entry, bool $unmasked): array
    {
        /** @var ProfessionalTaxReturnEntry $entry */
        return [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'member_name' => $entry->member_name,
            'state' => $entry->state_code,
            'state_source' => $entry->state_source,
            'calculated' => ['gross' => (float) $entry->calc_gross, 'pt_amount' => (float) $entry->calc_pt_amount],
            'export' => ['pt_amount' => (float) $entry->export_pt_amount],
            'rule' => ['id' => $entry->compliance_rule_id, 'version' => $entry->rule_version, 'checksum' => $entry->rule_checksum],
            'status' => $entry->status,
            'issues' => $entry->issues['list'] ?? [],
        ];
    }
}
