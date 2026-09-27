<?php

namespace App\Domain\Compliance\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Part J: the professional tax view of the platform rule store (code = PT, one row per state and
 * version). Same immutability and verification workflow as every ComplianceRule.
 */
class ProfessionalTaxRuleVersion extends ComplianceRule
{
    protected $table = 'compliance_rules';

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('code', fn (Builder $q) => $q->where('code', 'PT'));
    }
}
