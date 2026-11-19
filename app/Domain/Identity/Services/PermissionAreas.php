<?php

namespace App\Domain\Identity\Services;

use Illuminate\Support\Str;

/**
 * How the permission catalogue reads on a role (owner, 2026-11-19: "bifurcate them module wise … explain in a few
 * lines"): every key sits in a module (its first segment: `leave.apply` → leave), every module in an area of the
 * product, and each has one plain line saying what it covers. Areas follow the rail (People, Organisation, Workflows,
 * Services, Communication, Insights, Admin). Presentation only: the keys, their descriptions and what they grant stay
 * in `config/peopleos.php`. A module not listed here still shows, under "Other permissions", so nothing is ever left
 * ticked out of sight.
 */
final class PermissionAreas
{
    /**
     * Area => what it stands for and its modules, in display order.
     *
     * @var array<string, array{label: string, hint: string, modules: list<string>}>
     */
    public const AREAS = [
        'people' => ['label' => 'People & lifecycle',
            'hint' => 'Employee records from joining to leaving and beyond: profiles, documents, checks, letters, exits and alumni. HR works here; managers usually only need to view.',
            'modules' => ['employee', 'onboarding', 'document', 'bgv', 'letter', 'exit', 'alumni', 'bulk']],
        'time' => ['label' => 'Time & leave',
            'hint' => 'Attendance and leave. Employees apply and regularise, managers approve, and the time office sets up shifts and processes attendance.',
            'modules' => ['attendance', 'leave']],
        'pay' => ['label' => 'Payroll & statutory compliance',
            'hint' => 'Running payroll and meeting PF, ESI, PT, LWF and TDS obligations. It shows everyone\'s pay, so keep it to the payroll team.',
            'modules' => ['payroll', 'compliance']],
        'money' => ['label' => 'Compensation, expenses & assets',
            'hint' => 'Pay changes, expense claims and company assets: who files or requests, who approves and who pays or hands over.',
            'modules' => ['compensation', 'expense', 'asset']],
        'growth' => ['label' => 'Performance & learning',
            'hint' => 'Goals, appraisals, feedback, skills and learning. Employees need their own rights, managers the team ones, HR the set-up ones.',
            'modules' => ['performance', 'feedback', 'learning', 'skills', 'development']],
        'talent' => ['label' => 'Careers, talent & workforce planning',
            'hint' => 'Longer-term people planning: career paths, talent pools, succession, positions and projects.',
            'modules' => ['career', 'talent', 'succession', 'workforce', 'project']],
        'organisation' => ['label' => 'Organisation structure',
            'hint' => 'The shape of the organisation: companies, legal entities, establishments, departments, grades, designations and partners.',
            'modules' => ['company', 'legal_entity', 'establishment', 'organisation', 'people_setup', 'partner']],
        'workflows' => ['label' => 'Tasks, workflows & policies',
            'hint' => 'How work moves: the task inbox and approvals, workflows, forms, policies and notifications. Almost everyone needs the task inbox.',
            'modules' => ['task', 'workflow', 'form', 'policy', 'notification']],
        'services' => ['label' => 'HR help desk & knowledge',
            'hint' => 'Employee help: HR tickets, grievances and help articles.',
            'modules' => ['servicedesk', 'grievance', 'kb']],
        'engage' => ['label' => 'Communication & engagement',
            'hint' => 'Reaching and engaging people: announcements, surveys, events, recognition, birthdays and the social wall.',
            'modules' => ['communication', 'engagement', 'celebration', 'recognition', 'wall']],
        'insights' => ['label' => 'Reports, analytics & AI',
            'hint' => 'Dashboards, reports and AI assistants. They only ever show data the person is already allowed to see.',
            'modules' => ['analytics', 'ai', 'warehouse']],
        'setup' => ['label' => 'Setup & configuration',
            'hint' => 'Settings that shape PeopleOS for the whole organisation, and the Change Centre that publishes them.',
            'modules' => ['settings', 'features', 'custom_field', 'configuration', 'blueprint', 'currency', 'product']],
        'access' => ['label' => 'Users, access & security',
            'hint' => 'Who can sign in, what they may do, how other systems connect, and how the organisation\'s data is protected.',
            'modules' => ['user', 'role', 'audit', 'security', 'sso', 'data', 'api_key', 'integration', 'webhook']],
        'platform' => ['label' => 'Platform (Markedge operators)',
            'hint' => 'Rights for the people who run PeopleOS itself. Roles inside an organisation never need these.',
            'modules' => ['tenant']],
    ];

    /**
     * Module (a key's first segment) => [name, what it covers].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MODULES = [
        'employee' => ['Employees', 'Seeing, hiring, editing and moving employee records. Bank and statutory details need the separate “sensitive” rights.'],
        'onboarding' => ['Onboarding', 'Joining plans and their tasks for new hires.'],
        'document' => ['Employee documents', 'Uploading, verifying and sharing employee documents.'],
        'bgv' => ['Background verification', 'Background-check cases and their results.'],
        'letter' => ['Letters', 'Letter templates and issuing letters such as offer, experience and relieving letters.'],
        'exit' => ['Exits', 'Resignations, clearances, exit interviews and full & final settlement.'],
        'alumni' => ['Alumni', 'Former employees: their profiles, documents and requests.'],
        'bulk' => ['Bulk actions', 'Acting on many records at once; each record still checks its own permission.'],
        'attendance' => ['Attendance', 'Shifts, rosters, punches, regularisation and processing attendance.'],
        'leave' => ['Leave', 'Applying for and approving leave, setting up leave types and adjusting balances.'],
        'payroll' => ['Payroll', 'Salary set-up, running, approving and closing payroll, payslips, loans and advances.'],
        'compliance' => ['Statutory compliance', 'PF, ESI, PT, LWF and TDS: registrations, returns, challans and compliance reports.'],
        'compensation' => ['Compensation', 'Salaries and pay changes: proposing, reviewing, approving and putting them into effect.'],
        'expense' => ['Expenses & travel', 'Expense claims, advances and travel: filing, approving, paying and setting up.'],
        'asset' => ['Assets', 'Laptops, phones and other company assets: the register, handing over and requests.'],
        'performance' => ['Performance', 'Goals, check-ins, appraisals, continuous and 360 feedback, and improvement plans.'],
        'feedback' => ['Feedback templates', 'Question banks and feedback templates used in reviews and 360s.'],
        'learning' => ['Learning', 'Courses, learning paths, training sessions, certificates and their costs.'],
        'skills' => ['Skills', 'The skill library and assessing people\'s skills.'],
        'development' => ['Development plans', 'Personal development plans: one\'s own, the team\'s or everyone\'s.'],
        'career' => ['Careers', 'Career tracks and paths, and people\'s career goals and aspirations.'],
        'talent' => ['Talent pools', 'Talent pools, talent assessments and talent review sessions.'],
        'succession' => ['Succession', 'Critical positions and who could step into them.'],
        'workforce' => ['Positions & workforce plans', 'Positions, vacancies, headcount plans, scenarios and budgets.'],
        'project' => ['Customers & projects', 'Customers, projects and who works on them.'],
        'company' => ['Companies', 'The companies your organisation runs.'],
        'legal_entity' => ['Legal entities', 'Registered legal entities and checking their details.'],
        'establishment' => ['Establishments', 'Registered establishments (offices, factories) and who belongs to each.'],
        'organisation' => ['Organisation units', 'Locations, business units, departments, teams and cost centres, and the Organisation Designer.'],
        'people_setup' => ['People set-up', 'Levels, grades, designations, job families and employment types.'],
        'partner' => ['Business partners & vendors', 'Vendors and partners, and their sign-in.'],
        'task' => ['Tasks & approvals', 'The task inbox: approving, completing, reassigning, and handing work over while away.'],
        'workflow' => ['Workflows', 'Approval and process flows: building, publishing and running them.'],
        'form' => ['Forms', 'Custom forms and their submissions.'],
        'policy' => ['Policies', 'Policies and the rules that decide whom they apply to.'],
        'notification' => ['Notifications', 'E-mail and in-app notification templates, rules and the delivery log.'],
        'servicedesk' => ['HR help desk', 'HR tickets: raising them, working on them, and setting up categories and SLAs.'],
        'grievance' => ['Grievances', 'Raising and handling grievance cases.'],
        'kb' => ['Knowledge base', 'Help articles: reading, writing and approving them.'],
        'communication' => ['Announcements', 'Announcements, circulars and newsletters.'],
        'engagement' => ['Surveys & engagement', 'Surveys, campaigns, the mood bot, company events and their results.'],
        'celebration' => ['Birthdays', 'Birthdays and anniversaries, and wishing colleagues.'],
        'recognition' => ['Recognition', 'Badges, recognitions and reward points.'],
        'wall' => ['Social wall', 'Posts, reactions and comments on the company social wall.'],
        'analytics' => ['Reports & dashboards', 'Dashboards, reports and exporting their results.'],
        'ai' => ['AI assistants', 'The employee, manager and HR assistants, the payroll auditor and their governance.'],
        'warehouse' => ['Data warehouse', 'Sending datasets to the data warehouse feed.'],
        'settings' => ['Organisation settings', 'Organisation-wide settings in Setup.'],
        'features' => ['Features', 'Turning product features on or off.'],
        'custom_field' => ['Custom fields', 'Extra fields added to records.'],
        'configuration' => ['Change Centre', 'Proposing, approving, publishing and rolling back configuration changes.'],
        'blueprint' => ['Blueprints', 'Exporting and importing ready-made configuration packs.'],
        'currency' => ['Currencies', 'Exchange rates and the base currency.'],
        'product' => ['Product ideas', 'Suggesting improvements to PeopleOS and following them.'],
        'user' => ['Users', 'Sign-in accounts and which roles people hold.'],
        'role' => ['Roles', 'Roles and their permissions — this page.'],
        'audit' => ['Audit trail', 'The record of who changed what, and checking it has not been tampered with.'],
        'security' => ['Security policy', 'Multi-factor sign-in, passwords, sessions, the IP allowlist and data retention.'],
        'sso' => ['Single sign-on', 'Signing in through the organisation\'s identity provider.'],
        'data' => ['Full data export', 'Downloading an encrypted copy of all the organisation\'s data.'],
        'api_key' => ['API keys', 'Keys that let other systems call PeopleOS.'],
        'integration' => ['Integrations', 'Connections to other systems and the data they exchange.'],
        'webhook' => ['Webhooks', 'Messages PeopleOS sends to other systems when something happens.'],
        'tenant' => ['Organisations', 'Creating, changing and suspending customer organisations.'],
    ];

    /** The module a key belongs to: its first segment (`compliance.returns.file` → compliance). */
    public static function moduleOf(string $key): string
    {
        return Str::before($key, '.');
    }

    /** A key's action in plain words, the module left out: `leave.carry_forward` → "Carry forward". */
    public static function actionLabel(string $key): string
    {
        $action = Str::contains($key, '.') ? Str::after($key, '.') : $key;

        return Str::ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    /**
     * The catalogue's options in areas and modules, for GroupedCheckboxList. Within a module, keys keep the order of
     * `config/peopleos.php` (view, create, update … as declared); keys it does not declare follow alphabetically.
     *
     * @param  array<int|string, string>  $keysByValue  option value (a permission id) => permission key
     * @return list<array{key: string, label: string, hint: string, sections: list<array{label: string, hint: string, options: list<int|string>}>}>
     */
    public static function groups(array $keysByValue): array
    {
        $order = array_flip(array_keys(array_merge(...array_values((array) config('peopleos.permissions', [])))));
        $byModule = [];
        foreach ($keysByValue as $value => $key) {
            $byModule[self::moduleOf((string) $key)][$value] = (string) $key;
        }
        foreach ($byModule as $module => $keys) {
            uasort($keys, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
            $byModule[$module] = $keys;
        }

        $section = fn (string $module) => [
            'label' => self::MODULES[$module][0] ?? Str::headline($module),
            'hint' => self::MODULES[$module][1] ?? '',
            'options' => array_keys($byModule[$module]),
        ];

        $groups = [];
        $placed = [];
        foreach (self::AREAS as $area => $definition) {
            $sections = [];
            foreach ($definition['modules'] as $module) {
                if (isset($byModule[$module])) {
                    $sections[] = $section($module);
                    $placed[$module] = true;
                }
            }
            if ($sections !== []) {
                $groups[] = ['key' => $area, 'label' => $definition['label'], 'hint' => $definition['hint'], 'sections' => $sections];
            }
        }

        $rest = array_diff(array_keys($byModule), array_keys($placed));
        sort($rest);
        if ($rest !== []) {
            $groups[] = ['key' => 'other', 'label' => 'Other permissions', 'hint' => 'Permissions not filed under an area yet.',
                'sections' => array_map($section, $rest)];
        }

        return $groups;
    }
}
