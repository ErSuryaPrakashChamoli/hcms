<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\AttendanceExceptionCentre;
use App\Filament\Pages\ChangeIntelligencePage;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Pages\PlatformReadinessPage;
use App\Filament\Pages\SecurityPolicyPage;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\ConfigurationChanges\ConfigurationChangeResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\InboundEvents\InboundEventResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * UX.16: what needs each kind of person, as signals. A signal is one sentence with a reason, a severity and the
 * place to act: ['key', 'count' (?int), 'title', 'why', 'severity' (info|warning|danger), 'url' (?string)].
 *
 * Personal signals read only the viewer's own record. Team signals read the viewer's current reports (presence:
 * every current relationship, as Team pulse; decisions: the configured manager relationship types, as
 * performance). Operations, workforce and governance signals each require the permission of the screen they
 * summarise and query through the normal global scopes, so tenant, organisation scope and relationship scope
 * apply exactly as on that screen. A signal never shows a record the viewer could not open; most show only
 * counts. Nothing here grants access, predicts, scores people or decides anything.
 */
final class RoleSignals
{
    public function __construct(
        private readonly RoleLens $lenses,
        private readonly ApprovalCenter $approvals,
        private readonly PerformanceRelationships $relationships,
    ) {}

    /** Things about the viewer's own record: requests, probation, documents, pay, manager. @return list<array<string, mixed>> */
    public function personal(User $user): array
    {
        $me = $this->lenses->employee($user);
        if ($me === null) {
            return [];
        }
        $today = now()->startOfDay();
        $items = [];

        $declined = $this->safe(fn () => LeaveRequest::query()->with('leaveType')->where('employee_id', $me->id)->where('status', 'rejected')
            ->where('updated_at', '>=', now()->subDays(14))->latest('updated_at')->first());
        if ($declined !== null) {
            $items[] = $this->item('leave_declined', null, 'Your leave request needs attention',
                ($declined->leaveType?->name ?? 'Leave').' for '.$declined->from_date->format('j M').' was declined. See why and plan again if you need to.', 'warning',
                $this->url(fn () => LeaveRequestResource::canAccess() ? LeaveRequestResource::getUrl('index') : null));
        }

        $state = $me->lifecycle_state instanceof LifecycleState ? $me->lifecycle_state : LifecycleState::tryFrom((string) $me->lifecycle_state);
        if ($state === LifecycleState::Probation && $me->probation_end_date !== null && $me->probation_end_date->between($today, $today->copy()->addDays(30))) {
            $days = (int) $today->diffInDays($me->probation_end_date->copy()->startOfDay());
            $items[] = $this->item('probation_review', null, $days === 0 ? 'Your probation review is due today' : 'Your probation review is due in '.$days.' '.($days === 1 ? 'day' : 'days'),
                'Your manager confirms your probation by '.$me->probation_end_date->format('j M Y').'.', $days <= 7 ? 'warning' : 'info', null);
        }

        $expiring = $this->safe(fn () => EmployeeDocument::query()->where('employee_id', $me->id)->whereNotIn('status', ['archived', 'rejected'])
            ->whereNotNull('expires_on')->whereDate('expires_on', '>=', $today)->whereDate('expires_on', '<=', $today->copy()->addDays(30))->orderBy('expires_on')->limit(2)->get(), collect());
        foreach ($expiring as $doc) {
            $days = (int) $today->diffInDays($doc->expires_on->copy()->startOfDay());
            $items[] = $this->item('document_expiring_'.$doc->id, null, 'Your '.$doc->title.' expires in '.$days.' '.($days === 1 ? 'day' : 'days'),
                'Upload a renewed copy before '.$doc->expires_on->format('j M').' so your record stays complete.', $days <= 7 ? 'warning' : 'info',
                $this->url(fn () => MyHr::canAccess() ? MyHr::getUrl(['tab' => 'documents']) : null));
        }

        $payslip = $this->safe(fn () => Payslip::query()->where('employee_id', $me->id)->where('generated_at', '>=', now()->subDays(14))->whereNull('viewed_at')->latest('generated_at')->first());
        if ($payslip !== null && $user->can('view', $payslip)) {
            $items[] = $this->item('payslip', null, 'Your payslip'.($payslip->period?->label() ? ' for '.$payslip->period->label() : '').' is available',
                'Issued '.$payslip->generated_at->format('j M').'.', 'info', $this->url(fn () => PayslipResource::getUrl('view', ['record' => $payslip])));
        }

        $line = $me->currentManager;
        if ($line !== null && $line->effective_from !== null && $line->effective_from->greaterThanOrEqualTo($today->copy()->subDays(30))
            && ($me->joining_date === null || $line->effective_from->greaterThan($me->joining_date))) {
            $name = $this->safe(fn () => Employee::query()->withoutGlobalScopes()->with('person')->find($line->manager_id)?->display_name);
            $items[] = $this->item('manager_changed', null, $name ? 'Your manager changed to '.$name : 'Your manager changed',
                'From '.$line->effective_from->format('j M').'. Your requests now go to them.', 'info', null);
        }

        return $items;
    }

    /** The viewer's team as a manager: decisions, exceptions, probation, reviews and changes. @return list<array<string, mixed>> */
    public function team(User $user): array
    {
        $me = $this->lenses->employee($user);
        if ($me === null || ! $this->lenses->has($user, RoleLens::MANAGER)) {
            return [];
        }
        $team = $me->directReports()->currentlyEffective()->pluck('employee_id');
        $managed = $this->safe(fn () => $this->relationships->reportIds($me), collect());
        $teamUrl = $this->url(fn () => MyTeam::canAccess() ? MyTeam::getUrl() : null);
        $items = [];

        $decisions = $this->safe(fn () => $this->approvals->count($user), 0);
        if ($decisions > 0) {
            $items[] = $this->item('decisions', $decisions, $decisions === 1 ? 'decision needs you' : 'decisions need you',
                'Leave, attendance corrections and other requests waiting for your answer.', 'warning', $this->url(fn () => Approvals::canAccess() ? Approvals::getUrl() : null));
        }

        $exceptions = $team->isEmpty() ? 0 : $this->safe(fn () => AttendanceRecord::query()->whereIn('employee_id', $team)
            ->whereDate('date', '>=', now()->subDays(7))->whereDate('date', '<=', now())->where('is_regularised', false)
            ->where(fn ($q) => $q->whereIn('status', ['absent', 'incomplete'])->orWhere('late_minutes', '>', 0))
            ->distinct()->count('employee_id'), 0);
        if ($exceptions > 0) {
            $items[] = $this->item('attendance_exceptions', $exceptions, $exceptions === 1 ? 'team member has attendance exceptions' : 'team members have attendance exceptions',
                'Missed punches, late arrivals or unplanned absence in the last 7 days.', 'warning',
                $this->url(fn () => AttendanceExceptionCentre::canAccess() ? AttendanceExceptionCentre::getUrl() : null) ?? $teamUrl);
        }

        $probation = $managed->isEmpty() ? collect() : $this->safe(fn () => Employee::query()->whereKey($managed->all())->where('lifecycle_state', LifecycleState::Probation->value)
            ->whereNotNull('probation_end_date')->whereDate('probation_end_date', '<=', now()->addDays(30))->get(['id', 'probation_end_date']), collect());
        if ($probation->isNotEmpty()) {
            $overdue = $probation->filter(fn ($e) => $e->probation_end_date->isPast())->count();
            $one = $probation->count() === 1 ? $probation->first() : null;
            $items[] = $this->item('probation_due', $probation->count(), $probation->count() === 1 ? 'team member\'s probation decision is due' : 'probation decisions are due',
                $overdue > 0 ? $overdue.' already past the end date. Confirm, extend or end probation.' : 'Confirm, extend or end probation before the end date.', $overdue > 0 ? 'danger' : 'warning',
                $one && $user->can('view', $one) ? $this->url(fn () => EmployeeResource::getUrl('view', ['record' => $one])) : $teamUrl);
        }

        $attention = $this->safe(fn () => app(NeedsAttention::class)->forManager($me, $user), collect());
        if ($reviews = $attention->firstWhere('key', 'reviews')) {
            $items[] = $this->item('reviews', (int) $reviews['count'], (int) $reviews['count'] === 1 ? 'performance review is waiting for you' : 'performance reviews are waiting for you',
                'Manager reviews open in the current cycle.', 'warning', $reviews['url'] ?? null);
        }

        $changed = $team->isEmpty() ? 0 : $this->safe(fn () => EmployeePosition::query()->whereIn('employee_id', $team)->where('change_type', '!=', 'hire')
            ->whereDate('effective_from', '>=', now()->subDays(30))->whereDate('effective_from', '<=', now())->distinct()->count('employee_id'), 0);
        if ($changed > 0) {
            $items[] = $this->item('team_changes', $changed, $changed === 1 ? 'team member changed role or organisation' : 'team members changed role or organisation',
                'Transfers, promotions and other changes in the last 30 days.', 'info', $teamUrl);
        }

        return $items;
    }

    /** HR operational attention, most severe first. Each needs the permission of the screen it opens. @return list<array<string, mixed>> */
    public function operations(User $user): array
    {
        $items = [];
        $today = now()->startOfDay();
        $add = function (string $key, int $count, string $title, string $why, string $severity, ?string $url) use (&$items) {
            if ($count > 0) {
                $items[] = $this->item($key, $count, $title, $why, $severity, $url);
            }
        };
        $employees = fn (array $states) => Employee::query()->whereIn('lifecycle_state', $states);
        $probationUrl = fn () => EmployeeResource::getUrl('index').'?tableFilters[lifecycle_state][values][0]=probation';
        if ($user->hasPermission('employee.view')) {
            $add('joining', $this->safe(fn () => $employees([LifecycleState::PreEmployee->value, LifecycleState::Preboarding->value])->whereBetween('expected_joining_date', [$today, $today->copy()->addDays(7)])->count(), 0),
                'Joining in the next 7 days', 'Accounts, equipment and a buddy should be ready before day one.', 'info', $this->url(fn () => OnboardingPlanResource::getUrl('index')));
            $add('probation_due', $this->safe(fn () => $employees([LifecycleState::Probation->value])->whereBetween('probation_end_date', [$today, $today->copy()->addDays(14)])->count(), 0),
                'Probation ends within 14 days', 'Managers need to confirm, extend or end probation before the date passes.', 'warning', $this->url($probationUrl));
            $add('probation_overdue', $this->safe(fn () => $employees([LifecycleState::Probation->value])->where('probation_end_date', '<', $today)->count(), 0),
                'Probation decisions overdue', 'These people are past their probation end date without a decision.', 'danger', $this->url($probationUrl));
            $add('changes_ahead', $this->safe(fn () => EmployeePosition::query()->whereHas('employee')->where('change_type', '!=', 'hire')
                ->whereDate('effective_from', '>', $today)->whereDate('effective_from', '<=', $today->copy()->addDays(7))->distinct()->count('employee_id'), 0),
                'Employee changes take effect this week', 'Transfers, promotions and other changes scheduled for the next 7 days.', 'info',
                $this->url(fn () => ChangeIntelligencePage::canAccess() ? ChangeIntelligencePage::getUrl() : EmployeeResource::getUrl('index')));
        }
        if ($user->hasPermission('onboarding.manage') || $user->hasPermission('onboarding.view')) {
            $add('onboarding_overdue', $this->safe(fn () => OnboardingTask::query()->where('status', 'pending')->whereDate('due_on', '<', $today)->count(), 0),
                'Onboarding tasks overdue', 'New joiners are waiting on these steps.', 'warning', $this->url(fn () => OnboardingPlanResource::getUrl('index')));
        }
        if ($user->hasPermission('servicedesk.agent') || $user->hasPermission('servicedesk.view')) {
            $add('sla', $this->safe(fn () => app(CaseAccess::class)->visible(Ticket::query(), $user)->whereIn('tickets.status', Ticket::OPEN)->where('tickets.due_at', '<', now())->count(), 0),
                'HR requests past their SLA', 'Employees are waiting longer than promised.', 'danger', $this->url(fn () => TicketResource::getUrl('index')));
        }
        if ($user->hasPermission('document.verify')) {
            $pending = $this->safe(fn () => EmployeeDocument::query()->whereHas('employee')->where('status', 'pending')->orderBy('created_at')->get(['id', 'employee_id']), collect());
            $first = $pending->first() ? $this->safe(fn () => Employee::query()->find($pending->first()->employee_id)) : null;
            $add('documents_to_verify', $pending->count(), 'Documents awaiting verification', 'Uploaded documents stay unverified until someone in HR checks them.', 'warning',
                $first && $user->can('view', $first) ? $this->url(fn () => EmployeeResource::getUrl('view', ['record' => $first]).'#records') : null);
        }
        if ($user->hasPermission('exit.view')) {
            $add('exits', $this->safe(fn () => ExitCase::query()->whereIn('status', ExitCase::OPEN)->whereDate('last_working_day', '<=', now()->addDays(7))->count(), 0),
                'Leaving within 7 days', 'Clearance, handover and final settlement should be on track.', 'info', $this->url(fn () => ExitCaseResource::getUrl('index')));
        }
        if ($user->hasPermission('leave.view')) {
            $add('stale_leave', $this->safe(fn () => LeaveRequest::query()->where('status', 'pending')->where('created_at', '<', now()->subDays(3))->count(), 0),
                'Leave requests waiting over 3 days', 'An approver may be away; consider a nudge or a delegate.', 'warning', $this->url(fn () => Approvals::canAccess() ? Approvals::getUrl() : null));
        }
        if ($user->hasPermission('workflow.view')) {
            $add('workflows_failed', $this->safe(fn () => WorkflowInstance::query()->where('status', InstanceStatus::Failed->value)->count(), 0),
                'Workflows stopped with an error', 'The requests behind them are not moving until someone restarts or cancels them.', 'danger',
                $this->url(fn () => WorkflowInstanceResource::canAccess() ? WorkflowInstanceResource::getUrl('index') : null));
        }

        return $this->bySeverity($items);
    }

    /** The payroll side of HR operations: the latest run and what blocks it. @return list<array<string, mixed>> */
    public function payroll(User $user): array
    {
        if (! $user->hasPermission('payroll.view') && ! $user->hasPermission('payroll.calculate')) {
            return [];
        }
        $url = $this->url(fn () => PayrollControlRoom::canAccess() ? PayrollControlRoom::getUrl() : null);
        $run = $this->safe(fn () => PayrollRun::query()->with('period')->latest('id')->first());
        if ($run === null) {
            return [$this->item('payroll_none', null, 'No payroll run is open', 'Open a run for the next pay period from the control room.', 'info', $url)];
        }
        $label = $run->period?->label() ?? 'Run #'.$run->id;
        $status = (string) config("peopleos.payroll.run_statuses.{$run->status}", $run->status);
        $items = [];
        if ((int) $run->exception_count > 0) {
            $items[] = $this->item('payroll_exceptions', (int) $run->exception_count, ((int) $run->exception_count === 1 ? 'payroll exception' : 'payroll exceptions').' to resolve in '.$label,
                'The run cannot be signed off until they are resolved or accepted.', 'danger', $url);
        }
        if (! in_array($run->status, ['finalized', 'paid'], true)) {
            $items[] = $this->item('payroll_status', null, 'Payroll for '.$label.' is '.mb_strtolower($status), 'Calculate, validate and approve before the pay date.', 'warning', $url);
        }

        return $items;
    }

    /**
     * The workforce story for executives: the Workforce pulse headline and movement (the same aggregates and
     * permissions as the Workforce pulse page) plus decisions only they can make where configured.
     *
     * @return array{headline: string, movement: array<string, mixed>, decisions: list<array<string, mixed>>}|null
     */
    public function workforce(User $user): ?array
    {
        if (! $user->hasPermission('analytics.executive')) {
            return null;
        }
        $pulse = $this->safe(fn () => app(WorkforcePulse::class)->for($user));
        if ($pulse === null) {
            return null;
        }
        $decisions = [];
        if ($user->hasPermission('workforce.approve')) {
            $plans = $this->safe(fn () => WorkforcePlanVersion::query()->whereIn('status', ['submitted', 'under_review'])->count(), 0);
            if ($plans > 0) {
                $decisions[] = $this->item('plans_in_review', $plans, $plans === 1 ? 'headcount plan is waiting for your review' : 'headcount plans are waiting for your review',
                    'Submitted workforce plans need a decision before budgets are committed.', 'warning', null);
            }
        }

        return ['headline' => $pulse['headline'], 'movement' => $pulse['movement'], 'size' => $pulse['size'], 'decisions' => $decisions];
    }

    /**
     * Governance for administrators: configuration, access, security, integrations, delivery and workflows. Each
     * figure needs the screen it summarises; platform-wide figures (spanning tenants) need a platform administrator.
     *
     * @return array{attention: list<array<string, mixed>>, facts: list<array{key: string, label: string, value: string, url: ?string}>}
     */
    public function governance(User $user): array
    {
        $may = fn (callable $check) => (bool) rescue($check, false, false);
        $tenantId = app(TenantContext::class)->id();
        $items = [];
        $facts = [];
        $add = function (string $key, int $count, string $title, string $why, string $severity, ?string $url) use (&$items) {
            if ($count > 0) {
                $items[] = $this->item($key, $count, $title, $why, $severity, $url);
            }
        };

        if ($may(fn () => ConfigurationChangeResource::canAccess())) {
            $url = $this->url(fn () => ConfigurationChangeResource::getUrl('index'));
            $add('config_pending', $this->safe(fn () => ConfigurationChange::query()->where('status', ChangeStatus::PendingApproval)->count(), 0),
                'configuration changes await approval', 'Nothing changes until someone with approval rights publishes or rejects them.', 'warning', $url);
            $facts[] = ['key' => 'config_week', 'label' => 'Configuration changes this week', 'value' => (string) $this->safe(fn () => ConfigurationChange::query()->where('created_at', '>=', now()->subDays(7))->count(), 0), 'url' => $url];
        }
        if ($tenantId !== null && $may(fn () => UserResource::canAccess())) {
            $users = fn () => User::query()->where('tenant_id', $tenantId)->where('status', UserStatus::Active->value);
            $url = $this->url(fn () => UserResource::getUrl('index'));
            $add('users_without_role', $this->safe(fn () => $users()->whereDoesntHave('roles')->count(), 0),
                'people can sign in without a role', 'They see almost nothing. Assign a role or deactivate the account.', 'warning', $url);
            $add('unscoped_approvers', $this->safe(fn () => $users()->whereHas('roles.permissions', fn ($q) => $q->whereIn('key', ['leave.approve', 'attendance.approve', 'employee.update']))
                ->whereNotIn('id', UserAccessScope::query()->select('user_id'))->count(), 0),
                'people approve or edit employees across the whole tenant', 'They have no organisation scope, so the documented rule gives them every company. Scope them if that is not intended.', 'info', $url);
            $facts[] = ['key' => 'active_users', 'label' => 'Active users', 'value' => (string) $this->safe(fn () => $users()->count(), 0), 'url' => $url];
            if ($may(fn () => RoleResource::canAccess())) {
                $facts[] = ['key' => 'roles', 'label' => 'Roles', 'value' => (string) $this->safe(fn () => DB::table('roles')->where('tenant_id', $tenantId)->count(), 0), 'url' => $this->url(fn () => RoleResource::getUrl('index'))];
            }
        }
        if ($may(fn () => SecurityPolicyPage::canAccess())) {
            $mfa = $this->safe(fn () => app(SecurityPolicy::class)->mfaRequired(), true);
            $url = $this->url(fn () => SecurityPolicyPage::getUrl());
            $add('mfa_not_required', $mfa ? 0 : 1, 'Multi-factor sign-in is not required', 'Anyone with a password can sign in. Require an authenticator app in the security policy.', 'warning', $url);
            $facts[] = ['key' => 'mfa', 'label' => 'Multi-factor sign-in', 'value' => $mfa ? 'Required' : 'Optional', 'url' => $url];
        }
        if ($may(fn () => InboundEventResource::canAccess())) {
            $add('dead_letters', $this->safe(fn () => InboundEvent::query()->where('status', 'dead_letter')->count() + WebhookDelivery::query()->where('status', 'dead_letter')->count(), 0),
                'integration messages failed for good', 'They were retried and gave up. Fix the cause and replay them from the integration hub.', 'danger', $this->url(fn () => InboundEventResource::getUrl('index')));
        }
        if ($may(fn () => WebhookEndpointResource::canAccess())) {
            $add('webhooks_failing', $this->safe(fn () => WebhookEndpoint::query()->where('failure_count', '>', 0)->count(), 0),
                'webhook endpoints are failing', 'Connected systems are missing PeopleOS events.', 'warning', $this->url(fn () => WebhookEndpointResource::getUrl('index')));
        }
        if ($may(fn () => NotificationDeliveryResource::canAccess())) {
            $add('notifications_failed', $this->safe(fn () => NotificationDelivery::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(), 0),
                'notifications failed to deliver this week', 'People did not receive these messages. Check the channel settings.', 'warning', $this->url(fn () => NotificationDeliveryResource::getUrl('index')));
        }
        if ($may(fn () => WorkflowInstanceResource::canAccess())) {
            $add('workflows_failed', $this->safe(fn () => WorkflowInstance::query()->where('status', InstanceStatus::Failed->value)->count(), 0),
                'workflows stopped with an error', 'The requests behind them are not moving.', 'danger', $this->url(fn () => WorkflowInstanceResource::getUrl('index')));
        }
        if ($may(fn () => PlatformReadinessPage::canAccess()) && Schema::hasTable('failed_jobs')) {
            $add('failed_jobs', $this->safe(fn () => (int) DB::table('failed_jobs')->count(), 0),
                'background jobs failed on the platform', 'Platform-wide: these may belong to any tenant.', 'danger', $this->url(fn () => PlatformReadinessPage::getUrl()));
        }
        if ($may(fn () => AuditEventResource::canAccess())) {
            $facts[] = ['key' => 'audit_week', 'label' => 'Audited events this week', 'value' => (string) $this->safe(fn () => AuditEvent::query()->where('created_at', '>=', now()->subDays(7))->count(), 0),
                'url' => $this->url(fn () => AuditEventResource::getUrl('index'))];
        }

        return ['attention' => $this->bySeverity($items), 'facts' => $facts];
    }

    /** @return array<string, mixed> */
    private function item(string $key, ?int $count, string $title, string $why, string $severity, ?string $url): array
    {
        return compact('key', 'count', 'title', 'why', 'severity', 'url');
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function bySeverity(array $items): array
    {
        usort($items, fn ($a, $b) => (['danger' => 0, 'warning' => 1, 'info' => 2][$a['severity']] ?? 3) <=> (['danger' => 0, 'warning' => 1, 'info' => 2][$b['severity']] ?? 3));

        return $items;
    }

    private function url(callable $resolve): ?string
    {
        return rescue($resolve, null, false);
    }

    private function safe(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
