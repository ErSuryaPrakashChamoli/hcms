<?php

namespace App\Domain\Exit\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Support\RequestWorkflows;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity WO-11 (§23.6.2, owner decision WRK-3 / D-WO8): withdrawing a resignation.
 *
 * By default (exit.withdrawal.requires_approval off) a withdrawal takes effect at once, exactly as before (Exits::withdraw).
 * When the organisation asks for approval, the employee's (or HR's) withdrawal becomes a pending ExitWithdrawalRequest —
 * one per exit — raising `exit.withdrawal_requested` (a workflow trigger: a run on "Resignation withdrawal request", or on
 * any record started by that trigger, decides it; P5). Without a deciding run it waits in the Approval Center for HR
 * (`exit.manage`, the employee in scope, never the requester or the employee: ExitApprovalSource). Approving withdraws the
 * resignation through Exits::withdraw; rejecting lets the exit go on. A rejected resignation workflow still withdraws the
 * resignation directly (WRK-4, ResignationWorkflowBridge, unchanged).
 */
final class ExitWithdrawals
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function requiresApproval(): bool
    {
        return (bool) $this->settings->get('exit.withdrawal.requires_approval', false);
    }

    /** Whether the person may ask to withdraw this resignation: the employee themself, or HR in scope. */
    public function mayRequest(User $user, ExitCase $case): bool
    {
        return $case->type === 'resignation' && $case->isOpen()
            && ($this->isEmployee($user, $case) || ($user->hasPermission('exit.manage') && app(AccessScopes::class)->allows($user, $case)));
    }

    /**
     * Withdraw (setting off: at once, the ExitCase comes back) or ask to withdraw (setting on: the pending request).
     */
    public function request(ExitCase $case, User $actor, string $reason): ExitCase|ExitWithdrawalRequest
    {
        if (! $this->requiresApproval()) {
            // As before: withdrawn at once, with the reason as given.
            return app(Exits::class)->withdraw($case, $reason, $actor);
        }
        $reason = trim(strip_tags($reason));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Give the reason for withdrawing (up to 500 characters).');
        }
        if ($case->type !== 'resignation' || ! $case->isOpen()) {
            throw new RuntimeException('Only a resignation that is still open can be withdrawn.');
        }
        if (! $this->mayRequest($actor, $case)) {
            throw new RuntimeException('You cannot withdraw this resignation.');
        }

        try {
            $request = DB::transaction(function () use ($case, $actor, $reason) {
                $locked = ExitCase::query()->withoutGlobalScope(AccessScope::class)->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->type !== 'resignation' || ! $locked->isOpen()) {
                    throw new RuntimeException('Only a resignation that is still open can be withdrawn.');
                }
                if (ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->where('exit_case_id', $locked->id)->where('status', 'pending')->lockForUpdate()->exists()) {
                    throw new RuntimeException('A request to withdraw this resignation is already waiting for a decision.');
                }
                $request = new ExitWithdrawalRequest(['exit_case_id' => $locked->id, 'employee_id' => $locked->employee_id, 'requested_by' => $actor->id, 'reason' => $reason, 'status' => 'pending', 'open_key' => 'case:'.$locked->id]);
                $request->withAuditReason($reason)->save();
                $employee = $locked->employee()->firstOrFail();
                ExitEvent::dispatch('exit.withdrawal_requested', $employee, $request, ['number' => $locked->number, 'reference' => $request->reference(), 'by' => $actor->name],
                    array_values(array_filter([$locked->initiated_by !== $actor->id ? $locked->initiated_by : null])));

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('A request to withdraw this resignation is already waiting for a decision.');
        }

        return $request->refresh();
    }

    /** HR in scope who holds exit.manage, never the requester or the employee. */
    public function mayDecide(User $user, ExitWithdrawalRequest $request): bool
    {
        if (! $user->isActive() || ! $user->hasPermission('exit.manage') || (int) $request->requested_by === (int) $user->id) {
            return false;
        }
        if ((int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->employee_id)->value('user_id') === (int) $user->id) {
            return false;
        }

        return app(AccessScopes::class)->allows($user, $request);
    }

    public function approve(ExitWithdrawalRequest $request, User $actor, ?string $note = null, bool $viaWorkflow = false): ExitWithdrawalRequest
    {
        return $this->decide($request, $actor, 'approved', $note, $viaWorkflow);
    }

    public function reject(ExitWithdrawalRequest $request, User $actor, ?string $note, bool $viaWorkflow = false): ExitWithdrawalRequest
    {
        if (trim((string) $note) === '') {
            throw new RuntimeException('Give the reason for rejecting the withdrawal.');
        }

        return $this->decide($request, $actor, 'rejected', $note, $viaWorkflow);
    }

    /** The requester (or the employee) calls off their own request while it waits. */
    public function cancel(ExitWithdrawalRequest $request, User $actor): ExitWithdrawalRequest
    {
        if ((int) $request->requested_by !== (int) $actor->id && ! $this->isEmployeeOf($actor, $request)) {
            throw new RuntimeException('Only the person who asked can call the request off.');
        }

        return DB::transaction(function () use ($request) {
            $locked = ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw new RuntimeException('This request is no longer waiting.');
            }
            $locked->withAuditReason('Called off by the requester')->update(['status' => 'cancelled', 'open_key' => null, 'decided_at' => now(), 'decision_note' => 'Called off by the requester.']);

            return $locked;
        });
    }

    private function decide(ExitWithdrawalRequest $request, User $actor, string $status, ?string $note, bool $viaWorkflow): ExitWithdrawalRequest
    {
        if (! $viaWorkflow) {
            if (RequestWorkflows::inFlight(new ExitWithdrawalRequest, [$request->getKey()]) !== []) {
                throw new RuntimeException('An approval workflow decides this request.');
            }
            if (! $this->mayDecide($actor, $request)) {
                throw new RuntimeException('You cannot decide this request.');
            }
        }
        $note = trim(strip_tags((string) $note));

        return DB::transaction(function () use ($request, $actor, $status, $note) {
            $locked = ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw new RuntimeException('This request is no longer waiting.');
            }
            $case = ExitCase::query()->withoutGlobalScope(AccessScope::class)->findOrFail($locked->exit_case_id);
            $locked->withAuditReason($note !== '' ? $note : ucfirst($status))
                ->update(['status' => $status, 'open_key' => null, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note !== '' ? mb_substr($note, 0, 1000) : null]);
            if ($status === 'approved') {
                app(Exits::class)->withdraw($case, $locked->reason, $actor);
            }
            $employee = $case->employee()->withoutGlobalScope(AccessScope::class)->first();
            ExitEvent::dispatch('exit.withdrawal_'.$status, $employee, $locked, ['number' => $case->number, 'reference' => $locked->reference()],
                array_values(array_unique(array_filter([$locked->requested_by, $employee?->user_id]))));

            return $locked->refresh();
        });
    }

    private function isEmployee(User $user, ExitCase $case): bool
    {
        return (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($case->employee_id)->value('user_id') === (int) $user->id;
    }

    private function isEmployeeOf(User $user, ExitWithdrawalRequest $request): bool
    {
        return (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->employee_id)->value('user_id') === (int) $user->id;
    }
}
