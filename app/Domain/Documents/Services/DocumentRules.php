<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\OwnRecords;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * HROne setup parity CA-4 (Core HR → Employee → Document): the rules a document type carries. A document counts while it
 * is current: pending verification or verified (rejected and superseded ones do not).
 * - mandatory for every employee: missing ones are a reminder for the employee and a "missing documents" report;
 * - mandatory for onboarding: with documents.block_joining_without_mandatory on, an onboarding employee cannot join;
 * - linked to a profile field and "required with the field": the field cannot be entered or changed without one;
 * - employee may upload: the employee adds it from My HR (pending HR verification, never verified by the uploader).
 */
final class DocumentRules
{
    public const CURRENT = ['pending', 'verified'];

    public function __construct(private readonly SettingsRepository $settings, private readonly Documents $documents) {}

    /**
     * Active document types the employee must have (every employee, or before joining) and has no current document of.
     *
     * @return Collection<int, DocumentType>
     */
    public function missing(Employee $employee, string $for = 'employee'): Collection
    {
        $types = DocumentType::query()->where('status', 'active')->where($for === 'onboarding' ? 'mandatory_for_onboarding' : 'mandatory_for_employee', true)->orderBy('name')->get();
        if ($types->isEmpty()) {
            return $types;
        }
        $have = $this->currentTypeIds($employee, $types->modelKeys());

        return $types->reject(fn (DocumentType $type) => in_array($type->id, $have, true))->values();
    }

    /** Refuses joining while a document mandatory for onboarding is missing (only when the organisation asks for it). */
    public function assertCanJoin(Employee $employee): void
    {
        if (! (bool) $this->settings->get('documents.block_joining_without_mandatory', false)) {
            return;
        }
        $missing = $this->missing($employee, 'onboarding');
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('These documents are needed before joining: '.$missing->pluck('name')->implode(', ').'.');
        }
    }

    /** The name of a document type required with this profile field that the employee has no current document of. */
    public function missingForField(Employee $employee, string $field): ?string
    {
        $types = DocumentType::query()->where('status', 'active')->where('linked_field', $field)->where('required_with_field', true)->orderBy('name')->get();
        if ($types->isEmpty()) {
            return null;
        }
        $have = $this->currentTypeIds($employee, $types->modelKeys());

        return $types->first(fn (DocumentType $type) => ! in_array($type->id, $have, true))?->name;
    }

    /** @return Collection<int, DocumentType> the types employees may upload themselves */
    public function selfUploadTypes(): Collection
    {
        return DocumentType::query()->where('status', 'active')->where('employee_can_upload', true)->orderBy('name')->get();
    }

    /**
     * An employee's own upload (My HR, or a document attached to a self-service profile change, HROne parity MS-3): only
     * their own record, only a type that allows it; it waits for HR to verify. $comment is the uploader's comment.
     */
    public function employeeUpload(User $user, Employee $employee, DocumentType $type, UploadedFile $file, ?string $expiresOn = null, ?string $issuedOn = null, ?string $comment = null): EmployeeDocument
    {
        if (! $user->hasPermission('document.own') || ! app(OwnRecords::class)->isOwn($user, $employee->id)) {
            throw new RuntimeException('You can upload documents only to your own record.');
        }
        if (! $type->employee_can_upload || $type->status?->value !== 'active') {
            throw new RuntimeException('HR uploads this document type.');
        }
        if ($type->requires_expiry && blank($expiresOn)) {
            throw new RuntimeException("{$type->name} needs its expiry date.");
        }

        return $this->documents->store($employee, $file, $type, null, $expiresOn, $issuedOn, 'Uploaded by the employee (My HR)', uploadComment: $comment);
    }

    /** @return list<int> the given types the employee has a current document of */
    private function currentTypeIds(Employee $employee, array $typeIds): array
    {
        return EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->whereIn('status', self::CURRENT)->whereIn('document_type_id', $typeIds)->distinct()->pluck('document_type_id')->map(fn ($id) => (int) $id)->all();
    }
}
