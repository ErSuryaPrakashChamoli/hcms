<?php

namespace App\Filament\Support;

use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

/**
 * Master relationships R4: "Place under (optional)" on a new organisation unit. Blank creates it unplaced (place it later
 * in the Organisation Designer; it is listed in Organisation → Unmapped masters). Shown only to people who may design
 * the structure, and only when creating: an existing unit moves in the Designer.
 */
final class PlacementField
{
    public static function make(string $treeType): Select
    {
        return Select::make('organisation_placement')->label('Place under (optional)')
            ->options(fn () => app(OrganisationTree::class)->options(null, $treeType))
            ->searchable()->dehydrated(false)
            ->visible(fn (string $operation) => $operation === 'create' && (auth()->user()?->can('organisation.design') ?? false))
            ->helperText('Where it sits in the organisation structure. Leave blank to place it later in the Organisation Designer; units not placed are listed in Organisation → Unmapped masters.')
            ->saveRelationshipsUsing(function (Model $record, mixed $state): void {
                if (blank($state)) {
                    return;
                }
                app(OrganisationTree::class)->attach($record, OrganisationNode::query()->findOrFail((int) $state), 'Placed when it was created');
            });
    }
}
