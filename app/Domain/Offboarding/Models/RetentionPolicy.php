<?php

namespace App\Domain\Offboarding\Models;

use App\Domain\Offboarding\Enums\RetentionPolicyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.9: a retention policy version for one data category in one jurisdiction (platform configuration; RetentionPolicies).
 * legal_minimum_months null means LEGAL_MINIMUM_NOT_CONFIGURED: no minimum is assumed. policy_months is never below a
 * configured legal minimum.
 */
#[Fillable(['category', 'jurisdiction', 'version', 'legal_minimum_months', 'legal_basis', 'legal_reference', 'policy_months', 'policy_basis',
    'effective_from', 'status', 'proposed_by', 'proposed_at', 'reason', 'decided_by', 'decided_at', 'decision_reason'])]
class RetentionPolicy extends Model
{
    public const ALL_JURISDICTIONS = '*';

    public const LEGAL_MINIMUM_NOT_CONFIGURED = 'LEGAL_MINIMUM_NOT_CONFIGURED';

    protected function casts(): array
    {
        return ['status' => RetentionPolicyStatus::class, 'effective_from' => 'date', 'proposed_at' => 'datetime', 'decided_at' => 'datetime',
            'version' => 'integer', 'legal_minimum_months' => 'integer', 'policy_months' => 'integer'];
    }
}
