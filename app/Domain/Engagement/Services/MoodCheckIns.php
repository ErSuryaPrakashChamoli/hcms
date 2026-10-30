<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\MoodCheckIn;
use App\Domain\Engagement\Models\MoodParticipation;
use App\Domain\Engagement\Models\MoodReason;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HROne setup parity HP-9f (Engage → Happiness index): the mood bot, off until engage.mood.enabled. It follows the survey
 * anonymity boundary (docs/architecture/engagement-communication.md §3):
 *
 * - One check-in per employee and period (daily, weekly, fortnightly or monthly on the tenant's clock; weekly and
 *   fortnightly from engage.mood.weekday), enforced by the unique participation row (identity side: a date only).
 * - The check-in itself (content side) has a random key, no timestamps and no link to the person unless the organisation
 *   chose identified check-ins; confidential authorship goes only to engagement_identities (ConfidentialIdentities).
 * - Its group key is the employee's department, merged so that every group has at least the privacy threshold of
 *   eligible employees (GroupKeys), fixed per period; results are read only through MoodAnalytics.
 * - The audit records that a check-in happened (anonymous unless identified), never the mood, reasons or comment.
 */
final class MoodCheckIns
{
    public const COMMENT_MAX = 1000;

    public const DIMENSION = 'department';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TenantContext $tenants,
        private readonly GroupKeys $groups,
        private readonly AuditRecorder $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('engage.mood.enabled', false);
    }

    public function frequency(): string
    {
        $frequency = (string) $this->settings->get('engage.mood.frequency', 'weekly');

        return in_array($frequency, ['daily', 'weekly', 'fortnightly', 'monthly'], true) ? $frequency : 'weekly';
    }

    public function privacy(): string
    {
        $privacy = (string) $this->settings->get('engage.mood.privacy', 'anonymous');

        return array_key_exists($privacy, MoodCheckIn::PRIVACY) ? $privacy : 'anonymous';
    }

    public function weekday(): int
    {
        return min(7, max(1, (int) $this->settings->get('engage.mood.weekday', 5)));
    }

    /** Today on the tenant's own clock. */
    public function today(): CarbonInterface
    {
        return Carbon::now($this->tenants->current()?->timezone ?: config('app.timezone'))->startOfDay();
    }

    /** The period $day falls in: "2026-10-29" (daily), "2026-W44", "2026-F22" (ISO weeks 43–44), "2026-10". */
    public static function periodKey(string $frequency, CarbonInterface $day): string
    {
        return match ($frequency) {
            'daily' => $day->format('Y-m-d'),
            'fortnightly' => $day->format('o').'-F'.sprintf('%02d', (int) ceil((int) $day->format('W') / 2)),
            'monthly' => $day->format('Y-m'),
            default => $day->format('o').'-W'.sprintf('%02d', (int) $day->format('W')),
        };
    }

    /** The last day of a period, read from its key (null = not a period key). */
    public static function periodEnd(string $key): ?CarbonInterface
    {
        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) => Carbon::parse($key)->startOfDay(),
            (bool) preg_match('/^(\d{4})-W(\d{2})$/', $key, $m) => Carbon::now()->setISODate((int) $m[1], (int) $m[2], 7)->startOfDay(),
            (bool) preg_match('/^(\d{4})-F(\d{2})$/', $key, $m) => Carbon::now()->setISODate((int) $m[1], min(53, 2 * (int) $m[2]), 7)->startOfDay(),
            (bool) preg_match('/^\d{4}-\d{2}$/', $key) => Carbon::parse($key.'-01')->endOfMonth()->startOfDay(),
            default => null,
        };
    }

    /** "Week 44, 2026", "29 Oct 2026", "October 2026", "Weeks 43–44, 2026". */
    public static function periodLabel(string $key): string
    {
        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) => Carbon::parse($key)->format('j M Y'),
            (bool) preg_match('/^(\d{4})-W(\d{2})$/', $key, $m) => 'Week '.(int) $m[2].', '.$m[1],
            (bool) preg_match('/^(\d{4})-F(\d{2})$/', $key, $m) => 'Weeks '.(2 * (int) $m[2] - 1).'–'.(2 * (int) $m[2]).', '.$m[1],
            (bool) preg_match('/^\d{4}-\d{2}$/', $key) => Carbon::parse($key.'-01')->format('F Y'),
            default => $key,
        };
    }

    public function currentPeriod(): string
    {
        return self::periodKey($this->frequency(), $this->today());
    }

    /** Whether the question is asked today: every day (daily, monthly), from the weekday on (weekly, fortnightly). */
    public function windowOpen(?CarbonInterface $day = null): bool
    {
        $day ??= $this->today();

        return match ($this->frequency()) {
            'weekly' => $day->dayOfWeekIso >= $this->weekday(),
            'fortnightly' => (int) $day->format('W') % 2 === 0 || $day->dayOfWeekIso >= $this->weekday(),
            default => true,
        };
    }

    /** @return array<int, string> the active reasons, in order */
    public function reasons(): array
    {
        return MoodReason::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * What Home shows $user: the question, while the mood bot is on, the window open and they have not checked in for
     * the period (null = nothing to ask).
     *
     * @return array{period: string, label: string, privacy: string, reasons: array<int, string>}|null
     */
    public function prompt(User $user): ?array
    {
        if (! $this->enabled() || ! $user->hasPermission('engagement.mood') || ! $this->windowOpen()) {
            return null;
        }
        $employee = $this->employeeOf($user);
        $period = $this->currentPeriod();
        if ($employee === null || MoodParticipation::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('period_key', $period)->exists()) {
            return null;
        }

        return ['period' => $period, 'label' => self::periodLabel($period), 'privacy' => $this->privacy(), 'reasons' => $this->reasons()];
    }

    /**
     * $user checks in their mood (1–5) with reasons (active reason ids) and a comment.
     *
     * @param  list<int|string>  $reasonIds
     * @return array{status: 'submitted'|'already_submitted', period: string}
     */
    public function checkIn(User $user, int $mood, array $reasonIds = [], ?string $comment = null): array
    {
        Guard::authorise($user, 'engagement.mood');
        if (! $this->enabled()) {
            throw new EngagementRuleViolation('The mood bot is switched off.');
        }
        $employee = $this->employeeOf($user) ?? throw new EngagementRuleViolation('Moods are shared by employees.');
        if (! $this->windowOpen()) {
            throw new EngagementRuleViolation('The mood question is not open today.');
        }
        if (! array_key_exists($mood, MoodCheckIn::MOODS)) {
            throw new EngagementRuleViolation('Choose how you feel (1 to 5).');
        }
        $active = $this->reasons();
        $reasons = array_values(array_unique(array_map('intval', $reasonIds)));
        if (array_diff($reasons, array_keys($active)) !== []) {
            throw new EngagementRuleViolation('That is not one of the reasons.');
        }
        sort($reasons);
        $comment = trim(strip_tags((string) $comment));
        if (mb_strlen($comment) > self::COMMENT_MAX) {
            throw new EngagementRuleViolation('Comments are limited to '.number_format(self::COMMENT_MAX).' characters.');
        }
        $period = $this->currentPeriod();
        $privacy = $this->privacy();
        $groupKey = $this->groupKey($employee, $period);

        try {
            DB::transaction(function () use ($employee, $period, $mood, $reasons, $comment, $privacy, $groupKey, $user) {
                MoodParticipation::query()->create(['employee_id' => $employee->id, 'period_key' => $period, 'checked_in_on' => $this->today()->toDateString()]);
                $identified = $privacy === 'identified';
                $checkIn = MoodCheckIn::query()->create([
                    'period_key' => $period, 'group_key' => $groupKey, 'mood' => $mood, 'reason_ids' => $reasons === [] ? null : $reasons,
                    'comment' => $comment === '' ? null : $comment, 'privacy' => $privacy,
                    'employee_id' => $identified ? $employee->id : null, 'checked_in_on' => $identified ? $this->today()->toDateString() : null,
                ]);
                if ($privacy === 'confidential') {
                    EngagementIdentity::query()->create(['subject_type' => 'mood_checkin', 'subject_id' => $checkIn->id, 'employee_id' => $employee->id]);
                }
                $this->audit->record(AuditAction::MoodCheckInSubmitted, 'engagement', null, [], null, tenantId: (int) $this->tenants->id(),
                    actor: $identified ? $user : null, metadata: ['mode' => $privacy, 'period' => $period], entityLabel: 'Mood check-in', anonymous: ! $identified);
            });
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'already_submitted', 'period' => $period];
        }

        return ['status' => 'submitted', 'period' => $period];
    }

    /**
     * The employee's group for the period: their department, merged by GroupKeys so that every group has at least the
     * privacy threshold of eligible employees (people working here now), fixed for the period.
     */
    private function groupKey(Employee $employee, string $period): ?string
    {
        $keys = Cache::remember('mood-groups:'.$this->tenants->id().':'.$period, now()->addDays(40), function () {
            $eligible = AccessScope::withoutScoping(fn () => Employee::query()->whereIn('lifecycle_state', Birthdays::STATES)->pluck('id')->map(fn ($id) => (int) $id)->all());

            return $this->groups->assign(self::DIMENSION, $eligible, $this->today()->toDateString());
        });
        if (array_key_exists($employee->id, $keys)) {
            return $keys[$employee->id];
        }

        // Someone who joined during the period joins the merged remainder (it only grows), unless results are overall only.
        return array_filter($keys) === [] ? null : 'other';
    }

    private function employeeOf(User $user): ?Employee
    {
        return AccessScope::withoutScoping(fn () => Employee::query()->where('user_id', $user->id)->whereIn('lifecycle_state', Birthdays::STATES)->first());
    }
}
