<?php

namespace App\Domain\Offboarding\Models;

use App\Domain\Offboarding\Enums\RetentionPolicyStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * SaaS.9 closure: C, the customer's contractual retention requirement for one tenant (TenantRetentionTerms), distinct from
 * the legal minimum and Markedge's policy (RetentionPolicy). minimum_months: the customer requires Markedge to keep the data
 * at least this long after closure (it extends retention). maximum_days: the customer requires deletion within this many
 * days of closure (it never deletes anything by itself; a retention that runs past it is shown as a conflict to resolve).
 */
#[Fillable(['tenant_id', 'category', 'minimum_months', 'maximum_days', 'contract_reference', 'effective_from', 'status', 'proposed_by', 'proposed_at',
    'reason', 'decided_by', 'decided_at', 'decision_reason'])]
class TenantRetentionTerm extends Model
{
    use BelongsToTenant;

    public const ALL_CATEGORIES = '*';

    protected static function booted(): void
    {
        static::updating(function (self $term) {
            if (RetentionPolicyStatus::tryFrom((string) ($term->getRawOriginal('status') ?? '')) !== RetentionPolicyStatus::Pending) {
                throw new LogicException('A decided contractual retention term cannot be changed; propose a new one.');
            }
        });
        static::deleting(fn () => throw new LogicException('Contractual retention terms are never deleted.'));
    }

    protected function casts(): array
    {
        return ['status' => RetentionPolicyStatus::class, 'effective_from' => 'date', 'proposed_at' => 'datetime', 'decided_at' => 'datetime',
            'minimum_months' => 'integer', 'maximum_days' => 'integer'];
    }
}
