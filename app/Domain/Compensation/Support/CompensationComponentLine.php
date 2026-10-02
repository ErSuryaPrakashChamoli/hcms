<?php

namespace App\Domain\Compensation\Support;

use App\Domain\Payroll\Models\SalaryComponent;

/**
 * One component of the structure an approved compensation is built on: the payroll component (its
 * calculation method, taxability and statutory flags stay Payroll's) and the structure's override.
 */
final class CompensationComponentLine
{
    public function __construct(
        public readonly SalaryComponent $component,
        public readonly ?string $formulaOverride,
        public readonly int $sortOrder,
    ) {}
}
