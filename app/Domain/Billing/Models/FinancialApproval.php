<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7 completion (B-13): a maker-checker request for a financial operation (platform-level: Markedge's operators
 * decide it; subject_tenant_id names the tenant concerned, if any). It records the maker, the checker, when, both
 * reasons, the state before and after, and a unique correlation key. It is created pending; only another operator
 * can approve it; once approved, its execution is recorded once. Nothing else ever changes, and it is never deleted.
 */
#[Fillable(['reference', 'action', 'subject_type', 'subject_id', 'subject_tenant_id', 'payload', 'before', 'after', 'status', 'correlation_key',
    'maker_id', 'maker_reason', 'requested_at', 'checker_id', 'checker_reason', 'decided_at', 'executed_at', 'result'])]
class FinancialApproval extends Model
{
    use HasUlids;

    private const FIXED = ['reference', 'action', 'subject_type', 'subject_id', 'subject_tenant_id', 'payload', 'before', 'after', 'correlation_key',
        'maker_id', 'maker_reason', 'requested_at'];

    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            if ($approval->status !== ApprovalStatus::Pending || $approval->checker_id !== null || $approval->executed_at !== null) {
                throw new RuntimeException('An approval request starts pending, without a checker.');
            }
        });
        static::updating(function (self $approval): void {
            $dirty = array_keys($approval->getDirty());
            $allowed = match (ApprovalStatus::from($approval->getRawOriginal('status'))) {
                // Decided once: a final status, a checker and when; approval only by someone other than the maker.
                ApprovalStatus::Pending => ! $approval->isDirty(self::FIXED) && ! $approval->isDirty(['executed_at', 'result'])
                    && $approval->status !== ApprovalStatus::Pending && $approval->checker_id !== null && $approval->decided_at !== null
                    && ($approval->status !== ApprovalStatus::Approved || (int) $approval->checker_id !== (int) $approval->maker_id),
                // Executed once, recording what it produced.
                ApprovalStatus::Approved => array_diff($dirty, ['executed_at', 'result', 'updated_at']) === [] && $approval->getRawOriginal('executed_at') === null,
                default => false,
            };
            if (! $allowed) {
                throw new RuntimeException('An approval is decided once, by another operator, and executed once; nothing else changes.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Approvals are never deleted.');
        });
    }

    public function uniqueIds(): array
    {
        return ['reference'];
    }

    protected function casts(): array
    {
        return ['action' => ApprovalAction::class, 'status' => ApprovalStatus::class, 'payload' => 'array', 'before' => 'array', 'after' => 'array',
            'requested_at' => 'datetime', 'decided_at' => 'datetime', 'executed_at' => 'datetime', 'subject_id' => 'integer', 'subject_tenant_id' => 'integer'];
    }
}
