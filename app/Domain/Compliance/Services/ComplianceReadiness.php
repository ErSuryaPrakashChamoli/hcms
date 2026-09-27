<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5 production gate for one statutory output. A return is production-ready only when every
 * control holds: ADR-0001 structure, registration, VERIFIED and intact rule versions captured on
 * the output, payroll reconciliation balanced, zero blocking issues, approval by someone other
 * than the generator, an intact audit chain, and a verified export format.
 *
 * "Ready" means these controls are satisfied. It does not certify that a rule or a file layout is
 * legally correct — that is what rule and format verification record, with their evidence.
 */
final class ComplianceReadiness
{
    private ?bool $auditChain = null;

    public function __construct(private readonly AuditIntegrityVerifier $audit, private readonly TenantContext $tenants) {}

    /** @return array{ready: bool, checks: list<array{check: string, passed: bool, detail: string}>} */
    public function forReturn(StatutoryReturn $return): array
    {
        $issues = collect($return->validation ?? []);
        $rules = ComplianceRule::query()->whereIn('id', array_keys((array) $return->rule_versions))->get();
        $entries = (int) ($return->totals['entries'] ?? 0);
        $approvedStatuses = [StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED, StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILED];

        $checks = [
            ['check' => 'legal_structure', 'passed' => LegalEntity::query()->whereKey($return->legal_entity_id)->exists() && ($return->return_type === 'TDS' || $return->establishment_id !== null), 'detail' => 'ADR-0001 legal entity'.($return->return_type === 'TDS' ? '' : ' and establishment').' recorded on the output'],
            ['check' => 'registration_present', 'passed' => $return->validation !== null && ! $issues->contains(fn ($i) => in_array($i['code'], ['missing_registration', 'missing_tds_profile'], true)), 'detail' => 'Registration with the authority effective for the period'],
            ['check' => 'rules_verified', 'passed' => ($entries === 0 || $rules->isNotEmpty()) && $rules->every(fn (ComplianceRule $r) => $r->isVerified() && $r->checksumIntact()), 'detail' => $rules->isEmpty() ? 'No rule version used' : $rules->map(fn ($r) => "{$r->label()} ({$r->verification_status})")->implode(', ')],
            ['check' => 'rule_version_captured', 'passed' => $entries === 0 || (count((array) $return->rule_versions) > 0 && StatutorySnapshot::query()->where('statutory_return_id', $return->getKey())->exists()), 'detail' => 'Rule versions and checksums captured in immutable snapshots'],
            ['check' => 'reconciled', 'passed' => $return->reconciliation_status === 'balanced', 'detail' => 'Payroll reconciliation '.($return->reconciliation_status ?? 'not run')],
            ['check' => 'no_blocking_exceptions', 'passed' => $return->validation !== null && (int) $return->blocking_count === 0, 'detail' => "{$return->blocking_count} blocking issue(s)"],
            ['check' => 'approved_with_separation_of_duties', 'passed' => in_array($return->status, $approvedStatuses, true) && $return->approved_by !== null && (int) $return->approved_by !== (int) $return->generated_by, 'detail' => $return->approved_by ? 'Approved' : 'Not approved'],
            ['check' => 'audit_chain_intact', 'passed' => $this->auditChainIntact(), 'detail' => 'Tenant audit hash chain verifies'],
            ['check' => 'export_format_verified', 'passed' => $return->format_verification_status === 'verified', 'detail' => 'Export layout '.($return->format_verification_status ?? 'unknown')],
        ];

        return ['ready' => collect($checks)->every(fn ($c) => $c['passed']), 'checks' => $checks];
    }

    private function auditChainIntact(): bool
    {
        return $this->auditChain ??= (bool) ($this->audit->verify($this->tenants->id())['valid'] ?? false);
    }
}
