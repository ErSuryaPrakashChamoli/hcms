<?php

namespace App\Domain\Configuration\Overrides;

use Illuminate\Database\Eloquent\Model;

/**
 * An OverrideFields provider whose values must also suit the company they are for (master relationships R3: a
 * company's own grade must be a grade that company may use). ConfigurationOverrides::set() calls it after validate().
 */
interface CompanyAwareOverrideFields
{
    /**
     * Refuses (throws ApplicabilityRefusedException) when the values with the company's overrides applied do not suit it.
     *
     * @param  array<string, mixed>  $values  the shared values with the company's overrides applied
     */
    public function validateForCompany(Model $record, array $values, int $companyId): void;
}
