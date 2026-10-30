<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A role-specific dashboard (§85): ordered widgets [{type, title, metric|report_id, size, options}].
 *
 * HROne parity CA-10 (CXO access): a dashboard can also be a shared view — made from a preset, limited to an organisation
 * slice (`applicability`, which only narrows each viewer's own access), listed for named people besides its roles,
 * e-mailed as a link on a cadence, and with its payroll figures moving on a set day of the month. All of it is null on a
 * dashboard built before, which keeps it exactly as it was.
 */
#[Fillable(['tenant_id', 'name', 'slug', 'preset', 'description', 'role_ids', 'applicability', 'viewer_user_ids', 'email_user_ids', 'email_frequency', 'email_day_of_week', 'email_day_of_month', 'email_time', 'email_next_run_at', 'email_last_run_at', 'refresh_day', 'widgets', 'is_default', 'sort_order', 'status', 'owner_id'])]
class Dashboard extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'draft' => 'Draft', 'inactive' => 'Inactive'];

    public const EMAIL_FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $d): void {
            $d->slug = Str::slug($d->slug ?: $d->name);
            // CA-10: the next e-mail notice follows the cadence (the report-schedule rule); no cadence or nobody to tell, none.
            $raw = $d->getAttributes();
            if ($d->isDirty(['email_frequency', 'email_day_of_week', 'email_day_of_month', 'email_time', 'email_user_ids', 'status'])
                || (($raw['email_frequency'] ?? null) !== null && ($raw['email_next_run_at'] ?? null) === null)) {
                $d->email_next_run_at = $d->emailsOnCadence() ? $d->nextEmailAfter(now()) : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role_ids' => 'array', 'widgets' => 'array', 'is_default' => 'boolean', 'sort_order' => 'integer',
            'applicability' => 'array', 'viewer_user_ids' => 'array', 'email_user_ids' => 'array',
            'email_day_of_week' => 'integer', 'email_day_of_month' => 'integer', 'refresh_day' => 'integer',
            'email_next_run_at' => 'datetime', 'email_last_run_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'analytics';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Who the dashboard is listed for: analytics managers, its owner, its roles and (CA-10) its named viewers. No roles and
     * no named viewers = everyone with analytics.view, as before; named viewers alone = only them. Being listed grants no
     * figure: every widget still follows the viewer's own rights and access scope.
     */
    public function isForUser(User $user): bool
    {
        if ($user->hasPermission('analytics.manage') || $this->owner_id === $user->id) {
            return true;
        }
        $named = $this->namedViewers();
        if (empty($this->role_ids) && $named === null) {
            return true;
        }
        if ($named !== null && in_array((int) $user->getKey(), $named, true)) {
            return true;
        }

        return ! empty($this->role_ids) && $user->roles()->whereIn('roles.id', $this->role_ids)->exists();
    }

    /**
     * CA-10: the named viewers as user ids; null when none are named. A stored value that is not a list of ids names
     * nobody but still restricts the view (fail closed).
     *
     * @return list<int>|null
     */
    public function namedViewers(): ?array
    {
        return self::idList($this->getAttributes()['viewer_user_ids'] ?? null);
    }

    /** @return list<int>|null the e-mail recipients as user ids (null = none; a malformed value = nobody) */
    public function emailRecipients(): ?array
    {
        return self::idList($this->getAttributes()['email_user_ids'] ?? null);
    }

    /** CA-10: whether this dashboard is a shared view (made from a preset, sliced or with a payroll refresh day). */
    public function isView(): bool
    {
        $raw = $this->getAttributes();

        return ($raw['preset'] ?? null) !== null || ! in_array($raw['applicability'] ?? null, [null, '', '[]', '{}'], true) || ($raw['refresh_day'] ?? null) !== null;
    }

    /**
     * CA-10: the organisation slice, {dimension: [ids]}; [] = the whole organisation; null = the stored value is malformed
     * (an unknown dimension, an empty or non-numeric list): the view then shows nothing.
     *
     * @return array<string, list<int>>|null
     */
    public function slice(): ?array
    {
        $raw = $this->getAttributes()['applicability'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($value)) {
            return null;
        }
        $slice = [];
        foreach ($value as $dimension => $ids) {
            if (! is_string($dimension) || ! in_array($dimension, UserAccessScope::DIMENSIONS, true) || ! is_array($ids) || $ids === [] || ! array_is_list($ids)) {
                return null;
            }
            foreach ($ids as $id) {
                if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
                    return null;
                }
            }
            $slice[$dimension] = array_values(array_unique(array_map('intval', $ids)));
        }

        return $slice;
    }

    /**
     * CA-10: the moment the view's payroll figures stand at — the start of the latest refresh day at or before $now (this
     * month's once it has come, else last month's). Null = live (no refresh day). See hasValidRefreshDay().
     */
    public function payrollAsOf(?CarbonInterface $now = null): ?Carbon
    {
        $day = $this->getAttributes()['refresh_day'] ?? null;
        if ($day === null || ! $this->hasValidRefreshDay()) {
            return null;
        }
        $now = Carbon::parse($now ?? now());
        $cutoff = $now->copy()->startOfMonth()->day((int) $day)->startOfDay();

        return $cutoff->gt($now) ? $now->copy()->startOfMonth()->subMonthNoOverflow()->day((int) $day)->startOfDay() : $cutoff;
    }

    /** A refresh day is blank or 1–28; anything else is malformed and the view's payroll figures are not shown. */
    public function hasValidRefreshDay(): bool
    {
        $day = $this->getAttributes()['refresh_day'] ?? null;

        return $day === null || (is_numeric($day) && (int) $day == $day && (int) $day >= 1 && (int) $day <= 28);
    }

    /** CA-10: whether the view is e-mailed on a cadence (active, a known frequency and someone to tell). */
    public function emailsOnCadence(): bool
    {
        $raw = $this->getAttributes();

        return ($raw['status'] ?? 'active') === 'active'
            && array_key_exists((string) ($raw['email_frequency'] ?? ''), self::EMAIL_FREQUENCIES)
            && ($this->emailRecipients() ?? []) !== [];
    }

    /** The next notice strictly after $from, by the report-schedule cadence rule. */
    public function nextEmailAfter(CarbonInterface $from): Carbon
    {
        $raw = $this->getAttributes();

        return ReportSchedule::nextOccurrence((string) ($raw['email_frequency'] ?? 'weekly'), isset($raw['email_day_of_week']) ? (int) $raw['email_day_of_week'] : null,
            isset($raw['email_day_of_month']) ? (int) $raw['email_day_of_month'] : null, $raw['email_time'] ?? null, Carbon::parse($from));
    }

    /** @return list<int>|null */
    private static function idList(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                return [];
            }
            $ids[] = (int) $id;
        }

        return $ids === [] ? null : array_values(array_unique($ids));
    }
}
