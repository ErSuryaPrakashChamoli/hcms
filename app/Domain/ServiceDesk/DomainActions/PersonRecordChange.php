<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangePersonRecordAction;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: an address / emergency contact / family member change → the People action. It adds a new
 * record, or updates the employee's own record named by `record_id` (checked to belong to them by the
 * People action).
 */
abstract class PersonRecordChange extends ProfileChangeHandler
{
    abstract protected function action(): ChangePersonRecordAction;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** @return array<string, array<string, mixed>> */
    abstract protected function recordFields(): array;

    abstract protected function recordLabel(Model $record): string;

    public function fields(?Employee $employee): array
    {
        $existing = $employee ? $this->model()::query()->where('person_id', $employee->person_id)->get()->mapWithKeys(fn (Model $r) => [(string) $r->getKey() => $this->recordLabel($r)])->all() : [];

        return ['record_id' => ['label' => 'Change an existing entry (leave empty to add a new one)', 'type' => 'dropdown', 'options' => $existing, 'class' => 'sensitive']]
            + array_map(fn (array $f) => $f + ['class' => 'sensitive'], $this->recordFields());
    }

    public function executorPermission(): ?string
    {
        return 'employee.update';
    }

    public function validate(Employee $employee, array $data): array
    {
        $clean = $this->check($data, $this->action()->rules());
        if (filled($data['record_id'] ?? null)) {
            $record = $this->model()::query()->whereKey((int) $data['record_id'])->where('person_id', $employee->person_id)->first()
                ?? throw new ServiceDeskRuleViolation('That entry does not belong to this employee.');
            $clean['record_id'] = (int) $record->getKey();
        }

        return $clean;
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        $clean = $this->validate($employee, $data);
        $recordId = $clean['record_id'] ?? null;
        unset($clean['record_id']);
        if ($recordId !== null) {
            $record = $this->model()::query()->findOrFail($recordId);

            return $this->action()->update($employee, $record, $clean, $actor, $this->reason($ticket->number), $origin);
        }

        return $this->action()->add($employee, $clean, $actor, $this->reason($ticket->number), $origin);
    }
}
