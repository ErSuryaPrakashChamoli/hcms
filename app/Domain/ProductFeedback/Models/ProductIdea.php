<?php

namespace App\Domain\ProductFeedback\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\ProductFeedback\Enums\ProductIdeaKind;
use App\Domain\ProductFeedback\Enums\ProductIdeaStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity AC-3: an organisation's suggestion to Markedge (HROne's "Product enhancement request"): the module and
 * process it is about, a new feature or an improvement, the challenge, how it is managed today, the ideal solution, how
 * many people it affects and how much it matters, supporting files. Markedge's product team answers with a status and a
 * response. Written only through ProductIdeas (the organisation submits; a Markedge operator changes the status).
 *
 * The reference (IDEA-000042) comes from the id, so it is the same for the organisation and Markedge and unique across
 * organisations; it is stored once the row exists, before the write is audited.
 */
#[Fillable([
    'tenant_id', 'module', 'process', 'kind', 'title', 'challenge', 'current_workaround', 'ideal_solution', 'impact_people', 'priority',
    'attachments', 'status', 'platform_response', 'status_changed_at', 'submitted_by', 'submitted_at',
])]
class ProductIdea extends Model
{
    use Auditable, BelongsToTenant;

    public const PRIORITIES = ['low' => 'Low: a nice to have', 'medium' => 'Medium: it slows us down', 'high' => 'High: it holds up our work'];

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return [
            'status' => ProductIdeaStatus::class, 'kind' => ProductIdeaKind::class, 'attachments' => 'array', 'impact_people' => 'integer',
            'status_changed_at' => 'datetime', 'submitted_at' => 'datetime',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function auditLabel(): string
    {
        return trim('Product idea '.($this->getAttributes()['reference'] ?? ''));
    }

    /**
     * The field changes between two raw attribute snapshots, in AuditRecorder's shape. ProductIdeas audits each write
     * itself with the actor it was given (an operator answering from the platform), not the signed-in user.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, before: mixed, after: mixed, sensitive: bool}>
     */
    public function auditDiff(array $before, array $after): array
    {
        $excluded = array_flip($this->auditExcludedAttributes());
        $changes = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            if (! isset($excluded[$field]) && $old !== $value && (string) json_encode($old) !== (string) json_encode($value)) {
                $changes[] = ['field' => (string) $field, 'before' => $old, 'after' => $value, 'sensitive' => false];
            }
        }

        return $changes;
    }

    public static function referenceFor(int $id): string
    {
        return 'IDEA-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public static function idFromReference(?string $reference): ?int
    {
        return $reference !== null && preg_match('/^\s*IDEA-?(\d{1,18})\s*$/i', $reference, $m) === 1 ? (int) $m[1] : null;
    }

    public function priorityLabel(): ?string
    {
        $priority = $this->getAttributes()['priority'] ?? null;

        return $priority === null ? null : (string) str(self::PRIORITIES[$priority] ?? $priority)->before(':');
    }

    /** @return list<string> the attached files' names (the last part of each stored path), in order */
    public function attachmentNames(): array
    {
        return array_values(array_map(fn ($path) => basename((string) $path), (array) ($this->attachments ?? [])));
    }
}
