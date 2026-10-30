<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Policies\DashboardPolicy;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\DashboardViewer;
use RuntimeException;

/**
 * HROne parity CA-10 ("Do you want to share this by email?" + recipients): a shared view's periodic notice. It carries the
 * view's name and link only — never a figure, never an attachment — so each recipient sees the numbers only by opening the
 * view under their own rights and access scope. A recipient who could not open the view (inactive, no analytics.view, not
 * listed for it) is skipped. Sent through the notification path (in-app at once; e-mail by the tenant-bound delivery job
 * when the tenant's setting analytics.views.email allows it), each send audited; run with the report schedules
 * (peopleos:reports:run-due) or by an analytics manager ("Send now").
 */
final class DashboardDeliveries
{
    public const EVENT = 'analytics.view.shared';

    public function __construct(private readonly Notifier $notifier, private readonly AuditRecorder $audit, private readonly SettingsRepository $settings) {}

    /** The views whose notice is due in the current tenant; each slot is claimed first, so overlapping runs never send twice. */
    public function runDue(): int
    {
        $sent = 0;
        Dashboard::query()->where('status', 'active')->whereNotNull('email_next_run_at')->where('email_next_run_at', '<=', now())->orderBy('id')->get()
            ->each(function (Dashboard $view) use (&$sent) {
                $claimed = Dashboard::query()->whereKey($view->id)->where('email_next_run_at', $view->getRawOriginal('email_next_run_at'))
                    ->update(['email_last_run_at' => now(), 'email_next_run_at' => $view->emailsOnCadence() ? $view->nextEmailAfter(now()) : null, 'updated_at' => now()]);
                if ($claimed !== 1) {
                    return;
                }
                $this->deliver($view, null, true);
                $sent++;
            });

        return $sent;
    }

    /**
     * Send the view's notice to its recipients now.
     *
     * @return array{sent: list<int>, skipped: list<int>}
     */
    public function deliver(Dashboard $view, ?User $actor = null, bool $scheduled = false): array
    {
        if ($actor !== null && ! $actor->hasPermission('analytics.manage')) {
            throw new RuntimeException('You cannot send this view.');
        }
        $ids = $view->emailRecipients() ?? [];
        if ($ids === [] || ($view->getAttributes()['status'] ?? 'active') !== 'active') {
            return ['sent' => [], 'skipped' => $ids];
        }
        $policy = new DashboardPolicy;
        $users = User::forCurrentTenant()->whereKey($ids)->orderBy('id')->get();
        $eligible = $users->filter(fn (User $user) => $user->isActive() && ! $user->isPlatformAdmin() && $policy->view($user, $view))->values();
        $sent = $eligible->map(fn (User $user) => (int) $user->getKey())->all();
        $skipped = array_values(array_diff($ids, $sent));
        $email = (bool) $this->settings->get('analytics.views.email', true);

        if ($eligible->isNotEmpty()) {
            $this->notifier->send($eligible, $email ? ['in_app', 'email'] : ['in_app'], 'Dashboard view: '.$view->name,
                "Open \"{$view->name}\" in PeopleOS: ".DashboardViewer::getUrl(['dashboard' => $view->slug])."\nThe figures follow your own access; nothing is attached to this message.",
                self::EVENT, $view);
        }
        $this->audit->record(AuditAction::Export, 'analytics', $view, [], $scheduled ? 'Scheduled view notice (link only)' : 'View notice sent (link only)', actor: $actor,
            metadata: ['channel' => 'view_notice', 'recipients' => $sent, 'skipped' => $skipped, 'email' => $email, 'scheduled' => $scheduled]);

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
