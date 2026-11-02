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
use App\Domain\People\Models\InsurancePolicy;
use App\Domain\People\Models\PersonIdentityDocument;
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
 * HROne parity MS-4: and passports / driving licences on the person expiring (employee.identity_document_expiring).
 * HROne parity MS-5: and health insurance policies whose cover ends (employee.insurance_expiring).
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

                    // HROne parity MS-4: passports and driving licences on the person expiring within the document window
                    // (kind and dates only, never the number). Said only when there is one, so the line above stays as it was.
                    $identity = $this->identityDocumentsExpiring($today, $documentWindow);
                    if ($identity > 0) {
                        $this->line("{$tenant->slug}: identity documents expiring {$identity}");
                    }
                    // HROne parity MS-5: health insurance policies whose cover ends within the same window (insurer and dates
                    // only, never a member id); said only when there is one.
                    $insurance = $this->insurancePoliciesExpiring($today, $documentWindow);
                    if ($insurance > 0) {
                        $this->line("{$tenant->slug}: insurance policies expiring {$insurance}");
                    }

                    $warned = $this->warnPasswordsExpiring($security, $notifier, $today);
                    if ($warned !== null) {
                        $this->line("{$tenant->slug}: passwords expiring {$warned}");
                    }
                });
            }));

        return $runner->exitCode();
    }

    /**
     * HROne parity MS-4: one employee.identity_document_expiring reminder per passport / driving licence expiring between
     * today and the window's end, for employees still with the organisation. Notification rules and workflows decide what
     * happens (the same path as the other reminders).
     */
    private function identityDocumentsExpiring(CarbonInterface $today, int $window): int
    {
        $sent = 0;
        PersonIdentityDocument::query()
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [$today->toDateString(), $today->copy()->addDays($window)->toDateString()])
            ->orderBy('expires_on')->orderBy('id')
            ->each(function (PersonIdentityDocument $document) use ($today, &$sent): void {
                $employee = Employee::query()->with('person')->where('person_id', $document->person_id)
                    ->whereNotIn('lifecycle_state', [LifecycleState::Exited, LifecycleState::Alumni, LifecycleState::NotJoined])->first();
                if ($employee === null) {
                    return;
                }
                EmployeeReminderDue::dispatch($employee, 'employee.identity_document_expiring', [
                    'identity_document_id' => $document->getKey(), 'kind' => $document->kind, 'document' => $document->kindLabel(),
                    'expires_on' => $document->expires_on, 'days_left' => (int) $today->diffInDays($document->expires_on),
                ]);
                $sent++;
            });

        return $sent;
    }

    /**
     * HROne parity MS-5: one employee.insurance_expiring reminder per health insurance policy whose cover ends between today
     * and the window's end, for employees still with the organisation — the policy id, insurer, valid-until date and days
     * left; never a member id. Notification rules and workflows decide what happens.
     */
    private function insurancePoliciesExpiring(CarbonInterface $today, int $window): int
    {
        $sent = 0;
        InsurancePolicy::query()->with('employee.person')
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [$today->toDateString(), $today->copy()->addDays($window)->toDateString()])
            ->whereHas('employee', fn ($query) => $query->whereNotIn('lifecycle_state', [LifecycleState::Exited, LifecycleState::Alumni, LifecycleState::NotJoined]))
            ->orderBy('valid_until')->orderBy('id')
            ->each(function (InsurancePolicy $policy) use ($today, &$sent): void {
                EmployeeReminderDue::dispatch($policy->employee, 'employee.insurance_expiring', [
                    'insurance_policy_id' => $policy->getKey(), 'insurer' => $policy->insurer_name,
                    'valid_until' => $policy->valid_until, 'days_left' => (int) $today->diffInDays($policy->valid_until),
                ]);
                $sent++;
            });

        return $sent;
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
