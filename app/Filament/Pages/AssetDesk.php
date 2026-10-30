<?php

namespace App\Filament\Pages;

use App\Domain\Assets\Models\AssetRequest;
use App\Domain\Assets\Models\AssetSubtype;
use App\Filament\Resources\AssetCategories\AssetCategoryResource;
use App\Filament\Resources\AssetModels\AssetModelResource;
use App\Filament\Resources\AssetRequests\AssetRequestResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\AssetSubtypes\AssetSubtypeResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity XO-1 (HR Desk → Asset panel, matrix 26 §26.1 #2): the asset registers in HROne's order — Asset inventory ·
 * Employee assets · Asset acknowledgement · Asset request · Reports · Asset setup. "Employee assets" and "Asset
 * acknowledgement" open views of the asset register (assets in use with their custodian; in use and not yet
 * acknowledged) until registers of their own exist. Opens for people who run assets (asset.view or asset.manage /
 * asset.assign, plus asset.manage or asset.assign) and lists only what each of them can open; counts come from each
 * register's own (scoped) query. An employee or a manager requesting assets gets no desk.
 */
class AssetDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Assets';

    protected static ?string $navigationLabel = 'Asset desk';

    protected static ?string $title = 'Asset desk';

    protected static ?string $slug = 'asset-desk';

    protected static ?int $navigationSort = 0;

    /** Any one of these makes someone who sees the asset register an asset operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['asset.manage', 'asset.assign'];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! AssetResource::oversees()) {
            return false;
        }
        foreach (self::OPERATOR_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return static::anyEntryOpens();
            }
        }

        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'The asset inventory, who holds what, acknowledgements and asset requests in one place. Open a register on its view, save the views you use and report on what you see.';
    }

    protected function registers(): array
    {
        return ['assets'];
    }

    protected function sections(): array
    {
        $assets = fn (array $statuses) => fn () => AssetResource::getEloquentQuery()->whereIn('status', $statuses)->count();

        return [
            ['label' => 'Asset inventory', 'items' => [
                ['label' => 'Asset inventory', 'target' => AssetResource::class, 'description' => 'Every asset with its status, custodian and location'],
                ['label' => 'In stock', 'target' => AssetResource::class, 'params' => ['view' => 'in_stock'], 'count' => $assets(['in_stock'])],
                ['label' => 'In repair', 'target' => AssetResource::class, 'params' => ['view' => 'in_repair'], 'count' => $assets(['in_repair'])],
                ['label' => 'Lost', 'target' => AssetResource::class, 'params' => ['view' => 'lost'], 'count' => $assets(['lost'])],
            ]],
            ['label' => 'Employee assets', 'items' => [
                ['label' => 'Assets by employee', 'target' => AssetResource::class, 'params' => ['view' => 'assigned'], 'description' => 'Assets in use, with who holds each',
                    'count' => $assets(['assigned'])],
            ]],
            ['label' => 'Asset acknowledgement', 'items' => [
                ['label' => 'Waiting for acknowledgement', 'target' => AssetResource::class, 'params' => ['view' => 'unacknowledged'], 'description' => 'Handed over, not yet acknowledged by the employee',
                    'count' => fn () => AssetResource::getEloquentQuery()->whereIn('asset_subtype_id', AssetSubtype::query()->select('id')->where('acknowledgement_required', true))
                        ->whereHas('assignments', fn ($a) => $a->where('status', 'active')->whereNull('acknowledged_at'))->count()],
            ]],
            ['label' => 'Asset request', 'items' => [
                ['label' => 'Asset requests', 'target' => AssetRequestResource::class, 'description' => 'New, replacement and return requests, waiting for approval or to fulfil',
                    'count' => fn () => AssetRequestResource::getEloquentQuery()->whereIn('status', AssetRequest::OPEN)->count()],
            ]],
            ['label' => 'Reports', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made asset reports'],
                ['label' => 'My reports', 'target' => ReportResource::class],
            ]],
            ['label' => 'Asset setup', 'items' => [
                ['label' => 'Setup centre: asset', 'target' => SetupCentre::class, 'params' => ['module' => 'asset'], 'description' => 'Asset settings and the asset policy'],
                ['label' => 'Asset types', 'target' => AssetCategoryResource::class],
                ['label' => 'Asset sub types', 'target' => AssetSubtypeResource::class],
                ['label' => 'Products', 'target' => AssetModelResource::class],
                ['label' => 'Policies', 'target' => PolicyResource::class],
            ]],
        ];
    }
}
