<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Commercial\OperatorChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SaaS.7 completion (B-13): maker-checker for financial operations (price publication, credit notes including an
 * invoice's cancellation, refunds, invoice write-offs, acceptance or write-off of a payment exception). No amount
 * thresholds. A maker (platform operator + reason) requests; another platform operator approves (or rejects); the
 * maker may withdraw. Each request carries what it will do (payload), the state before and after, and a unique
 * correlation key (requesting the same thing twice returns the pending request).
 *
 * Execution is never a separate entry point: every executor (BillingCatalog, CreditNotes, Invoices, Refunds,
 * Payments) starts with claim(), which accepts only an approval of its action that another operator approved and
 * that has not run yet, locked in the executing transaction; executed() records the result. The approval desk
 * approves and executes in one transaction, so a failed execution leaves the request pending. The model refuses a
 * self-approval and any second decision or execution even when written directly.
 */
final class FinancialApprovals
{
    public function __construct(private readonly BillingAudit $audit) {}

    /** @param  array<string, mixed>  $payload  @param  array<string, mixed>  $before  @param  array<string, mixed>  $after */
    public function request(ApprovalAction $action, Model $subject, ?Tenant $tenant, array $payload, array $before, array $after, string $correlationKey,
        string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'financial operations');
        $key = mb_substr("{$action->value}:{$correlationKey}", 0, 150);
        if (($existing = FinancialApproval::query()->where('correlation_key', $key)->first()) !== null) {
            return $existing->status === ApprovalStatus::Pending ? $existing
                : throw new RuntimeException("This request was already {$existing->status->value} ({$existing->reference}).");
        }
        try {
            return DB::transaction(function () use ($action, $subject, $tenant, $payload, $before, $after, $key, $reason, $maker) {
                $approval = FinancialApproval::query()->create(['action' => $action, 'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(),
                    'subject_tenant_id' => $tenant?->id, 'payload' => $payload, 'before' => $before, 'after' => $after, 'status' => ApprovalStatus::Pending,
                    'correlation_key' => $key, 'maker_id' => $maker->id, 'maker_reason' => trim($reason), 'requested_at' => now()]);
                $this->record(AuditAction::ApprovalRequested, $approval, $tenant, [['field' => 'approval', 'before' => 'none', 'after' => 'pending']], $reason, $maker);

                return $approval;
            });
        } catch (UniqueConstraintViolationException) {
            return FinancialApproval::query()->where('correlation_key', $key)->firstOrFail();
        }
    }

    /** Marks a pending request approved by $checker. Only the approval desk calls it, inside the transaction that executes it. */
    public function approve(FinancialApproval $approval, string $reason, User $checker): FinancialApproval
    {
        OperatorChange::assert($checker, $reason, 'financial operations');
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('An approval is decided in the transaction that executes it.');
        }
        $locked = FinancialApproval::query()->lockForUpdate()->findOrFail($approval->id);
        if ($locked->status !== ApprovalStatus::Pending) {
            throw new RuntimeException("This request is already {$locked->status->value}.");
        }
        if ((int) $locked->maker_id === (int) $checker->id) {
            throw new RuntimeException('Maker-checker: the operator who requested this cannot approve it. Another operator must.');
        }
        $locked->forceFill(['status' => ApprovalStatus::Approved, 'checker_id' => $checker->id, 'checker_reason' => trim($reason), 'decided_at' => now()])->save();
        $this->record(AuditAction::ApprovalApproved, $locked, $this->tenant($locked), [['field' => 'approval', 'before' => 'pending', 'after' => 'approved']], $reason, $checker);

        return $locked;
    }

    public function reject(FinancialApproval $approval, string $reason, User $checker): FinancialApproval
    {
        OperatorChange::assert($checker, $reason, 'financial operations');

        return DB::transaction(function () use ($approval, $reason, $checker) {
            $locked = FinancialApproval::query()->lockForUpdate()->findOrFail($approval->id);
            if ($locked->status !== ApprovalStatus::Pending) {
                throw new RuntimeException("This request is already {$locked->status->value}.");
            }
            if ((int) $locked->maker_id === (int) $checker->id) {
                throw new RuntimeException('The maker withdraws a request; another operator rejects it.');
            }
            $locked->forceFill(['status' => ApprovalStatus::Rejected, 'checker_id' => $checker->id, 'checker_reason' => trim($reason), 'decided_at' => now()])->save();
            $this->record(AuditAction::ApprovalRejected, $locked, $this->tenant($locked), [['field' => 'approval', 'before' => 'pending', 'after' => 'rejected']], $reason, $checker);

            return $locked;
        });
    }

    public function withdraw(FinancialApproval $approval, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'financial operations');

        return DB::transaction(function () use ($approval, $reason, $maker) {
            $locked = FinancialApproval::query()->lockForUpdate()->findOrFail($approval->id);
            if ($locked->status !== ApprovalStatus::Pending) {
                throw new RuntimeException("This request is already {$locked->status->value}.");
            }
            if ((int) $locked->maker_id !== (int) $maker->id) {
                throw new RuntimeException('Only the operator who requested it can withdraw a request.');
            }
            $locked->forceFill(['status' => ApprovalStatus::Withdrawn, 'checker_id' => $maker->id, 'checker_reason' => trim($reason), 'decided_at' => now()])->save();
            $this->record(AuditAction::ApprovalWithdrawn, $locked, $this->tenant($locked), [['field' => 'approval', 'before' => 'pending', 'after' => 'withdrawn']], $reason, $maker);

            return $locked;
        });
    }

    /**
     * The only way into an executor: the approval of $action, approved by someone other than its maker, not executed
     * yet, locked in the executing transaction.
     */
    public function claim(FinancialApproval $approval, ApprovalAction $action): FinancialApproval
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('An approved operation runs inside the approving transaction.');
        }
        $locked = FinancialApproval::query()->lockForUpdate()->findOrFail($approval->id);
        if ($locked->action !== $action || $locked->status !== ApprovalStatus::Approved || $locked->executed_at !== null
            || $locked->checker_id === null || (int) $locked->checker_id === (int) $locked->maker_id) {
            throw new RuntimeException("{$action->label()} needs a request approved by a second operator and not yet carried out.");
        }

        return $locked;
    }

    public function executed(FinancialApproval $locked, string $result): void
    {
        $locked->forceFill(['executed_at' => now(), 'result' => mb_substr($result, 0, 150)])->save();
    }

    /** @return Collection<int, FinancialApproval> */
    public function list(?ApprovalStatus $status = null, int $limit = 200): Collection
    {
        return FinancialApproval::query()->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->limit($limit)->get();
    }

    private function tenant(FinancialApproval $approval): ?Tenant
    {
        return $approval->subject_tenant_id === null ? null : Tenant::query()->find($approval->subject_tenant_id);
    }

    /** @param  list<array{field: string, before: mixed, after: mixed}>  $changes */
    private function record(AuditAction $action, FinancialApproval $approval, ?Tenant $tenant, array $changes, string $reason, User $actor): void
    {
        $label = "{$approval->action->label()} request {$approval->reference}";
        $meta = ['approval' => $approval->reference, 'action' => $approval->action->value, 'subject' => "{$approval->subject_type}#{$approval->subject_id}",
            'maker_id' => $approval->maker_id, 'checker_id' => $approval->checker_id, 'correlation_id' => $approval->correlation_key,
            'before' => $approval->before, 'after' => $approval->after];
        $tenant === null
            ? $this->audit->platform($action, 'billing', $approval, $label, $changes, $reason, $actor, $meta)
            : $this->audit->both($action, 'billing', $tenant, $approval, $label, $changes, $reason, $actor, $meta);
    }
}
