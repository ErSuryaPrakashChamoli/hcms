<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListAssets extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AssetResource::class;

    public static function registerKey(): string
    {
        return 'assets';
    }

    /**
     * HROne parity XO-1 (matrix 26 §26.1 #2): the Asset panel's inventory states, "Employee Assets" (assets in use with
     * their custodian) and "Asset acknowledgement" (in use, not yet acknowledged) as views of the asset register.
     */
    public static function presetViews(): array
    {
        return [
            'in_stock' => ['label' => 'In stock', 'filters' => ['status' => ['value' => 'in_stock']]],
            'assigned' => ['label' => 'In use, by employee', 'filters' => ['status' => ['value' => 'assigned']]],
            'unacknowledged' => ['label' => 'Waiting for acknowledgement', 'filters' => ['acknowledgement' => ['value' => 'pending']]],
            'in_repair' => ['label' => 'In repair', 'filters' => ['status' => ['value' => 'in_repair']]],
            'lost' => ['label' => 'Lost', 'filters' => ['status' => ['value' => 'lost']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['assets_assigned', 'assets_by_category'];
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity XO-1: views and report for the HR side; "My assets" keeps its look.
            ...(AssetResource::oversees() ? $this->registerToolActions() : []),
            CreateAction::make()->label('Receive asset'),
        ];
    }
}
