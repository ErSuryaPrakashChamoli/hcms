<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Assets\Models\Asset;
use Illuminate\Database\Eloquent\Builder;

class AssetsDataset extends Dataset
{
    public function key(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return 'Asset register';
    }

    public function permissions(): array
    {
        return ['asset.view'];
    }

    public function fields(): array
    {
        return [
            'asset_tag' => ['label' => 'Tag', 'type' => 'string', 'value' => fn ($a) => $a->asset_tag],
            'name' => ['label' => 'Asset', 'type' => 'string', 'value' => fn ($a) => $a->name],
            'category' => ['label' => 'Category', 'type' => 'string', 'value' => fn ($a) => $a->category?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($a) => $a->status],
            'condition' => ['label' => 'Condition', 'type' => 'string', 'value' => fn ($a) => $a->condition],
            'custodian' => ['label' => 'Custodian', 'type' => 'string', 'value' => fn ($a) => $a->custodian?->person?->full_name],
            'location' => ['label' => 'Location', 'type' => 'string', 'value' => fn ($a) => $a->location?->name],
            'purchase_date' => ['label' => 'Purchased on', 'type' => 'date', 'value' => fn ($a) => $a->purchase_date],
            'purchase_cost' => ['label' => 'Purchase cost', 'type' => 'number', 'value' => fn ($a) => $a->purchase_cost === null ? null : (float) $a->purchase_cost],
            'warranty_until' => ['label' => 'Warranty until', 'type' => 'date', 'value' => fn ($a) => $a->warranty_until],
            'count' => ['label' => 'Assets (1 per row)', 'type' => 'number', 'value' => fn ($a) => 1],
        ];
    }

    public function query(): Builder
    {
        return Asset::query()->with(['category', 'custodian.person', 'location'])->orderBy('asset_tag');
    }
}
