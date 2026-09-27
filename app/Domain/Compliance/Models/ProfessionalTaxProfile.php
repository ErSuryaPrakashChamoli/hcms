<?php

namespace App\Domain\Compliance\Models;

use Illuminate\Database\Eloquent\Builder;

/** Part J: the PT view of an establishment statutory profile (statute = PT). */
class ProfessionalTaxProfile extends EstablishmentStatutoryProfile
{
    protected static function booted(): void
    {
        // A block closure: a returned value would halt the remaining saving listeners.
        static::saving(function (ProfessionalTaxProfile $p): void {
            $p->statute = 'PT';
        });
        parent::booted();
        static::addGlobalScope('statute', fn (Builder $q) => $q->where('statute', 'PT'));
    }
}
