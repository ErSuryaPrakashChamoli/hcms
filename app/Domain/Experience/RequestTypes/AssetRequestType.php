<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\AssetRequests\AssetRequestResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: asset request (Asset requests → "Request an asset"; for an employee: "Request for an employee"). */
final class AssetRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'asset';
    }

    public function label(): string
    {
        return 'Asset';
    }

    public function description(): string
    {
        return 'A new asset, a replacement or a return';
    }

    public function module(): string
    {
        return 'Assets';
    }

    public function icon(): string
    {
        return 'heroicon-o-computer-desktop';
    }

    public function keywords(): array
    {
        return ['asset', 'laptop', 'phone', 'equipment', 'replacement', 'return asset'];
    }

    public function permission(): string
    {
        return 'asset.request';
    }

    /** The request needs an asset type in use (the service refuses otherwise). */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('asset.request') && AssetRequestResource::canAccess()
            && AssetCategory::query()->where('status', ActiveStatus::Active)->exists();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(AssetRequestResource::getUrl('index'), 'requestAsset');
    }

    public function onBehalfPermission(): ?string
    {
        return 'asset.request';
    }

    /** For the asset team, or a person with asset.request who manages someone (whom the service lets them raise for). */
    public function onBehalfUrl(User $user): ?string
    {
        if (! $this->signedIn($user) || ! AssetRequestResource::canAccess()) {
            return null;
        }
        $relationships = app(PerformanceRelationships::class);
        $allowed = $user->hasPermission('asset.assign') || $user->hasPermission('asset.manage')
            || ($user->hasPermission('asset.request') && $relationships->reportIds($relationships->forUser($user))->isNotEmpty());

        return $allowed && (AssetRequestResource::team() || $user->can('asset.request'))
            ? $this->withAction(AssetRequestResource::getUrl('index'), 'requestForEmployee') : null;
    }

    public function model(): ?string
    {
        return AssetRequest::class;
    }

    protected function resource(): ?string
    {
        return AssetRequestResource::class;
    }

    protected function with(): array
    {
        return ['category', 'subtype', 'model'];
    }

    public function statusMap(): array
    {
        return [
            'requested' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'fulfilled' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Needed by', 'column' => 'needed_by'],
            'decided' => ['label' => 'Decided on', 'column' => 'decided_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['number', 'reason'];
    }

    public function summary(Model $record): array
    {
        /** @var AssetRequest $record */
        return [
            'title' => $this->join($record->number, AssetRequest::KINDS[$record->kind] ?? null, $record->describe() ?: null),
            'detail' => $this->short($record->reason),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['asset.requested', 'asset.return_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'Your manager, or the asset team, in the Approval Center';
    }

    public function approverPermissions(): array
    {
        return ['asset.assign', 'asset.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return (string) ($record->getAttributes()['kind'] ?? '') === 'return' ? 'asset_return_request' : 'asset_request';
    }

    public function knowledgeCategory(): ?string
    {
        return 'it';
    }
}
