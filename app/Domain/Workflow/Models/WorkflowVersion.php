<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** definition = ['nodes' => [{id,type,name,config}], 'edges' => [{from,to,label}]]; immutable once published. */
#[Fillable(['tenant_id', 'workflow_id', 'version', 'definition', 'status', 'published_by', 'published_at'])]
class WorkflowVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') !== VersionStatus::Draft->value && $version->isDirty('definition')) {
                throw new WorkflowException('Published workflow versions are immutable; create a new draft.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'version' => 'integer',
            'status' => VersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'workflow';
    }

    public function auditLabel(): string
    {
        $workflow = $this->relationLoaded('workflow') ? $this->workflow : $this->workflow()->first();

        return ($workflow?->name ?? 'Workflow')." v{$this->version}";
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return array<int, array<string, mixed>> */
    public function nodes(): array
    {
        return array_values($this->definition['nodes'] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function node(string $id): ?array
    {
        foreach ($this->nodes() as $node) {
            if (($node['id'] ?? null) === $id) {
                return $node;
            }
        }

        return null;
    }

    /** @return array<int, array{from: string, to: string, label?: ?string}> */
    public function edgesFrom(string $id): array
    {
        return array_values(array_filter($this->definition['edges'] ?? [], fn (array $e) => ($e['from'] ?? null) === $id));
    }

    public function startNodeId(): ?string
    {
        foreach ($this->nodes() as $node) {
            if (($node['type'] ?? null) === 'start') {
                return $node['id'];
            }
        }

        return null;
    }
}
