<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity MS-4 (matrix 22 §22.2.2 #3, #4): a passport or driving licence of the person. Highly sensitive (blueprint
 * §80): the number is encrypted at rest, hidden from serialisation and masked in the audit trail; lists show only the last
 * four characters (`number_last4`). Written only through ChangeIdentityDocumentAction (employee.sensitive.update, the CA-4
 * linked document); read on the Employee 360 with `viewSensitive`, and by the employee only as PRV-1 allows (MyProfile).
 */
#[Fillable(['tenant_id', 'person_id', 'kind', 'number', 'issued_on', 'expires_on', 'issuing_country', 'place_of_issue'])]
#[Hidden(['number'])]
class PersonIdentityDocument extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            $number = $document->getAttributes()['number'] ?? null;
            if ($document->isDirty('number') && $number !== null) {
                $document->number_last4 = mb_substr((string) $document->number, -4);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'number' => 'encrypted',
            'issued_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    public static function kinds(): array
    {
        return (array) config('peopleos.people.identity_document_kinds', []);
    }

    public function kindLabel(): string
    {
        return (string) (self::kinds()[$this->kind] ?? ucfirst(str_replace('_', ' ', (string) $this->kind)));
    }

    public function maskedNumber(): string
    {
        return '••••'.($this->getAttributes()['number_last4'] ?? '');
    }

    public function auditLabel(): string
    {
        return $this->kindLabel().' '.$this->maskedNumber();
    }

    /** @return list<string> */
    public function auditSensitiveAttributes(): array
    {
        return ['number'];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'number_last4'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
