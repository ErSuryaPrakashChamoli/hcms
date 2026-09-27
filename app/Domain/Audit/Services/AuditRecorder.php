<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The single write path into the audit log.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Guard $auth,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<int, array{field: string, before: mixed, after: mixed, sensitive?: bool}>  $changes
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AuditAction $action,
        string $module,
        ?Model $entity = null,
        array $changes = [],
        ?string $reason = null,
        ?string $approvalReference = null,
        CarbonInterface|string|null $effectiveDate = null,
        array $metadata = [],
        ?string $entityLabel = null,
        ?int $tenantId = null,
        ?User $actor = null,
    ): AuditEvent {
        $tenantId ??= $entity?->getAttribute('tenant_id') ?? $this->tenants->id();
        $actor ??= $this->resolveActor();
        $occurredAt = Carbon::now();

        $normalisedChanges = array_values(array_map(fn (array $change) => [
            'field' => $change['field'],
            'before' => $this->stringify($change['before'] ?? null, $change['sensitive'] ?? false),
            'after' => $this->stringify($change['after'] ?? null, $change['sensitive'] ?? false),
            'is_sensitive' => (bool) ($change['sensitive'] ?? false),
        ], $changes));

        $attributes = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'actor_roles' => $actor?->roleSlugs(),
            'action' => $action->value,
            'module' => $module,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity ? (string) $entity->getKey() : null,
            'entity_label' => $entityLabel ?? $this->labelFor($entity),
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'source' => $this->resolveSource(),
            'request_id' => Context::get('request_id'),
            'reason' => $reason,
            'approval_reference' => $approvalReference,
            'effective_date' => $effectiveDate ? Carbon::parse($effectiveDate)->toDateString() : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ];

        // Writes cross tenant boundaries by design (platform events have no tenant), so the
        // tenant scope is bypassed here and only here.
        return $this->tenants->bypass(fn () => DB::transaction(function () use ($attributes, $normalisedChanges, $occurredAt) {
            $previousHash = AuditEvent::query()
                ->when(
                    $attributes['tenant_id'] === null,
                    fn ($q) => $q->whereNull('tenant_id'),
                    fn ($q) => $q->where('tenant_id', $attributes['tenant_id']),
                )
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('hash');

            $attributes['previous_hash'] = $previousHash;
            $attributes['hash'] = AuditEvent::computeHash(
                $previousHash,
                AuditEvent::canonicalPayload($attributes, $normalisedChanges),
            );
            $attributes['created_at'] = $occurredAt;

            $event = new AuditEvent($attributes);
            $event->save();

            if ($normalisedChanges !== []) {
                $event->fieldChanges()->createMany($normalisedChanges);
            }

            return $event;
        }));
    }

    private function resolveActor(): ?User
    {
        $user = $this->auth->user();

        return $user instanceof User ? $user : null;
    }

    private function resolveSource(): string
    {
        if ($source = Context::get('audit.source')) {
            return $source;
        }

        if (app()->runningInConsole()) {
            return app()->runningUnitTests() ? 'test' : 'console';
        }

        return $this->request->is('api/*') ? 'api' : 'web';
    }

    private function labelFor(?Model $entity): ?string
    {
        if ($entity === null) {
            return null;
        }

        if (method_exists($entity, 'auditLabel')) {
            return $entity->auditLabel();
        }

        foreach (['name', 'title', 'code', 'key', 'email'] as $attribute) {
            if (! empty($entity->getAttribute($attribute))) {
                return (string) $entity->getAttribute($attribute);
            }
        }

        return null;
    }

    private function stringify(mixed $value, bool $sensitive): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($sensitive) {
            return config('peopleos.audit.mask');
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof UnitEnum => (string) ($value->value ?? $value->name),
            $value instanceof DateTimeInterface => Carbon::instance($value)->toIso8601String(),
            is_array($value) || is_object($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
