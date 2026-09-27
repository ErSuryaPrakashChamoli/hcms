<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Person data shown on the Employee 360. The owner record is the Employee, the relationship
 * lives on its Person, so the relationship is resolved through employee->person.
 */
abstract class PersonSatelliteRelationManager extends RelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function getRelationship(): Relation|Builder
    {
        return $this->getOwnerRecord()->person->{static::getRelationshipName()}();
    }
}
