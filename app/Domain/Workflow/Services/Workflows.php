<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/** Draft / publish lifecycle for workflow definitions. */
final class Workflows
{
    public function __construct(
        private readonly WorkflowDefinition $definitions,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param  array<string, mixed>|null  $definition */
    public function draft(Workflow $workflow, ?array $definition = null): WorkflowVersion
    {
        if ($existing = $workflow->draft()->first()) {
            if ($definition !== null) {
                $existing->update(['definition' => $definition]);
            }

            return $existing;
        }

        $latest = $workflow->versions()->first();

        return WorkflowVersion::create([
            'workflow_id' => $workflow->id,
            'version' => ($latest?->version ?? 0) + 1,
            'definition' => $definition ?? $latest?->definition ?? self::skeleton(),
            'status' => VersionStatus::Draft,
        ]);
    }

    public function publish(Workflow $workflow, ?string $reason = null): WorkflowVersion
    {
        $draft = $workflow->draft()->first() ?? throw new WorkflowException('Nothing to publish: create a draft first.');
        $errors = $this->definitions->validate($draft->definition ?? []);

        if ($errors !== []) {
            throw new WorkflowException('The workflow is not valid: '.implode(' ', $errors));
        }

        return DB::transaction(function () use ($workflow, $draft, $reason) {
            $workflow->published()->first()?->withAuditReason($reason)->update(['status' => VersionStatus::Retired]);

            $draft->withAuditReason($reason)->update([
                'status' => VersionStatus::Published,
                'published_by' => auth()->id(),
                'published_at' => now(),
            ]);

            $this->audit->record(AuditAction::WorkflowChanged, 'workflow', $draft, reason: $reason, metadata: ['workflow' => $workflow->key, 'version' => $draft->version, 'event' => 'published']);

            return $draft;
        });
    }

    /** @return array<string, mixed> */
    public static function skeleton(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => []],
                ['id' => 'end', 'type' => 'end', 'name' => 'End', 'config' => []],
            ],
            'edges' => [['from' => 'start', 'to' => 'end', 'label' => null]],
        ];
    }
}
