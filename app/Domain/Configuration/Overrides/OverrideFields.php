<?php

namespace App\Domain\Configuration\Overrides;

use Illuminate\Database\Eloquent\Model;

/**
 * For a configuration type whose overridable fields depend on the record (a policy's settings depend on its type and
 * its rows): which fields a company may override, the shared values they override, and how the overridden values are
 * checked together. Named in a type's spec as 'overrides' => SomeClass::class.
 */
interface OverrideFields
{
    /** @return array<string, OverrideField> path => field */
    public function fields(Model $record): array;

    /** @return array<string, mixed> the shared values (what the paths are read from) on a date */
    public function shared(Model $record, string $on): array;

    /**
     * Refuses (throws) when the shared values with these overrides applied would be invalid together.
     *
     * @param  array<string, mixed>  $values  the shared values with the overrides applied
     */
    public function validate(Model $record, array $values): void;
}
