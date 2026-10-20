<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationReminderLog;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Phase 11 reminders: compensation changes and cycles waiting for a decision longer than
 * peopleos.compensation.approval_reminder_days, sent in-app to the people whose step it is (never the
 * proposer acting on their own change). Throttled through compensation_reminder_logs (one per
 * subject per week); a reminder never triggers another.
 */
final class CompensationReminders
{
    public function __construct(private readonly AccessScopes $scopes) {}

    /** @return array{changes: int, cycles: int} */
    public function tick(): array
    {
        $sent = ['changes' => 0, 'cycles' => 0];
        $threshold = Carbon::now()->subDays((int) config('peopleos.compensation.approval_reminder_days', 3));
        $duty = ['submitted' => 'compensation.review', 'under_review' => 'compensation.approve', 'approved' => 'compensation.execute'];

        CompensationChange::query()->withoutGlobalScope(AccessScope::class)->whereNull('compensation_cycle_id')->whereIn('status', array_keys($duty))
            ->where('updated_at', '<=', $threshold)->orderBy('id')->get()
            ->each(function (CompensationChange $change) use ($duty, &$sent) {
                $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($change->employee_id);
                if ($employee === null || ! $this->once('pending_change', $change, 7)) {
                    return;
                }
                $recipients = $this->holders($duty[$change->status], array_values($change->actors()), $employee);
                if ($recipients !== []) {
                    CompensationEvent::dispatch('compensation.reminder.pending_change', $employee, $change, ['reference' => $change->reference, 'status' => $change->status, 'employee_code' => $employee->employee_code], $recipients);
                    $sent['changes']++;
                }
            });

        CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->whereIn('status', array_keys($duty))->where('updated_at', '<=', $threshold)->orderBy('id')->get()
            ->each(function (CompensationCycle $cycle) use ($duty, &$sent) {
                if (! $this->once('pending_cycle', $cycle, 7)) {
                    return;
                }
                $recipients = $this->holders($duty[$cycle->status], array_filter([$cycle->prepared_by, $cycle->reviewed_by, $cycle->approved_by]));
                if ($recipients !== []) {
                    CompensationEvent::dispatch('compensation.reminder.pending_cycle', null, $cycle, ['cycle' => $cycle->code, 'status' => $cycle->status], $recipients);
                    $sent['cycles']++;
                }
            });

        return $sent;
    }

    /** @return list<int> */
    private function holders(string $permission, array $except, ?Employee $employee = null): array
    {
        $except = array_map('intval', array_filter($except));

        return User::query()->forCurrentTenant()->get()
            ->filter(fn (User $u) => $u->isActive() && ! in_array((int) $u->id, $except, true) && $u->hasPermission($permission)
                && ($employee === null || ((int) $employee->user_id !== (int) $u->id && $this->scopes->allows($u, $employee))))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function once(string $reminder, Model $subject, int $everyDays): bool
    {
        $type = class_basename($subject);
        if (CompensationReminderLog::query()->where('reminder', $reminder)->where('subject_type', $type)->where('subject_id', $subject->getKey())
            ->whereDate('reminded_on', '>', Carbon::now()->subDays($everyDays)->toDateString())->exists()) {
            return false;
        }
        try {
            CompensationReminderLog::query()->create(['reminder' => $reminder, 'subject_type' => $type, 'subject_id' => $subject->getKey(), 'reminded_on' => now()->toDateString()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
