<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\TaskInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity P4 (Inbox → Employee request lock, "ESS lock"). When switched on, an employee's own self-service
 * requests of the listed kinds are refused while the lock applies to them:
 *   - overdue tasks: they have a task of the counted kinds overdue by more than N days (the refusal names the tasks; the
 *     lock lifts as soon as they are done), and/or
 *   - a lock period: requests raised between two dates.
 * Settings choose the request kinds, the employees locked (blank = everyone) and those exempted. HR or a manager raising
 * a request on someone's behalf is never locked, nor are system-raised requests. Off by default (inbox.ess_lock.enabled).
 * Every self-service request path calls assertOpen() before it writes anything.
 */
final class SelfServiceLock
{
    /** The self-service request paths the lock can cover (setting inbox.ess_lock.request_kinds; blank = all). */
    public const REQUEST_KINDS = [
        'leave' => 'Leave', 'time_office' => 'Time-office requests (short leave, on duty, shift change, overtime, comp-off, restricted holiday)',
        'regularisation' => 'Attendance regularisation', 'loan' => 'Loans and salary advances', 'reimbursement_claim' => 'Reimbursement claims',
        'variable_pay' => 'Variable pay', 'expense' => 'Expense reports and expense advances', 'travel' => 'Travel requests',
        'asset_request' => 'Asset requests', 'service_desk' => 'Helpdesk and service requests',
        // HROne parity MS-3: self-service profile changes (My profile).
        'profile_change' => 'Profile changes',
    ];

    /** The employee tasks that count towards the lock (options `employee_task_kinds`; blank = all). */
    public const TASK_KINDS = [
        'workflow' => 'Workflow tasks and approvals', 'onboarding' => 'Onboarding tasks', 'knowledge' => 'Policy acknowledgements',
        'communication' => 'Announcement acknowledgements', 'engagement' => 'Surveys', 'feedback' => 'Feedback asked of the employee (exit interview, confirmation, probation, induction)',
        'performance' => 'One-on-one feedback', 'assets' => 'Asset acknowledgements', 'servicedesk' => 'Helpdesk requests waiting for the employee',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('inbox.ess_lock.enabled', false);
    }

    /**
     * Refuse a self-service request while the lock applies.
     *
     * @param  class-string<RuntimeException>  $exception  the requesting domain's own rule exception
     */
    public function assertOpen(Employee $employee, string $kind, ?User $requester = null, string $exception = RuntimeException::class): void
    {
        if (($refusal = $this->refusal($employee, $kind, $requester)) !== null) {
            throw new $exception($refusal);
        }
    }

    /** Why the employee's own request of this kind is locked now, or null when it is open. */
    public function refusal(Employee $employee, string $kind, ?User $requester = null): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $requester ??= auth()->user();
        // Requests raised on someone's behalf (HR, a manager) or by the system are never locked.
        if ($requester === null || $employee->user_id === null || (int) $employee->user_id !== (int) $requester->id) {
            return null;
        }
        $kinds = $this->list('inbox.ess_lock.request_kinds');
        if ($kinds !== [] && ! in_array($kind, $kinds, true)) {
            return null;
        }
        $only = $this->list('inbox.ess_lock.employee_ids');
        if (($only !== [] && ! in_array((string) $employee->id, $only, true)) || in_array((string) $employee->id, $this->list('inbox.ess_lock.exempt_employee_ids'), true)) {
            return null;
        }
        $what = mb_strtolower(self::REQUEST_KINDS[$kind] ?? str_replace('_', ' ', $kind));
        $basis = (string) $this->settings->get('inbox.ess_lock.basis', 'overdue_tasks');

        if (in_array($basis, ['period', 'both'], true) && ($period = $this->period()) !== null) {
            [$from, $to] = $period;
            $today = today();
            if (($from === null || $today->gte($from)) && ($to === null || $today->lte($to))) {
                $when = match (true) {
                    $from === null => 'until '.$to->format('d M Y'),
                    $to === null => 'from '.$from->format('d M Y'),
                    default => 'from '.$from->format('d M Y').' to '.$to->format('d M Y'),
                };

                return "Self-service requests ({$what}) are locked {$when}. Ask HR if it cannot wait.";
            }
        }
        if (in_array($basis, ['overdue_tasks', 'both'], true)) {
            $overdue = $this->overdueTasks($employee, $requester);
            if ($overdue->isNotEmpty()) {
                $names = $overdue->take(3)->map(fn (ExperienceTask $t) => $t->title)->implode('; ');
                $more = $overdue->count() > 3 ? ' and '.($overdue->count() - 3).' more' : '';

                return "Your self-service requests ({$what}) are locked until you finish your overdue tasks: {$names}{$more}.";
            }
        }

        return null;
    }

    /**
     * The person's tasks of the counted kinds overdue by more than the lock period (inbox.ess_lock.after_days).
     *
     * @return Collection<int, ExperienceTask>
     */
    public function overdueTasks(Employee $employee, User $user): Collection
    {
        $days = max(1, min(90, (int) $this->settings->get('inbox.ess_lock.after_days', 7)));
        $cutoff = now()->subDays($days);
        $counted = $this->list('inbox.ess_lock.task_kinds');
        $counts = fn (string $kind) => $counted === [] || in_array($kind, $counted, true);

        $tasks = collect();
        try {
            $tasks = app(ExperienceTasks::class)->for($user, $employee)->filter(fn (ExperienceTask $t) => $counts($t->domain));
        } catch (Throwable $e) {
            report($e);
        }
        if ($counts('onboarding')) {
            $tasks = $tasks->merge(OnboardingTask::query()->where('status', 'pending')->where('owner_user_id', $user->id)->whereNotNull('due_on')
                ->whereDate('due_on', '<', $cutoff->toDateString())->orderBy('due_on')->limit(20)->get()
                ->map(fn (OnboardingTask $t) => new ExperienceTask('onboarding', $t->type, $t->title, 'Onboarding', $t->due_on, $this->inboxUrl())));
        }

        return $tasks->filter(fn (ExperienceTask $t) => $t->dueAt !== null && $t->dueAt->lt($cutoff))->sortBy(fn (ExperienceTask $t) => $t->dueAt->timestamp)->values();
    }

    /** @return array{0: ?Carbon, 1: ?Carbon}|null the lock period (inbox.ess_lock.period_from / period_to), null when none is set */
    private function period(): ?array
    {
        $from = $this->settings->get('inbox.ess_lock.period_from');
        $to = $this->settings->get('inbox.ess_lock.period_to');
        if (blank($from) && blank($to)) {
            return null;
        }

        return [blank($from) ? null : Carbon::parse($from)->startOfDay(), blank($to) ? null : Carbon::parse($to)->startOfDay()];
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        return array_values(array_map('strval', array_filter((array) $this->settings->get($key, []), fn ($v) => $v !== null && $v !== '')));
    }

    private function inboxUrl(): ?string
    {
        try {
            return TaskInbox::getUrl(['tab' => 'onboarding']);
        } catch (Throwable) {
            return null;
        }
    }
}
