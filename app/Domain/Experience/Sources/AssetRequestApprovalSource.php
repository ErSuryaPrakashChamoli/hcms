<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Services\AssetRequests;
use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\AssetRequests\AssetRequestResource;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * HROne setup parity AS-B decisions in the Approval Center: asset requests waiting for approval (new and replacement
 * requests, and returns, as asset.request.workflow_for says) that no workflow is running for — for the employee's line
 * manager and the asset team in scope, never one's own (AssetRequests::canApprove, checked again on deciding). Approving
 * sends the request to the asset type's owner role.
 */
final class AssetRequestApprovalSource implements ApprovalSource, DescribesApprovalKinds
{
    private const SCAN = 200;

    public function types(): array
    {
        return ['asset_request', 'asset_return_request'];
    }

    public function kindLabels(): array
    {
        return ['asset_request' => 'Asset request', 'asset_return_request' => 'Asset return'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        // Approvers: the asset team (in scope) and line managers (their reports) — read once, before any request is.
        $team = $user->hasPermission('asset.assign') || $user->hasPermission('asset.manage');
        $relationships = app(PerformanceRelationships::class);
        $reports = $user->hasPermission('asset.request') ? $relationships->reportIds($relationships->forUser($user))->map(fn ($id) => (int) $id)->flip()->all() : [];
        if (! $team && $reports === []) {
            return [];
        }
        $rows = AssetRequest::query()->with(['employee.person', 'category', 'subtype', 'model', 'asset', 'requester'])->where('status', 'requested')
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))
            ->when(! $team, fn ($q) => $q->whereIn('employee_id', array_keys($reports)))->orderBy('id')->limit(self::SCAN)->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $open = WorkflowInstance::query()->whereIn('subject_type', [AssetRequest::class, (new AssetRequest)->getMorphClass()])->whereIn('subject_id', $rows->pluck('id'))
            ->whereIn('status', InstanceStatus::inFlight())->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
        if ($team) {
            app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));
        }
        $decides = fn (AssetRequest $r) => (int) $r->employee?->user_id !== (int) $user->id
            && (isset($reports[(int) $r->employee_id]) || ($team && app(AccessScopes::class)->allows($user, $r)));

        return $rows->reject(fn (AssetRequest $r) => in_array((int) $r->id, $open, true))->filter($decides)
            ->map(function (AssetRequest $r) {
                $return = $r->kind === 'return';

                return new ApprovalItem(
                    id: ($return ? 'asset_return_request:' : 'asset_request:').$r->id,
                    type: $return ? 'asset_return_request' : 'asset_request',
                    typeLabel: 'Asset',
                    title: $r->number.' · '.(AssetRequest::KINDS[$r->kind] ?? $r->kind).' · '.$r->describe().($r->asset ? ' ['.$r->asset->asset_tag.']' : ''),
                    subject: $r->employee?->display_name,
                    subjectEmployeeId: $r->employee_id,
                    reason: $r->reason,
                    impact: $return ? 'The asset team takes the asset back' : 'The asset team hands over an asset of this type'.($r->category?->fulfilment_days !== null ? ' within '.$r->category->fulfilment_days.' day(s)' : ''),
                    effectiveOn: $r->needed_by,
                    dueAt: $r->created_at?->copy()->addDays(2),
                    requestedAt: $r->created_at,
                    requestedBy: $r->requester?->name,
                    risk: 'normal',
                    riskReason: null,
                    decisions: ['approve', 'reject'],
                    url: $this->url(),
                    record: $r,
                );
            })->values()->all();
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        return match ($decision) {
            'approve' => tap('Asset request approved', fn () => app(AssetRequests::class)->approve($item->record, $note, $user)),
            'reject' => tap('Asset request rejected', fn () => app(AssetRequests::class)->reject($item->record, (string) $note, $user)),
            default => throw new AuthorizationException('Unsupported decision.'),
        };
    }

    private function url(): ?string
    {
        try {
            return AssetRequestResource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}
