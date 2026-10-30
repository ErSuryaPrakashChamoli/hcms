<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Services\AccessScopeGroups;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * HROne parity CA-10 (Setup → Access rights → CXO access): the shared-view fields of a dashboard as the builder saves
 * them, and the view list's Clone and Inactivate. Everything here only decides where a view is listed and which slice it
 * shows; no figure is granted (each viewer's own rights and access scope still apply when it renders).
 */
final class DashboardViews
{
    public const DAYS_OF_WEEK = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * The form's shared-view fields, checked and normalised for the dashboard row: applicability (one organisation
     * dimension, as HROne's filterType, with values the author can see), named viewers and e-mail recipients (people of
     * this organisation), the e-mail cadence and the payroll refresh day. The form-only keys are removed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalise(array $data, ?string $preset, User $author): array
    {
        $errors = [];
        $dimension = (string) ($data['applicability_dimension'] ?? '');
        $ids = array_values(array_unique(array_map('intval', array_filter((array) ($data['applicability_ids'] ?? []), fn ($id) => filled($id)))));
        $applicability = null;
        if ($dimension !== '') {
            if (! in_array($dimension, UserAccessScope::DIMENSIONS, true)) {
                $errors['applicability_dimension'] = 'Choose an organisation dimension.';
            } elseif (in_array($preset, Dashboards::ENTERPRISE_ONLY, true)) {
                $errors['applicability_dimension'] = 'This view covers the whole organisation only.';
            } elseif ($ids === []) {
                $errors['applicability_ids'] = 'Choose at least one value, or apply the view to the whole organisation.';
            } elseif ((AccessScopeGroups::UNITS[$dimension])::query()->whereKey($ids)->count() !== count($ids)) {
                // Fail closed: only units the author (the signed-in user saving the form) can see — the access scope applies.
                $errors['applicability_ids'] = 'Choose values you can see.';
            } else {
                $applicability = [$dimension => $ids];
            }
        }

        $people = function (string $field) use ($data, &$errors): ?array {
            $ids = array_values(array_unique(array_map('intval', array_filter((array) ($data[$field] ?? []), fn ($id) => filled($id)))));
            if ($ids === []) {
                return null;
            }
            $found = User::forCurrentTenant()->where('is_platform_admin', false)->whereKey($ids)->count();
            if ($found !== count($ids)) {
                $errors[$field] = 'Choose people of this organisation.';
            }

            return $ids;
        };
        $viewers = $people('viewer_user_ids');
        $recipients = $people('email_user_ids');

        $frequency = filled($data['email_frequency'] ?? null) ? (string) $data['email_frequency'] : null;
        $dayOfWeek = filled($data['email_day_of_week'] ?? null) ? (int) $data['email_day_of_week'] : null;
        $dayOfMonth = filled($data['email_day_of_month'] ?? null) ? (int) $data['email_day_of_month'] : null;
        $time = filled($data['email_time'] ?? null) ? (string) $data['email_time'] : null;
        if ($frequency !== null) {
            if (! array_key_exists($frequency, Dashboard::EMAIL_FREQUENCIES)) {
                $errors['email_frequency'] = 'Choose how often it is sent.';
            }
            if ($recipients === null) {
                $errors['email_user_ids'] = 'Choose who receives it.';
            }
            if ($frequency === 'weekly' && ! array_key_exists((int) $dayOfWeek, self::DAYS_OF_WEEK)) {
                $errors['email_day_of_week'] = 'Choose the day of the week.';
            }
            if ($frequency === 'monthly' && ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 28)) {
                $errors['email_day_of_month'] = 'Choose a day from 1 to 28.';
            }
            if ($time !== null && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors['email_time'] = 'Use the 24-hour time, e.g. 07:00.';
            }
        }

        $refresh = filled($data['refresh_day'] ?? null) ? $data['refresh_day'] : null;
        if ($refresh !== null && (! is_numeric($refresh) || (int) $refresh != $refresh || (int) $refresh < 1 || (int) $refresh > 28)) {
            $errors['refresh_day'] = 'Choose a day from 1 to 28, or leave it blank for live figures.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($message, $field) => ["data.{$field}" => $message])->all());
        }

        unset($data['applicability_dimension'], $data['applicability_ids'], $data['preset']);

        return array_merge($data, [
            'applicability' => $applicability, 'viewer_user_ids' => $viewers, 'email_user_ids' => $recipients,
            'email_frequency' => $frequency, 'email_day_of_week' => $frequency === 'weekly' ? $dayOfWeek : null,
            'email_day_of_month' => $frequency === 'monthly' ? $dayOfMonth : null, 'email_time' => $frequency === null ? null : ($time ?? '07:00'),
            'refresh_day' => $refresh === null ? null : (int) $refresh,
        ]);
    }

    /**
     * The form state of a saved view (its one organisation dimension and values).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fill(array $data): array
    {
        $applicability = is_array($data['applicability'] ?? null) ? $data['applicability'] : [];
        $data['applicability_dimension'] = (string) (array_key_first($applicability) ?? '');
        $data['applicability_ids'] = array_values((array) (reset($applicability) ?: []));

        return $data;
    }

    /** "Departments: Engineering, Finance" / "Whole organisation" / "Not valid" — the view's slice for people to read. */
    public function describe(Dashboard $dashboard): string
    {
        $slice = $dashboard->slice();
        if ($slice === null) {
            return 'Not valid';
        }
        if ($slice === []) {
            return 'Whole organisation';
        }

        return collect($slice)->map(function (array $ids, string $dimension) {
            $names = (AccessScopeGroups::UNITS[$dimension])::query()->whereKey($ids)->orderBy('name')->pluck('name');
            $hidden = count($ids) - $names->count();

            return (AccessScopeGroups::LABELS[$dimension] ?? $dimension).': '.$names->implode(', ').($hidden > 0 ? " (+{$hidden} not visible to you)" : '');
        })->implode('; ');
    }

    /**
     * HROne "Clone": a copy of the view (its widgets, audience, slice, e-mail and refresh day), as a draft that nobody but
     * analytics managers sees until it is switched on; never the default dashboard.
     */
    public function clone(Dashboard $source, User $actor): Dashboard
    {
        $this->assertManager($actor);
        $name = Str::limit('Copy of '.$source->name, 255, '');
        $base = Str::limit(Str::slug($name), 56, '') ?: 'copy';
        $slug = $base;
        for ($n = 2; Dashboard::query()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }
        $copy = $source->replicate(['slug', 'is_default', 'status', 'email_next_run_at', 'email_last_run_at', 'owner_id', 'created_at', 'updated_at']);
        $copy->fill(['name' => $name, 'slug' => $slug, 'is_default' => false, 'status' => 'draft', 'owner_id' => $actor->getKey()]);
        $copy->withAuditReason('Cloned from '.$source->name)->save();

        return $copy;
    }

    /** HROne "Inactivate" / switch back on. The default dashboard stays active. */
    public function setStatus(Dashboard $dashboard, string $status, User $actor, ?string $reason = null): Dashboard
    {
        $this->assertManager($actor);
        if (! array_key_exists($status, Dashboard::STATUSES)) {
            throw new RuntimeException('Unknown dashboard status.');
        }
        if ($dashboard->is_default && $status !== 'active') {
            throw new RuntimeException('The default dashboard stays active.');
        }
        $dashboard->withAuditReason($reason)->update(['status' => $status]);

        return $dashboard;
    }

    private function assertManager(User $actor): void
    {
        if (! $actor->hasPermission('analytics.manage')) {
            throw new RuntimeException('You cannot change dashboard views.');
        }
    }
}
