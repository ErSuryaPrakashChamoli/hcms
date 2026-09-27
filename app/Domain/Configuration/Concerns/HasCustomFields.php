<?php

namespace App\Domain\Configuration\Concerns;

use App\Domain\Configuration\Models\CustomFieldValue;
use App\Domain\Configuration\Services\CustomFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait HasCustomFields
{
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'model');
    }

    /** @return array<string, mixed> key => value */
    public function customFields(): array
    {
        return app(CustomFields::class)->valuesFor($this);
    }

    public function customField(string $key): mixed
    {
        return $this->customFields()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $values */
    public function setCustomFields(array $values, ?string $reason = null): static
    {
        app(CustomFields::class)->store($this, $values, $reason);

        return $this;
    }
}
