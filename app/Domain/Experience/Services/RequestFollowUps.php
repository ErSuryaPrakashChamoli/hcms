<?php

namespace App\Domain\Experience\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Experience\Events\RequestFollowUpEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * HROne parity RQ-2 (matrix 20 §20.1.1 #6, #9; §20.2.1 #40): the requester following up their own request. Neither action
 * changes the request, its approvers or its route.
 *
 * - remind(): "Send reminder" — only the requester, only on their own pending request, at most once every
 *   `requests.reminder.min_hours` hours per request (default 24; read from the request's audit trail, serialised by a cache
 *   lock so two clicks send one). It goes to the people the request waits with (RequestApprovers) as `request.reminder`
 *   through the notification rules / delivery log, and is audited on the request (REQUEST_REMINDER_SENT, recipient ids).
 * - notifyOthers(): "Notify others" — for the request types listed in `requests.notify_others.types` (empty = off), the
 *   requester informs up to MAX_RECIPIENTS colleagues they may find in search (PeopleVisibility) with a message of at most
 *   MAX_MESSAGE characters, as `request.intimated`; audited on the request (REQUEST_INTIMATED, recipient ids, never the
 *   text); at most INTIMATIONS_PER_HOUR per person.
 */
final class RequestFollowUps
{
    public const MAX_MESSAGE = 500;

    public const MAX_RECIPIENTS = 20;

    public const INTIMATIONS_PER_HOUR = 10;

    public function __construct(
        private readonly RequestTimeline $timeline,
        private readonly RequestApprovers $approvers,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenants,
    ) {}

    public function minHours(): int
    {
        return max(1, (int) $this->settings->get('requests.reminder.min_hours', 24));
    }

    /** When the next reminder may be sent (null: now), or null when none was sent yet. */
    public function nextReminderAt(Model $record): ?CarbonInterface
    {
        $last = AuditEvent::query()->where('entity_type', $record::class)->where('entity_id', (string) $record->getKey())
            ->where('action', AuditAction::RequestReminderSent->value)->orderByDesc('occurred_at')->orderByDesc('id')->first(['id', 'occurred_at'])?->occurred_at;
        if ($last === null) {
            return null;
        }
        $next = $last->copy()->addHours($this->minHours());

        return $next->gt(now()) ? $next : null;
    }

    /** Why the person may not send a reminder now (null: they may). */
    public function reminderRefusal(User $viewer, RequestType $type, Model $record): ?string
    {
        if (! $this->timeline->isOwn($viewer, $type, $record)) {
            return 'You can only send reminders about your own requests.';
        }
        if ($type->status($record) !== RequestStatus::Pending) {
            return 'Reminders are for requests still waiting for a decision.';
        }
        $next = $this->nextReminderAt($record);

        return $next !== null ? 'You reminded the approvers recently. You can send another reminder after '.$next->format('d M Y, H:i').'.' : null;
    }

    /** Send the reminder; returns how many people were reminded. */
    public function remind(User $viewer, RequestType $type, Model $record): int
    {
        $lock = Cache::lock('request-reminder:'.$this->tenants->id().':'.$record::class.':'.$record->getKey(), 10);
        if (! $lock->get()) {
            throw new RuntimeException('A reminder about this request is being sent. Try again in a moment.');
        }
        try {
            if (($refusal = $this->reminderRefusal($viewer, $type, $record)) !== null) {
                throw new RuntimeException($refusal);
            }
            $now = $this->approvers->current($type, $record);
            $recipients = $now['users']->reject(fn (User $u) => (int) $u->getKey() === (int) $viewer->getKey())->values();
            if ($recipients->isEmpty()) {
                throw new RuntimeException('Nobody can be reminded about this request right now.');
            }
            $ids = $recipients->map(fn (User $u) => (int) $u->getKey())->all();
            $this->audit->record(AuditAction::RequestReminderSent, $this->module($record), $record,
                metadata: ['request_type' => $type->key(), 'recipients' => $ids, 'via' => $now['via']], actor: $viewer);
            RequestFollowUpEvent::dispatch('request.reminder', $record, $this->context($viewer, $type, $record), $ids);

            return count($ids);
        } finally {
            $lock->release();
        }
    }

    /** Whether "Notify others" is switched on for this request type (Setup → Automation → Requests). */
    public function notifyOthersOffered(RequestType $type): bool
    {
        return in_array($type->key(), array_map('strval', (array) $this->settings->get('requests.notify_others.types', [])), true);
    }

    /** Why the person may not inform colleagues about this request (null: they may). */
    public function notifyRefusal(User $viewer, RequestType $type, Model $record): ?string
    {
        if (! $this->notifyOthersOffered($type)) {
            return 'Informing colleagues is not switched on for this kind of request.';
        }
        if (! $this->timeline->isOwn($viewer, $type, $record)) {
            return 'You can only inform colleagues about your own requests.';
        }

        return in_array($type->status($record), [RequestStatus::Pending, RequestStatus::Approved], true) ? null : 'Only requests waiting for a decision or approved can be shared.';
    }

    /**
     * The colleagues the person may inform: people they may find in search (PeopleVisibility) with an active account, never
     * themselves, by name, code or e-mail.
     *
     * @return array<int, string> employee id => "Name (code)"
     */
    public function recipientOptions(User $viewer, ?string $search = null, array $only = []): array
    {
        $query = app(PeopleVisibility::class)->query($viewer)->with('person')->whereNotNull('user_id')->where('user_id', '!=', $viewer->getKey())
            ->whereHas('user', fn ($q) => $q->where('status', UserStatus::Active->value));
        if ($only !== []) {
            $query->whereKey(array_map('intval', $only));
        } elseif (trim((string) $search) !== '') {
            PeopleVisibility::matchName($query, (string) $search);
        }

        return $query->orderBy('employee_code')->limit(50)->get()
            ->mapWithKeys(fn (Employee $e) => [(int) $e->id => trim(($e->person?->display_name ?? $e->employee_code).' ('.$e->employee_code.')')])->all();
    }

    /**
     * Inform the chosen colleagues; returns how many were informed.
     *
     * @param  list<int|string>  $employeeIds
     */
    public function notifyOthers(User $viewer, RequestType $type, Model $record, array $employeeIds, ?string $message = null): int
    {
        if (($refusal = $this->notifyRefusal($viewer, $type, $record)) !== null) {
            throw new RuntimeException($refusal);
        }
        $message = trim(strip_tags((string) $message));
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            throw new RuntimeException('The message can be at most '.self::MAX_MESSAGE.' characters.');
        }
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if ($employeeIds === []) {
            throw new RuntimeException('Choose at least one colleague.');
        }
        if (count($employeeIds) > self::MAX_RECIPIENTS) {
            throw new RuntimeException('Choose at most '.self::MAX_RECIPIENTS.' colleagues.');
        }
        $allowed = $this->recipientOptions($viewer, null, $employeeIds);
        if (count($allowed) !== count($employeeIds)) {
            throw new RuntimeException('You can only inform colleagues you can find in search.');
        }
        $userIds = Employee::query()->whereKey(array_keys($allowed))->pluck('user_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $limiter = 'request-intimated:'.$this->tenants->id().':'.$viewer->getKey();
        if (RateLimiter::tooManyAttempts($limiter, self::INTIMATIONS_PER_HOUR)) {
            throw new RuntimeException('You have informed colleagues about requests many times in the last hour. Try again later.');
        }
        RateLimiter::hit($limiter, 3600);
        $this->audit->record(AuditAction::RequestIntimated, $this->module($record), $record,
            metadata: ['request_type' => $type->key(), 'recipients' => $userIds, 'with_message' => $message !== ''], actor: $viewer);
        RequestFollowUpEvent::dispatch('request.intimated', $record, $this->context($viewer, $type, $record) + ['message' => $message], $userIds);

        return count($userIds);
    }

    /** @return array<string, mixed> labels only: the request's type, title and dates, who follows it up; never its reason */
    private function context(User $viewer, RequestType $type, Model $record): array
    {
        $summary = rescue(fn () => $type->summary($record), ['title' => $type->label()], false);
        $dates = rescue(fn () => $type->dates($record), [], false);

        return [
            'type' => $type->label(),
            'type_key' => $type->key(),
            'title' => (string) ($summary['title'] ?? $type->label()),
            'status' => $type->statusLabel($record),
            'requested_on' => ($dates['requested'] ?? null)?->format('d M Y'),
            'by' => $viewer->name,
        ];
    }

    private function module(Model $record): string
    {
        return method_exists($record, 'auditModule') ? (string) $record->auditModule() : 'experience';
    }
}
