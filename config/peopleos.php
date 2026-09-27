<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Attendance\Adapters\EsslAdapter;
use App\Domain\Attendance\Adapters\GenericJsonAdapter;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Providers\ManualProvider;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Channels\EmailChannel;
use App\Domain\Notifications\Channels\InAppChannel;
use App\Domain\Notifications\Channels\LogChannel;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\ProfitCentre;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Payroll\Models\EmployeeSalaryAssignment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Models\TenantSetting;

/*
|--------------------------------------------------------------------------
| PeopleOS platform catalogue
|--------------------------------------------------------------------------
| Platform-owned definitions that tenants consume but never edit: the permission catalogue,
| default (system) role templates, default feature flags and default settings. Tenant
| administrators configure *their* roles, feature toggles and setting values on top of this.
*/

return [

    'name' => env('PEOPLEOS_NAME', 'Markedge PeopleOS'),

    /*
    | resource.action keys, grouped by module. Synced to the permissions table by
    | `php artisan peopleos:sync-permissions`.
    */
    'permissions' => [
        'tenant' => [
            'tenant.view' => 'View tenants',
            'tenant.create' => 'Create tenants',
            'tenant.update' => 'Update tenants',
            'tenant.suspend' => 'Suspend or reactivate tenants',
        ],
        'company' => [
            'company.view' => 'View companies / legal entities',
            'company.create' => 'Create companies',
            'company.update' => 'Update companies',
            'company.delete' => 'Delete companies',
        ],
        'user' => [
            'user.view' => 'View users',
            'user.create' => 'Create users',
            'user.update' => 'Update users',
            'user.delete' => 'Delete users',
            'user.assign_roles' => 'Assign roles to users',
        ],
        'role' => [
            'role.view' => 'View roles and permissions',
            'role.create' => 'Create roles',
            'role.update' => 'Update roles and their permissions',
            'role.delete' => 'Delete roles',
        ],
        'settings' => [
            'settings.view' => 'View tenant settings',
            'settings.update' => 'Update tenant settings',
        ],
        'features' => [
            'features.view' => 'View feature flags',
            'features.update' => 'Toggle feature flags',
        ],
        'audit' => [
            'audit.view' => 'View audit and change history',
            'audit.verify' => 'Verify audit chain integrity',
        ],
        'organisation' => [
            'organisation.view' => 'View organisation structure (locations, business units, divisions, departments, teams, cost/profit centres)',
            'organisation.create' => 'Create organisation units',
            'organisation.update' => 'Update organisation units',
            'organisation.delete' => 'Delete organisation units',
            'organisation.design' => 'Use the Organisation Designer to build and rearrange the hierarchy',
        ],
        'people_setup' => [
            'people_setup.view' => 'View people setup (levels, grades, job families, designations, categories, employment types, work modes)',
            'people_setup.create' => 'Create people setup records',
            'people_setup.update' => 'Update people setup records',
            'people_setup.delete' => 'Delete people setup records',
        ],
        'custom_field' => [
            'custom_field.view' => 'View custom field definitions',
            'custom_field.create' => 'Create custom fields',
            'custom_field.update' => 'Update custom fields',
            'custom_field.delete' => 'Delete custom fields',
        ],
        'form' => [
            'form.view' => 'View forms and submissions',
            'form.create' => 'Create forms',
            'form.update' => 'Update draft forms',
            'form.delete' => 'Delete forms',
            'form.publish' => 'Publish form versions',
            'form.submit' => 'Submit forms on behalf of employees',
            'form.approve' => 'Approve or reject submissions',
        ],
        'policy' => [
            'policy.view' => 'View policies and assignment rules',
            'policy.create' => 'Create policies and rules',
            'policy.update' => 'Update draft policy versions and rules',
            'policy.delete' => 'Delete policies and rules',
            'policy.publish' => 'Publish policy versions',
        ],
        'configuration' => [
            'configuration.view' => 'View the Configuration Change Centre',
            'configuration.update' => 'Propose configuration changes',
            'configuration.approve' => 'Approve or reject configuration changes',
            'configuration.publish' => 'Publish approved or scheduled changes now',
            'configuration.rollback' => 'Roll back a published change',
            'configuration.delete' => 'Discard draft changes',
        ],
        'blueprint' => [
            'blueprint.export' => 'Export configuration blueprints',
            'blueprint.import' => 'Import blueprints and apply configuration packs',
        ],
        'workflow' => [
            'workflow.view' => 'View workflows and their runs',
            'workflow.create' => 'Create workflows',
            'workflow.update' => 'Edit draft workflow versions',
            'workflow.delete' => 'Delete workflows',
            'workflow.publish' => 'Publish workflow versions',
            'workflow.run' => 'Start workflows manually',
            'workflow.cancel' => 'Cancel running workflows',
        ],
        'task' => [
            'task.view' => 'See the task inbox (own tasks)',
            'task.act' => 'Approve, reject or complete own tasks',
            'task.view_all' => 'See every task in the tenant',
            'task.reassign' => 'Reassign tasks to someone else',
        ],
        'onboarding' => [
            'onboarding.view' => 'View onboarding plans and templates',
            'onboarding.manage' => 'Create templates, start plans, complete or skip any task',
            'onboarding.act' => 'Complete own onboarding tasks',
        ],
        'document' => [
            'document.view' => 'View and download employee documents',
            'document.upload' => 'Upload employee documents',
            'document.verify' => 'Verify or reject documents',
            'document.delete' => 'Delete documents',
            'document.types' => 'Manage document types',
        ],
        'bgv' => [
            'bgv.view' => 'View background verification cases',
            'bgv.manage' => 'Initiate cases and record check results',
        ],
        'api_key' => [
            'api_key.manage' => 'Create and revoke integration API keys',
        ],
        'payroll' => [
            'payroll.view' => 'View payroll runs, entries and payslips (all employees)',
            'payroll.manage' => 'Configure salary components, structures, adjustments and statutory registrations',
            'payroll.calculate' => 'Create runs and calculate payroll',
            'payroll.approve' => 'Approve calculated payroll runs',
            'payroll.finalize' => 'Finalize, reopen and mark runs as paid',
            'payroll.payslip' => 'View own payslips',
        ],
        'compliance' => [
            'compliance.view' => 'View statutory rules and compliance status',
        ],
        'enterprise' => [
            'sso.manage' => 'Configure single sign-on connections',
            'webhook.manage' => 'Configure webhook endpoints and inspect deliveries',
            'security.manage' => 'Manage tenant security policy (IP allowlist, MFA, passwords, sessions, retention)',
            'currency.manage' => 'Maintain exchange rates and the base currency',
            'warehouse.export' => 'Export datasets to the data warehouse feed',
        ],
        'ai' => [
            'ai.use' => 'Ask the Employee and Policy assistants about own data and policies',
            'ai.manager' => 'Use the Manager Assistant for direct reports',
            'ai.hr' => 'Use the HR Copilot (workforce questions, pending HR work, natural-language people search)',
            'ai.payroll_auditor' => 'Run the AI Payroll Auditor on payroll runs',
            'ai.workforce' => 'Use Workforce Intelligence (trends, attrition risk, capacity)',
            'ai.admin' => 'Review AI interaction logs and governance settings',
        ],
        'analytics' => [
            'analytics.view' => 'View dashboards',
            'analytics.reports' => 'Build and run reports on datasets you are allowed to see',
            'analytics.manage' => 'Manage shared reports, schedules and dashboards',
            'analytics.export' => 'Export report results',
            'analytics.executive' => 'Open the Workforce Command Centre',
        ],
        'exit' => [
            'exit.view' => 'View exit cases, clearances and settlements',
            'exit.manage' => 'Initiate, withdraw and complete exits; create alumni',
            'exit.clear' => 'Act on clearance stages assigned to me or my role',
            'exit.settle' => 'Calculate, approve and pay full & final settlements',
            'exit.interview' => 'Conduct and read exit interviews',
            'exit.resign' => 'Submit own resignation',
        ],
        'letter' => [
            'letter.view' => 'View issued letters',
            'letter.manage' => 'Manage letter templates',
            'letter.issue' => 'Generate, approve and issue letters',
        ],
        'alumni' => [
            'alumni.view' => 'View alumni profiles and requests',
            'alumni.manage' => 'Handle alumni requests and profiles',
            'alumni.portal' => 'Use the alumni portal (own profile, documents, requests)',
        ],
        'servicedesk' => [
            'servicedesk.view' => 'View and work every HR service desk ticket (agent)',
            'servicedesk.manage' => 'Configure ticket categories, SLAs and assignment',
            'servicedesk.request' => 'Raise and follow own tickets',
        ],
        'grievance' => [
            'grievance.view' => 'View grievance cases you are assigned to or granted access to',
            'grievance.manage' => 'Configure grievance categories, assign and close cases',
            'grievance.raise' => 'Raise a grievance',
        ],
        'kb' => [
            'kb.view' => 'Read published knowledge base articles',
            'kb.manage' => 'Write and publish knowledge base articles',
        ],
        'communication' => [
            'communication.view' => 'Read announcements',
            'communication.manage' => 'Publish announcements, circulars and newsletters',
        ],
        'learning' => [
            'learning.view' => 'View courses, paths, sessions and every enrolment',
            'learning.manage' => 'Configure courses, learning paths, assessments and sessions',
            'learning.assign' => 'Assign learning to employees and mark attendance',
            'learning.learn' => 'Take assigned learning (own enrolments)',
        ],
        'asset' => [
            'asset.view' => 'View the asset register',
            'asset.manage' => 'Configure categories/models, procure, repair and dispose of assets',
            'asset.assign' => 'Assign, transfer and take back assets',
            'asset.own' => 'View and acknowledge own assets',
        ],
        'performance' => [
            'performance.view' => 'View all goals, appraisals, feedback and career data',
            'performance.manage' => 'Configure cycles, rating scales, competencies, KRA library and career paths',
            'performance.goals' => 'Manage own goals and check in on progress',
            'performance.review' => 'Complete reviews assigned to me (self, manager, peer)',
            'performance.calibrate' => 'Calibrate and finalize appraisals',
            'performance.feedback' => 'Give and request continuous feedback',
            'performance.team' => 'View and act on direct reports (goals, reviews, one-on-ones, PIPs)',
        ],
        'leave' => [
            'leave.view' => 'View leave requests, balances and the leave register',
            'leave.apply' => 'Apply for own leave and cancel own requests',
            'leave.approve' => 'Approve or reject leave requests',
            'leave.manage' => 'Configure leave types, adjust balances, encash, apply on behalf, cancel any request',
        ],
        'attendance' => [
            'attendance.view' => 'View attendance records and the exception centre',
            'attendance.manage' => 'Configure shifts, schedules, holidays and devices; record manual punches; reprocess',
            'attendance.regularise' => 'Request regularisation for own attendance',
            'attendance.approve' => 'Approve regularisations and overtime',
        ],
        'notification' => [
            'notification.view' => 'View notification templates and rules',
            'notification.update' => 'Manage notification templates and rules',
            'notification.deliveries' => 'View the notification delivery log',
        ],
        'employee' => [
            'employee.view' => 'View employees and Employee 360 (non-sensitive tabs)',
            'employee.create' => 'Hire / create employees',
            'employee.update' => 'Update employee and personal data',
            'employee.delete' => 'Delete employee records',
            'employee.position' => 'Assign positions, transfers, promotions and managers',
            'employee.lifecycle' => 'Move employees through lifecycle states',
            'employee.sensitive.view' => 'View bank and statutory details (audited)',
            'employee.import' => 'Import employees from files (upload, map, validate, approve, run)',
            'employee.sensitive.update' => 'Update bank and statutory details',
        ],
    ],

    /*
    | System roles provisioned for every tenant. Slugs are stable identifiers; tenants may rename
    | and re-permission non-locked roles, and add their own. Permissions support `module.*` and `*`.
    */
    'roles' => [
        'tenant-super-admin' => [
            'name' => 'Tenant Super Admin',
            'description' => 'Full control of the tenant.',
            'permissions' => ['*'],
        ],
        'tenant-hr-admin' => [
            'name' => 'Tenant HR Admin',
            'description' => 'Configures the HRMS for the tenant.',
            'permissions' => ['company.*', 'organisation.*', 'people_setup.*', 'employee.*', 'custom_field.*', 'form.*', 'policy.*', 'configuration.view', 'configuration.update', 'configuration.publish', 'configuration.rollback', 'configuration.delete', 'blueprint.*', 'workflow.*', 'task.*', 'notification.*', 'onboarding.*', 'document.*', 'bgv.*', 'api_key.*', 'attendance.*', 'leave.*', 'payroll.*', 'compliance.*', 'performance.*', 'learning.*', 'asset.*', 'servicedesk.*', 'grievance.*', 'kb.*', 'communication.*', 'exit.*', 'letter.*', 'alumni.*', 'analytics.*', 'ai.*', 'sso.*', 'webhook.*', 'security.*', 'currency.*', 'warehouse.*', 'user.*', 'role.view', 'settings.*', 'features.view', 'audit.view'],
        ],
        'hr-manager' => [
            'name' => 'HR Manager',
            'description' => 'Operates HR processes.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'employee.create', 'employee.update', 'employee.position', 'employee.lifecycle', 'form.view', 'form.submit', 'form.approve', 'policy.view', 'configuration.view', 'workflow.view', 'workflow.run', 'task.*', 'notification.view', 'notification.deliveries', 'onboarding.*', 'document.view', 'document.upload', 'document.verify', 'bgv.*', 'attendance.view', 'attendance.approve', 'leave.*', 'servicedesk.*', 'grievance.*', 'kb.*', 'communication.*', 'exit.*', 'letter.*', 'alumni.*', 'analytics.view', 'analytics.reports', 'analytics.export', 'ai.use', 'ai.manager', 'ai.hr', 'ai.workforce', 'user.view', 'audit.view'],
        ],
        'hr-executive' => [
            'name' => 'HR Executive',
            'description' => 'Day-to-day HR operations.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'employee.create', 'employee.update', 'task.view', 'task.act', 'onboarding.view', 'onboarding.act', 'document.view', 'document.upload', 'bgv.view', 'leave.view', 'leave.apply', 'user.view'],
        ],
        'payroll-admin' => [
            'name' => 'Payroll Admin',
            'description' => 'Runs and approves payroll.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'employee.sensitive.view', 'employee.sensitive.update', 'attendance.view', 'leave.view', 'payroll.*', 'compliance.view', 'ai.use', 'ai.payroll_auditor', 'task.view', 'task.act', 'audit.view'],
        ],
        'attendance-admin' => [
            'name' => 'Attendance Admin',
            'description' => 'Manages attendance configuration and exceptions.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'attendance.*', 'leave.view', 'policy.view'],
        ],
        'performance-admin' => [
            'name' => 'Performance Admin',
            'description' => 'Manages performance cycles.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view'],
        ],
        'asset-admin' => [
            'name' => 'Asset Admin',
            'description' => 'Manages company assets.',
            'permissions' => ['company.view', 'organisation.view', 'employee.view', 'asset.*', 'task.view', 'task.act'],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'People manager.',
            'permissions' => ['company.view', 'organisation.view', 'employee.view', 'task.view', 'task.act', 'onboarding.view', 'onboarding.act', 'attendance.view', 'attendance.approve', 'attendance.regularise', 'leave.view', 'leave.apply', 'leave.approve', 'performance.goals', 'performance.review', 'performance.feedback', 'performance.team', 'learning.learn', 'learning.assign', 'asset.own', 'servicedesk.request', 'grievance.raise', 'kb.view', 'communication.view', 'exit.clear', 'exit.resign', 'ai.use', 'ai.manager'],
        ],
        'employee' => [
            'name' => 'Employee',
            'description' => 'Standard employee access.',
            'permissions' => ['task.view', 'task.act', 'onboarding.act', 'attendance.regularise', 'leave.apply', 'payroll.payslip', 'performance.goals', 'performance.review', 'performance.feedback', 'learning.learn', 'asset.own', 'servicedesk.request', 'grievance.raise', 'kb.view', 'communication.view', 'exit.resign', 'ai.use'],
        ],
        'executive' => [
            'name' => 'Executive',
            'description' => 'Workforce Command Centre and dashboards; no transactional access.',
            'permissions' => ['analytics.view', 'analytics.executive', 'analytics.reports', 'ai.workforce', 'company.view', 'organisation.view'],
        ],
        'alumni' => [
            'name' => 'Alumni',
            'description' => 'Former employee: own documents and requests only.',
            'permissions' => ['alumni.portal', 'payroll.payslip'],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Read-only access with full audit visibility.',
            'permissions' => ['audit.*', 'company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'custom_field.view', 'form.view', 'policy.view', 'configuration.view', 'workflow.view', 'task.view_all', 'notification.view', 'notification.deliveries', 'onboarding.view', 'document.view', 'bgv.view', 'attendance.view', 'leave.view', 'payroll.view', 'compliance.view', 'performance.view', 'learning.view', 'asset.view', 'servicedesk.view', 'kb.view', 'communication.view', 'exit.view', 'letter.view', 'alumni.view', 'analytics.view', 'analytics.executive', 'ai.admin', 'webhook.manage', 'user.view', 'role.view', 'settings.view', 'features.view'],
        ],
    ],

    /*
    | Feature flags every tenant starts with. Tenants toggle these; platform adds new ones.
    */
    'features' => [
        'organisation.designer' => ['enabled' => false, 'description' => 'Visual organisation designer'],
        'audit.sensitive_access' => ['enabled' => true, 'description' => 'Audit views of sensitive fields'],
        'configuration.approval' => ['enabled' => false, 'description' => 'Route medium/high-risk configuration changes through the Change Centre for approval'],
        'ai.assistants' => ['enabled' => true, 'description' => 'Employee, Policy, Manager and HR assistants (grounded, permission-aware)'],
        'ai.payroll_auditor' => ['enabled' => true, 'description' => 'Anomaly detection on payroll runs'],
        'ai.workforce_intelligence' => ['enabled' => true, 'description' => 'Attrition-risk inference and workforce narrative'],
        'ai.llm' => ['enabled' => false, 'description' => 'Send grounded facts to the configured language-model provider for natural-language answers (off = deterministic answers only)'],
        'security.mfa' => ['enabled' => false, 'description' => 'Multi-factor authentication'],
    ],

    /*
    | Default setting values seeded per tenant.
    */
    'settings' => [
        'branding.display_name' => null,
        'branding.primary_colour' => '#f59e0b',
        'locale.date_format' => 'd M Y',
        'locale.time_format' => 'H:i',
        'security.password.min_length' => 12,
        'security.session.lifetime_minutes' => 120,
        'employee.code.prefix' => 'EMP',
        'employee.code.padding' => 5,
        'employee.probation.default_months' => 6,
        'employee.probation.reminder_days' => 14,
        'employee.joining.reminder_days' => 3,
        'documents.expiry.reminder_days' => 30,
        'attendance.punch_window_hours' => 4,
        'attendance.regularisation.window_days' => 7,
        'leave.year_start_month' => 1,
        'payroll.lop_from_attendance' => true,
        'attendance.process_on_punch' => true,
        'tenant.base_currency' => 'INR',
        'tenant.locale' => 'en',
        'security.ip_allowlist' => '',
        'security.session_idle_minutes' => 0,
        'security.password_min_length' => 10,
        'security.password_expiry_days' => 0,
        'security.mfa_required' => false,
        'retention.ai_interactions_days' => 365,
        'retention.notification_deliveries_days' => 180,
        'retention.report_runs_days' => 90,
        'exit.notice_days' => 30,
        'exit.clearance_lead_days' => 7,
        'exit.encashment_basis' => 'basic',
        'exit.notice_recovery' => true,
        'exit.it_clearance_role' => 'asset-admin',
        'exit.finance_clearance_role' => 'payroll-admin',
        'exit.hr_clearance_role' => 'tenant-hr-admin',
        'payroll.payslip.prefix' => 'PS',
        'leave.exclude_weekly_offs' => true,
        'leave.exclude_holidays' => true,
        // Lowest risk level that needs approval when the configuration.approval feature is on.
        'configuration.approval.minimum_risk' => 'medium',
    ],

    /*
    | Organisation tree. Node types wrap organisation masters; `children` lists which node types
    | may sit directly beneath each type (blueprint §9: hierarchy is configuration, not code).
    */
    'organisation' => [
        'node_types' => [
            'company' => ['label' => 'Company', 'model' => Company::class, 'root' => true,
                'children' => ['business_unit', 'division', 'department', 'location', 'team']],
            'business_unit' => ['label' => 'Business Unit', 'model' => BusinessUnit::class, 'root' => false,
                'children' => ['business_unit', 'division', 'department', 'location', 'team']],
            'division' => ['label' => 'Division', 'model' => Division::class, 'root' => false,
                'children' => ['division', 'department', 'location', 'team']],
            'department' => ['label' => 'Department', 'model' => Department::class, 'root' => false,
                'children' => ['department', 'team']],
            'team' => ['label' => 'Team', 'model' => Team::class, 'root' => false,
                'children' => ['team']],
            'location' => ['label' => 'Location', 'model' => Location::class, 'root' => false,
                'children' => ['department', 'team']],
        ],

        // Starting records every new tenant receives (a configuration pack seed, §76). Tenants edit freely.
        'defaults' => [
            'employment_types' => [
                ['code' => 'FULL_TIME', 'name' => 'Full-time'],
                ['code' => 'PART_TIME', 'name' => 'Part-time'],
                ['code' => 'FIXED_TERM', 'name' => 'Fixed-term'],
                ['code' => 'CONTRACT', 'name' => 'Contract'],
                ['code' => 'CONSULTANT', 'name' => 'Consultant'],
                ['code' => 'APPRENTICE', 'name' => 'Apprentice'],
                ['code' => 'INTERN', 'name' => 'Intern'],
            ],
            'employee_categories' => [
                ['code' => 'PERMANENT', 'name' => 'Permanent'],
                ['code' => 'PROBATIONER', 'name' => 'Probationer'],
                ['code' => 'CONTRACT', 'name' => 'Contract'],
                ['code' => 'CONSULTANT', 'name' => 'Consultant'],
                ['code' => 'INTERN', 'name' => 'Intern'],
                ['code' => 'APPRENTICE', 'name' => 'Apprentice'],
                ['code' => 'TEMPORARY', 'name' => 'Temporary'],
                ['code' => 'PART_TIME', 'name' => 'Part-time'],
            ],
            'work_modes' => [
                ['code' => 'OFFICE', 'name' => 'Office'],
                ['code' => 'REMOTE', 'name' => 'Remote'],
                ['code' => 'HYBRID', 'name' => 'Hybrid'],
                ['code' => 'FIELD', 'name' => 'Field'],
            ],
            'levels' => [
                ['code' => 'L1', 'name' => 'L1', 'rank' => 1],
                ['code' => 'L2', 'name' => 'L2', 'rank' => 2],
                ['code' => 'L3', 'name' => 'L3', 'rank' => 3],
                ['code' => 'L4', 'name' => 'L4', 'rank' => 4],
                ['code' => 'L5', 'name' => 'L5', 'rank' => 5],
                ['code' => 'L6', 'name' => 'L6', 'rank' => 6],
            ],
        ],
    ],

    /*
    | People option lists. Kept as configuration so tenants can be given different vocabularies
    | later without schema changes.
    */
    'people' => [
        'genders' => ['male' => 'Male', 'female' => 'Female', 'non_binary' => 'Non-binary', 'undisclosed' => 'Prefer not to say'],
        'marital_statuses' => ['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'widowed' => 'Widowed', 'undisclosed' => 'Prefer not to say'],
        'blood_groups' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
        'address_types' => ['current' => 'Current', 'permanent' => 'Permanent', 'correspondence' => 'Correspondence'],
        'family_relations' => ['spouse' => 'Spouse', 'father' => 'Father', 'mother' => 'Mother', 'son' => 'Son', 'daughter' => 'Daughter', 'brother' => 'Brother', 'sister' => 'Sister', 'other' => 'Other'],
        'import_max_rows' => 5000,
        'reporting_types' => ['line' => 'Line manager', 'functional' => 'Functional manager', 'dotted' => 'Dotted line', 'hrbp' => 'HR business partner', 'mentor' => 'Mentor', 'buddy' => 'Buddy', 'project' => 'Project manager', 'secondary' => 'Secondary manager'],
        'skill_proficiencies' => ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'expert' => 'Expert'],
        'position_change_types' => ['hire' => 'Hire', 'transfer' => 'Transfer', 'promotion' => 'Promotion', 'demotion' => 'Demotion', 'reassignment' => 'Reassignment', 'correction' => 'Data correction'],
    ],

    /*
    | Lifecycle state graph (blueprint §19). Keys are the states an employee may leave, values the
    | states they may enter. Protected platform logic; tenants configure *what happens* on each
    | transition (later phases), not which transitions exist.
    */
    'lifecycle' => [
        'transitions' => [
            'pre_employee' => ['preboarding', 'onboarding', 'joined'],
            'preboarding' => ['onboarding', 'joined', 'pre_employee'],
            'onboarding' => ['joined', 'probation', 'active'],
            'joined' => ['probation', 'active', 'notice_period', 'exited'],
            'probation' => ['confirmed', 'active', 'notice_period', 'exited', 'suspended'],
            'confirmed' => ['active', 'on_leave', 'suspended', 'notice_period', 'exited'],
            'active' => ['on_leave', 'suspended', 'notice_period', 'exited', 'probation'],
            'on_leave' => ['active', 'notice_period', 'exited'],
            'suspended' => ['active', 'exited'],
            'notice_period' => ['active', 'exited'],
            'exited' => ['alumni', 'active'],
            'alumni' => ['active'],
        ],
    ],

    /*
    | Custom Field Engine (§41): which entities may carry tenant-defined fields.
    */
    'custom_fields' => [
        'entities' => [
            'employee' => ['label' => 'Employee', 'model' => Employee::class],
            'company' => ['label' => 'Company', 'model' => Company::class],
            'department' => ['label' => 'Department', 'model' => Department::class],
            'location' => ['label' => 'Location', 'model' => Location::class],
            'designation' => ['label' => 'Designation', 'model' => Designation::class],
            'asset' => ['label' => 'Asset', 'model' => Asset::class],
        ],
        'types' => [
            'text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date', 'boolean' => 'Yes / No',
            'dropdown' => 'Dropdown', 'multiselect' => 'Multi-select', 'email' => 'Email', 'phone' => 'Phone', 'url' => 'URL',
        ],
    ],

    /*
    | Rule Engine (§43): the employee attributes a condition may test. Options resolve at runtime.
    */
    'rules' => [
        'fields' => [
            'company_id' => ['label' => 'Company', 'type' => 'model', 'model' => Company::class],
            'location_id' => ['label' => 'Location', 'type' => 'model', 'model' => Location::class],
            'business_unit_id' => ['label' => 'Business unit', 'type' => 'model', 'model' => BusinessUnit::class],
            'division_id' => ['label' => 'Division', 'type' => 'model', 'model' => Division::class],
            'department_id' => ['label' => 'Department', 'type' => 'model', 'model' => Department::class],
            'team_id' => ['label' => 'Team', 'type' => 'model', 'model' => Team::class],
            'designation_id' => ['label' => 'Designation', 'type' => 'model', 'model' => Designation::class],
            'level_id' => ['label' => 'Level', 'type' => 'model', 'model' => Level::class],
            'grade_id' => ['label' => 'Grade', 'type' => 'model', 'model' => Grade::class],
            'employment_type_id' => ['label' => 'Employment type', 'type' => 'model', 'model' => EmploymentType::class],
            'employee_category_id' => ['label' => 'Employee category', 'type' => 'model', 'model' => EmployeeCategory::class],
            'work_mode_id' => ['label' => 'Work mode', 'type' => 'model', 'model' => WorkMode::class],
            'lifecycle_state' => ['label' => 'Lifecycle state', 'type' => 'enum', 'enum' => LifecycleState::class],
            'gender' => ['label' => 'Gender', 'type' => 'options', 'options_key' => 'people.genders'],
            'tenure_months' => ['label' => 'Tenure (months)', 'type' => 'number'],
        ],
        'operators' => [
            'equals' => 'is', 'not_equals' => 'is not', 'in' => 'is any of', 'not_in' => 'is none of',
            'greater_than' => 'greater than', 'less_than' => 'less than', 'gte' => 'at least', 'lte' => 'at most',
            'is_empty' => 'is empty', 'is_not_empty' => 'is not empty',
        ],
    ],

    /*
    | Policy Engine (§43): policy types and the settings each carries. Later modules consume
    | these settings; this phase makes them configurable, versioned and assignable.
    */
    'policies' => [
        'types' => [
            'leave' => ['label' => 'Leave policy', 'fields' => [
                ['key' => 'count_weekly_offs', 'label' => 'Count weekly offs inside a leave span', 'type' => 'boolean'],
                ['key' => 'count_holidays', 'label' => 'Count holidays inside a leave span', 'type' => 'boolean'],
                ['key' => 'entitlements', 'label' => 'Entitlements per leave type', 'type' => 'repeater', 'fields' => [
                    ['key' => 'leave_type_code', 'label' => 'Leave type', 'type' => 'select', 'options_from' => 'leave_types'],
                    ['key' => 'days', 'label' => 'Days per year', 'type' => 'number'],
                    ['key' => 'accrual_frequency', 'label' => 'Accrual', 'type' => 'select', 'options' => ['annual' => 'Annual (credited at year start)', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly']],
                    ['key' => 'prorate_on_join', 'label' => 'Pro-rate in the joining year', 'type' => 'boolean'],
                    ['key' => 'proration', 'label' => 'Pro-ration basis', 'type' => 'select', 'options' => ['monthly' => 'Remaining months', 'daily' => 'Remaining days', 'none' => 'No pro-ration']],
                    ['key' => 'eligible_after', 'label' => 'Available', 'type' => 'select', 'options' => ['immediate' => 'Immediately', 'days' => 'After N days of service', 'months' => 'After N months of service', 'confirmation' => 'After confirmation']],
                    ['key' => 'eligible_after_value', 'label' => 'N (days or months)', 'type' => 'number'],
                    ['key' => 'carry_forward_limit', 'label' => 'Carry-forward limit (days)', 'type' => 'number'],
                    ['key' => 'carry_forward_expiry_months', 'label' => 'Carried days expire after (months, 0 = never)', 'type' => 'number'],
                    ['key' => 'encashment_allowed', 'label' => 'Encashment allowed', 'type' => 'boolean'],
                    ['key' => 'max_encash_days', 'label' => 'Max encashable days per year', 'type' => 'number'],
                    ['key' => 'probation_eligible', 'label' => 'Available during probation', 'type' => 'boolean'],
                    ['key' => 'negative_balance_limit', 'label' => 'Negative balance allowed up to (days)', 'type' => 'number'],
                    ['key' => 'half_day_allowed', 'label' => 'Half days allowed', 'type' => 'boolean'],
                    ['key' => 'min_notice_days', 'label' => 'Minimum notice (days)', 'type' => 'number'],
                    ['key' => 'max_consecutive_days', 'label' => 'Maximum consecutive days (0 = no limit)', 'type' => 'number'],
                    ['key' => 'document_required_after_days', 'label' => 'Document required beyond (days, 0 = never)', 'type' => 'number'],
                ]],
            ]],
            'attendance' => ['label' => 'Attendance policy', 'fields' => [
                ['key' => 'grace_minutes', 'label' => 'Grace (minutes)', 'type' => 'number'],
                ['key' => 'late_marks_threshold', 'label' => 'Late marks before action', 'type' => 'number'],
                ['key' => 'late_mark_action', 'label' => 'Action on threshold', 'type' => 'select', 'options' => ['warn' => 'Warn', 'half_day' => 'Deduct half day', 'lop' => 'Loss of pay']],
                ['key' => 'regularisation_allowed', 'label' => 'Regularisation allowed', 'type' => 'boolean'],
                ['key' => 'regularisation_window_days', 'label' => 'Regularisation window (days)', 'type' => 'number'],
                ['key' => 'manager_approval_required', 'label' => 'Manager approval required', 'type' => 'boolean'],
            ]],
            'overtime' => ['label' => 'Overtime policy', 'fields' => [
                ['key' => 'minimum_minutes', 'label' => 'Minimum OT (minutes)', 'type' => 'number'],
                ['key' => 'max_daily_minutes', 'label' => 'Maximum OT per day (minutes)', 'type' => 'number'],
                ['key' => 'rounding_minutes', 'label' => 'Round OT down to (minutes)', 'type' => 'number'],
                ['key' => 'weekday_rate', 'label' => 'Weekday rate (x)', 'type' => 'number'],
                ['key' => 'weekend_rate', 'label' => 'Weekend rate (x)', 'type' => 'number'],
                ['key' => 'holiday_rate', 'label' => 'Holiday rate (x)', 'type' => 'number'],
                ['key' => 'max_hours_per_week', 'label' => 'Maximum hours per week', 'type' => 'number'],
                ['key' => 'approval_required', 'label' => 'Approval required', 'type' => 'boolean'],
            ]],
            'work_schedule' => ['label' => 'Working hours policy', 'fields' => [
                ['key' => 'daily_hours', 'label' => 'Daily hours', 'type' => 'number'],
                ['key' => 'weekly_hours', 'label' => 'Weekly hours', 'type' => 'number'],
                ['key' => 'working_days', 'label' => 'Working days', 'type' => 'multiselect', 'options' => ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun']],
                ['key' => 'flexible_timing', 'label' => 'Flexible timing', 'type' => 'boolean'],
            ]],
            'exit' => ['label' => 'Exit policy', 'fields' => [
                ['key' => 'notice_period_days', 'label' => 'Notice period (days)', 'type' => 'number'],
                ['key' => 'notice_buyout_allowed', 'label' => 'Notice buy-out allowed', 'type' => 'boolean'],
            ]],
            'general' => ['label' => 'General policy', 'fields' => []],
        ],
    ],

    /*
    | Configuration Change Centre (§71). Risk decides whether a change waits for approval.
    */
    'configuration' => [
        'risk' => [
            'low' => [
                Course::class, LearningPath::class,
                AssetCategory::class, AssetModel::class,
                Competency::class, Kra::class, CareerPath::class,
                Designation::class, Department::class,
                Team::class, Location::class,
                EmployeeCategory::class, EmploymentType::class,
                WorkMode::class, JobFamily::class,
                Level::class, Grade::class,
                Skill::class,
            ],
            'medium' => [
                SalaryComponent::class, SalaryStructure::class,
                CompanyStatutoryProfile::class,
                Company::class, BusinessUnit::class,
                Division::class, CostCentre::class,
                ProfitCentre::class,
                CustomField::class, Form::class,
                Policy::class, PolicyVersion::class,
                PolicyAssignmentRule::class,
            ],
            'high' => [
                SsoConnection::class, WebhookEndpoint::class,
                Role::class, TenantSetting::class,
                TenantFeature::class,
            ],
        ],
        'packs_path' => resource_path('packs'),
    ],

    /*
    | Workflow Platform (§44–§46).
    */
    'workflows' => [
        'node_types' => [
            'start' => 'Start', 'approval' => 'Approval', 'condition' => 'Condition', 'task' => 'Task',
            'notification' => 'Notification', 'wait' => 'Wait', 'webhook' => 'Webhook', 'automation' => 'Update record',
            'document' => 'Generate document', 'end' => 'End',
        ],
        'approver_types' => [
            'manager' => 'Line manager of the subject employee', 'hierarchy_level' => 'Manager N levels up',
            'role' => 'Anyone holding a role', 'user' => 'A specific user', 'initiator_manager' => "Initiator's line manager", 'field' => 'User id from a context field',
        ],
        'approval_modes' => ['single' => 'Single approver', 'sequential' => 'One after another', 'parallel' => 'All must approve', 'majority' => 'Majority decides'],
        'escalation_actions' => ['remind' => 'Send a reminder', 'escalate_to_manager' => "Escalate to the assignee's manager", 'escalate_to_role' => 'Escalate to a role'],
        'trigger_events' => [
            'manual' => 'Started manually',
            'employee.joined' => 'Employee joined', 'employee.probation' => 'Probation started', 'employee.confirmed' => 'Employee confirmed',
            'employee.notice_period' => 'Resignation / notice period', 'employee.exited' => 'Employee exited', 'employee.alumni' => 'Alumni created',
            'form.submitted' => 'Form submitted', 'configuration.change.proposed' => 'Configuration change proposed',
            'employee.pre_employee' => 'Pre-employee created (from RMS or manually)', 'employee.preboarding' => 'Preboarding started',
            'employee.joining_due' => 'Joining date approaching', 'employee.probation_ending' => 'Probation ending soon', 'employee.probation_overdue' => 'Probation end date passed',
            'onboarding.completed' => 'Onboarding plan completed', 'bgv.completed' => 'Background verification completed', 'document.expiring' => 'Document expiring',
            'attendance.regularisation_requested' => 'Attendance regularisation requested', 'attendance.overtime_recorded' => 'Overtime recorded',
            'servicedesk.ticket.created' => 'Service desk ticket created', 'grievance.raised' => 'Grievance raised',
            'leave.requested' => 'Leave requested', 'leave.approved' => 'Leave approved', 'leave.cancelled' => 'Leave cancelled',
            'payroll.finalized' => 'Payroll finalized', 'payroll.paid' => 'Payroll paid',
            'performance.appraisal.finalized' => 'Appraisal finalized', 'performance.pip.opened' => 'Improvement plan opened', 'performance.promotion.recommended' => 'Promotion recommended',
            'learning.completed' => 'Learning completed', 'learning.overdue' => 'Learning overdue', 'asset.assigned' => 'Asset assigned', 'asset.returned' => 'Asset returned',
            'servicedesk.ticket.created' => 'Service desk ticket created', 'servicedesk.ticket.resolved' => 'Service desk ticket resolved', 'grievance.raised' => 'Grievance raised',
            'exit.initiated' => 'Exit initiated', 'exit.clearance.pending' => 'Clearance stage pending', 'exit.completed' => 'Exit completed', 'letter.issued' => 'Letter issued', 'alumni.request.created' => 'Alumni request',
        ],
        'subject_types' => [
            Employee::class => 'Employee',
            FormSubmission::class => 'Form submission',
            ConfigurationChange::class => 'Configuration change',
        ],
        'default_sla_hours' => 48,
    ],

    /*
    | Notification Engine (§47): Event -> Rule -> Audience -> Channel -> Template -> Delivery -> Tracking.
    */
    'notifications' => [
        'channels' => [
            'in_app' => ['label' => 'In-app', 'driver' => InAppChannel::class],
            'email' => ['label' => 'Email', 'driver' => EmailChannel::class],
            'sms' => ['label' => 'SMS', 'driver' => LogChannel::class],
            'whatsapp' => ['label' => 'WhatsApp', 'driver' => LogChannel::class],
            'push' => ['label' => 'Push', 'driver' => LogChannel::class],
        ],
        'audience_types' => [
            'subject' => 'The subject employee', 'manager' => "Subject's line manager", 'initiator' => 'Who started it',
            'role' => 'Everyone with a role', 'user' => 'A specific user', 'assignee' => 'Task assignee',
        ],
        'events' => [
            'employee.joined', 'employee.probation', 'employee.confirmed', 'employee.notice_period', 'employee.exited', 'employee.alumni',
            'form.submitted', 'form.reviewed', 'configuration.change.proposed', 'configuration.change.published',
            'workflow.task.assigned', 'workflow.task.reminder', 'workflow.task.escalated', 'workflow.completed',
            'employee.pre_employee', 'employee.preboarding', 'employee.joining_due', 'employee.probation_ending', 'employee.probation_overdue',
            'employee.created', 'employee.transferred', 'employee.promoted', 'employee.manager_changed', 'employee.salary_changed', 'employee.department_changed', 'employee.designation_changed', 'employee.location_changed', 'employee.company_changed', 'employee.rehired',
            'onboarding.started', 'onboarding.task.assigned', 'onboarding.completed', 'bgv.initiated', 'bgv.completed', 'document.uploaded', 'document.verified', 'document.rejected', 'document.expiring',
            'attendance.regularisation_requested', 'attendance.regularisation_approved', 'attendance.regularisation_rejected', 'attendance.overtime_recorded', 'attendance.exception',
            'attendance.punch_received', 'attendance.processed', 'attendance.status_changed', 'attendance.overtime_approved', 'attendance.overtime_rejected', 'attendance.regularisation_cancelled',
            'leave.requested', 'leave.approved', 'leave.rejected', 'leave.cancelled', 'leave.cancel_requested', 'leave.cancellation_rejected', 'leave.balance_changed', 'leave.expired', 'leave.carried_forward', 'leave.encashment_requested', 'leave.encashment_approved', 'leave.balance_adjusted', 'leave.accrued',
            'payroll.calculated', 'payroll.approved', 'payroll.finalized', 'payroll.paid', 'payroll.payslip_generated', 'payroll.exception',
            'performance.cycle.launched', 'performance.cycle.stage_changed', 'performance.review.assigned', 'performance.review.submitted', 'performance.appraisal.finalized',
            'performance.feedback.received', 'performance.feedback.requested', 'performance.goal.assigned', 'performance.pip.opened', 'performance.promotion.recommended',
            'learning.assigned', 'learning.due_soon', 'learning.overdue', 'learning.completed', 'learning.failed', 'learning.certificate_expiring', 'learning.session.registered',
            'asset.assigned', 'asset.returned', 'asset.transferred', 'asset.repair', 'asset.disposed', 'asset.lost',
            'servicedesk.ticket.created', 'servicedesk.ticket.assigned', 'servicedesk.ticket.commented', 'servicedesk.ticket.resolved', 'servicedesk.ticket.closed', 'servicedesk.ticket.escalated', 'servicedesk.ticket.reopened',
            'grievance.raised', 'grievance.assigned', 'grievance.updated', 'grievance.resolved', 'grievance.escalated',
            'kb.article.published', 'communication.published',
            'exit.initiated', 'exit.withdrawn', 'exit.clearance.pending', 'exit.clearance.cleared', 'exit.clearance.blocked', 'exit.settlement.calculated', 'exit.settlement.approved', 'exit.settlement.paid', 'exit.interview.submitted', 'exit.completed', 'exit.alumni_created',
            'letter.requested', 'letter.approved', 'letter.issued', 'alumni.request.created', 'alumni.request.handled',
        ],
    ],

    /*
    | Onboarding (§21), documents (§39) and background verification (§22).
    */
    'onboarding' => [
        // Phase key => [label, default due offset in days from joining date]
        'phases' => [
            'preboarding' => ['label' => 'Preboarding', 'offset' => -3],
            'day_one' => ['label' => 'Day 1', 'offset' => 0],
            'week_one' => ['label' => 'First week', 'offset' => 5],
            'day_30' => ['label' => '30 days', 'offset' => 30],
            'day_60' => ['label' => '60 days', 'offset' => 60],
            'day_90' => ['label' => '90 days', 'offset' => 90],
        ],
        'owner_types' => ['employee' => 'The employee', 'manager' => 'Line manager', 'buddy' => 'Buddy', 'hrbp' => 'HR business partner', 'role' => 'A role', 'user' => 'A specific user'],
        'item_types' => ['task' => 'Checklist item', 'document' => 'Collect a document', 'form' => 'Fill a form', 'acknowledgement' => 'Policy acknowledgement'],
    ],
    'documents' => [
        'categories' => ['identity' => 'Identity', 'education' => 'Education', 'employment' => 'Previous employment', 'tax' => 'Tax', 'statutory' => 'Statutory', 'company' => 'Company', 'training' => 'Training', 'medical' => 'Medical', 'exit' => 'Exit', 'other' => 'Other'],
        'disk' => env('PEOPLEOS_DOCUMENTS_DISK', 'local'),
        'max_kb' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
        'sensitive_categories' => ['identity', 'tax', 'statutory', 'medical'],
        'defaults' => [
            ['code' => 'PAN', 'name' => 'PAN card', 'category' => 'identity', 'requires_expiry' => false, 'mandatory_for_onboarding' => true],
            ['code' => 'AADHAAR', 'name' => 'Aadhaar', 'category' => 'identity', 'requires_expiry' => false, 'mandatory_for_onboarding' => true],
            ['code' => 'PASSPORT', 'name' => 'Passport', 'category' => 'identity', 'requires_expiry' => true, 'mandatory_for_onboarding' => false],
            ['code' => 'ADDRESS_PROOF', 'name' => 'Address proof', 'category' => 'identity', 'requires_expiry' => false, 'mandatory_for_onboarding' => true],
            ['code' => 'DEGREE', 'name' => 'Highest degree certificate', 'category' => 'education', 'requires_expiry' => false, 'mandatory_for_onboarding' => true],
            ['code' => 'RELIEVING', 'name' => 'Previous relieving letter', 'category' => 'employment', 'requires_expiry' => false, 'mandatory_for_onboarding' => false],
            ['code' => 'PAYSLIP', 'name' => 'Previous payslips', 'category' => 'employment', 'requires_expiry' => false, 'mandatory_for_onboarding' => false],
            ['code' => 'BANK_PROOF', 'name' => 'Cancelled cheque / bank proof', 'category' => 'statutory', 'requires_expiry' => false, 'mandatory_for_onboarding' => true],
            ['code' => 'OFFER', 'name' => 'Signed offer letter', 'category' => 'company', 'requires_expiry' => false, 'mandatory_for_onboarding' => true],
            ['code' => 'BGV_CONSENT', 'name' => 'BGV consent', 'category' => 'company', 'requires_expiry' => false, 'mandatory_for_onboarding' => true],
        ],
    ],
    'bgv' => [
        'check_types' => ['identity' => 'Identity', 'address' => 'Address', 'education' => 'Education', 'employment' => 'Previous employment', 'reference' => 'Reference', 'criminal' => 'Criminal record (where lawful)', 'document' => 'Document verification'],
        'providers' => [
            'manual' => ['label' => 'Manual (in-house)', 'driver' => ManualProvider::class],
        ],
        'default_checks' => ['identity', 'address', 'education', 'employment'],
    ],
    'api' => [
        'scopes' => [
            'rms.write' => 'Create pre-employees from recruitment', 'rms.read' => 'Read pre-employee status', 'bgv.write' => 'Post background verification results', 'attendance.write' => 'Push attendance punches (devices or any source) and raise regularisations',
            'employees.read' => 'Read employees and positions', 'employees.write' => 'Create employees and change lifecycle state', 'employees.sensitive.read' => 'Read sensitive employee fields (personal contacts, statutory ids, bank) — audited', 'organisation.read' => 'Read organisation reference data by code', 'attendance.read' => 'Read attendance records, exceptions, regularisations, shifts and schedules', 'leave.read' => 'Read leave types, balances, transactions, requests and the leave calendar', 'leave.write' => 'Submit and cancel leave requests on behalf of employees', 'payroll.read' => 'Read payroll runs and payslips (sensitive)',
            'documents.read' => 'Read document metadata', 'assets.read' => 'Read the asset register', 'performance.read' => 'Read appraisals and goals', 'workflows.read' => 'Read workflow instances and tasks',
            'reports.run' => 'Run saved reports', 'scim' => 'SCIM 2.0 user provisioning', 'webhooks.read' => 'Read webhook deliveries',
        ],
    ],

    /*
    | Payroll (§29–§31). Component vocabulary and the default (editable) component catalogue.
    | Statutory components are created by the compliance pack and locked for tenants (§32).
    */
    'payroll' => [
        'component_types' => ['earning' => 'Earning', 'deduction' => 'Deduction', 'employer_contribution' => 'Employer contribution', 'reimbursement' => 'Reimbursement (non-taxable)'],
        'calculation_methods' => ['fixed' => 'Fixed amount from the salary assignment', 'formula' => 'Formula', 'statutory' => 'Statutory (compliance pack)'],
        'classifications' => [
            'basic' => 'Basic', 'da' => 'Dearness allowance', 'hra' => 'House rent allowance', 'allowance' => 'Allowance', 'bonus' => 'Bonus',
            'incentive' => 'Incentive / commission', 'reimbursement' => 'Reimbursement', 'pf_employee' => 'PF (employee)', 'pf_employer' => 'PF (employer)',
            'esi_employee' => 'ESI (employee)', 'esi_employer' => 'ESI (employer)', 'pt' => 'Professional tax', 'lwf_employee' => 'LWF (employee)',
            'lwf_employer' => 'LWF (employer)', 'tds' => 'Income tax (TDS)', 'gratuity' => 'Gratuity provision', 'other_deduction' => 'Other deduction', 'other' => 'Other',
        ],
        'run_statuses' => ['draft' => 'Draft', 'calculated' => 'Calculated', 'validated' => 'Validated', 'approved' => 'Approved', 'finalized' => 'Finalized', 'paid' => 'Paid'],
        'exception_types' => ['no_salary' => 'No salary assignment', 'no_bank' => 'No primary bank account', 'no_pan' => 'PAN missing (TDS applies)', 'negative_net' => 'Negative net pay', 'attendance_missing' => 'Attendance not processed', 'no_structure' => 'Salary structure has no components', 'formula_error' => 'Formula error'],
        'defaults' => [
            ['code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning', 'classification' => 'basic', 'calculation_method' => 'formula', 'formula' => 'ctc_monthly * 0.40', 'taxable' => true, 'pf_applicable' => true, 'esi_applicable' => true, 'include_in_ctc' => true, 'include_in_gross' => true, 'is_proratable' => true, 'sort_order' => 10],
            ['code' => 'HRA', 'name' => 'House rent allowance', 'type' => 'earning', 'classification' => 'hra', 'calculation_method' => 'formula', 'formula' => 'basic * 0.50', 'taxable' => true, 'pf_applicable' => false, 'esi_applicable' => true, 'include_in_ctc' => true, 'include_in_gross' => true, 'is_proratable' => true, 'sort_order' => 20],
            ['code' => 'CONV', 'name' => 'Conveyance allowance', 'type' => 'earning', 'classification' => 'allowance', 'calculation_method' => 'fixed', 'formula' => null, 'taxable' => true, 'pf_applicable' => false, 'esi_applicable' => true, 'include_in_ctc' => true, 'include_in_gross' => true, 'is_proratable' => true, 'sort_order' => 30],
            ['code' => 'SPECIAL', 'name' => 'Special allowance', 'type' => 'earning', 'classification' => 'allowance', 'calculation_method' => 'formula', 'formula' => 'max(0, ctc_monthly - basic - hra - conv - pf_employer)', 'taxable' => true, 'pf_applicable' => false, 'esi_applicable' => true, 'include_in_ctc' => true, 'include_in_gross' => true, 'is_proratable' => true, 'sort_order' => 90],
            ['code' => 'BONUS', 'name' => 'Bonus', 'type' => 'earning', 'classification' => 'bonus', 'calculation_method' => 'fixed', 'formula' => null, 'taxable' => true, 'pf_applicable' => false, 'esi_applicable' => false, 'include_in_ctc' => false, 'include_in_gross' => false, 'is_proratable' => false, 'sort_order' => 100],
            ['code' => 'ADV', 'name' => 'Salary advance recovery', 'type' => 'deduction', 'classification' => 'other_deduction', 'calculation_method' => 'fixed', 'formula' => null, 'taxable' => false, 'pf_applicable' => false, 'esi_applicable' => false, 'include_in_ctc' => false, 'include_in_gross' => false, 'is_proratable' => false, 'sort_order' => 300],
        ],
    ],

    /*
    | Performance & talent (§34–§36). Vocabulary and the defaults every tenant receives.
    */
    'performance' => [
        'cycle_types' => ['annual' => 'Annual', 'half_yearly' => 'Half-yearly', 'quarterly' => 'Quarterly', 'probation' => 'Probation', 'project' => 'Project'],
        'stages' => ['goal_setting' => 'Goal setting', 'self_review' => 'Self review', 'manager_review' => 'Manager review', 'peer_review' => 'Peer / 360 review', 'calibration' => 'Calibration', 'final' => 'Final rating'],
        'goal_levels' => ['company' => 'Company goal', 'business' => 'Business goal', 'department' => 'Department goal', 'team' => 'Team goal', 'employee' => 'Employee goal'],
        'goal_types' => ['goal' => 'Goal', 'okr' => 'Objective (OKR)', 'kra' => 'KRA', 'kpi' => 'KPI'],
        'measure_types' => ['percentage' => 'Percentage', 'numeric' => 'Number', 'currency' => 'Amount', 'boolean' => 'Done / not done', 'milestone' => 'Milestones'],
        'goal_statuses' => ['draft' => 'Draft', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
        'review_types' => ['self' => 'Self', 'manager' => 'Manager', 'peer' => 'Peer', 'upward' => 'Upward (direct report)', 'skip_level' => 'Skip level', 'external' => 'External stakeholder'],
        'appraisal_statuses' => ['pending' => 'Pending', 'in_review' => 'In review', 'calibration' => 'Calibration', 'finalized' => 'Finalized', 'acknowledged' => 'Acknowledged'],
        'feedback_types' => ['praise' => 'Praise', 'constructive' => 'Constructive', 'request' => 'Feedback request'],
        'feedback_visibility' => ['private' => 'Only the recipient', 'manager' => 'Recipient and their manager', 'public' => 'Everyone in the tenant'],
        'competency_categories' => ['core' => 'Core', 'functional' => 'Functional', 'leadership' => 'Leadership', 'behavioural' => 'Behavioural'],
        'pip_statuses' => ['draft' => 'Draft', 'active' => 'Active', 'extended' => 'Extended', 'completed' => 'Completed successfully', 'unsuccessful' => 'Unsuccessful', 'withdrawn' => 'Withdrawn'],
        'default_weights' => ['goals' => 70, 'competencies' => 30],
        'defaults' => [
            'rating_scale' => ['name' => 'Five-point scale', 'code' => 'FIVE_POINT', 'levels' => [
                ['value' => 1, 'label' => 'Needs Improvement', 'description' => 'Consistently below expectations'],
                ['value' => 2, 'label' => 'Developing', 'description' => 'Partially meets expectations'],
                ['value' => 3, 'label' => 'Meets Expectations', 'description' => 'Consistently meets expectations'],
                ['value' => 4, 'label' => 'Exceeds Expectations', 'description' => 'Frequently exceeds expectations'],
                ['value' => 5, 'label' => 'Exceptional', 'description' => 'Role model; consistently far exceeds expectations'],
            ]],
            'competencies' => [
                ['code' => 'OWNERSHIP', 'name' => 'Ownership & accountability', 'category' => 'core'],
                ['code' => 'COLLAB', 'name' => 'Collaboration', 'category' => 'core'],
                ['code' => 'COMMS', 'name' => 'Communication', 'category' => 'behavioural'],
                ['code' => 'CUSTOMER', 'name' => 'Customer focus', 'category' => 'core'],
                ['code' => 'LEARN', 'name' => 'Learning agility', 'category' => 'behavioural'],
                ['code' => 'LEAD', 'name' => 'Leading others', 'category' => 'leadership'],
            ],
        ],
    ],

    /*
    | Enterprise & international (§87, §96, §109, §110).
    */
    'enterprise' => [
        'sso_providers' => ['entra' => 'Microsoft Entra ID', 'google' => 'Google Workspace', 'okta' => 'Okta', 'oidc' => 'Generic OpenID Connect'],
        'sso_presets' => [
            'entra' => ['authorization' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize', 'token' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token', 'userinfo' => 'https://graph.microsoft.com/oidc/userinfo', 'scopes' => 'openid profile email'],
            'google' => ['authorization' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo', 'scopes' => 'openid profile email'],
            'okta' => ['authorization' => 'https://{domain}/oauth2/v1/authorize', 'token' => 'https://{domain}/oauth2/v1/token', 'userinfo' => 'https://{domain}/oauth2/v1/userinfo', 'scopes' => 'openid profile email'],
        ],
        'webhook_events' => [
            'employee.created', 'employee.joined', 'employee.probation', 'employee.confirmed', 'employee.notice_period', 'employee.exited', 'employee.alumni',
            'employee.salary_changed', 'employee.transferred', 'employee.promoted', 'employee.manager_changed', 'employee.rehired', 'leave.requested', 'leave.approved', 'leave.rejected', 'leave.cancelled', 'attendance.regularisation_requested',
            'payroll.calculated', 'payroll.approved', 'payroll.finalized', 'payroll.paid', 'performance.appraisal.finalized', 'learning.completed',
            'asset.assigned', 'asset.returned', 'servicedesk.ticket.created', 'servicedesk.ticket.resolved', 'grievance.raised', 'exit.initiated', 'exit.completed',
            'letter.issued', 'workflow.completed', 'document.expiring',
        ],
        'webhook_max_attempts' => 5,
        'tiers' => ['shared' => 'Shared infrastructure', 'dedicated' => 'Dedicated tenant infrastructure'],
        'regions' => ['in' => 'India', 'eu' => 'European Union', 'us' => 'United States', 'ae' => 'UAE', 'sg' => 'Singapore', 'au' => 'Australia'],
        'locales' => ['en' => 'English', 'en-IN' => 'English (India)', 'en-GB' => 'English (UK)', 'en-US' => 'English (US)', 'hi' => 'हिन्दी', 'ar' => 'العربية'],
        'warehouse_disk' => env('PEOPLEOS_WAREHOUSE_DISK', 'local'),
    ],

    /*
    | AI & intelligence (§93–§95). Assistants answer only from permission-filtered domain data; the
    | optional language model receives those facts, never raw tables, and can never write.
    */
    'ai' => [
        'provider' => env('PEOPLEOS_AI_PROVIDER', 'none'),
        'anthropic' => ['key' => env('ANTHROPIC_API_KEY'), 'model' => env('PEOPLEOS_AI_MODEL', 'claude-sonnet-5'), 'max_tokens' => 700, 'timeout' => 20, 'endpoint' => env('PEOPLEOS_AI_ENDPOINT', 'https://api.anthropic.com/v1/messages'), 'version' => '2023-06-01'],
        'redact_identifiers' => true,
        'history_limit' => 20,
        'assistants' => [
            'employee' => ['label' => 'Employee Assistant', 'permission' => 'ai.use', 'feature' => 'ai.assistants', 'description' => 'Leave, attendance, payslips, requests, policies — for you'],
            'policy' => ['label' => 'Policy Assistant', 'permission' => 'ai.use', 'feature' => 'ai.assistants', 'description' => 'Answers from the knowledge base and published policies'],
            'manager' => ['label' => 'Manager Assistant', 'permission' => 'ai.manager', 'feature' => 'ai.assistants', 'description' => 'Your team: attendance, leave, reviews, pending actions'],
            'hr' => ['label' => 'HR Copilot', 'permission' => 'ai.hr', 'feature' => 'ai.assistants', 'description' => 'Pending HR work and natural-language people questions'],
            'payroll_auditor' => ['label' => 'Payroll Auditor', 'permission' => 'ai.payroll_auditor', 'feature' => 'ai.payroll_auditor', 'description' => 'Anomalies in the latest payroll run'],
            'workforce' => ['label' => 'Workforce Analyst', 'permission' => 'ai.workforce', 'feature' => 'ai.workforce_intelligence', 'description' => 'Trends, cost, attrition risk, skills, capacity'],
        ],
        'payroll_audit' => ['net_change_pct' => 30, 'deduction_share_pct' => 60, 'lop_days' => 10, 'tds_jump_pct' => 100, 'salary_revision_pct' => 50],
        'attrition_risk' => ['bands' => ['low' => 2, 'medium' => 4], 'signals' => [
            'short_tenure' => ['points' => 1, 'label' => 'Less than 12 months of tenure'],
            'no_revision' => ['points' => 2, 'label' => 'No salary revision in 24 months'],
            'low_rating' => ['points' => 2, 'label' => 'Latest final rating of 2 or below'],
            'learning_overdue' => ['points' => 1, 'label' => 'Mandatory learning overdue'],
            'no_one_on_one' => ['points' => 1, 'label' => 'No one-on-one in 90 days'],
            'absences' => ['points' => 1, 'label' => 'Three or more unexplained absences in 30 days'],
            'constructive_feedback' => ['points' => 1, 'label' => 'Constructive feedback in the last 60 days'],
            'stalled_high_performer' => ['points' => 1, 'label' => 'High performer without promotion for 24 months'],
            'open_grievance' => ['points' => 2, 'label' => 'Has an open grievance'],
        ]],
        'config_search' => [
            ['keywords' => 'working hours shift timing schedule roster', 'label' => 'Shifts', 'url' => '/admin/shifts'],
            ['keywords' => 'working hours schedule week pattern roster', 'label' => 'Work schedules', 'url' => '/admin/work-schedules'],
            ['keywords' => 'overtime attendance rules grace late policy', 'label' => 'Policies (attendance, overtime, leave)', 'url' => '/admin/policies'],
            ['keywords' => 'holiday calendar', 'label' => 'Holiday calendars', 'url' => '/admin/holiday-calendars'],
            ['keywords' => 'designation title role job', 'label' => 'Designations', 'url' => '/admin/designations'],
            ['keywords' => 'level band', 'label' => 'Levels', 'url' => '/admin/levels'],
            ['keywords' => 'grade pay band', 'label' => 'Grades', 'url' => '/admin/grades'],
            ['keywords' => 'job family function', 'label' => 'Job families', 'url' => '/admin/job-families'],
            ['keywords' => 'department team unit division organisation structure', 'label' => 'Organisation designer', 'url' => '/admin/organisation-designer'],
            ['keywords' => 'company entity legal', 'label' => 'Companies', 'url' => '/admin/companies'],
            ['keywords' => 'location office city', 'label' => 'Locations', 'url' => '/admin/locations'],
            ['keywords' => 'leave type earned casual sick', 'label' => 'Leave types', 'url' => '/admin/leave-types'],
            ['keywords' => 'salary component allowance basic hra', 'label' => 'Salary components', 'url' => '/admin/salary-components'],
            ['keywords' => 'salary structure ctc', 'label' => 'Salary structures', 'url' => '/admin/salary-structures'],
            ['keywords' => 'pf esi pt tds statutory registration', 'label' => 'Statutory profiles', 'url' => '/admin/statutory-profiles'],
            ['keywords' => 'statutory rules compliance rates', 'label' => 'Statutory rules', 'url' => '/admin/compliance-rules'],
            ['keywords' => 'performance cycle appraisal review', 'label' => 'Performance cycles', 'url' => '/admin/performance-cycles'],
            ['keywords' => 'rating scale', 'label' => 'Rating scales', 'url' => '/admin/rating-scales'],
            ['keywords' => 'competency skill framework', 'label' => 'Competencies', 'url' => '/admin/competencies'],
            ['keywords' => 'kra kpi library', 'label' => 'KRA library', 'url' => '/admin/kras'],
            ['keywords' => 'career path ladder', 'label' => 'Career paths', 'url' => '/admin/career-paths'],
            ['keywords' => 'course training learning lms', 'label' => 'Courses', 'url' => '/admin/courses'],
            ['keywords' => 'asset category laptop', 'label' => 'Asset categories', 'url' => '/admin/asset-categories'],
            ['keywords' => 'ticket category sla service desk', 'label' => 'Service desk categories & SLAs', 'url' => '/admin/ticket-categories'],
            ['keywords' => 'grievance category posh committee', 'label' => 'Grievance categories & handlers', 'url' => '/admin/grievance-categories'],
            ['keywords' => 'letter template experience relieving', 'label' => 'Letter templates', 'url' => '/admin/letter-templates'],
            ['keywords' => 'workflow approval flow', 'label' => 'Workflows', 'url' => '/admin/workflows'],
            ['keywords' => 'notification template email rule', 'label' => 'Notification rules', 'url' => '/admin/notification-rules'],
            ['keywords' => 'custom field', 'label' => 'Custom fields', 'url' => '/admin/custom-fields'],
            ['keywords' => 'form builder', 'label' => 'Forms', 'url' => '/admin/forms'],
            ['keywords' => 'role permission access', 'label' => 'Roles', 'url' => '/admin/roles'],
            ['keywords' => 'user login', 'label' => 'Users', 'url' => '/admin/users'],
            ['keywords' => 'setting notice period leave year payroll', 'label' => 'Settings', 'url' => '/admin/tenant-settings'],
            ['keywords' => 'feature flag toggle', 'label' => 'Features', 'url' => '/admin/tenant-features'],
            ['keywords' => 'change centre approval configuration history rollback', 'label' => 'Configuration change centre', 'url' => '/admin/configuration-changes'],
            ['keywords' => 'pack blueprint import export', 'label' => 'Configuration packs', 'url' => '/admin/configuration-packs'],
            ['keywords' => 'api key integration webhook', 'label' => 'API keys', 'url' => '/admin/api-keys'],
            ['keywords' => 'onboarding template checklist', 'label' => 'Onboarding templates', 'url' => '/admin/onboarding-templates'],
            ['keywords' => 'document type', 'label' => 'Document types', 'url' => '/admin/document-types'],
            ['keywords' => 'dashboard widget', 'label' => 'Dashboard builder', 'url' => '/admin/dashboards'],
            ['keywords' => 'report builder export schedule', 'label' => 'Reports', 'url' => '/admin/reports'],
        ],
    ],

    /*
    | Analytics (§84–§85, §56): report builder vocabulary and dashboard widget types.
    */
    'analytics' => [
        'operators' => ['equals' => 'is', 'not_equals' => 'is not', 'in' => 'is any of', 'contains' => 'contains', 'gt' => 'greater than', 'gte' => 'at least', 'lt' => 'less than', 'lte' => 'at most', 'between' => 'between', 'is_empty' => 'is empty', 'not_empty' => 'is not empty', 'last_days' => 'in the last N days', 'this_month' => 'this month', 'this_year' => 'this year'],
        'aggregations' => ['count' => 'Count', 'sum' => 'Sum', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum'],
        'visualizations' => ['table' => 'Table', 'bar' => 'Bar chart', 'line' => 'Line chart', 'pie' => 'Pie chart', 'kpi' => 'Single number'],
        'formats' => ['csv' => 'CSV (Excel-compatible)'],
        'max_rows' => 10000,
        'schedule_frequencies' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'],
        'widget_types' => ['kpi' => 'KPI number', 'trend' => 'Trend (last 12 months)', 'chart' => 'Chart from a report', 'table' => 'Table from a report', 'leaderboard' => 'Leaderboard (top rows of a report)', 'alerts' => 'Needs attention counts'],
        'widget_sizes' => ['1' => 'Small', '2' => 'Half width', '4' => 'Full width'],
        'metrics' => [
            'headcount' => 'Headcount', 'joiners_30d' => 'Joiners (last 30 days)', 'exits_30d' => 'Exits (last 30 days)', 'attrition_rate' => 'Attrition rate (12 months, %)',
            'absenteeism_rate' => 'Absenteeism (last 30 days, %)', 'on_leave_today' => 'On leave today', 'people_cost' => 'People cost (last finalized payroll)', 'cost_per_head' => 'Cost per head (last payroll)',
            'avg_tenure_months' => 'Average tenure (months)', 'women_share' => 'Women in workforce (%)', 'high_performers' => 'High performers (latest cycle)', 'learning_completion' => 'Mandatory learning completion (%)',
            'open_tickets' => 'Open service desk tickets', 'open_grievances' => 'Open grievances', 'exits_in_progress' => 'Exits in progress', 'pending_approvals' => 'Pending approvals',
        ],
    ],

    /*
    | Exit, full & final, exit interview, letters, alumni (§40, §59–§62).
    */
    'exit' => [
        'types' => ['resignation' => 'Resignation', 'termination' => 'Termination', 'retirement' => 'Retirement', 'contract_expiry' => 'Contract expiry', 'absconding' => 'Absconding', 'death' => 'Death', 'mutual' => 'Mutual separation', 'other' => 'Other'],
        'immediate_types' => ['termination', 'absconding', 'death'],
        'statuses' => ['initiated' => 'Initiated', 'notice' => 'Serving notice', 'clearance' => 'Clearance', 'settlement' => 'Settlement', 'completed' => 'Completed', 'withdrawn' => 'Withdrawn', 'cancelled' => 'Cancelled'],
        'clearance_stages' => [
            'manager' => ['name' => 'Manager clearance', 'items' => ['Knowledge transfer completed', 'Pending work handed over', 'Access to team systems reviewed']],
            'it' => ['name' => 'IT clearance', 'items' => ['Email and system access revoked on last day', 'Data backed up / transferred', 'Software licences released']],
            'finance' => ['name' => 'Finance clearance', 'items' => ['Advances and loans settled', 'Expense claims closed', 'Company cards returned']],
            'asset' => ['name' => 'Asset clearance', 'items' => ['All company property returned']],
            'hr' => ['name' => 'HR clearance', 'items' => ['Exit interview done', 'Documents collected', 'Alumni details captured']],
        ],
        'clearance_statuses' => ['pending' => 'Pending', 'cleared' => 'Cleared', 'blocked' => 'Blocked', 'na' => 'Not applicable'],
        'settlement_statuses' => ['draft' => 'Draft', 'calculated' => 'Calculated', 'approved' => 'Approved', 'paid' => 'Paid'],
        'interview_reasons' => ['better_opportunity' => 'Better opportunity', 'compensation' => 'Compensation', 'career_growth' => 'Career growth', 'manager' => 'Manager / leadership', 'culture' => 'Work culture', 'workload' => 'Workload / burnout', 'relocation' => 'Relocation', 'personal' => 'Personal / family', 'health' => 'Health', 'higher_studies' => 'Higher studies', 'retirement' => 'Retirement', 'other' => 'Other'],
        'interview_dimensions' => ['manager_experience' => 'Manager experience', 'compensation' => 'Compensation & benefits', 'culture' => 'Culture', 'workload' => 'Workload', 'location' => 'Location / commute', 'career_opportunities' => 'Career opportunities', 'work_environment' => 'Work environment'],
    ],
    'letters' => [
        'types' => ['offer' => 'Offer', 'appointment' => 'Appointment', 'confirmation' => 'Confirmation', 'promotion' => 'Promotion', 'increment' => 'Increment', 'transfer' => 'Transfer', 'relieving' => 'Relieving', 'experience' => 'Experience', 'salary_certificate' => 'Salary certificate', 'employment_certificate' => 'Employment certificate', 'noc' => 'No objection certificate', 'warning' => 'Warning', 'show_cause' => 'Show cause', 'appreciation' => 'Appreciation', 'custom' => 'Custom'],
        'statuses' => ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved', 'issued' => 'Issued', 'rejected' => 'Rejected'],
        'number_prefix' => 'LTR',
    ],
    'alumni' => [
        'request_types' => ['experience_letter' => 'Experience letter', 'relieving_letter' => 'Relieving letter', 'employment_verification' => 'Employment verification', 'salary_certificate' => 'Salary certificate', 'payslip' => 'Payslip copy', 'form16' => 'Form 16', 'reference' => 'Reference request', 'other' => 'Other'],
        'request_statuses' => ['submitted' => 'Submitted', 'verified' => 'Verified', 'approved' => 'Approved', 'generated' => 'Generated', 'delivered' => 'Delivered', 'rejected' => 'Rejected'],
        'letter_for_request' => ['experience_letter' => 'experience', 'relieving_letter' => 'relieving', 'employment_verification' => 'employment_certificate', 'salary_certificate' => 'salary_certificate'],
    ],

    /*
    | HR service desk, grievances, knowledge base, communication (§48–§51).
    */
    'servicedesk' => [
        'priorities' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'],
        'statuses' => ['new' => 'New', 'open' => 'Open', 'pending' => 'Waiting on employee', 'resolved' => 'Resolved', 'closed' => 'Closed'],
        'auto_close_days' => 5,
        'defaults' => [
            ['code' => 'LETTER', 'name' => 'Letters & certificates', 'description' => 'Experience, salary, address, NOC and other letters', 'sla_hours' => 72],
            ['code' => 'PAYROLL', 'name' => 'Payroll & tax query', 'sla_hours' => 48],
            ['code' => 'ATTENDANCE', 'name' => 'Attendance & leave query', 'sla_hours' => 24],
            ['code' => 'PROFILE', 'name' => 'Profile / data correction', 'sla_hours' => 48],
            ['code' => 'IT', 'name' => 'IT & assets', 'sla_hours' => 24],
            ['code' => 'POLICY', 'name' => 'Policy question', 'sla_hours' => 48],
            ['code' => 'OTHER', 'name' => 'Something else', 'sla_hours' => 72],
        ],
    ],
    'grievance' => [
        'severities' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'],
        'statuses' => ['submitted' => 'Submitted', 'under_review' => 'Under review', 'investigating' => 'Investigating', 'action_taken' => 'Action taken', 'resolved' => 'Resolved', 'closed' => 'Closed', 'withdrawn' => 'Withdrawn'],
        'note_types' => ['note' => 'Note', 'evidence' => 'Evidence', 'action' => 'Action taken', 'decision' => 'Decision', 'employee' => 'Employee message'],
        'defaults' => [
            ['code' => 'WORKPLACE', 'name' => 'Workplace conduct', 'is_confidential' => true, 'allow_anonymous' => true, 'sla_days' => 30],
            ['code' => 'POSH', 'name' => 'Sexual harassment (PoSH)', 'description' => 'Handled by the Internal Committee only', 'is_confidential' => true, 'allow_anonymous' => false, 'sla_days' => 90],
            ['code' => 'DISCRIMINATION', 'name' => 'Discrimination / bias', 'is_confidential' => true, 'allow_anonymous' => true, 'sla_days' => 30],
            ['code' => 'PAY', 'name' => 'Pay & benefits dispute', 'is_confidential' => false, 'allow_anonymous' => false, 'sla_days' => 21],
            ['code' => 'MANAGER', 'name' => 'Manager relationship', 'is_confidential' => true, 'allow_anonymous' => true, 'sla_days' => 30],
            ['code' => 'SAFETY', 'name' => 'Health & safety', 'is_confidential' => false, 'allow_anonymous' => true, 'sla_days' => 14],
            ['code' => 'OTHER', 'name' => 'Other', 'is_confidential' => true, 'allow_anonymous' => true, 'sla_days' => 30],
        ],
    ],
    'kb' => [
        'categories' => ['hr_policy' => 'HR policies', 'attendance' => 'Attendance', 'leave' => 'Leave', 'travel' => 'Travel', 'posh' => 'PoSH', 'wfh' => 'Work from home', 'it' => 'IT', 'conduct' => 'Code of conduct', 'expense' => 'Expenses', 'benefits' => 'Benefits', 'payroll' => 'Payroll & tax', 'other' => 'Other'],
    ],
    'communication' => [
        'types' => ['announcement' => 'Announcement', 'circular' => 'Circular', 'newsletter' => 'Newsletter', 'policy' => 'Policy publication', 'instruction' => 'Instruction'],
    ],

    /*
    | Learning / LMS (§37).
    */
    'learning' => [
        'course_types' => ['video' => 'Video', 'document' => 'Document / reading', 'elearning' => 'E-learning (external link)', 'classroom' => 'Classroom training', 'virtual' => 'Virtual training', 'assessment' => 'Assessment only'],
        'categories' => ['compliance' => 'Compliance', 'onboarding' => 'Onboarding', 'technical' => 'Technical', 'leadership' => 'Leadership', 'soft_skills' => 'Soft skills', 'product' => 'Product', 'safety' => 'Health & safety', 'other' => 'Other'],
        'module_types' => ['video' => 'Video', 'document' => 'Document', 'link' => 'External link', 'text' => 'Text', 'assessment' => 'Assessment'],
        'enrolment_statuses' => ['enrolled' => 'Enrolled', 'in_progress' => 'In progress', 'completed' => 'Completed', 'failed' => 'Failed', 'overdue' => 'Overdue', 'expired' => 'Expired', 'withdrawn' => 'Withdrawn'],
        'session_statuses' => ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
        'attendee_statuses' => ['registered' => 'Registered', 'attended' => 'Attended', 'absent' => 'Absent', 'cancelled' => 'Cancelled'],
        'due_soon_days' => 7,
        'certificate_expiry_notice_days' => 30,
        'certificate_prefix' => 'CERT',
    ],

    /*
    | Assets (§38).
    */
    'assets' => [
        'statuses' => ['in_stock' => 'In stock', 'assigned' => 'Assigned', 'in_transit' => 'In transit', 'in_repair' => 'In repair', 'lost' => 'Lost', 'retired' => 'Retired', 'disposed' => 'Disposed'],
        'conditions' => ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'damaged' => 'Damaged', 'not_working' => 'Not working'],
        'movement_types' => ['procured' => 'Procured', 'assigned' => 'Assigned', 'transferred' => 'Transferred', 'returned' => 'Returned', 'repair_out' => 'Sent for repair', 'repair_in' => 'Back from repair', 'lost' => 'Reported lost', 'found' => 'Found', 'retired' => 'Retired', 'disposed' => 'Disposed', 'location' => 'Location change'],
        'disposal_methods' => ['sale' => 'Sale', 'scrap' => 'Scrap', 'donation' => 'Donation', 'return_to_vendor' => 'Returned to vendor', 'write_off' => 'Write-off'],
        'defaults' => [
            'categories' => [
                ['code' => 'LAPTOP', 'name' => 'Laptop', 'requires_serial' => true, 'is_it_asset' => true],
                ['code' => 'DESKTOP', 'name' => 'Desktop', 'requires_serial' => true, 'is_it_asset' => true],
                ['code' => 'MONITOR', 'name' => 'Monitor', 'requires_serial' => true, 'is_it_asset' => true],
                ['code' => 'MOBILE', 'name' => 'Mobile phone', 'requires_serial' => true, 'is_it_asset' => true],
                ['code' => 'SIM', 'name' => 'SIM card', 'requires_serial' => false, 'is_it_asset' => true],
                ['code' => 'ID_CARD', 'name' => 'ID card', 'requires_serial' => false, 'is_it_asset' => false],
                ['code' => 'ACCESS_CARD', 'name' => 'Access card', 'requires_serial' => false, 'is_it_asset' => false],
                ['code' => 'LICENCE', 'name' => 'Software licence', 'requires_serial' => false, 'is_it_asset' => true],
                ['code' => 'VEHICLE', 'name' => 'Vehicle', 'requires_serial' => true, 'is_it_asset' => false],
                ['code' => 'EQUIPMENT', 'name' => 'Tools & equipment', 'requires_serial' => false, 'is_it_asset' => false],
                ['code' => 'OTHER', 'name' => 'Other company property', 'requires_serial' => false, 'is_it_asset' => false],
            ],
        ],
    ],

    /*
    | Compliance (§32). Rule parameters live in the platform-owned compliance_rules table, seeded
    | from database/data/compliance/in.php, versioned and effective-dated. Values are
    | illustrative and MUST be verified against current government notifications before
    | production use.
    */
    'compliance' => [
        // Statutory safety (Phase 0.2): refuse to finalize payroll on rules not verified against
        // official sources. On in production; tests and development may switch it on explicitly.
        'enforce_verified_rules' => env('PEOPLEOS_ENFORCE_VERIFIED_RULES', env('APP_ENV') === 'production'),
        'verification_statuses' => ['illustrative' => 'Illustrative / development only', 'verified' => 'Verified against official source'],
        'jurisdictions' => ['IN' => 'India'],
        'states' => ['AP' => 'Andhra Pradesh', 'DL' => 'Delhi', 'GJ' => 'Gujarat', 'HR' => 'Haryana', 'KA' => 'Karnataka', 'KL' => 'Kerala', 'MH' => 'Maharashtra', 'MP' => 'Madhya Pradesh', 'RJ' => 'Rajasthan', 'TG' => 'Telangana', 'TN' => 'Tamil Nadu', 'UP' => 'Uttar Pradesh', 'WB' => 'West Bengal'],
        'tax_regimes' => ['new' => 'New regime (default)', 'old' => 'Old regime'],
        'financial_year_start_month' => 4,
    ],

    /*
    | Leave engine (§25, §26). Types every new tenant receives; policies decide entitlements.
    */
    'leave' => [
        // Lifecycle states in which leave may be requested unless a policy entitlement overrides (Phase 3 §58).
        'eligible_states' => ['joined', 'probation', 'confirmed', 'active', 'on_leave', 'notice_period'],
        'units' => ['days' => 'Days', 'hours' => 'Hours'],
        'cancellation_policies' => ['self' => 'Employee may cancel', 'approval' => 'Cancellation needs approval', 'not_allowed' => 'Only HR may cancel'],
        'categories' => ['paid' => 'Paid leave', 'unpaid' => 'Unpaid leave', 'comp_off' => 'Compensatory off', 'restricted' => 'Restricted / optional holiday', 'special' => 'Special (maternity, paternity, bereavement…)'],
        'ledger_types' => ['accrual' => 'Accrual', 'carry_forward' => 'Carried forward', 'lapse' => 'Lapsed', 'usage' => 'Used', 'reversal' => 'Reversed', 'adjustment' => 'Adjustment', 'encashment' => 'Encashed', 'comp_off_credit' => 'Comp-off credit'],
        'defaults' => [
            ['code' => 'EL', 'name' => 'Earned leave', 'category' => 'paid', 'is_paid' => true, 'allow_half_day' => true, 'is_encashable' => true, 'colour' => '#16a34a'],
            ['code' => 'CL', 'name' => 'Casual leave', 'category' => 'paid', 'is_paid' => true, 'allow_half_day' => true, 'is_encashable' => false, 'colour' => '#2563eb'],
            ['code' => 'SL', 'name' => 'Sick leave', 'category' => 'paid', 'is_paid' => true, 'allow_half_day' => true, 'is_encashable' => false, 'colour' => '#f59e0b'],
            ['code' => 'ML', 'name' => 'Maternity leave', 'category' => 'special', 'is_paid' => true, 'allow_half_day' => false, 'is_encashable' => false, 'applicable_gender' => 'female', 'colour' => '#db2777'],
            ['code' => 'PL', 'name' => 'Paternity leave', 'category' => 'special', 'is_paid' => true, 'allow_half_day' => false, 'is_encashable' => false, 'applicable_gender' => 'male', 'colour' => '#7c3aed'],
            ['code' => 'BL', 'name' => 'Bereavement leave', 'category' => 'special', 'is_paid' => true, 'allow_half_day' => false, 'is_encashable' => false, 'colour' => '#475569'],
            ['code' => 'CO', 'name' => 'Compensatory off', 'category' => 'comp_off', 'is_paid' => true, 'allow_half_day' => true, 'is_encashable' => false, 'colour' => '#0d9488'],
            ['code' => 'RH', 'name' => 'Restricted holiday', 'category' => 'restricted', 'is_paid' => true, 'allow_half_day' => false, 'is_encashable' => false, 'colour' => '#ea580c'],
            ['code' => 'LWP', 'name' => 'Leave without pay', 'category' => 'unpaid', 'is_paid' => false, 'allow_half_day' => true, 'is_encashable' => false, 'colour' => '#dc2626'],
        ],
    ],

    /*
    | Attendance (§13–§15, §23–§24, §27–§28).
    */
    'attendance' => [
        'sources' => ['biometric' => 'Biometric', 'mobile' => 'Mobile', 'web' => 'Web', 'api' => 'API', 'manual' => 'Manual', 'import' => 'Import', 'kiosk' => 'Kiosk'],
        'statuses' => [
            'present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent', 'incomplete' => 'Incomplete (missed punch)',
            'weekly_off' => 'Weekly off', 'holiday' => 'Holiday', 'leave' => 'Leave', 'unpaid_leave' => 'Leave without pay', 'wfh' => 'Work from home', 'on_duty' => 'On duty', 'field_duty' => 'Field duty', 'not_processed' => 'Not processed',
        ],
        'exception_types' => ['missed_punch' => 'Missed punch', 'missing_in' => 'Missing IN punch', 'missing_out' => 'Missing OUT punch', 'invalid_sequence' => 'Invalid punch sequence', 'late' => 'Late coming', 'early_leave' => 'Early leaving', 'short_hours' => 'Short hours', 'absent' => 'Absent without leave', 'no_shift' => 'No shift assigned', 'holiday_work' => 'Worked on holiday / weekly off', 'overtime' => 'Overtime pending approval'],
        'regularisation_types' => ['missed_punch' => 'Missed punch', 'late' => 'Late coming', 'early_leave' => 'Early leaving', 'wfh' => 'Work from home', 'on_duty' => 'On duty', 'field_duty' => 'Field duty', 'absent' => 'Marked absent by mistake'],
        'source_types' => ['biometric_device' => 'Biometric device', 'mobile' => 'Mobile', 'web' => 'Web', 'api' => 'API', 'manual' => 'Manual', 'import' => 'Import'],
        'overtime_statuses' => ['none' => 'None', 'pending' => 'Pending approval', 'approved' => 'Approved', 'rejected' => 'Rejected'],
        'engine_version' => '2.0',
        'import_max_rows' => 50000,
        'holiday_types' => ['public' => 'Public holiday', 'optional' => 'Optional / restricted holiday', 'company' => 'Company holiday'],
        'adapters' => [
            'generic' => ['label' => 'Generic JSON', 'driver' => GenericJsonAdapter::class],
            'essl' => ['label' => 'eSSL (push)', 'driver' => EsslAdapter::class],
        ],
    ],

    /*
    | Data classification (architecture contract §17). Levels drive masking, access audit, export
    | and AI restrictions; the classes listed must keep their sensitive attributes masked in audit
    | and behind the *.sensitive permissions. Checked by the architecture tests.
    */
    'data_classification' => [
        'levels' => ['public', 'internal', 'confidential', 'sensitive', 'highly_sensitive', 'statutory', 'financial'],
        'highly_sensitive' => [
            EmployeeBankAccount::class => ['account_number'],
            EmployeeStatutoryDetail::class => ['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number'],
            User::class => ['password', 'app_authentication_secret', 'app_authentication_recovery_codes'],
            SsoConnection::class => ['client_secret'],
            WebhookEndpoint::class => ['secret'],
        ],
        'financial' => [
            EmployeeSalaryAssignment::class, PayrollEntry::class,
            Payslip::class, FinalSettlement::class,
        ],
        'statutory' => [EmployeeTaxDeclaration::class, CompanyStatutoryProfile::class],
        'confidential' => [
            Grievance::class, ImprovementPlan::class,
            OneOnOne::class, BgvCase::class, EmployeeDocument::class,
        ],
    ],

    'audit' => [
        // Attributes never diffed into audit_event_changes.
        'ignored_attributes' => ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'password'],
        // Attribute names masked in audit_event_changes unless the model says otherwise.
        'sensitive_attributes' => ['password', 'account_number', 'pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number', 'salary'],
        'mask' => '••••',
    ],
];
