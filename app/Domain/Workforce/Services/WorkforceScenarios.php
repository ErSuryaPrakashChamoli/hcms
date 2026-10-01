<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\WorkforceScenario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 10 planning scenarios: tenant-named (no hard-coded Baseline / Growth …), with explicit
 * planning assumptions such as an attrition percentage — labelled as assumptions, never computed
 * per employee. Approval is a second person's act and locks the scenario. Scenarios never change
 * live positions or employees.
 */
final class WorkforceScenarios
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $assumptions */
    public function create(string $code, string $name, ?string $description, array $assumptions, User $actor): WorkforceScenario
    {
        $this->authorise($actor, 'workforce.plan');

        try {
            $scenario = WorkforceScenario::query()->create(['code' => $code, 'name' => $name, 'description' => $description, 'assumptions' => array_filter($assumptions, fn ($v) => $v !== null && $v !== ''), 'created_by' => $actor->id]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException("Scenario code {$code} is already used.");
        }

        return $scenario;
    }

    /** @param  array<string, mixed>  $data */
    public function update(WorkforceScenario $scenario, array $data, User $actor): WorkforceScenario
    {
        $this->authorise($actor, 'workforce.plan');
        if (array_key_exists('assumptions', $data)) {
            $data['assumptions'] = array_filter((array) $data['assumptions'], fn ($v) => $v !== null && $v !== '');
        }
        $scenario->update(array_intersect_key($data, array_flip(['name', 'description', 'assumptions'])));

        return $scenario;
    }

    public function approve(WorkforceScenario $scenario, User $actor): WorkforceScenario
    {
        $this->authorise($actor, 'workforce.approve');
        if ((int) $scenario->created_by === (int) $actor->id) {
            throw new RuntimeException('The person who prepared a scenario cannot approve it.');
        }

        return DB::transaction(function () use ($scenario, $actor) {
            $current = WorkforceScenario::query()->whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw new RuntimeException('Only a draft scenario is approved.');
            }
            $scenario->setRawAttributes($current->getAttributes(), true);
            $scenario->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record(AuditAction::Approved, 'workforce', $scenario, [['field' => 'status', 'before' => 'draft', 'after' => 'approved']], null, actor: $actor, metadata: ['event' => 'scenario_approved']);
            WorkforceEvent::dispatch('workforce.scenario.approved', null, $scenario, ['code' => $scenario->code, 'name' => $scenario->name], array_filter([(int) $scenario->created_by]));

            return $scenario;
        });
    }

    public function archive(WorkforceScenario $scenario, string $reason, User $actor): WorkforceScenario
    {
        $this->authorise($actor, 'workforce.plan');
        if (trim($reason) === '') {
            throw new RuntimeException('Archiving a scenario needs a reason.');
        }
        $scenario->withAuditReason($reason)->update(['status' => 'archived']);

        return $scenario;
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException("This needs {$permission}.");
        }
    }
}
