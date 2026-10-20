<?php

namespace App\Domain\Tax\Contracts;

use App\Domain\Tax\Enums\TaxIdType;
use InvalidArgumentException;

/** SaaS.7: a format check for one identifier type (structure and check digit; never a live authority lookup). */
interface TaxIdValidator
{
    public function type(): TaxIdType;

    /**
     * @return string the normalised identifier
     *
     * @throws InvalidArgumentException
     */
    public function validate(string $value, ?string $subdivision): string;
}
