<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\LwfReturn;
use App\Domain\Compliance\Models\LwfReturnEntry;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Labour welfare fund return (Phase 5 Part K), jurisdiction-aware and extensible: contribution
 * months, amounts and ceilings come from the state's LWF rule version; the state is the
 * establishment's. A state without a rule version cannot produce a return.
 */
final class LwfReturns extends PayrollLineReturns
{
    public const TYPE = 'LWF';

    public function type(): string
    {
        return self::TYPE;
    }

    public function generate(Establishment $establishment, int $year, int $month, User $actor, ?string $reason = null, string $source = 'ui'): StatutoryReturn
    {
        if (blank($establishment->state)) {
            throw new RuntimeException("{$establishment->name} has no state; labour welfare fund is state-specific.");
        }

        return parent::generate($establishment, $year, $month, $actor, $reason, $source);
    }

    protected function stateFor(Establishment $establishment): ?string
    {
        return $establishment->state;
    }

    protected function formCode(): string
    {
        return 'LWF';
    }

    protected function formatCode(): string
    {
        return 'LWF_RETURN';
    }

    protected function registrationType(): string
    {
        return 'lwf_registration';
    }

    protected function lineCodes(): array
    {
        return ['LWF_EE', 'LWF_ER'];
    }

    protected function runModel(): string
    {
        return LwfReturn::class;
    }

    protected function entryModel(): string
    {
        return LwfReturnEntry::class;
    }

    protected function runForeignKey(): string
    {
        return 'lwf_return_id';
    }

    protected function runAttributes(StatutoryReturn $return, Establishment $establishment): array
    {
        return [
            'statutory_registration_id' => $this->registrations->forEstablishment($establishment, 'lwf_registration', $return->period_end)?->getKey(),
            'state_code' => $establishment->state,
            'return_month' => $return->period_start->toDateString(),
        ];
    }

    protected function entryAttributes(StatutoryReturn $return, PayrollEntry $payroll, ?ComplianceRule $rule): array
    {
        $ee = $payroll->lines->firstWhere('code', 'LWF_EE');
        $er = $payroll->lines->firstWhere('code', 'LWF_ER');

        return [
            'state_code' => $ee?->basis['state'] ?? $rule?->state,
            'calc_gross' => (float) $payroll->gross,
            'calc_ee_contribution' => (float) ($ee?->amount ?? 0),
            'calc_er_contribution' => (float) ($er?->amount ?? 0),
            'export_ee_contribution' => round((float) ($ee?->amount ?? 0), 2),
            'export_er_contribution' => round((float) ($er?->amount ?? 0), 2),
        ];
    }

    protected function reconciledColumns(): array
    {
        return ['LWF_EE' => 'calc_ee_contribution', 'LWF_ER' => 'calc_er_contribution'];
    }

    protected function exportRow(Model $entry): array
    {
        /** @var LwfReturnEntry $entry */
        return [$entry->member_name, $entry->state_code, number_format((float) $entry->export_ee_contribution, 2, '.', ''), number_format((float) $entry->export_er_contribution, 2, '.', '')];
    }

    protected function extraValidation(StatutoryReturn $return, Collection $entries, callable $flag, callable $add): void
    {
        $rule = $this->rules->resolve('LWF', $return->period_end, $return->state_code);

        if ($rule === null) {
            $add('statutory_rule_missing', 'blocking', "No labour welfare fund rule version for {$return->state_code} is effective in {$return->period_key}.");
        } elseif (! in_array((int) $return->period_start->month, (array) $rule->param('months', []), true) && $entries->isNotEmpty()) {
            $add('outside_contribution_month', 'blocking', "{$return->period_key} is not an LWF contribution month for {$return->state_code} under {$rule->label()}.");
        }

        foreach ($entries as $entry) {
            if ($entry->state_code !== $return->state_code) {
                $flag($entry, 'state_mismatch', 'blocking', "LWF was deducted for state {$entry->state_code}, but the establishment is in {$return->state_code}.");
            }
        }
    }

    public function present(Model $entry, bool $unmasked): array
    {
        /** @var LwfReturnEntry $entry */
        return [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'member_name' => $entry->member_name,
            'state' => $entry->state_code,
            'calculated' => ['gross' => (float) $entry->calc_gross, 'ee_contribution' => (float) $entry->calc_ee_contribution, 'er_contribution' => (float) $entry->calc_er_contribution],
            'export' => ['ee_contribution' => (float) $entry->export_ee_contribution, 'er_contribution' => (float) $entry->export_er_contribution],
            'rule' => ['id' => $entry->compliance_rule_id, 'version' => $entry->rule_version, 'checksum' => $entry->rule_checksum],
            'status' => $entry->status,
            'issues' => $entry->issues['list'] ?? [],
        ];
    }
}
