<?php

namespace App\Domain\Configuration\Applicability;

use Illuminate\Database\Eloquent\Model;

/**
 * For configuration types whose references are not plain columns (policy settings, template items, …): what a record
 * points at, and which records of the type point at a given record. Named in a type's spec as 'references_provider'.
 */
interface ReferencesProvider
{
    /** @return list<array{type: string, id: int, label: string, path: string}> registered configuration records the record points at */
    public function references(Model $record): array;

    /** @return list<Model> records of this provider's type that point at the given record */
    public function referencing(string $type, int $id): array;
}
