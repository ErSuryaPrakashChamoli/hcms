<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\Payslip;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\ChangeIntelligencePage;
use App\Filament\Pages\ConfigurationFinder;
use App\Filament\Pages\Home;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\OrganisationMap;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Pages\People;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\FeedbackEntries\FeedbackEntryResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Users\UserResource;
use Closure;
use Throwable;

/**
 * UX: the universal quick-action launcher (Create / Request / Approve / Assign / Review / Send /
 * Generate / Schedule). Each action opens an existing page or an existing Filament action on it
 * (`?action=`), so the form, validation and domain service are the ones already in place. An action is
 * listed only when its destination's own canAccess / canCreate / permission allows it; the launcher
 * hides nothing that is otherwise reachable and opens nothing that is otherwise blocked.
 */
final class QuickActions
{
    public function __construct(private readonly RoleLens $lenses, private readonly ApprovalCenter $approvals) {}

    /**
     * @return list<array{key: string, verb: string, label: string, hint: string, icon: string, keywords: list<string>, url: string, lens: string}>
     */
    public function for(User $user): array
    {
        $me = $this->lenses->employee($user);
        $actions = [];
        $add = function (string $key, string $verb, string $label, string $hint, string $icon, array $keywords, Closure $url, string $lens, bool $allowed) use (&$actions) {
            if (! $allowed) {
                return;
            }
            try {
                $target = $url();
            } catch (Throwable) {
                return;
            }
            if ($target !== null) {
                $actions[] = compact('key', 'verb', 'label', 'hint', 'icon', 'keywords', 'lens') + ['url' => $target];
            }
        };

        // Employee self-service: hosted on Home as real Filament actions.
        $add('request_leave', 'Request', 'Request leave', 'Pick dates, see your balance, submit', 'heroicon-o-calendar-days', ['leave', 'holiday', 'vacation', 'time off', 'pto', 'sick'],
            fn () => Home::getUrl().'?action=requestLeave', RoleLens::EMPLOYEE, $me !== null && $user->can('leave.apply'));
        $add('regularise', 'Request', 'Fix my attendance', 'Missed punch or wrong time', 'heroicon-o-clock', ['attendance', 'punch', 'regularise', 'regularize', 'missed'],
            fn () => Home::getUrl().'?action=regularise', RoleLens::EMPLOYEE, $me !== null && $user->can('attendance.regularise'));
        $add('ask_hr', 'Request', 'Raise an HR request', 'A question or a service from HR', 'heroicon-o-chat-bubble-left-ellipsis', ['hr', 'help', 'request', 'ticket', 'question', 'ask'],
            fn () => Home::getUrl().'?action=askHr', RoleLens::EMPLOYEE, $me !== null && $user->can('servicedesk.request'));
        $add('services', 'Request', 'Browse HR services', 'Letters, certificates, changes and more', 'heroicon-o-squares-2x2', ['service', 'catalogue', 'letter', 'certificate'],
            fn () => MyHr::getUrl(['tab' => 'services']), RoleLens::EMPLOYEE, MyHr::canAccess());
        $payslip = $me ? Payslip::query()->where('employee_id', $me->id)->latest('generated_at')->first() : null;
        $add('payslip', 'Review', 'Open my latest payslip', $payslip ? 'Generated '.$payslip->generated_at?->format('d M Y') : 'No payslip yet', 'heroicon-o-banknotes', ['payslip', 'salary', 'pay', 'slip'],
            fn () => PayslipResource::getUrl('view', ['record' => $payslip]), RoleLens::EMPLOYEE, $payslip !== null && $user->can('view', $payslip));
        $add('my_documents', 'Review', 'My documents', 'Letters, IDs and certificates', 'heroicon-o-document-text', ['document', 'documents', 'letter', 'files'],
            fn () => MyHr::getUrl(['tab' => 'documents']), RoleLens::EMPLOYEE, MyHr::canAccess());

        // Manager.
        $pending = $this->approvals->count($user);
        $add('approvals', 'Approve', $pending > 0 ? "Review {$pending} approval".($pending === 1 ? '' : 's') : 'Open approvals', 'Leave, attendance, compensation, letters, workflow steps', 'heroicon-o-check-badge', ['approve', 'approval', 'pending', 'decide'],
            fn () => Approvals::getUrl(), RoleLens::MANAGER, Approvals::canAccess());
        $add('team_leave', 'Review', 'Team leave calendar', 'Who is away and when', 'heroicon-o-calendar', ['leave', 'calendar', 'team', 'away', 'absence'],
            fn () => LeaveCalendar::getUrl(), RoleLens::MANAGER, LeaveCalendar::canAccess());
        $add('my_team', 'Review', 'My team', 'Your direct reports today', 'heroicon-o-user-group', ['team', 'reports', 'my team'],
            fn () => MyTeam::getUrl(), RoleLens::MANAGER, MyTeam::canAccess());
        $add('give_feedback', 'Send', 'Give feedback', 'Recognise or coach someone', 'heroicon-o-hand-thumb-up', ['feedback', 'praise', 'kudos', 'recognition'],
            fn () => FeedbackEntryResource::getUrl('index').'?action=give', RoleLens::MANAGER, FeedbackEntryResource::canAccess() && $user->can('create', FeedbackEntryResource::getModel()));
        $add('one_on_one', 'Schedule', 'Schedule a one-on-one', 'Agenda and notes in one place', 'heroicon-o-chat-bubble-left-right', ['1:1', 'one on one', 'meeting', 'check-in'],
            fn () => OneOnOneResource::getUrl('index').'?action=create', RoleLens::MANAGER, OneOnOneResource::canAccess() && OneOnOneResource::canCreate());

        // HR.
        $add('find_person', 'Review', 'Find a person', 'Directory with filters and previews', 'heroicon-o-users', ['people', 'directory', 'employee', 'find', 'search'],
            fn () => People::getUrl(), RoleLens::HR, People::canAccess());
        $add('add_employee', 'Create', 'Add an employee', 'Hire into a position', 'heroicon-o-user-plus', ['hire', 'new joiner', 'add', 'employee', 'onboard'],
            fn () => EmployeeResource::getUrl('create'), RoleLens::HR, EmployeeResource::canCreate());
        $add('generate_letter', 'Generate', 'Generate a letter', 'From an approved template', 'heroicon-o-document-plus', ['letter', 'certificate', 'experience', 'offer'],
            fn () => LetterResource::getUrl('index').'?action=generate', RoleLens::HR, LetterResource::canAccess() && $user->can('letter.issue'));
        $add('hr_queue', 'Review', 'HR request queue', 'Cases by SLA', 'heroicon-o-lifebuoy', ['tickets', 'cases', 'queue', 'requests', 'sla'],
            fn () => TicketResource::getUrl('index'), RoleLens::HR, TicketResource::canAccess() && $user->can('servicedesk.agent'));
        $add('announce', 'Send', 'Publish an announcement', 'Targeted, with acknowledgement', 'heroicon-o-megaphone', ['announcement', 'communicate', 'news', 'circular'],
            fn () => AnnouncementResource::getUrl('create'), RoleLens::HR, AnnouncementResource::canCreate());
        $add('survey', 'Create', 'Create a survey', 'Pulse or engagement', 'heroicon-o-clipboard-document-list', ['survey', 'pulse', 'engagement', 'poll'],
            fn () => SurveyResource::getUrl('create'), RoleLens::HR, SurveyResource::canCreate());

        // Payroll.
        $add('payroll', 'Review', 'Payroll control room', 'Current run, exceptions, sign-off', 'heroicon-o-calculator', ['payroll', 'run', 'salary', 'exceptions'],
            fn () => PayrollControlRoom::getUrl(), RoleLens::PAYROLL, PayrollControlRoom::canAccess());
        $add('open_run', 'Create', 'Open a payroll run', 'For the next pay period', 'heroicon-o-plus-circle', ['payroll', 'run', 'new run'],
            fn () => PayrollRunResource::getUrl('index').'?action=openRun', RoleLens::PAYROLL, PayrollRunResource::canAccess() && $user->can('payroll.calculate'));

        // Executive / insights.
        $add('workforce', 'Review', 'Workforce command center', 'What changed, what needs a decision', 'heroicon-o-presentation-chart-line', ['workforce', 'headcount', 'attrition', 'analytics', 'insights'],
            fn () => WorkforceCommandCentre::getUrl(), RoleLens::EXECUTIVE, WorkforceCommandCentre::canAccess());
        $add('changes', 'Review', 'What changed this month', 'Joiners, exits, moves, pay changes', 'heroicon-o-arrows-right-left', ['changes', 'what changed', 'movement', 'joiners', 'exits'],
            fn () => ChangeIntelligencePage::getUrl(), RoleLens::EXECUTIVE, ChangeIntelligencePage::canAccess());
        $add('org_map', 'Review', 'Organisation map', 'Structure, headcount, vacancies', 'heroicon-o-building-office-2', ['org', 'chart', 'structure', 'hierarchy', 'organisation', 'organization'],
            fn () => OrganisationMap::getUrl(), RoleLens::EXECUTIVE, OrganisationMap::canAccess());

        // Admin.
        $add('invite_user', 'Create', 'Invite a user', 'Account and roles', 'heroicon-o-key', ['user', 'access', 'role', 'invite', 'login'],
            fn () => UserResource::getUrl('create'), RoleLens::SYSTEM_ADMIN, UserResource::canCreate());
        $add('configure', 'Review', 'What do you want to configure?', 'Find the right setting', 'heroicon-o-adjustments-horizontal', ['configure', 'settings', 'setup', 'policy'],
            fn () => ConfigurationFinder::getUrl(), RoleLens::SYSTEM_ADMIN, ConfigurationFinder::canAccess());
        $add('all_modules', 'Review', 'All modules', 'Everything you can open, by area', 'heroicon-o-squares-plus', ['modules', 'admin', 'all', 'apps'],
            fn () => AdminCentre::getUrl(), RoleLens::SYSTEM_ADMIN, AdminCentre::canAccess());

        return $actions;
    }

    /** The actions to feature for one lens (Home), keeping the launcher's order. */
    public function forLens(User $user, string $lens, int $limit = 6): array
    {
        $own = array_values(array_filter($this->for($user), fn (array $a) => $a['lens'] === $lens));

        return array_slice($own, 0, $limit);
    }
}
