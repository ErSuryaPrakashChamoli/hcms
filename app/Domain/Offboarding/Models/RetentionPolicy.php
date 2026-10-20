<?php

namespace App\Domain\Offboarding\Models;

use App\Domain\Offboarding\Enums\RetentionPolicyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * SaaS.9: a retention policy version for one data category in one jurisdiction (platform configuration; RetentionPolicies).
 *
 * Two of the three retention inputs live here and stay distinct (the third, the customer's contract, is a
 * TenantRetentionTerm):
 * - A. the legal minimum: null months = LEGAL_MINIMUM_NOT_CONFIGURED (none assumed). An entered minimum is
 *   pending_verification until its authoritative source, reviewer and verification date are recorded, and then verified;
 * - B. Markedge's company policy (policy_months), never below a configured legal minimum, counted from its start event.
 */
#[Fillable(['category', 'jurisdiction', 'version', 'legal_minimum_months', 'legal_minimum_status', 'legal_basis', 'legal_reference', 'legal_source',
    'legal_verified_by', 'legal_verified_on', 'policy_months', 'policy_basis', 'start_event', 'financial_year_end',
    'effective_from', 'status', 'proposed_by', 'proposed_at', 'reason', 'decided_by', 'decided_at', 'decision_reason'])]
class RetentionPolicy extends Model
{
    public const ALL_JURISDICTIONS = '*';

    public const LEGAL_MINIMUM_NOT_CONFIGURED = 'LEGAL_MINIMUM_NOT_CONFIGURED';

    public const LEGAL_MINIMUM_PENDING_VERIFICATION = 'LEGAL_MINIMUM_PENDING_VERIFICATION';

    /** legal_minimum_status values */
    public const LEGAL_NOT_CONFIGURED = 'not_configured';

    public const LEGAL_PENDING_VERIFICATION = 'pending_verification';

    public const LEGAL_VERIFIED = 'verified';

    /** start_event values: what the period is counted from */
    public const START_TENANT_CLOSURE = 'tenant_closure';

    public const START_FINANCIAL_YEAR_END = 'financial_year_end_after_closure';

    public const START_EVENTS = [
        self::START_TENANT_CLOSURE => 'The tenant\'s closure date',
        self::START_FINANCIAL_YEAR_END => 'The end of the financial year in which the tenant closed',
    ];

    protected static function booted(): void
    {
        // A decided version is history: only a pending version changes (its decision). A new rule is a new version.
        static::updating(function (self $policy) {
            if (RetentionPolicyStatus::tryFrom((string) ($policy->getRawOriginal('status') ?? '')) !== RetentionPolicyStatus::Pending) {
                throw new LogicException('A decided retention policy version cannot be changed; propose a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Retention policy versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['status' => RetentionPolicyStatus::class, 'effective_from' => 'date', 'proposed_at' => 'datetime', 'decided_at' => 'datetime',
            'legal_verified_on' => 'date', 'version' => 'integer', 'legal_minimum_months' => 'integer', 'policy_months' => 'integer'];
    }
}
