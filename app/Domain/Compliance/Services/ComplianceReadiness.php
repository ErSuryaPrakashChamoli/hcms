<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Compliance\Exceptions\ProductionGateBlocked;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EpfReturnRun;
use App\Domain\Compliance\Models\EsiReturnRun;
use App\Domain\Compliance\Models\LwfReturn;
use App\Domain\Compliance\Models\ProfessionalTaxReturn;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Compliance\Models\TdsQuarterlyReturn;
use App\Domain\Compliance\Services\Returns\ReturnGenerators;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Support\Tenancy\TenantContext;

/**
 * Production gate for one statutory output (Phase 5, extended in Phase 6 §18). A return is
 * production-eligible only when every control holds:
 *
 *   payroll finalized · legal entity verified · establishment verified · registration verified ·
 *   employees explicitly assigned · rules VERIFIED, intact and free of open regulatory notices ·
 *   rule versions captured · evidence complete · export layout verified · file structurally valid ·
 *   reconciled · zero blocking issues · approval with separation of duties · audit chain intact
 *
 * There is no override. "Eligible" means these controls are satisfied; it does not certify that a
 * rule or layout is legally correct — that is what rule and layout verification record.
 */
final class ComplianceReadiness
{
    private ?bool $auditChain = null;

    public function __construct(
        private readonly AuditIntegrityVerifier $audit,
        private readonly TenantContext $tenants,
        private readonly ComplianceRules $rules,
        private readonly RuleVerifications $verifications,
        private readonly ExportLayouts $layouts,
    ) {}

    /** @return array{ready: bool, checks: list<array{check: string, passed: bool, detail: string}>} */
    public function forReturn(StatutoryReturn $return, bool $includeExportChecks = true): array
    {
        $issues = collect($return->validation ?? []);
        $rules = ComplianceRule::query()->whereIn('id', array_keys((array) $return->rule_versions))->get();
        $entries = (int) ($return->totals['entries'] ?? 0);
        $approvedStatuses = [StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED, StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILED];
        $entity = LegalEntity::query()->withoutGlobalScope(AccessScope::class)->find($return->legal_entity_id);
        $establishment = $return->establishment_id ? Establishment::query()->withoutGlobalScope(AccessScope::class)->find($return->establishment_id) : null;
        $registration = $this->registrationFor($return);
        $runs = PayrollRun::query()->whereIn('id', (array) $return->payroll_run_ids)->get();
        $notices = $rules->map(fn (ComplianceRule $r) => $this->rules->pendingNotice($r, $return->period_end))->filter();
        $evidenceGaps = $rules->flatMap(fn (ComplianceRule $r) => $r->evidenceDocuments()->exists() ? array_map(fn ($g) => "{$r->label()}: {$g}", $this->verifications->coverageGaps($r)) : ["{$r->label()}: no evidence document"]);
        $layout = $this->layouts->forReturn($return);
        $unassigned = $this->unassignedEmployees($return);
        $isTds = $return->return_type === 'TDS';

        $checks = [
            ['check' => 'payroll_finalized', 'passed' => $runs->isNotEmpty() && $runs->every(fn (PayrollRun $r) => in_array($r->status, ['finalized', 'paid'], true)), 'detail' => $runs->isEmpty() ? 'No payroll run recorded' : $runs->map(fn ($r) => "run #{$r->id} {$r->status}")->implode(', ')],
            ['check' => 'legal_entity_verified', 'passed' => (bool) $entity?->isRecordVerified(), 'detail' => 'Legal entity '.($entity?->verification_status ?? 'missing')],
            ['check' => 'establishment_verified', 'passed' => $isTds || (bool) $establishment?->isRecordVerified(), 'detail' => $isTds ? 'Not applicable (legal-entity return)' : 'Establishment '.($establishment?->verification_status ?? 'missing')],
            ['check' => 'registration_verified', 'passed' => $registration !== null && $registration->verification_status === 'verified', 'detail' => $registration ? $registration->typeLabel().' '.$registration->maskedNumber().' '.$registration->verification_status : 'No registration on the return'],
            ['check' => 'employees_assigned', 'passed' => $isTds || $unassigned === 0, 'detail' => $isTds ? 'Not applicable (legal-entity return)' : "{$unassigned} employee line(s) without an explicit establishment assignment"],
            ['check' => 'rules_verified', 'passed' => ($entries === 0 || $rules->isNotEmpty()) && $rules->every(fn (ComplianceRule $r) => $r->isVerified() && $r->checksumIntact()) && $notices->isEmpty(), 'detail' => $rules->isEmpty() ? 'No rule version used' : $rules->map(fn ($r) => "{$r->label()} ({$r->verification_status})")->implode(', ').($notices->isNotEmpty() ? '; open notice: '.$notices->pluck('title')->unique()->implode('; ') : '')],
            ['check' => 'rule_version_captured', 'passed' => $entries === 0 || (count((array) $return->rule_versions) > 0 && StatutorySnapshot::query()->where('statutory_return_id', $return->getKey())->exists()), 'detail' => 'Rule versions and checksums captured in immutable snapshots'],
            ['check' => 'evidence_complete', 'passed' => $evidenceGaps->isEmpty(), 'detail' => $evidenceGaps->isEmpty() ? 'Every rule parameter traced to stored official evidence' : $evidenceGaps->take(5)->implode('; ')],
            ['check' => 'export_layout_verified', 'passed' => (bool) $layout?->isVerified(), 'detail' => 'Export layout '.($layout ? $layout->label().' '.$layout->status : 'missing')],
            ['check' => 'reconciled', 'passed' => $return->reconciliation_status === 'balanced', 'detail' => 'Payroll reconciliation '.($return->reconciliation_status ?? 'not run')],
            ['check' => 'no_blocking_exceptions', 'passed' => $return->validation !== null && (int) $return->blocking_count === 0 && ! $issues->contains('severity', 'blocking'), 'detail' => "{$return->blocking_count} blocking issue(s)"],
            ['check' => 'approved_with_separation_of_duties', 'passed' => in_array($return->status, $approvedStatuses, true) && $return->approved_by !== null && (int) $return->approved_by !== (int) $return->generated_by, 'detail' => $return->approved_by ? 'Approved' : 'Not approved'],
            ['check' => 'audit_chain_intact', 'passed' => $this->auditChainIntact(), 'detail' => 'Tenant audit hash chain verifies'],
        ];

        if ($includeExportChecks) {
            $checks[] = ['check' => 'export_locally_validated', 'passed' => (bool) ($return->local_validation['valid'] ?? false), 'detail' => $return->local_validation === null ? 'Not exported yet' : ($return->local_validation['valid'] ? 'File valid against '.$return->local_validation['layout'] : ($return->local_validation['issue_count'] ?? 0).' structural issue(s)')];
        }

        return ['ready' => collect($checks)->every(fn ($c) => $c['passed']), 'checks' => $checks];
    }

    /** Production exports require every control except the file check that export itself performs. */
    public function assertExportable(StatutoryReturn $return): void
    {
        $gate = $this->forReturn($return, false);
        $failed = collect($gate['checks'])->reject(fn ($c) => $c['passed']);

        if ($failed->isNotEmpty()) {
            throw new ProductionGateBlocked('The production gate blocks this export: '.$failed->pluck('check')->implode(', ').'.', $failed->all());
        }
    }

    public function registrationFor(StatutoryReturn $return): ?StatutoryRegistration
    {
        $id = match ($return->return_type) {
            'EPF' => EpfReturnRun::query()->where('statutory_return_id', $return->getKey())->value('statutory_registration_id'),
            'ESI' => EsiReturnRun::query()->where('statutory_return_id', $return->getKey())->value('statutory_registration_id'),
            'PT' => ProfessionalTaxReturn::query()->where('statutory_return_id', $return->getKey())->value('statutory_registration_id'),
            'LWF' => LwfReturn::query()->where('statutory_return_id', $return->getKey())->value('statutory_registration_id'),
            'TDS' => TdsQuarterlyReturn::query()->where('statutory_return_id', $return->getKey())->value('tan_registration_id'),
            default => null,
        };

        return $id ? StatutoryRegistration::query()->withoutGlobalScope(AccessScope::class)->find($id) : null;
    }

    /** Lines whose employee was not explicitly assigned to the establishment when payroll ran. */
    private function unassignedEmployees(StatutoryReturn $return): int
    {
        if ($return->return_type === 'TDS') {
            return 0;
        }

        $entries = app(ReturnGenerators::class)->for($return->return_type)->entries($return)->withoutGlobalScope(AccessScope::class)->get(['id', 'payroll_entry_id', 'issues']);
        $payroll = PayrollEntry::query()->whereIn('id', $entries->pluck('payroll_entry_id')->filter())->get(['id', 'inputs'])->keyBy('id');

        return $entries->filter(fn ($e) => ($e->issues['establishment_source'] ?? null) !== 'payroll_entry'
            || ($payroll->get($e->payroll_entry_id)?->inputs['statutory_context']['establishment_source'] ?? null) !== 'assignment')->count();
    }

    private function auditChainIntact(): bool
    {
        return $this->auditChain ??= (bool) ($this->audit->verify($this->tenants->id())['valid'] ?? false);
    }
}
