<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: reusable audience definitions. Criteria may only name organisation units and people
 * inside the preparer's scope; resolution is always constrained to the scope of whoever uses the
 * audience (AudienceQuery).
 */
final class Audiences
{
    public function __construct(private readonly AudienceQuery $query, private readonly AuditRecorder $audit) {}

    /** @param  array{code: string, name: string, description?: ?string, criteria?: array}  $data */
    public function create(array $data, User $actor): Audience
    {
        Guard::authorise($actor, 'engagement.manage', 'communication.manage');
        $criteria = $this->checked($data['criteria'] ?? [], $actor);
        if (blank($data['code'] ?? null) || blank($data['name'] ?? null)) {
            throw new EngagementRuleViolation('An audience needs a code and a name.');
        }

        return DB::transaction(function () use ($data, $criteria, $actor) {
            $audience = Audience::query()->create(['code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'criteria' => $criteria, 'owner_id' => $actor->id]);
            $this->audit->record(AuditAction::AudienceCreated, 'engagement', $audience, [], null, actor: $actor, metadata: ['criteria' => array_keys($criteria)]);

            return $audience;
        });
    }

    /** Changing a definition never changes anything already submitted: surveys and announcements pin a copy. */
    public function update(Audience $audience, array $data, User $actor): Audience
    {
        Guard::authorise($actor, 'engagement.manage', 'communication.manage');
        $criteria = array_key_exists('criteria', $data) ? $this->checked($data['criteria'] ?? [], $actor) : $audience->criteria;
        $audience->update(['name' => $data['name'] ?? $audience->name, 'description' => $data['description'] ?? $audience->description, 'criteria' => $criteria]);

        return $audience;
    }

    public function deactivate(Audience $audience, User $actor): Audience
    {
        Guard::authorise($actor, 'engagement.manage', 'communication.manage');
        $audience->update(['status' => 'inactive']);

        return $audience;
    }

    /** How many employees the criteria reach today inside the user's scope (a count, never a list). */
    public function preview(array $criteria, User $actor): int
    {
        Guard::authorise($actor, 'engagement.manage', 'communication.manage');

        return $this->query->count($this->checked($criteria, $actor), $actor);
    }

    private function checked(array $criteria, User $actor): array
    {
        $criteria = $this->query->normalise($criteria);
        if (! $this->query->withinScope($criteria, $actor)) {
            throw new EngagementRuleViolation('The audience names people or organisation units outside your scope.');
        }

        return $criteria;
    }
}
