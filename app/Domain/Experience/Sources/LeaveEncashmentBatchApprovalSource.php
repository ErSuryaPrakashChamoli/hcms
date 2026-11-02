<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveEncashmentBatch;
use App\Domain\Leave\Services\LeaveEncashmentBatches;
use App\Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * HROne parity PO-8: HR encashment batches waiting for a second person's approval (`payroll.encashment.batch_approval`,
 * on by default). Listed for people who manage leave, did not make the batch, are not in it and can reach every employee
 * in it; LeaveEncashmentBatches checks the same again when deciding. Approving processes the batch in their name.
 */
final class LeaveEncashmentBatchApprovalSource implements ApprovalSource, DescribesApprovalKinds
{
    public function types(): array
    {
        return ['leave_encashment_batch'];
    }

    public function kindLabels(): array
    {
        return ['leave_encashment_batch' => 'Leave encashment batch'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        if (! $user->hasPermission('leave.manage')) {
            return [];
        }
        $batches = app(LeaveEncashmentBatches::class);

        return LeaveEncashmentBatch::query()->with(['leaveType', 'creator'])->where('status', 'pending')->where('created_by', '!=', $user->id)->orderBy('id')->limit(100)->get()
            ->filter(fn (LeaveEncashmentBatch $b) => ($about === null || in_array($about, $b->plannedEmployeeIds(), true)) && $batches->approvalRefusal($b, $user) === null)
            ->map(fn (LeaveEncashmentBatch $b) => new ApprovalItem(
                id: 'leave_encashment_batch:'.$b->id, type: 'leave_encashment_batch', typeLabel: 'Leave',
                title: 'Encash '.($b->leaveType?->name ?? 'leave').' for '.$b->employee_count.' employee(s) · '.$b->encashLabel(),
                subject: null, subjectEmployeeId: null, reason: $b->note,
                impact: 'Creates approved encashments, takes the days off their '.($b->leaveType?->name ?? 'leave').' balances and pays them in '.mb_strtolower($b->payMonthLabel()),
                effectiveOn: null, dueAt: $b->created_at?->copy()->addDays(3), requestedAt: $b->created_at, requestedBy: $b->creator?->name,
                risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: $this->url($b), record: $b,
                facts: array_values(array_filter([
                    'Leave year '.$b->period_year,
                    ($skipped = count((array) ($b->plan['skipped'] ?? []))) > 0 ? $skipped.' employee(s) skipped at review' : null,
                ])),
            ))->values()->all();
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        $record = $item->record;
        if (! $record instanceof LeaveEncashmentBatch) {
            throw new AuthorizationException('Unsupported item.');
        }
        $batches = app(LeaveEncashmentBatches::class);

        return match ($decision) {
            'approve' => tap('Encashment batch approved', fn () => $batches->approve($record, $user, $note)),
            'reject' => tap('Encashment batch rejected', fn () => $batches->reject($record, $user, (string) $note)),
            default => throw new AuthorizationException('Unsupported decision.'),
        };
    }

    private function url(LeaveEncashmentBatch $batch): ?string
    {
        try {
            return LeaveEncashmentBatchResource::getUrl('view', ['record' => $batch]);
        } catch (Throwable) {
            return null;
        }
    }
}
