<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Concerns\HasCustomFields;
use Illuminate\Database\Eloquent\Model;

/**
 * For Create/Edit/View pages of models using HasCustomFields: loads values into the form state
 * and persists them after the record itself is saved.
 */
trait SavesCustomFields
{
    /** @var array<string, mixed>|null */
    private ?array $pendingCustomFields = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof Model && in_array(HasCustomFields::class, class_uses_recursive($record), true)) {
            $data[CustomFieldsSchema::STATE] = $record->customFields();
        }

        return $data;
    }

    /** Pull custom field values out of the submitted data so they never hit the model's fill(). */
    protected function extractCustomFields(array &$data): void
    {
        $this->pendingCustomFields = $data[CustomFieldsSchema::STATE] ?? null;
        unset($data[CustomFieldsSchema::STATE]);
    }

    protected function persistCustomFields(Model $record, ?string $reason = null): void
    {
        if ($this->pendingCustomFields === null || ! in_array(HasCustomFields::class, class_uses_recursive($record), true)) {
            return;
        }

        $record->setCustomFields($this->pendingCustomFields, $reason);
        $this->pendingCustomFields = null;
    }
}
