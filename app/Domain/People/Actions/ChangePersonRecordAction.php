<?php

namespace App\Domain\People\Actions;

use App\Domain\Employment\Concerns\ChangesProfileData;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12: the one write path for one kind of person record (address, emergency contact, family
 * member). People owns these records (they belong to the lifetime Person); the concrete actions name
 * the record, its rules and its event. Permissions follow the existing Employee 360 rules for person
 * data: adding needs employee.create, changing employee.update, removing employee.delete — always
 * within organisation scope, whatever the caller.
 *
 * @template T of Model
 */
abstract class ChangePersonRecordAction
{
    use ChangesProfileData;

    public function __construct(private readonly ProfileChangeGuard $guard) {}

    /** @return class-string<T> */
    abstract protected function model(): string;

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    abstract protected function event(): string;

    abstract protected function label(): string;

    /** HROne setup parity CA-4: the profile field a document type may be required with (peopleos.documents.linked_fields). */
    protected function linkedDocumentField(): ?string
    {
        return null;
    }

    /** @param  array<string, mixed>  $data */
    public function add(Employee $employee, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): Model
    {
        $this->guard->authorize($actor, $employee, 'employee.create', $origin);
        $clean = $this->validated($data, $this->rules());
        if (($field = $this->linkedDocumentField()) !== null) {
            $this->guard->requireLinkedDocument($employee, $field);
        }

        return DB::transaction(function () use ($employee, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $class = $this->model();
            $record = new $class($clean + ['person_id' => $employee->person_id]);
            $record->withAuditReason($reason, $origin?->reference)->save();
            $this->recordChange($employee, $this->event(), 'personal', $this->label().' added', $record, 'added', $origin);

            return $record;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Employee $employee, Model $record, array $data, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): Model
    {
        $this->assertBelongs($employee, $record);
        $this->guard->authorize($actor, $employee, 'employee.update', $origin);
        $clean = $this->validated($data, $this->rules());
        if (($field = $this->linkedDocumentField()) !== null) {
            $this->guard->requireLinkedDocument($employee, $field);
        }

        return DB::transaction(function () use ($employee, $record, $clean, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $record->withAuditReason($reason, $origin?->reference)->update($clean);
            $this->recordChange($employee, $this->event(), 'personal', $this->label().' updated', $record, 'updated', $origin);

            return $record;
        });
    }

    public function remove(Employee $employee, Model $record, User $actor, ?string $reason = null, ?ChangeOrigin $origin = null): void
    {
        $this->assertBelongs($employee, $record);
        $this->guard->authorize($actor, $employee, 'employee.delete');

        DB::transaction(function () use ($employee, $record, $reason, $origin) {
            $employee = $this->lockEmployee($employee);
            $record->withAuditReason($reason, $origin?->reference)->delete();
            $this->recordChange($employee, $this->event(), 'personal', $this->label().' removed', $record, 'removed', $origin);
        });
    }

    private function assertBelongs(Employee $employee, Model $record): void
    {
        if (! $record instanceof ($this->model()) || (int) $record->getAttribute('person_id') !== (int) $employee->person_id) {
            throw new ProfileChangeRefused('That record does not belong to this employee.');
        }
    }
}
