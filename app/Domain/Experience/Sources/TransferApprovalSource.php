<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Employment\Services\TransferRequests;
use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Filament\Resources\TransferRequests\TransferRequestResource;

/** HROne setup parity (Workforce → Transfer policy): transfer requests in the Approval Center. */
final class TransferApprovalSource implements ApprovalSource, DescribesApprovalKinds
{
    public function types(): array
    {
        return ['transfer'];
    }

    /** HROne setup parity P3: request names for Setup → Inbox actions. */
    public function kindLabels(): array
    {
        return ['transfer' => 'Transfer request'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        if (! $user->hasPermission('employee.position')) {
            return [];
        }
        $requests = TransferRequest::query()->with(['employee.person', 'requester', 'reason'])->where('status', 'pending')->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(200)->get();
        app(AccessScopes::class)->primeEmployeeIds($user, $requests->pluck('employee_id'));

        return $requests->filter(fn (TransferRequest $r) => (int) $r->requested_by !== (int) $user->id && (int) $r->employee?->user_id !== (int) $user->id && app(AccessScopes::class)->allows($user, $r))
            ->map(fn (TransferRequest $r) => new ApprovalItem(
                id: 'transfer:'.$r->id, type: 'transfer', typeLabel: 'Workforce',
                title: 'Transfer from '.$r->effective_date->format('d M Y'), subject: $r->employee?->display_name, subjectEmployeeId: $r->employee_id,
                reason: trim(($r->reason?->name ?? '').($r->note ? ' — '.$r->note : '')) ?: null, impact: $r->summary(), effectiveOn: $r->effective_date, dueAt: $r->created_at?->copy()->addDays(3),
                requestedAt: $r->created_at, requestedBy: $r->requester?->name, risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: TransferRequestResource::getUrl(), record: $r,
            ))->values()->all();
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        $request = $item->record;
        $service = app(TransferRequests::class);
        $decision === 'approve' ? $service->approve($request, $note, $user) : $service->reject($request, (string) $note, $user);

        return $decision === 'approve' ? 'Transfer approved and applied' : 'Transfer rejected';
    }
}
