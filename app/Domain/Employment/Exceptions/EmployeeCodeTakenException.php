<?php

namespace App\Domain\Employment\Exceptions;

use InvalidArgumentException;

/** Raised when a hire names an employee code another employee of the tenant already holds (any company, any scope). */
class EmployeeCodeTakenException extends InvalidArgumentException
{
    public function __construct(public readonly string $employeeCode)
    {
        parent::__construct("Employee code {$employeeCode} is already in use by another employee.");
    }
}
