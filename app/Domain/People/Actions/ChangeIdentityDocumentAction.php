<?php

namespace App\Domain\People\Actions;

use App\Domain\Employment\Concerns\ChangesProfileData;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\PersonIdentityDocument;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity MS-4 (matrix 22 §22.2.2 #3, #4): the one write path for a person's passport and driving licence. Like the
 * statutory identifiers and bank accounts these are highly sensitive, so every change needs employee.sensitive.update
 * (within organisation scope) whatever the caller; a self-service change arrives only through ProfileChanges, always after
 * approval (section `identity_documents`). Entering or changing a number needs the CA-4 document "required with" the kind
 * (linked fields `passport`, `driving_licence`). One record per kind; audit masks the number; the timeline entry and the
 * event employee.identity_document_changed carry no number.
 */
final class ChangeIdentityDocumentAction
{
    use ChangesProfileData;

    public const PERMISSION = 'employee.sensitive.update';

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    /** @return array<string, mixed> */
    public function rules(bool $adding = true): array
    {
        return [
            'kind' => ['required', 'in:'.implode(',', array_keys(PersonIdentityDocument::kinds()))],
            'number' => [$adding ? 'required' : 'nullable', 'string', 'min:4', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\/]*$/'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date'],
            'issuing_country' => ['nullable', 'string', 'size:2', 'alpha'],
            'place_of_issue' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function add(Employee $employee, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): PersonIdentityDocument
    {
        $this->guard->authorize($actor, $employee, self::PERMISSION, $origin);
        $clean = $this->normalised($this->validated($data, $this->rules(true)));
        $this->assertDates($clean['issued_on'] ?? null, $clean['expires_on'] ?? null);
        $this->guard->requireLinkedDocument($employee, (string) $clean['kind']);

        return DB::transaction(function () use ($employee, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            if (PersonIdentityDocument::query()->where('person_id', $employee->person_id)->where('kind', $clean['kind'])->lockForUpdate()->exists()) {
                throw new ProfileChangeRefused('A '.mb_strtolower((string) (PersonIdentityDocument::kinds()[$clean['kind']] ?? $clean['kind'])).' is already on file: change that one instead.');
            }
            $document = new PersonIdentityDocument($clean + ['person_id' => $employee->person_id]);
            $document->withAuditReason($reason, $origin?->reference)->save();
            $this->recordChange($employee, 'employee.identity_document_changed', 'statutory', $document->kindLabel().' added', $document, 'added', $origin);

            return $document;
        });
    }

    /** @param  array<string, mixed>  $data  number may be omitted (or blank) to keep it */
    public function update(Employee $employee, PersonIdentityDocument $document, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): PersonIdentityDocument
    {
        $this->assertBelongs($employee, $document);
        $this->guard->authorize($actor, $employee, self::PERMISSION, $origin);
        $data['kind'] = $document->kind; // the kind of a record never changes
        $clean = $this->normalised($this->validated($data, $this->rules(false)));
        $this->assertDates(array_key_exists('issued_on', $clean) ? $clean['issued_on'] : $document->issued_on?->toDateString(),
            array_key_exists('expires_on', $clean) ? $clean['expires_on'] : $document->expires_on?->toDateString());
        if (blank($clean['number'] ?? null)) {
            unset($clean['number']);
        } elseif ((string) $clean['number'] !== (string) $document->number) {
            $this->guard->requireLinkedDocument($employee, (string) $document->kind);
        }
        unset($clean['kind']);

        return DB::transaction(function () use ($employee, $document, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $document->withAuditReason($reason, $origin?->reference)->update($clean);
            $this->recordChange($employee, 'employee.identity_document_changed', 'statutory', $document->kindLabel().' updated', $document, 'updated', $origin);

            return $document;
        });
    }

    public function remove(Employee $employee, PersonIdentityDocument $document, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): void
    {
        $this->assertBelongs($employee, $document);
        $this->guard->authorize($actor, $employee, self::PERMISSION);

        DB::transaction(function () use ($employee, $document, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $document->withAuditReason($reason, $origin?->reference)->delete();
            $this->recordChange($employee, 'employee.identity_document_changed', 'statutory', $document->kindLabel().' removed', $document, 'removed', $origin);
        });
    }

    /** A document never expires before it was issued (with the dates on file for what an update leaves out). */
    private function assertDates(mixed $issued, mixed $expires): void
    {
        if (filled($issued) && filled($expires) && strtotime((string) $expires) < strtotime((string) $issued)) {
            throw new ProfileChangeRefused('The expiry date is before the date of issue.');
        }
    }

    /** Numbers in capitals without spaces; the issuing country as a capital ISO code. */
    private function normalised(array $clean): array
    {
        if (filled($clean['number'] ?? null)) {
            $clean['number'] = mb_strtoupper(preg_replace('/\s+/', '', (string) $clean['number']));
        }
        if (filled($clean['issuing_country'] ?? null)) {
            $clean['issuing_country'] = mb_strtoupper((string) $clean['issuing_country']);
        }

        return $clean;
    }

    private function assertBelongs(Employee $employee, PersonIdentityDocument $document): void
    {
        if ((int) $document->person_id !== (int) $employee->person_id) {
            throw new ProfileChangeRefused('That record does not belong to this employee.');
        }
    }
}
