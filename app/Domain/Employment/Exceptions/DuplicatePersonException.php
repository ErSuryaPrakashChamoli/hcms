<?php

namespace App\Domain\Employment\Exceptions;

use Illuminate\Support\Collection;
use RuntimeException;

/** Raised when creating a person/employee would duplicate an existing person in the tenant (contract §3). */
class DuplicatePersonException extends RuntimeException
{
    /** @param  Collection<int, array{person_id: int, employee_id: ?int, employee_code: ?string, name: string, matched_on: list<string>}>  $candidates */
    public function __construct(public readonly Collection $candidates)
    {
        parent::__construct('A person with the same '.$candidates->flatMap(fn ($c) => $c['matched_on'])->unique()->implode(' / ').' already exists ('.$candidates->pluck('name')->implode(', ').'). Attach the existing person or review the duplicate.');
    }
}
