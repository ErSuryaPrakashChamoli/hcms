<?php

namespace App\Domain\Assets\Services;

use App\Domain\Assets\Events\AssetEvent;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Numbering\NumberSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity AS-B: asset requests, from raising to fulfilment.
 *
 * - Raise: the employee for themselves (`asset.request`), their line manager for them (`asset.request`), or HR in scope
 *   (`asset.assign` / `asset.manage`). New (an asset type, and the sub type and product where the raiser may choose them:
 *   asset.request.subtype_visible_to / model_visible_to), replacement or return (of an asset the employee holds). Under an
 *   asset policy with request_scope `mapped`, employees and managers may ask only for entitled assets. Requests of the
 *   kinds asset.request.workflow_for names wait for approval (`asset.requested` / `asset.return_requested` start
 *   workflows, else the Approval Center: the line manager or the asset team); the rest go straight to fulfilment.
 * - Approve / reject (never one's own). An approved request is due its asset type's fulfilment days after approval and
 *   goes to the type's owner role (else Asset Admin), who fulfils it — assigns an asset of the type, replaces the old one
 *   or takes it back (Assets) — or declines it with a reason.
 * - Automatic requests: the asset policy's entitlements for a joiner (due asset.onboarding.due_days after joining), and a
 *   return for every asset a leaving employee holds (owner decision 2026-10-29: on by default, asset.exit.return_requests;
 *   except the exit types and asset types listed in asset.exit.return_excluded_exit_types / _categories; nothing when the
 *   employee holds no company asset; due asset.exit.due_days before the last working day; cancelled if the exit is
 *   withdrawn). Each is raised once (`source_key`).
 */
final class AssetRequests
{
    public function __construct(
        private readonly Assets $assets,
        private readonly AssetEntitlements $entitlements,
        private readonly SettingsRepository $settings,
        private readonly NumberSeries $numbers,
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
    ) {}

    /** @param  array<string, mixed>  $data  kind, asset_category_id, asset_subtype_id, asset_model_id, asset_id (return / replacement), reason, needed_by */
    public function request(Employee $employee, array $data, ?User $actor = null): AssetRequest
    {
        $actor ??= auth()->user();
        $audience = $actor !== null ? $this->audience($actor, $employee) : null;
        if ($audience === null) {
            throw new RuntimeException('You cannot request assets for this employee.');
        }
        // HROne setup parity P4: the employee request lock refuses the employee's own request while it applies.
        app(SelfServiceLock::class)->assertOpen($employee, 'asset_request', $actor);
        $kind = (string) ($data['kind'] ?? 'new');
        if (! array_key_exists($kind, AssetRequest::KINDS)) {
            throw new RuntimeException('Choose what the request is for: a new asset, a replacement or a return.');
        }
        $reason = filled($data['reason'] ?? null) ? mb_substr(trim((string) $data['reason']), 0, 500) : null;
        if ($reason === null && $kind !== 'new') {
            throw new RuntimeException('Give the reason for the '.strtolower(AssetRequest::KINDS[$kind]).'.');
        }
        $neededBy = filled($data['needed_by'] ?? null) ? CarbonImmutable::parse($data['needed_by'])->startOfDay() : null;
        if ($neededBy !== null && $neededBy->lt(CarbonImmutable::today())) {
            throw new RuntimeException('The date the asset is needed by cannot be in the past.');
        }

        $asset = null;
        if ($kind === 'new') {
            $category = AssetCategory::query()->whereKey($data['asset_category_id'] ?? null)->where('status', ActiveStatus::Active)->first() ?? throw new RuntimeException('Choose an asset type in use.');
        } else {
            $asset = Asset::query()->withoutGlobalScope(AccessScope::class)->whereKey($data['asset_id'] ?? null)->where('custodian_id', $employee->id)->where('status', 'assigned')->first()
                ?? throw new RuntimeException('Choose an asset the employee holds.');
            $category = AssetCategory::query()->findOrFail($asset->asset_category_id);
            if ($kind === 'return' && AssetRequest::query()->withoutGlobalScope(AccessScope::class)->where('asset_id', $asset->id)->where('kind', 'return')->whereIn('status', AssetRequest::OPEN)->exists()) {
                throw new RuntimeException("A return of {$asset->asset_tag} is already open.");
            }
        }
        // Who may choose the sub type and the product (Asset → Settings); a return is for the asset held.
        [$subtypeId, $modelId] = $kind === 'return' ? [$asset->asset_subtype_id, $asset->asset_model_id] : [
            in_array($audience, (array) $this->settings->get('asset.request.subtype_visible_to', ['employee', 'manager', 'hr']), true) ? self::id($data['asset_subtype_id'] ?? null) : null,
            in_array($audience, (array) $this->settings->get('asset.request.model_visible_to', ['hr']), true) ? self::id($data['asset_model_id'] ?? null) : null,
        ];
        if ($kind !== 'return') {
            $this->assertTaxonomy($category, $subtypeId, $modelId);
            if ($audience !== 'hr' && ($refusal = $this->entitlements->requestRefusal($employee, (int) $category->id, $subtypeId, $modelId)) !== null) {
                throw new RuntimeException($refusal);
            }
        }

        $needsApproval = $this->needsApproval($kind);
        $request = $this->raise($employee, [
            'kind' => $kind, 'source' => $audience, 'asset_category_id' => $category->id, 'asset_subtype_id' => $subtypeId, 'asset_model_id' => $modelId,
            'asset_id' => $asset?->id, 'asset_assignment_id' => $asset?->currentAssignment()->value('id'), 'reason' => $reason, 'needed_by' => $neededBy?->toDateString(),
            'status' => $needsApproval ? 'requested' : 'approved', 'due_on' => $needsApproval ? null : $this->dueAfter($category),
        ], $category, $actor);
        if ($needsApproval) {
            AssetEvent::dispatch($kind === 'return' ? 'asset.return_requested' : 'asset.requested', $employee, $request, $this->context($request) + ['kind' => $kind], $this->managerUsers($employee));
        } else {
            AssetEvent::dispatch('asset.request_to_fulfil', $employee, $request, $this->context($request), $this->ownerUsers($request));
        }

        return $request;
    }

    public function approve(AssetRequest $request, ?string $note = null, ?User $actor = null): AssetRequest
    {
        return DB::transaction(function () use ($request, $note, $actor) {
            [$locked, $actor] = $this->decidable($request, $actor, ['requested'], 'approve');
            $category = AssetCategory::query()->find($locked->asset_category_id);
            $locked->withAuditReason($note)->update(['status' => 'approved', 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_note' => self::short($note),
                'due_on' => $locked->due_on?->toDateString() ?? ($category ? $this->dueAfter($category) : null)]);
            $this->audit->record(AuditAction::Approved, 'assets', $locked, reason: $note, actor: $actor);
            $employee = $this->employee($locked);
            AssetEvent::dispatch('asset.request_approved', $employee, $locked, $this->context($locked), array_filter([$employee->user_id]));
            AssetEvent::dispatch('asset.request_to_fulfil', $employee, $locked, $this->context($locked), $this->ownerUsers($locked));

            return $locked->refresh();
        });
    }

    /** Rejects a request waiting for approval (an approver), or declines one waiting for fulfilment (the owner role). */
    public function reject(AssetRequest $request, string $note, ?User $actor = null): AssetRequest
    {
        if (trim($note) === '') {
            throw new RuntimeException('Give the reason for rejecting.');
        }

        return DB::transaction(function () use ($request, $note, $actor) {
            $actor ??= auth()->user();
            $locked = $this->lock($request);
            $ability = $locked->status === 'approved' ? 'fulfil' : 'approve';
            [$locked, $actor] = $this->decidable($locked, $actor, ['requested', 'approved'], $ability);
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Rejected, 'assets', $locked, reason: $note, actor: $actor);
            $employee = $this->employee($locked);
            AssetEvent::dispatch('asset.request_rejected', $employee, $locked, $this->context($locked) + ['note' => $note], array_filter([$employee->user_id]));

            return $locked;
        });
    }

    /**
     * Fulfils an approved request: a new asset is assigned ($asset: in stock, of the requested type, sub type and
     * product), a replacement takes the old asset back and assigns $asset, a return takes the asset back.
     *
     * @param  array{condition?: ?string, note?: ?string}  $data
     */
    public function fulfil(AssetRequest $request, ?Asset $asset = null, array $data = [], ?User $actor = null): AssetRequest
    {
        return DB::transaction(function () use ($request, $asset, $data, $actor) {
            [$locked, $actor] = $this->decidable($request, $actor, ['approved'], 'fulfil');
            $employee = $this->employee($locked);
            $note = filled($data['note'] ?? null) ? mb_substr(trim((string) $data['note']), 0, 255) : null;
            $condition = filled($data['condition'] ?? null) ? (string) $data['condition'] : null;
            $given = null;
            if ($locked->kind !== 'new') {
                $old = $locked->asset_id !== null ? Asset::query()->withoutGlobalScope(AccessScope::class)->find($locked->asset_id) : null;
                if ($old !== null && $old->status === 'assigned' && (int) $old->custodian_id === (int) $employee->id) {
                    $this->assets->returnAsset($old, $condition, $note ?? 'Asset request '.$locked->number, $actor);
                } elseif ($locked->kind === 'return') {
                    $note = trim(($note ?? '').' (the asset was already back)');
                }
            }
            if ($locked->kind !== 'return') {
                if ($asset === null) {
                    throw new RuntimeException('Choose the asset to hand over.');
                }
                $asset = Asset::query()->withoutGlobalScope(AccessScope::class)->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
                if ((int) $asset->asset_category_id !== (int) $locked->asset_category_id
                    || ($locked->asset_subtype_id !== null && (int) $asset->asset_subtype_id !== (int) $locked->asset_subtype_id)
                    || ($locked->asset_model_id !== null && (int) $asset->asset_model_id !== (int) $locked->asset_model_id)) {
                    throw new RuntimeException("{$asset->asset_tag} is not the asset requested (".$locked->loadMissing(['category', 'subtype', 'model'])->describe().').');
                }
                $this->assets->assign($asset, $employee, now(), null, $note ?? 'Asset request '.$locked->number, null, $actor);
                $given = $asset->id;
            }
            $locked->withAuditReason($note)->update(['status' => 'fulfilled', 'fulfilled_at' => now(), 'fulfilled_by' => $actor->id, 'fulfilment_note' => self::short($note),
                'fulfilled_asset_id' => $given ?? $locked->asset_id]);
            AssetEvent::dispatch('asset.request_fulfilled', $employee, $locked, $this->context($locked->refresh()), array_filter([$employee->user_id]));

            return $locked;
        });
    }

    /** The employee (or whoever raised it, or the asset team) withdraws an open request. */
    public function cancel(AssetRequest $request, string $reason, ?User $actor = null): AssetRequest
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for cancelling.');
        }
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $reason, $actor) {
            $locked = $this->lock($request);
            if ($actor === null || ! $this->canCancel($actor, $locked)) {
                throw new RuntimeException('You cannot cancel this asset request.');
            }

            return $this->close($locked, $reason, $actor);
        });
    }

    /**
     * Raises a joiner's entitled assets (AssetEntitlements::provisionJoiner), once each, approved by the policy and due
     * asset.onboarding.due_days after joining.
     *
     * @param  list<array{category: int, subtype: ?int, model: ?int, quantity: int}>  $entitlements
     */
    public function raiseForJoiner(Employee $employee, array $entitlements, ?User $actor = null): int
    {
        $joined = CarbonImmutable::parse($employee->joining_date ?? now())->startOfDay();
        $due = $joined->addDays(max(0, (int) $this->settings->get('asset.onboarding.due_days', 7)))->toDateString();
        $raised = 0;
        foreach ($entitlements as $i => $entitlement) {
            $category = AssetCategory::query()->find($entitlement['category']);
            if ($category === null || $category->status !== ActiveStatus::Active) {
                continue;
            }
            for ($n = 1; $n <= $entitlement['quantity']; $n++) {
                $request = $this->raiseOnce($employee, 'joiner:'.$i.':'.$n, [
                    'kind' => 'new', 'source' => 'policy', 'asset_category_id' => $category->id, 'asset_subtype_id' => $entitlement['subtype'], 'asset_model_id' => $entitlement['model'],
                    'reason' => 'New joiner: asset policy entitlement', 'needed_by' => $joined->toDateString(), 'status' => 'approved', 'due_on' => $due,
                ], $category, $actor);
                $raised += $request !== null ? 1 : 0;
            }
        }

        return $raised;
    }

    /**
     * Exit initiated: a return request for every asset the employee holds, due asset.exit.due_days before the last working
     * day (never before today), to the asset type's owner role. Owner decision 2026-10-29: raised by default
     * (asset.exit.return_requests), except for the exit types in asset.exit.return_excluded_exit_types and the asset types
     * in asset.exit.return_excluded_categories; an employee holding no company asset gets none.
     */
    public function raiseForExit(Employee $employee, ExitCase $case): int
    {
        if (! (bool) $this->settings->get('asset.exit.return_requests', true)
            || in_array((string) $case->type, array_map('strval', (array) ($this->settings->get('asset.exit.return_excluded_exit_types') ?? [])), true)) {
            return 0;
        }
        $excluded = array_map('intval', array_filter((array) ($this->settings->get('asset.exit.return_excluded_categories') ?? []), 'is_numeric'));
        $lwd = CarbonImmutable::parse($case->last_working_day ?? now())->startOfDay();
        $due = $lwd->subDays(max(0, (int) $this->settings->get('asset.exit.due_days', 0)));
        $due = $due->lt(CarbonImmutable::today()) ? CarbonImmutable::today() : $due;
        $raised = 0;
        foreach (AssetAssignment::query()->withoutGlobalScope(AccessScope::class)->with('asset')->where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')->get() as $assignment) {
            $asset = $assignment->asset;
            $category = $asset !== null ? AssetCategory::query()->find($asset->asset_category_id) : null;
            if ($category === null || in_array((int) $category->id, $excluded, true)) {
                continue;
            }
            $request = $this->raiseOnce($employee, 'exit:'.$case->id.':'.$assignment->id, [
                'kind' => 'return', 'source' => 'exit', 'asset_category_id' => $category->id, 'asset_subtype_id' => $asset->asset_subtype_id, 'asset_model_id' => $asset->asset_model_id,
                'asset_id' => $asset->id, 'asset_assignment_id' => $assignment->id, 'reason' => 'Exit '.$case->number.': last working day '.$lwd->format('j M Y'),
                'status' => 'approved', 'due_on' => $due->toDateString(),
            ], $category, null);
            $raised += $request !== null ? 1 : 0;
        }

        return $raised;
    }

    /** The exit was withdrawn: its open return requests are cancelled. */
    public function cancelForExit(Employee $employee, ExitCase $case): int
    {
        $open = AssetRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('source', 'exit')
            ->where('source_key', 'like', 'exit:'.$case->id.':%')->whereIn('status', AssetRequest::OPEN)->get();
        foreach ($open as $request) {
            DB::transaction(fn () => $this->close($this->lock($request), 'Exit '.$case->number.' withdrawn', null));
        }

        return $open->count();
    }

    /** Whether a request of this kind waits for approval (asset.request.workflow_for: none / new / return / both). */
    public function needsApproval(string $kind): bool
    {
        return match ((string) $this->settings->get('asset.request.workflow_for', 'new')) {
            'both' => true,
            'new' => $kind !== 'return',
            'return' => $kind === 'return',
            default => false,
        };
    }

    /** Who the user raises a request as for this employee: employee (own), hr (asset team in scope), manager, or null. */
    public function audience(User $user, Employee $employee): ?string
    {
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $user->id) {
            return $user->hasPermission('asset.request') ? 'employee' : null;
        }
        if (($user->hasPermission('asset.assign') || $user->hasPermission('asset.manage')) && app(AccessScopes::class)->allows($user, $employee)) {
            return 'hr';
        }
        $relationships = app(PerformanceRelationships::class);

        return $user->hasPermission('asset.request') && $relationships->manages($relationships->forUser($user), $employee->id) ? 'manager' : null;
    }

    /** Approves or rejects a request waiting for approval: the line manager or the asset team in scope, never one's own. */
    public function canApprove(User $user, AssetRequest $request): bool
    {
        if ($this->isOwn($user, $request)) {
            return false;
        }
        if (($user->hasPermission('asset.assign') || $user->hasPermission('asset.manage')) && app(AccessScopes::class)->allows($user, $request)) {
            return true;
        }
        $relationships = app(PerformanceRelationships::class);

        return $relationships->manages($relationships->forUser($user), $request->employee_id);
    }

    /** Fulfils or declines an approved request: the asset team in scope or a holder of the asset type's owner role, never one's own. */
    public function canFulfil(User $user, AssetRequest $request): bool
    {
        if ($this->isOwn($user, $request)) {
            return false;
        }
        if (($user->hasPermission('asset.assign') || $user->hasPermission('asset.manage')) && app(AccessScopes::class)->allows($user, $request)) {
            return true;
        }

        return $request->owner_role_id !== null && $user->roles()->where('roles.id', $request->owner_role_id)->where('roles.status', ActiveStatus::Active->value)->exists();
    }

    public function canCancel(User $user, AssetRequest $request): bool
    {
        if (! $request->isOpen()) {
            return false;
        }

        return $this->isOwn($user, $request) || (int) $request->requested_by === (int) $user->id || $this->canFulfil($user, $request);
    }

    /**
     * Creates the request under a lock on the employee; with a source key, null when that key was raised before.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function raise(Employee $employee, array $attributes, AssetCategory $category, ?User $actor, ?string $sourceKey = null): ?AssetRequest
    {
        $this->numbers->prepare('asset_request', NumberSeries::companyOf($employee));

        return DB::transaction(function () use ($employee, $attributes, $category, $actor, $sourceKey) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
            if ($sourceKey !== null && AssetRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('source_key', $sourceKey)->lockForUpdate()->exists()) {
                return null;
            }
            $request = new AssetRequest($attributes + [
                'number' => $this->numbers->next('asset_request', NumberSeries::companyOf($employee)), 'employee_id' => $employee->id, 'source_key' => $sourceKey,
                'owner_role_id' => $category->owner_role_id ?? Role::query()->where('slug', 'asset-admin')->value('id'), 'requested_by' => $actor?->id,
            ]);
            $request->withAuditReason($attributes['reason'] ?? null)->save();
            $this->audit->record(AuditAction::Submitted, 'assets', $request, reason: $attributes['reason'] ?? null, actor: $actor,
                metadata: ['kind' => $request->kind, 'source' => $request->source]);

            return $request;
        });
    }

    /** An automatic request, raised once per key and employee; null when it was raised before. */
    private function raiseOnce(Employee $employee, string $key, array $attributes, AssetCategory $category, ?User $actor): ?AssetRequest
    {
        $request = $this->raise($employee, $attributes, $category, $actor, $key);
        if ($request === null) {
            return null;
        }
        AssetEvent::dispatch('asset.request_to_fulfil', $employee, $request, $this->context($request), $this->ownerUsers($request));

        return $request;
    }

    private function close(AssetRequest $request, string $reason, ?User $actor): AssetRequest
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('This asset request is '.strtolower(AssetRequest::STATUSES[$request->status] ?? $request->status).' and can no longer be cancelled.');
        }
        $wasApproved = $request->status === 'approved';
        $request->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => self::short($reason), 'cancelled_at' => now()]);
        WorkflowInstance::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->id)->whereIn('status', InstanceStatus::inFlight())->get()
            ->each(fn (WorkflowInstance $instance) => $this->workflows->cancel($instance, 'Asset request cancelled'));
        $this->audit->record(AuditAction::Cancelled, 'assets', $request, reason: $reason, actor: $actor);
        AssetEvent::dispatch('asset.request_cancelled', $this->employee($request), $request, $this->context($request) + ['reason' => $reason], $wasApproved ? $this->ownerUsers($request) : []);

        return $request;
    }

    /**
     * @param  list<string>  $statuses
     * @return array{0: AssetRequest, 1: User}
     */
    private function decidable(AssetRequest $request, ?User $actor, array $statuses, string $ability): array
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new RuntimeException('An asset request is decided by a person.');
        }
        $locked = $this->lock($request);
        if (! in_array($locked->status, $statuses, true)) {
            throw new RuntimeException('This asset request is '.strtolower(AssetRequest::STATUSES[$locked->status] ?? $locked->status).' and cannot be changed that way now.');
        }
        $allowed = $ability === 'fulfil' ? $this->canFulfil($actor, $locked) : $this->canApprove($actor, $locked);
        if (! $allowed) {
            throw new RuntimeException($this->isOwn($actor, $locked) ? 'You cannot decide or fulfil your own asset request.' : 'You cannot act on this asset request.');
        }

        return [$locked, $actor];
    }

    private function assertTaxonomy(AssetCategory $category, ?int $subtypeId, ?int $modelId): void
    {
        if ($subtypeId !== null && ! AssetSubtype::query()->whereKey($subtypeId)->where('asset_category_id', $category->id)->exists()) {
            throw new RuntimeException("Choose a sub type of {$category->name}.");
        }
        if ($modelId !== null) {
            $model = AssetModel::query()->whereKey($modelId)->where('asset_category_id', $category->id)->first() ?? throw new RuntimeException("Choose a product of {$category->name}.");
            if ($subtypeId !== null && $model->asset_subtype_id !== null && (int) $model->asset_subtype_id !== $subtypeId) {
                throw new RuntimeException("{$model->name} is not of the chosen sub type.");
            }
        }
    }

    /** Due the asset type's fulfilment days from today (null when the type sets none). */
    private function dueAfter(AssetCategory $category): ?string
    {
        return $category->fulfilment_days !== null ? CarbonImmutable::today()->addDays((int) $category->fulfilment_days)->toDateString() : null;
    }

    private function isOwn(User $user, AssetRequest $request): bool
    {
        return (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->employee_id)->value('user_id') === (int) $user->id;
    }

    private function lock(AssetRequest $request): AssetRequest
    {
        return AssetRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
    }

    private function employee(AssetRequest $request): Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($request->employee_id);
    }

    /** @return array<string, mixed> references only */
    private function context(AssetRequest $request): array
    {
        return ['number' => $request->number, 'kind' => AssetRequest::KINDS[$request->kind] ?? $request->kind, 'asset_type' => AssetCategory::query()->whereKey($request->asset_category_id)->value('name'),
            'tag' => $request->asset_id ? Asset::query()->withoutGlobalScope(AccessScope::class)->whereKey($request->asset_id)->value('asset_tag') : null, 'due_on' => $request->due_on?->toDateString()];
    }

    /** @return list<int> active holders of the request's owner role */
    private function ownerUsers(AssetRequest $request): array
    {
        if ($request->owner_role_id === null) {
            return [];
        }

        return Role::query()->whereKey($request->owner_role_id)->first()?->users()->pluck('users.id')->map(fn ($id) => (int) $id)->unique()->take(25)->values()->all() ?? [];
    }

    /** @return list<int> */
    private function managerUsers(Employee $employee): array
    {
        return array_values(array_filter([$employee->currentManager()->with('manager')->first()?->manager?->user_id]));
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function short(?string $note): ?string
    {
        return $note === null ? null : mb_substr(trim($note), 0, 500);
    }
}
