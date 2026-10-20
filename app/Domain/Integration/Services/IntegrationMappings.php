<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\IntegrationMapping;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Support\LinkableEntities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Phase 14 (ADR-0014 addendum): an external value for a dimension (company, department, grade, …)
 * → the PeopleOS record. Without an explicit mapping the PeopleOS code is the default mapping. Enum
 * or label equality is never assumed.
 */
final class IntegrationMappings
{
    public function __construct(private readonly LinkableEntities $entities) {}

    public function map(IntegrationSystem $system, string $dimension, string $externalValue, Model $target): IntegrationMapping
    {
        $alias = $this->entities->aliasFor($target);
        if ($alias !== $dimension) {
            throw new IntegrationRejected("A {$dimension} value must map to a {$dimension}.");
        }
        $externalValue = trim($externalValue);
        if ($externalValue === '' || mb_strlen($externalValue) > 191) {
            throw new IntegrationRejected('An external value is needed (at most 191 characters).');
        }

        try {
            return IntegrationMapping::query()->create(['integration_system_id' => $system->id, 'dimension' => $dimension, 'external_value' => $externalValue, 'peopleos_type' => $alias, 'peopleos_id' => $target->getKey()]);
        } catch (UniqueConstraintViolationException) {
            throw new IntegrationRejected('That external value is already mapped.', 'duplicate', 409);
        }
    }

    public function resolve(IntegrationSystem $system, string $dimension, string $externalValue): ?Model
    {
        $this->entities->classFor($dimension);
        $mapping = IntegrationMapping::query()->where('integration_system_id', $system->id)->where('dimension', $dimension)->where('external_value', trim($externalValue))->where('status', 'active')->first();

        return $mapping !== null ? $this->entities->find($mapping->peopleos_type, $mapping->peopleos_id) : $this->entities->findByCodeOrId($dimension, trim($externalValue));
    }
}
