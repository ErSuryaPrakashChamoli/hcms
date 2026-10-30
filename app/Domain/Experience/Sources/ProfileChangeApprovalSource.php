<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Contracts\SupportsSendBack;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Services\ProfileChanges;
use App\Domain\People\Support\ProfileEditSections;
use App\Domain\Workflow\Support\RequestWorkflows;
use App\Filament\Resources\Employees\EmployeeResource;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * HROne parity MS-3 (matrix 22 §22.2.12 #1, #4): self-service profile changes waiting for approval. An item is listed to
 * the people who may apply it — every right its actions ask (employee.update / employee.create, employee.sensitive.update
 * for bank and identifiers), the employee in their scope — never to the requester or the employee themself, and not while
 * a workflow decides it (P5). The card shows earlier → new per field (sensitive values masked without
 * employee.sensitive.view). Approving applies the change through ProfileChanges (which checks it all again); a request
 * may be sent back to the employee (P3) when the organisation switches that on for "Profile change".
 */
final class ProfileChangeApprovalSource implements ApprovalSource, DescribesApprovalKinds, SupportsSendBack
{
    public function types(): array
    {
        return ['profile_change'];
    }

    public function kindLabels(): array
    {
        return ['profile_change' => 'Profile change'];
    }

    public function sendBackTypes(): array
    {
        return ['profile_change'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        if (! $user->hasPermission('employee.update') && ! $user->hasPermission('employee.create') && ! $user->hasPermission('employee.sensitive.update')) {
            return [];
        }
        $requests = ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'requester'])->where('status', 'pending')
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(200)->get();
        $inWorkflow = RequestWorkflows::inFlight(new ProfileChangeRequest, $requests->pluck('id'));
        $changes = app(ProfileChanges::class);

        return $requests->reject(fn (ProfileChangeRequest $r) => in_array((int) $r->id, $inWorkflow, true))
            ->filter(fn (ProfileChangeRequest $r) => $changes->mayDecide($user, $r))
            ->map(fn (ProfileChangeRequest $r) => new ApprovalItem(
                id: 'profile_change:'.$r->id, type: 'profile_change', typeLabel: 'Profile change',
                title: ProfileEditSections::label((string) $r->section).' · '.$r->reference(), subject: $r->employee?->display_name, subjectEmployeeId: $r->employee_id,
                reason: $r->comment, impact: count((array) ($r->fields ?? [])).' field(s) change on the employee\'s profile once approved'.(($r->document_ids ?? []) !== [] ? '; a supporting document is attached (pending verification)' : ''),
                effectiveOn: null, dueAt: $r->created_at?->copy()->addDays(3), requestedAt: $r->created_at, requestedBy: $r->requester?->name,
                risk: in_array($r->section, ['bank', 'identifiers', 'legal_identity'], true) ? 'high' : 'normal',
                riskReason: in_array($r->section, ['bank', 'identifiers', 'legal_identity'], true) ? 'Identity or salary account: check the attached proof' : null,
                decisions: ['approve', 'reject'], url: $this->url($r), record: $r,
                changesUsing: fn () => $changes->diff($r, $user),
            ))->values()->all();
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        if (! $item->record instanceof ProfileChangeRequest) {
            throw new AuthorizationException('Unsupported item.');
        }
        $changes = app(ProfileChanges::class);

        return match ($decision) {
            'approve' => tap('Profile change approved and applied', fn () => $changes->approve($item->record, $user, $note)),
            'reject' => tap('Profile change rejected', fn () => $changes->reject($item->record, $user, (string) $note)),
            default => throw new AuthorizationException('Unsupported decision.'),
        };
    }

    private function url(ProfileChangeRequest $request): ?string
    {
        try {
            return $request->employee ? EmployeeResource::getUrl('view', ['record' => $request->employee]) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
