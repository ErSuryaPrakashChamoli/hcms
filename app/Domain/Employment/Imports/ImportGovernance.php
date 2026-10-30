<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Events\ImportEvent;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * HROne setup parity P2 (Data import → Import approval). Who approves an upload follows `imports.approval_mode` for the
 * upload types in `imports.approval_types` (empty = every type):
 *   - self (default, as before): whoever may work on the upload approves it (bank and salary uploads: their uploader);
 *   - other_person (maker-checker): someone other than the uploader who may work on that upload type (and holds
 *     `imports.approver_role` when one is set) approves; for bank and salary uploads that checker may open the upload
 *     once it is validated;
 *   - workflow: validating the upload raises `import.validated`, which starts the workflows listening for it (subject:
 *     the upload); ImportWorkflowBridge approves or discards it when the run ends. While a run is open nobody approves by
 *     hand; with no run, maker-checker applies.
 * The rule is enforced on the model (every upload service's approve() goes through it), so no screen or path skips it.
 */
final class ImportGovernance
{
    public const MODES = [
        'self' => 'The person working on the upload approves it (bank and salary uploads: their uploader)',
        'other_person' => 'Another person approves it (maker-checker)',
        'workflow' => 'A workflow approves it',
    ];

    /** Statuses in which the uploader is still preparing the file (a checker has nothing to look at yet). */
    private const MAKER_STATES = ['uploaded', 'inspected', 'mapped'];

    /** The approval mode that applies to an upload type. */
    public static function mode(string $type): string
    {
        $settings = app(SettingsRepository::class);
        $mode = (string) ($settings->get('imports.approval_mode', 'self') ?? 'self');
        $types = array_values(array_filter((array) $settings->get('imports.approval_types', [])));
        if (! array_key_exists($mode, self::MODES) || ($types !== [] && ! in_array($type, $types, true))) {
            return 'self';
        }

        return $mode;
    }

    /** Whether someone other than the uploader approves uploads of this type. */
    public static function checkedByAnother(string $type): bool
    {
        return self::mode($type) !== 'self';
    }

    /** The workflow run deciding the upload (running, waiting or sent back), if any. */
    public static function openWorkflow(EmployeeImport $import): ?WorkflowInstance
    {
        return WorkflowInstance::query()->where('subject_type', $import->getMorphClass())->where('subject_id', $import->getKey())
            ->whereIn('status', InstanceStatus::inFlight())->latest('id')->first();
    }

    public static function latestWorkflow(EmployeeImport $import): ?WorkflowInstance
    {
        return WorkflowInstance::query()->where('subject_type', $import->getMorphClass())->where('subject_id', $import->getKey())->latest('id')->first();
    }

    /** Why the user may not approve the upload now, or null when the approval rule allows it. */
    public static function refusal(EmployeeImport $import, ?User $user): ?string
    {
        $mode = self::mode((string) $import->type);
        if ($mode === 'self') {
            return null;
        }
        if ($mode === 'workflow' && ($run = self::openWorkflow($import)) !== null) {
            return "This upload is approved through its workflow (run #{$run->id}); it is waiting there.";
        }
        if ($user === null || (int) $user->getKey() === (int) $import->uploaded_by) {
            return 'Another person approves this upload (maker-checker): the uploader cannot approve it.';
        }
        $role = self::approverRole();
        if ($role !== null && ! in_array($role, $user->roleSlugs(), true)) {
            $name = Role::query()->where('slug', $role)->value('name') ?? $role;

            return "Only holders of the {$name} role approve uploads.";
        }

        return null;
    }

    public static function assertMayApprove(EmployeeImport $import, ?User $user): void
    {
        if (($why = self::refusal($import, $user)) !== null) {
            throw new RuntimeException($why);
        }
    }

    /**
     * Bank details and salary uploads are seen by their uploader only; under maker-checker or workflow approval the
     * checker (someone allowed to make such an upload) also sees one that left the uploader's preparation.
     */
    public static function checkerMaySee(User $user, EmployeeImport $import): bool
    {
        return self::checkedByAnother((string) $import->type) && ! in_array($import->status, self::MAKER_STATES, true)
            && ImportTypes::allowed($user, (string) $import->type);
    }

    /** @return list<string> statuses a checker may see uploads in (for list queries) */
    public static function checkerStates(): array
    {
        return array_values(array_diff(EmployeeImport::STATUSES, self::MAKER_STATES));
    }

    /** Narrow a list of one uploader-only type (bank details, salaries) to the user's own uploads and those they check. */
    public static function ownOrChecked(Builder $query, string $type, ?User $user): Builder
    {
        $checker = $user !== null && self::checkedByAnother($type) && ImportTypes::allowed($user, $type);

        return $query->where(fn (Builder $q) => $q->where('uploaded_by', $user?->getKey() ?? 0)
            ->when($checker, fn (Builder $q) => $q->orWhereIn('status', self::checkerStates())));
    }

    /** Whether this user may run the approval step now (button visibility; the services check again). */
    public static function mayApproveNow(EmployeeImport $import, ?User $user): bool
    {
        if ($import->status !== 'validated' || self::refusal($import, $user) !== null) {
            return false;
        }

        return match ($import->type) {
            BankAccountImports::TYPE, SalaryImports::TYPE => self::checkedByAnother((string) $import->type)
                ? ImportTypes::allowed($user, (string) $import->type) : (int) $import->uploaded_by === (int) $user?->getKey(),
            default => true,
        };
    }

    /** What the approval step waits for, for the upload screens; null when nothing is waiting. */
    public static function waitingFor(EmployeeImport $import, ?User $user): ?string
    {
        if ($import->status !== 'validated' || self::mode((string) $import->type) === 'self') {
            return null;
        }
        if (($run = self::openWorkflow($import)) !== null) {
            return "Waiting for its workflow (run #{$run->id}, {$run->status->getLabel()}).";
        }

        return (int) $import->uploaded_by === (int) $user?->getKey() ? 'Waiting for another person to approve it (maker-checker).' : null;
    }

    public static function approvalLabel(EmployeeImport $import): string
    {
        return match (self::mode((string) $import->type)) {
            'other_person' => 'Maker-checker',
            'workflow' => 'Workflow',
            default => 'Uploader',
        };
    }

    public static function workflowStatus(EmployeeImport $import): ?string
    {
        $run = self::latestWorkflow($import);
        if ($run === null) {
            return null;
        }

        return $run->status === InstanceStatus::Completed ? 'Completed: '.($run->outcome ?? 'done') : $run->status->getLabel();
    }

    /** `imports.approver_role`: the role slug a checker must hold (blank = anyone who may work on the upload type). */
    public static function approverRole(): ?string
    {
        $role = app(SettingsRepository::class)->get('imports.approver_role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    // ---- model hooks (EmployeeImport::booted) ----------------------------------------------------------------

    /** Before an upload is saved: the approval rule, and the time a run starts. */
    public static function updating(EmployeeImport $import): void
    {
        if (! $import->isDirty('status')) {
            return;
        }
        if ($import->status === 'approved') {
            self::assertMayApprove($import, $import->approved_by ? User::query()->find($import->approved_by) : null);
        }
        if ($import->status === 'importing' && (self::attribute($import, 'run_started_at') === null)) {
            $import->run_started_at = now();
        }
    }

    /** After an upload is saved: its events, and an open approval workflow cancelled when the upload changed under it. */
    public static function updated(EmployeeImport $import): void
    {
        if (! $import->wasChanged('status')) {
            return;
        }
        $from = (string) $import->getOriginal('status');
        if (! in_array($import->status, ['validated', 'approved', 'imported'], true) && $from !== 'validated') {
            return;
        }
        $context = ['type' => $import->type, 'type_label' => ImportTypes::label((string) $import->type), 'file' => $import->original_name, 'rows' => (int) self::attribute($import, 'row_count'),
            'create' => (int) self::attribute($import, 'create_count'), 'update' => (int) self::attribute($import, 'update_count'), 'errors' => (int) self::attribute($import, 'error_count')];

        if ($from === 'validated' && in_array($import->status, ['inspected', 'mapped', 'discarded'], true) && ($run = self::openWorkflow($import)) !== null) {
            app(WorkflowEngine::class)->cancel($run, $import->status === 'discarded' ? 'The upload was discarded.' : 'The upload changed after validation; it is approved again once validated.');
        }

        match ($import->status) {
            'validated' => ImportEvent::dispatch('import.validated', $import, $context, self::checkedByAnother((string) $import->type) ? self::checkerIds($import) : [], self::mode((string) $import->type) === 'workflow' && self::openWorkflow($import) === null),
            'approved' => ImportEvent::dispatch('import.approved', $import, $context + ['by' => User::query()->find($import->approved_by)?->name], (int) $import->approved_by !== (int) $import->uploaded_by ? [(int) $import->uploaded_by] : []),
            'imported' => ImportEvent::dispatch('import.completed', $import, $context + ['failed' => (int) self::attribute($import, 'failure_count')]),
            default => null,
        };
    }

    /** An attribute a freshly created upload may not carry yet (strict mode refuses reading a missing one). */
    private static function attribute(EmployeeImport $import, string $key): mixed
    {
        return $import->getAttributes()[$key] ?? null;
    }

    /** @return list<int> who hears that an upload waits for a checker: the approver role's holders, never the uploader */
    private static function checkerIds(EmployeeImport $import): array
    {
        $role = self::approverRole();
        if ($role === null) {
            return [];
        }

        return Role::query()->where('slug', $role)->first()?->users()->pluck('users.id')->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === (int) $import->uploaded_by)->values()->all() ?? [];
    }
}
