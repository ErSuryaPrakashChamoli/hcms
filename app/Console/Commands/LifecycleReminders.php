<?php

namespace App\Console\Commands;

use App\Domain\Documents\Events\DocumentExpiring;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Daily lifecycle sweep (§114): joiners due, probations ending or overdue, documents expiring.
 * Emits events; tenants decide what happens through notification rules and workflows.
 * HROne setup parity: also reminds people whose password is about to expire (security.password_expiry_warning_days).
 */
class LifecycleReminders extends Command
{
    protected $signature = 'peopleos:lifecycle:reminders {--tenant=} {--force : Run again even if today\'s sweep already ran for the tenant}';

    protected $description = 'Emit joining, probation and document-expiry reminder events for every tenant';

    public function handle(TenantContext $tenants, SettingsRepository $settings, Documents $documents, SecurityPolicy $security, Notifier $notifier): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants, $settings, $documents, $security, $notifier) {
                $tenants->runAs($tenant, function () use ($tenant, $settings, $documents, $security, $notifier) {
                    $today = now()->startOfDay();
                    // Phase 14: one sweep per tenant per day (a re-run or an overlapping server emits nothing twice).
                    if (! $this->option('force') && ! TenantRunner::claim('lifecycle.reminders', $today->toDateString())) {
                        $this->line("{$tenant->slug}: already swept today");

                        return;
                    }
                    $joiningWindow = (int) $settings->get('employee.joining.reminder_days', 3);
                    $probationWindow = (int) $settings->get('employee.probation.reminder_days', 14);
                    $documentWindow = (int) $settings->get('documents.expiry.reminder_days', 30);
                    $counts = ['joining_due' => 0, 'probation_ending' => 0, 'probation_overdue' => 0, 'document_expiring' => 0];

                    Employee::query()->with('person')
                        ->whereIn('lifecycle_state', [LifecycleState::PreEmployee, LifecycleState::Preboarding])
                        ->whereNotNull('expected_joining_date')
                        ->whereBetween('expected_joining_date', [$today->toDateString(), $today->copy()->addDays($joiningWindow)->toDateString()])
                        ->each(function (Employee $e) use (&$counts) {
                            EmployeeReminderDue::dispatch($e, 'employee.joining_due', ['expected_joining_date' => $e->expected_joining_date]);
                            $counts['joining_due']++;
                        });

                    Employee::query()->with('person')
                        ->where('lifecycle_state', LifecycleState::Probation)
                        ->whereNotNull('probation_end_date')
                        ->each(function (Employee $e) use ($today, $probationWindow, &$counts) {
                            if ($e->probation_end_date->lt($today)) {
                                EmployeeReminderDue::dispatch($e, 'employee.probation_overdue', ['probation_end_date' => $e->probation_end_date, 'days_overdue' => (int) $e->probation_end_date->diffInDays($today)]);
                                $counts['probation_overdue']++;
                            } elseif ($e->probation_end_date->lte($today->copy()->addDays($probationWindow))) {
                                EmployeeReminderDue::dispatch($e, 'employee.probation_ending', ['probation_end_date' => $e->probation_end_date, 'days_left' => (int) $today->diffInDays($e->probation_end_date)]);
                                $counts['probation_ending']++;
                            }
                        });

                    foreach ($documents->expiringWithin($documentWindow) as $document) {
                        DocumentExpiring::dispatch($document);
                        $counts['document_expiring']++;
                    }

                    $this->line(sprintf('%s: joining %d, probation ending %d, overdue %d, documents expiring %d', $tenant->slug, ...array_values($counts)));

                    $warned = $this->warnPasswordsExpiring($security, $notifier, $today);
                    if ($warned !== null) {
                        $this->line("{$tenant->slug}: passwords expiring {$warned}");
                    }
                });
            }));

        return $runner->exitCode();
    }

    /**
     * HROne setup parity (Password policy): while passwords expire, each active user whose password expires within the
     * warning window is told once per expiry date (in-app and e-mail), with the date. Null when nothing is configured.
     */
    private function warnPasswordsExpiring(SecurityPolicy $security, Notifier $notifier, CarbonInterface $today): ?int
    {
        $days = $security->expiryDays();
        $window = $security->expiryWarningDays();
        if ($days === 0 || $window === 0) {
            return null;
        }
        $warned = 0;
        User::forCurrentTenant()->where('status', UserStatus::Active->value)->whereNotNull('password_changed_at')
            ->where('password_changed_at', '>=', $today->copy()->subDays($days))
            ->where('password_changed_at', '<', $today->copy()->subDays($days)->addDays($window + 1))
            ->orderBy('id')
            ->each(function (User $user) use ($security, $notifier, $today, &$warned): void {
                $expires = $security->passwordExpiresAt($user->password_changed_at);
                if ($expires === null || $expires->lt($today) || ! TenantRunner::claim("password_expiring:{$user->id}", $expires->toDateString())) {
                    return;
                }
                $date = $expires->format('d M Y');
                $notifier->send([$user], ['in_app', 'email'], "Your password expires on {$date}",
                    "Your organisation asks for a new password every {$security->expiryDays()} days. Change it under Account security before {$date}; after that you will be asked for a new one when you sign in.",
                    'security.password_expiring', $user);
                $warned++;
            });

        return $warned;
    }
}
