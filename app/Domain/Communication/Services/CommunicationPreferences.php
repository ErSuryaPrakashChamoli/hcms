<?php

namespace App\Domain\Communication\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Communication\Models\CommunicationPreference;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: employee preferences for OPTIONAL communication only:
 * - the optional announcement types;
 * - survey invitations and reminders.
 *
 * Mandatory types (policy publications, instructions) always go out on every real channel, and
 * transactional notifications (leave, cases, tasks…) are outside preferences entirely.
 *
 * Updates lock the preference row. Delivery reads it with a shared lock (channelsFor(lock: true)),
 * so a change committed before a delivery claims a recipient is always honoured.
 */
final class CommunicationPreferences
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<string, string> optional category => label */
    public function categories(): array
    {
        $types = array_diff_key(config('peopleos.communication.types'), array_flip(config('peopleos.communication.mandatory_types')));

        return $types + ['survey' => 'Survey invitations and reminders'];
    }

    public function isMandatory(string $category): bool
    {
        return in_array($category, config('peopleos.communication.mandatory_types'), true);
    }

    /** @return array<string, array{in_app: bool, email: bool}> */
    public function for(Employee $employee): array
    {
        $stored = CommunicationPreference::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->get()->keyBy('category');

        return collect($this->categories())->mapWithKeys(fn ($label, $category) => [$category => [
            'in_app' => (bool) ($stored[$category]->in_app ?? true), 'email' => (bool) ($stored[$category]->email ?? true),
        ]])->all();
    }

    /**
     * Real channels to use for this employee and category. Mandatory categories ignore preferences.
     *
     * @return list<string>
     */
    public function channelsFor(Employee $employee, string $category, bool $lock = false): array
    {
        $channels = array_keys(array_filter(config('peopleos.communication.channels'), fn ($label, $channel) => (bool) config("peopleos.notifications.channels.{$channel}"), ARRAY_FILTER_USE_BOTH));
        if ($this->isMandatory($category) || ! array_key_exists($category, $this->categories())) {
            return $channels;
        }
        $query = CommunicationPreference::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('category', $category);
        $preference = ($lock ? $query->sharedLock() : $query)->first();
        if ($preference === null) {
            return $channels;
        }

        return array_values(array_filter($channels, fn (string $c) => (bool) $preference->getAttribute($c)));
    }

    /** The employee changes their own preference (or HR with communication.manage, in scope). */
    public function set(Employee $employee, string $category, bool $inApp, bool $email, User $actor): CommunicationPreference
    {
        if ((int) $employee->user_id !== (int) $actor->id) {
            throw new EngagementRuleViolation('People change their own communication preferences.');
        }
        if ($this->isMandatory($category) || ! array_key_exists($category, $this->categories())) {
            throw new EngagementRuleViolation('That communication is mandatory or unknown; it cannot be switched off.');
        }

        try {
            return DB::transaction(function () use ($employee, $category, $inApp, $email, $actor) {
                $preference = CommunicationPreference::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('category', $category)->lockForUpdate()->first();
                $before = $preference ? ['in_app' => $preference->in_app, 'email' => $preference->email] : ['in_app' => true, 'email' => true];
                $preference ??= new CommunicationPreference(['employee_id' => $employee->id, 'category' => $category]);
                $preference->fill(['in_app' => $inApp, 'email' => $email])->save();
                $changes = collect(['in_app' => $inApp, 'email' => $email])->filter(fn ($v, $k) => $before[$k] !== $v)
                    ->map(fn ($v, $k) => ['field' => "{$category}.{$k}", 'before' => $before[$k] ? 'on' : 'off', 'after' => $v ? 'on' : 'off'])->values()->all();
                if ($changes !== []) {
                    $this->audit->record(AuditAction::CommunicationPreferenceChanged, 'communication', $preference, $changes, null, actor: $actor, metadata: ['category' => $category]);
                }

                return $preference;
            }, 3); // a first-time save racing another first-time save can deadlock on the gap lock: retried
        } catch (UniqueConstraintViolationException) {
            // Two first-time saves raced on the unique key: the other one won; apply ours on top of it.
            return $this->set($employee, $category, $inApp, $email, $actor);
        }
    }
}
