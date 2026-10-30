<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Experience\Services\ProfileSectionVisibility;
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
    /** HROne setup parity CA-6: the profile section (peopleos.profile.sections) this tab belongs to. */
    protected static string $profileSection;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // CA-6: the profile-section matrix can only hide the tab, on top of the 360's own rule.
        return (auth()->user()?->can('view', $ownerRecord) ?? false)
            && app(ProfileSectionVisibility::class)->allows(auth()->user(), $ownerRecord, static::$profileSection);
    }

    public function getRelationship(): Relation|Builder
    {
        return $this->getOwnerRecord()->person->{static::getRelationshipName()}();
    }
}
