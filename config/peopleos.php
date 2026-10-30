<?php

use App\Domain\Alumni\Models\AlumniRequest;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Support\AssetAcknowledgementTaskSource;
use App\Domain\Attendance\Adapters\EsslAdapter;
use App\Domain\Attendance\Adapters\GenericJsonAdapter;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Providers\ManualProvider;
use App\Domain\Communication\Services\CommunicationTaskSource;
use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationRange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\EpfReturnEntry;
use App\Domain\Compliance\Models\EsiReturnEntry;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\ParallelPayrollLine;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Compliance\Models\TdsAnnualLedger;
use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Models\TdsProfile;
use App\Domain\Compliance\Models\TdsQuarterlyReturnEntry;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\EsiReturns;
use App\Domain\Compliance\Services\Returns\LwfReturns;
use App\Domain\Compliance\Services\Returns\ProfessionalTaxReturns;
use App\Domain\Compliance\Services\Tds\TdsQuarterlyReturns;
use App\Domain\ComplianceReports\Builders\BonusRegister;
use App\Domain\ComplianceReports\Builders\CombinedMusterWages;
use App\Domain\ComplianceReports\Builders\DeductionRegister;
use App\Domain\ComplianceReports\Builders\EmployeeRegister;
use App\Domain\ComplianceReports\Builders\EmploymentReturn;
use App\Domain\ComplianceReports\Builders\EqualRemuneration;
use App\Domain\ComplianceReports\Builders\EsiChallan;
use App\Domain\ComplianceReports\Builders\EsiContributionPeriod;
use App\Domain\ComplianceReports\Builders\GratuityRegister;
use App\Domain\ComplianceReports\Builders\HolidayList;
use App\Domain\ComplianceReports\Builders\JoinersAndLeavers;
use App\Domain\ComplianceReports\Builders\LabourWelfareFundStatement;
use App\Domain\ComplianceReports\Builders\LeaveRegister;
use App\Domain\ComplianceReports\Builders\MaternityRegister;
use App\Domain\ComplianceReports\Builders\MusterRoll;
use App\Domain\ComplianceReports\Builders\OvertimeRegister;
use App\Domain\ComplianceReports\Builders\PfChallan;
use App\Domain\ComplianceReports\Builders\PoshAnnualReport;
use App\Domain\ComplianceReports\Builders\ProfessionalTaxStatement;
use App\Domain\ComplianceReports\Builders\ReturnWorkingSheet;
use App\Domain\ComplianceReports\Builders\SalaryVariance;
use App\Domain\ComplianceReports\Builders\TdsStatement;
use App\Domain\ComplianceReports\Builders\WageRegister;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Feedback\Support\FeedbackTaskSource;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Handlers\BgvResultsHandler;
use App\Domain\Integration\Handlers\LinkReferenceHandler;
use App\Domain\Integration\Handlers\RetireReferenceHandler;
use App\Domain\Knowledge\Services\PolicyAcknowledgementTaskSource;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningCost;
use App\Domain\Learning\Models\LearningEvidence;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Letters\Models\Letter;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Loans\Models\Loan;
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
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\ProfitCentre;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\CalibrationAdjustment;
use App\Domain\Performance\Models\CalibrationSession;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\ImprovementPlanCheckpoint;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Support\Feedback360TaskSource;
use App\Domain\Performance\Support\InitiativeFeedbackTaskSource;
use App\Domain\Performance\Support\OneOnOneFeedbackTaskSource;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Models\TenantSetting;
use App\Domain\ServiceDesk\DomainActions\AddressChange;
use App\Domain\ServiceDesk\DomainActions\BankAccountChange;
use App\Domain\ServiceDesk\DomainActions\CompensationProposalLink;
use App\Domain\ServiceDesk\DomainActions\EmergencyContactChange;
use App\Domain\ServiceDesk\DomainActions\FamilyMemberChange;
use App\Domain\ServiceDesk\DomainActions\LeaveRequestAction;
use App\Domain\ServiceDesk\DomainActions\LetterRequestAction;
use App\Domain\ServiceDesk\DomainActions\ManagerChange;
use App\Domain\ServiceDesk\DomainActions\RegularisationAction;
use App\Domain\ServiceDesk\DomainActions\StatutoryIdentityChange;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\ServiceDeskTaskSource;
use App\Domain\Skills\Models\SkillAssessment;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Models\TalentAssessment;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentProfile;
use App\Domain\Talent\Models\TalentReviewItem;
use App\Domain\Workflow\Services\WorkflowTaskSource;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;

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
            // HROne setup parity P4 (Inbox → Delegate): out-of-office periods.
            'task.delegate' => 'Set up an out-of-office period: hand your approvals to a colleague for some days',
            'task.delegate_any' => 'Set up and end out-of-office periods for anyone',
            // HROne parity RQ-8 (Inbox → "Ignore api"): close a system-generated workflow task as not relevant (reason, audited, restorable).
            'task.ignore' => 'Mark system-generated tasks in your inbox as not relevant (with a reason), and restore them',
        ],
        'onboarding' => [
            'onboarding.view' => 'View onboarding plans and templates',
            'onboarding.manage' => 'Create templates, start plans, complete or skip any task',
            'onboarding.act' => 'Complete own onboarding tasks',
        ],
        'document' => [
            'document.view' => 'View and download employee documents',
            'document.own' => 'View and download my own documents (Phase 12 self-service)',
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
            // HROne parity CA-11: read-only access to keys, their call logs, usage and security events, and the API reference.
            'api_key.view' => 'View integration API keys, their call logs, usage and security events, and the API reference',
        ],
        'integration' => [
            'integration.view' => 'View integrations, external references, mappings and inbound events (never payload bodies)',
            'integration.manage' => 'Register integrations, rotate their signing secrets, map values, retire references and reprocess events (Phase 14)',
        ],
        'payroll' => [
            'payroll.view' => 'View payroll runs, entries and payslips (all employees)',
            'payroll.manage' => 'Configure salary components, structures, adjustments and statutory registrations',
            'payroll.calculate' => 'Create runs and calculate payroll',
            'payroll.approve' => 'Approve calculated payroll runs',
            'payroll.finalize' => 'Finalize, reopen and mark runs as paid',
            'payroll.payslip' => 'View own payslips',
            // HROne parity HP-2: employee self-service for payroll requests.
            'payroll.request' => 'Request loans and salary advances, claim reimbursements and request variable pay for oneself',
            // HROne parity HP-3 (PY-14).
            'payroll.override' => 'Override calculated PF, ESI, PT, LWF and TDS amounts for an employee in an open pay month (audited)',
        ],
        'legal_structure' => [
            'legal_entity.view' => 'View legal entities (ADR-0001)',
            'legal_entity.create' => 'Create legal entities',
            'legal_entity.update' => 'Update legal entities',
            'establishment.view' => 'View establishments',
            'establishment.create' => 'Create establishments',
            'establishment.update' => 'Update establishments and assign employees to them',
            'legal_entity.verify' => 'Verify legal entity details against the incorporation document (never the submitter)',
            'establishment.verify' => 'Verify establishment details against the registration certificate (never the submitter)',
        ],
        'compliance' => [
            'compliance.view' => 'View statutory rules and compliance status',
            'compliance.registrations.view' => 'View statutory registrations (numbers masked)',
            'compliance.registrations.manage' => 'Create, correct and close statutory registrations and establishment statutory profiles',
            'compliance.profile_corrections.approve' => 'Approve or reject a correction of an establishment statutory profile for months whose payroll is approved or finalised (never one you requested; audited)',
            'compliance.sensitive.view' => 'See unmasked registration numbers, UAN, ESI IP and PAN on statutory outputs (audited)',
            'compliance.returns.view' => 'View statutory returns, entries, snapshots and reconciliations',
            'compliance.returns.generate' => 'Generate, validate and revise statutory returns',
            'compliance.returns.approve' => 'Approve statutory returns (never the generator)',
            'compliance.returns.export' => 'Export approved statutory returns',
            'compliance.returns.file' => 'Record filing submission and acknowledgement (never the approver)',
            'compliance.reconcile' => 'Reconcile statutory returns against payroll',
            'compliance.tds.manage' => 'Maintain TDS profiles, investment proofs, ledgers and certificates',
            'compliance.parallel.manage' => 'Run parallel payroll cycles: import reference values, compare, review differences, reconcile',
            'compliance.reports.view' => 'Open compliance reports: what falls due for a period and the packs generated',
            'compliance.reports.generate' => 'Generate and download compliance registers, challan workings and statements from payroll, attendance and leave in your scope (audited)',
            'compliance.reports.review' => 'Record the authorised review of a compliance report pack generated by someone else (audited)',
        ],
        'enterprise' => [
            'sso.manage' => 'Configure single sign-on connections',
            'webhook.manage' => 'Configure webhook endpoints and inspect deliveries',
            'security.manage' => 'Manage tenant security policy (IP allowlist, MFA, passwords, sessions, retention)',
            'currency.manage' => 'Maintain exchange rates and the base currency',
            'warehouse.export' => 'Export datasets to the data warehouse feed',
            'data.export' => 'Request and download a full, encrypted export of the organisation\'s data (audited)',
        ],
        // HROne parity WO-1: bulk actions on registers (selected records or all matching the filters). Every record still
        // needs the action's own permission and the person's access scope; records they may not act on are reported, not done.
        'bulk' => [
            'bulk.run' => 'Run bulk actions on registers (selected records or all matching the filters; each record still needs the action\'s own permission and your access scope)',
            'bulk.view_all' => 'See every bulk run of the organisation and its per-record results',
        ],
        'ai' => [
            'ai.use' => 'Ask the Employee and Policy assistants about own data and policies',
            'ai.manager' => 'Use the Manager Assistant for direct reports',
            'ai.hr' => 'Use the HR Copilot (workforce questions, pending HR work, natural-language people search)',
            'ai.payroll_auditor' => 'Run the AI Payroll Auditor on payroll runs',
            'ai.workforce' => 'Use Workforce Intelligence (trends, capacity, skills — aggregate facts; no individual risk scoring)',
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
            'servicedesk.view' => 'View HR service desk cases in your organisation scope (read only)',
            'servicedesk.agent' => 'Work HR service desk cases in your organisation scope: acknowledge, assign, respond, resolve (Phase 12)',
            'servicedesk.manage' => 'Configure ticket categories, SLA policies, assignment and draft service catalogue versions',
            'servicedesk.catalogue_approve' => 'Approve service catalogue versions — never one you prepared (Phase 12)',
            'servicedesk.request' => 'Raise and follow own tickets',
            'servicedesk.team' => 'See the status of HR requests raised by the people you manage — never sensitive or confidential cases (Phase 12)',
            'servicedesk.confidential' => 'Work restricted (confidential / employee-relations) cases you are assigned or explicitly granted (Phase 12)',
            'servicedesk.analytics' => 'View HR service analytics (aggregates, small groups suppressed) (Phase 12)',
            'servicedesk.bulk' => 'Run bulk case operations (each case still authorised individually) (Phase 12)',
        ],
        'grievance' => [
            'grievance.view' => 'View grievance cases you are assigned to or granted access to',
            'grievance.manage' => 'Configure grievance categories, assign and close cases',
            'grievance.raise' => 'Raise a grievance',
        ],
        'kb' => [
            'kb.view' => 'Read published knowledge base articles',
            'kb.manage' => 'Write knowledge base articles, submit them for review and publish approved ones',
            'kb.review' => 'Review and approve knowledge base articles — never your own (Phase 12)',
        ],
        'communication' => [
            'communication.view' => 'Read announcements',
            'communication.manage' => 'Prepare announcements, circulars and newsletters for the people in your scope, and publish approved ones',
            'communication.approve' => 'Approve announcements — never one you prepared (Phase 13)',
        ],
        'engagement' => [
            'engagement.view' => 'View surveys, audiences and campaigns (configuration only — never responses)',
            'engagement.manage' => 'Prepare surveys, audiences and campaigns for the people in your scope',
            'engagement.approve' => 'Approve surveys and campaigns — never one you prepared',
            'engagement.analytics' => 'See survey results for surveys within your scope (aggregates only; small groups suppressed)',
            'engagement.comments' => 'Read free-text survey comments above the privacy threshold (never attributed)',
            'engagement.responses' => 'View individual responses to IDENTIFIED surveys within your scope (never confidential or anonymous)',
            'engagement.confidential_identity' => 'Identify the author of one confidential response or feedback item, with a reason (audited). Anonymous responses can never be identified',
            'engagement.team_results' => 'Managers: see your team\'s aggregate results where the survey allows it',
            'engagement.participate' => 'Take surveys and give feedback',
            'engagement.feedback' => 'Handle employee feedback and refer identified feedback to the service desk',
            // HROne setup parity HP-9f: the mood bot (Engage → Happiness index), off until engage.mood.enabled.
            'engagement.mood' => 'Share how you feel when the mood bot asks (anonymous unless the organisation chose otherwise)',
        ],
        'learning' => [
            'learning.view' => 'View courses, paths, sessions and every enrolment',
            'learning.manage' => 'Configure courses, learning paths, assessments and sessions',
            'learning.assign' => 'Assign learning to employees and mark attendance',
            'learning.learn' => 'Take assigned learning (own enrolments)',
            'learning.team' => 'View the learning of employees I manage (configured relationships)',
            'learning.approve' => 'Approve or reject learning requests (never my own)',
            'learning.publish' => 'Approve catalogue items for publication (second person)',
            'learning.certificates' => 'Record external certificates, review evidence and revoke certificates',
            'learning.costs' => 'View and record learning costs',
            'learning.analytics' => 'View aggregated learning analytics',
        ],
        'skills' => [
            'skills.view' => 'View every employee skill profile and assessment (not private notes)',
            'skills.manage' => 'Configure the skill library and skill scales',
            'skills.assess' => 'Assess the skills of employees I manage',
            'skills.self' => 'Maintain my own skill profile (self-declared, never verified)',
            'skills.private_notes' => 'Read assessors\' private notes (access audited)',
        ],
        'development' => [
            'development.view' => 'View every development plan (not private notes)',
            'development.manage' => 'Manage any development plan',
            'development.own' => 'Maintain my own development plans',
            'development.team' => 'Create and manage development plans for employees I manage',
        ],
        'career' => [
            'career.view' => 'View every career profile, aspiration, goal and mobility interest (within organisation scope)',
            'career.manage' => 'Configure career tracks, career paths and role requirements',
            'career.self' => 'Maintain my own career profile, aspirations, career goals and mobility interests',
            'career.team' => 'View the career information employees I manage have chosen to share',
        ],
        'talent' => [
            'talent.view' => 'View talent profiles and talent pool membership (no confidential notes)',
            'talent.manage' => 'Configure talent pools and assessment models; manage pool membership and talent reviews',
            'talent.assess' => 'Record talent assessments',
            'talent.review' => 'Take part in talent review sessions and record decisions',
            'talent.confidential' => 'Read confidential talent notes and assessments (access audited)',
            'talent.analytics' => 'View aggregated talent and succession analytics',
        ],
        'succession' => [
            'succession.view' => 'View critical positions, succession plans and successors (within organisation scope)',
            'succession.manage' => 'Designate critical positions; manage succession plans and successors',
            'succession.assess' => 'Record readiness assessments',
            'succession.team' => 'View succession information about employees I manage',
            'succession.own_candidacy' => 'See my own succession candidacy (granted only by explicit decision)',
        ],
        'workforce' => [
            'workforce.view' => 'View positions, occupancy, vacancies, workforce plans and snapshots (within organisation scope; no costs)',
            'workforce.manage' => 'Create and change positions and request position changes (propose, plan, open, freeze, hold, abolish, close)',
            'workforce.approve' => 'Approve positions, position changes, scenarios, budgets and workforce plans (never one\'s own submission)',
            'workforce.plan' => 'Prepare workforce plans, scenarios and budgets and submit them for approval',
            'workforce.review' => 'Review submitted workforce plans',
            'workforce.costs' => 'See and record planned, budget and actual workforce costs (field security)',
            'workforce.team' => 'View headcount, open and planned positions and vacancies under the positions I hold or for employees I manage',
            'workforce.analytics' => 'View workforce analytics and dashboards',
        ],
        // Phase 11: Compensation owns employee compensation; payroll permissions grant none of this.
        'compensation' => [
            'compensation.view' => 'View employee compensation, history, scheduled changes and change details within organisation scope (field security; audited)',
            'compensation.propose' => 'Propose compensation changes (never for oneself)',
            'compensation.review' => 'Review submitted compensation changes (never one\'s own proposal)',
            'compensation.approve' => 'Approve, reject or cancel compensation changes (never one\'s own proposal or review)',
            'compensation.execute' => 'Execute approved compensation changes into the effective-dated compensation history (never a change one proposed, reviewed or approved)',
            'compensation.configure' => 'Maintain compensation structures, components and ranges (drafts; approval by another person)',
            'compensation.cycles' => 'Prepare compensation cycles (annual increment, promotion, market adjustment)',
            'compensation.budget' => 'Maintain compensation budgets',
            'compensation.team' => 'View the approved compensation of employees I manage (configured manager relationships only)',
            'compensation.self' => 'View my own approved compensation (when the tenant allows it)',
            'compensation.analytics' => 'View compensation analytics (aggregates with small-group suppression)',
            'compensation.export' => 'Export compensation data (audited)',
        ],
        'asset' => [
            'asset.view' => 'View the asset register',
            'asset.manage' => 'Configure categories/models, procure, repair and dispose of assets',
            'asset.assign' => 'Assign, transfer and take back assets',
            'asset.own' => 'View and acknowledge own assets',
            // HROne setup parity AS-B: ask for a new asset, a replacement or a return (managers also for their team).
            'asset.request' => 'Request assets (new, replacement, return) for oneself and, as a manager, for the team',
        ],
        // HROne setup parity (Expense): business expenses, advances and travel (docs/hrone-parity/setup/11-expense-asset-helpdesk.md).
        'expense' => [
            'expense.view' => 'View expense masters, expense policies and (in scope) expense claims, advances and trips',
            'expense.manage' => 'Configure expense categories and limits, groups, location groups, taxes and the expense and travel forms',
            'expense.claim' => 'File own expenses and expense reports, request advances and travel',
            'expense.approve' => 'Decide expense reports, advances and travel requests in scope (never one\'s own)',
            'expense.pay' => 'Pay expenses, dispense advances, recover and write off advances',
            'expense.travel_desk' => 'Book travel and accommodation and record bookings',
        ],
        'performance' => [
            'performance.view' => 'View all goals, appraisals, feedback and career data',
            'performance.manage' => 'Configure cycles, rating scales, competencies, KRA library and career paths',
            'performance.goals' => 'Manage own goals and check in on progress',
            'performance.review' => 'Complete reviews assigned to me (self, manager, peer)',
            'performance.calibrate' => 'Calibrate and finalize appraisals',
            'performance.feedback' => 'Give and request continuous feedback',
            'performance.team' => 'View and act on direct reports (goals, reviews, one-on-ones, PIPs)',
            'performance.checkins' => 'Submit own check-ins and respond to reports\' check-ins',
            'performance.pip' => 'Create and manage performance improvement plans',
            'performance.analytics' => 'View aggregated performance analytics (small groups suppressed)',
            'performance.private_notes' => 'Read managers\' private one-on-one notes (access audited)',
            'performance.anonymous_identity' => 'Reveal the author of anonymous feedback (access audited)',
            // HROne setup parity HP-8j: initiatives (own and, for performance managers, their team's) and every initiative in scope.
            'performance.initiatives' => 'Create and track own initiatives; assign initiatives to the people one manages and give feedback on them',
            'performance.initiatives_manage' => 'See and manage every initiative in my scope (assign, change, cancel, see all feedback)',
            // HROne setup parity HP-8h: 360 feedback rounds (launch, extend, close, nominations and anonymity-safe results in scope).
            'performance.feedback_360' => 'Run 360 feedback rounds: launch, extend the window, close, follow nominations and see results in my scope',
        ],
        'leave' => [
            'leave.view' => 'View leave requests, balances and the leave register',
            'leave.apply' => 'Apply for own leave and cancel own requests',
            'leave.approve' => 'Approve or reject leave requests',
            'leave.manage' => 'Configure leave types, adjust balances, encash, apply on behalf, cancel any request',
            // HROne parity TX-2: narrower rights split out of leave.manage (which still grants them, see permission_splits).
            'leave.adjust' => 'Adjust leave balances (one balance, or by upload)',
            'leave.carry_forward' => 'Carry forward and lapse leave balances at the end of a leave year',
        ],
        'attendance' => [
            'attendance.view' => 'View attendance records and the exception centre',
            'attendance.manage' => 'Configure shifts, schedules, holidays and devices; record manual punches; reprocess',
            'attendance.regularise' => 'Request regularisation and other time-office requests (short leave, on duty, shift change, overtime, comp-off, restricted holiday, check-in location) for own attendance',
            'attendance.approve' => 'Approve regularisations and overtime',
            // HROne parity TX-2: narrower rights split out of attendance.manage (which still grants them, see permission_splits).
            'attendance.roster' => 'Roster employees on shifts or weekly offs for days, remove rostered days, upload a shift roster',
            'attendance.override' => 'Change a day\'s attendance status or worked hours (day overrides, day override and monthly attendance uploads)',
            'attendance.punch' => 'Record punches by hand, retry failed punches and upload punch files',
            'attendance.process' => 'Process and reprocess attendance for a day, a range of days or one employee\'s day',
            'attendance.arrears' => 'Work on attendance arrears (corrections to days already used by a finalized payroll run)',
        ],
        'notification' => [
            'notification.view' => 'View notification templates and rules',
            'notification.update' => 'Manage notification templates and rules',
            'notification.deliveries' => 'View the notification delivery log',
            'notification.resend' => 'Send a failed notification delivery again (with a reason; audited)',
        ],
        'employee' => [
            'employee.view' => 'View employees and Employee 360 (non-sensitive tabs)',
            // UX.19 (G12): the subject's own record only, read-only; every section keeps its own permission.
            'employee.self' => 'Open your own Employee 360 (your record only, read-only; sensitive data keeps its own permission)',
            'employee.create' => 'Hire / create employees',
            'employee.update' => 'Update employee and personal data',
            'employee.delete' => 'Delete employee records',
            'employee.position' => 'Assign positions, transfers, promotions and managers',
            'employee.lifecycle' => 'Move employees through lifecycle states',
            'employee.sensitive.view' => 'View bank and statutory details (audited)',
            'employee.import' => 'Import employees from files (upload, map, validate, approve, run)',
            'employee.sensitive.update' => 'Update bank and statutory details',
            // HROne parity MS-2 (owner decision PRV-1 / D-MS1 open): your own PAN, Aadhaar reference, UAN, PF / ESIC numbers and
            // bank account numbers on My profile, masked (last 4) with an audited reveal. Not in the employee role template:
            // by default employees do not see them, as before.
            'employee.self_sensitive' => 'See your own identifiers and bank account numbers on My profile (masked; each reveal audited)',
            // HROne parity WO-2: e-mail an employee from the Employee 360 or a register (audited without the body).
            'employee.message' => 'Send e-mails to employees from their profile or a register (work e-mail; personal e-mail also needs employee.sensitive.view; audited without the body)',
        ],
        // Birthdays: colleagues see the day and month only (never the year); celebration managers see private ones too.
        'celebration' => [
            'celebration.view' => 'See colleagues\' birthdays (day and month only) and wish them on the day',
            'celebration.manage' => 'See every birthday in my scope (including private ones), receive the monthly and daily birthday lists, and set up birthdays',
        ],
        // HROne setup parity HP-9c: badges colleagues give one another (Engage → Badges).
        'recognition' => [
            'recognition.view' => 'See public recognitions, the wall of fame and the badges you received or gave',
            'recognition.give' => 'Give badges to colleagues (within the monthly quota and each badge\'s rules)',
            'recognition.manage' => 'Set up badges and custom badges, see every recognition in my scope (private ones too) and withdraw one',
            // HROne setup parity HP-9d: the points budget and every balance (deposit, adjust, redeem for an employee).
            'recognition.points' => 'Manage reward points: deposit the points budget, see every balance in my scope, adjust balances and redeem points for employees',
        ],
        // HROne setup parity HP-9e: the social wall (Engage → Social wall), off until engage.wall.enabled.
        'wall' => [
            'wall.view' => 'See the social wall, react to posts and comment where comments are allowed',
            'wall.post' => 'Write HR posts on the social wall',
            'wall.moderate' => 'Moderate the social wall: hide and restore posts and comments, pin posts',
        ],
        // HROne setup parity P13 (Forms & templates → Feedback templates). Answers are read through the process they serve
        // (exit.interview, employee.lifecycle, onboarding.manage), never through these keys.
        'feedback' => [
            'feedback.view' => 'See question banks and feedback templates',
            'feedback.manage' => 'Set up question banks and feedback templates, and add templates from the library',
        ],
    ],

    /*
    | HROne parity TX-2: a narrower key split out of a broader one => the broader key. A check of the narrower key also
    | accepts the broader key (User::hasPermission), so a role holding only the broader key — a custom role, or a system
    | role the tenant edited — keeps every action it had. On a deploy a system role is given a split key only when it
    | holds the broader key (ProvisionTenantAction::seedSystemRoles), so nobody gains access either.
    */
    'permission_splits' => [
        'attendance.roster' => 'attendance.manage',
        'attendance.override' => 'attendance.manage',
        'attendance.punch' => 'attendance.manage',
        'attendance.process' => 'attendance.manage',
        'attendance.arrears' => 'attendance.manage',
        'leave.adjust' => 'leave.manage',
        'leave.carry_forward' => 'leave.manage',
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
        // SaaS.10: the first administrator of a self-service tenant. Explicit keys, never `*`: company and organisation
        // set-up, people records, users and role assignments, tenant settings and security, the audit trail and the
        // data export. Payroll, statutory, compensation and sensitive personal data need a specialist role, given
        // explicitly (RoleGrants: never Tenant Super Admin, which only a holder or Markedge can give).
        'account-owner' => [
            'name' => 'Account Owner',
            'description' => 'Sets up the organisation, invites people and gives them roles. Payroll, statutory and sensitive data need a specialist role, given explicitly.',
            'permissions' => ['company.view', 'company.create', 'company.update', 'organisation.view', 'organisation.create', 'organisation.update', 'organisation.design', 'legal_entity.view', 'legal_entity.create', 'legal_entity.update', 'establishment.view', 'establishment.create', 'establishment.update', 'people_setup.view', 'people_setup.create', 'people_setup.update', 'policy.view', 'employee.view', 'employee.create', 'employee.update', 'employee.import', 'user.view', 'user.create', 'user.update', 'user.assign_roles', 'role.view', 'settings.view', 'settings.update', 'features.view', 'security.manage', 'audit.view', 'notification.view', 'data.export', 'task.view', 'task.act', 'task.delegate', 'celebration.view', 'celebration.manage'],
        ],
        'tenant-hr-admin' => [
            'name' => 'Tenant HR Admin',
            'description' => 'Configures the HRMS for the tenant.',
            'permissions' => ['company.*', 'organisation.*', 'people_setup.*', 'employee.*', 'bulk.*', 'custom_field.*', 'form.*', 'policy.*', 'configuration.view', 'configuration.update', 'configuration.publish', 'configuration.rollback', 'configuration.delete', 'blueprint.*', 'workflow.*', 'task.*', 'notification.*', 'onboarding.*', 'document.*', 'bgv.*', 'api_key.*', 'integration.*', 'attendance.*', 'leave.*', 'payroll.*', 'compliance.*', 'performance.*', 'learning.*', 'skills.*', 'development.*', 'career.*', 'talent.view', 'talent.manage', 'talent.assess', 'talent.review', 'talent.confidential', 'talent.analytics', 'succession.view', 'succession.manage', 'succession.assess', 'succession.team', 'workforce.*', 'compensation.*', 'asset.*', 'expense.*', 'servicedesk.*', 'grievance.*', 'kb.*', 'communication.*', 'engagement.*', 'exit.*', 'letter.*', 'alumni.*', 'analytics.*', 'ai.*', 'sso.*', 'webhook.*', 'security.*', 'currency.*', 'warehouse.*', 'user.*', 'role.view', 'settings.*', 'features.view', 'audit.view', 'celebration.*', 'recognition.*', 'wall.*', 'feedback.*'],
        ],
        'hr-manager' => [
            'name' => 'HR Manager',
            'description' => 'Operates HR processes.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'employee.create', 'employee.update', 'employee.position', 'employee.lifecycle', 'employee.message', 'bulk.run', 'compensation.view', 'compensation.propose', 'compensation.review', 'form.view', 'form.submit', 'form.approve', 'policy.view', 'configuration.view', 'workflow.view', 'workflow.run', 'task.*', 'notification.view', 'notification.deliveries', 'onboarding.*', 'document.view', 'document.upload', 'document.verify', 'bgv.*', 'attendance.view', 'attendance.approve', 'leave.*', 'expense.view', 'expense.approve', 'servicedesk.*', 'grievance.*', 'kb.*', 'communication.*', 'engagement.view', 'engagement.manage', 'engagement.approve', 'engagement.analytics', 'engagement.comments', 'engagement.responses', 'engagement.participate', 'engagement.feedback', 'exit.*', 'letter.*', 'alumni.*', 'analytics.view', 'analytics.reports', 'analytics.export', 'ai.use', 'ai.manager', 'ai.hr', 'ai.workforce', 'user.view', 'audit.view', 'celebration.view', 'celebration.manage', 'recognition.view', 'recognition.give', 'recognition.manage', 'recognition.points', 'wall.view', 'wall.post', 'wall.moderate', 'engagement.mood', 'feedback.view'],
        ],
        'hr-executive' => [
            'name' => 'HR Executive',
            'description' => 'Day-to-day HR operations.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'employee.create', 'employee.update', 'task.view', 'task.act', 'task.delegate', 'onboarding.view', 'onboarding.act', 'document.view', 'document.upload', 'bgv.view', 'leave.view', 'leave.apply', 'user.view', 'celebration.view', 'recognition.view', 'recognition.give', 'wall.view', 'engagement.mood'],
        ],
        'payroll-admin' => [
            'name' => 'Payroll Admin',
            'description' => 'Runs and approves payroll.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'employee.sensitive.view', 'employee.sensitive.update', 'attendance.view', 'leave.view', 'payroll.*', 'expense.view', 'expense.pay', 'compensation.view', 'compensation.execute', 'compliance.view', 'compliance.reports.view', 'compliance.reports.generate', 'ai.use', 'ai.payroll_auditor', 'task.view', 'task.act', 'task.delegate', 'audit.view'],
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
            'permissions' => ['company.view', 'organisation.view', 'employee.view', 'asset.*', 'task.view', 'task.act', 'task.delegate'],
        ],
        // HROne setup parity (Expense): expense masters, expense and advance policies, approvals and payments.
        'expense-admin' => [
            'name' => 'Expense Admin',
            'description' => 'Configures expense categories, limits and policies; decides and pays expenses.',
            'permissions' => ['company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'policy.view', 'policy.create', 'policy.update', 'policy.publish', 'currency.manage', 'expense.*', 'task.view', 'task.act', 'task.delegate'],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'People manager.',
            'permissions' => ['company.view', 'organisation.view', 'employee.view', 'task.view', 'task.act', 'task.delegate', 'onboarding.view', 'onboarding.act', 'attendance.view', 'attendance.approve', 'attendance.regularise', 'leave.view', 'leave.apply', 'leave.approve', 'payroll.request', 'expense.claim', 'expense.approve', 'performance.goals', 'performance.review', 'performance.feedback', 'performance.team', 'performance.initiatives', 'learning.learn', 'learning.assign', 'learning.team', 'learning.approve', 'skills.self', 'skills.assess', 'development.own', 'development.team', 'career.self', 'career.team', 'workforce.team', 'asset.own', 'asset.request', 'servicedesk.request', 'servicedesk.team', 'grievance.raise', 'kb.view', 'communication.view', 'engagement.participate', 'engagement.team_results', 'exit.clear', 'exit.resign', 'ai.use', 'ai.manager', 'celebration.view', 'recognition.view', 'recognition.give', 'wall.view', 'engagement.mood'],
        ],
        'employee' => [
            'name' => 'Employee',
            'description' => 'Standard employee access.',
            'permissions' => ['employee.self', 'task.view', 'task.act', 'task.delegate', 'onboarding.act', 'attendance.regularise', 'leave.apply', 'payroll.payslip', 'payroll.request', 'expense.claim', 'compensation.self', 'document.own', 'performance.goals', 'performance.review', 'performance.feedback', 'performance.initiatives', 'learning.learn', 'skills.self', 'development.own', 'career.self', 'asset.own', 'asset.request', 'servicedesk.request', 'grievance.raise', 'kb.view', 'communication.view', 'engagement.participate', 'exit.resign', 'ai.use', 'celebration.view', 'recognition.view', 'recognition.give', 'wall.view', 'engagement.mood'],
        ],
        'executive' => [
            'name' => 'Executive',
            'description' => 'Workforce Command Centre and dashboards; no transactional access.',
            'permissions' => ['analytics.view', 'analytics.executive', 'analytics.reports', 'ai.workforce', 'company.view', 'organisation.view', 'workforce.analytics', 'compensation.analytics'],
        ],
        'alumni' => [
            'name' => 'Alumni',
            'description' => 'Former employee: own documents and requests only.',
            'permissions' => ['alumni.portal', 'payroll.payslip'],
        ],
        // HROne parity SV-7 (AR-1, page-access group EXTERNAL): business partners, consultants and vendors who sign in without
        // an employee record. A minimal set (their own tasks), no employee data. Nobody holds it until partner sign-in is
        // allowed (owner decision PRV-9 / IP-04).
        'external' => [
            'name' => 'External user',
            'description' => 'Business partner, consultant or vendor without an employee record: their own tasks only, no employee data.',
            'permissions' => ['task.view', 'task.act'],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Read-only access with full audit visibility.',
            'permissions' => ['audit.*', 'bulk.view_all', 'company.view', 'organisation.view', 'people_setup.view', 'employee.view', 'custom_field.view', 'form.view', 'policy.view', 'configuration.view', 'workflow.view', 'task.view_all', 'notification.view', 'notification.deliveries', 'onboarding.view', 'document.view', 'bgv.view', 'attendance.view', 'leave.view', 'payroll.view', 'compensation.view', 'compliance.view', 'performance.view', 'learning.view', 'skills.view', 'development.view', 'career.view', 'workforce.view', 'asset.view', 'expense.view', 'servicedesk.view', 'kb.view', 'communication.view', 'engagement.view', 'exit.view', 'letter.view', 'alumni.view', 'analytics.view', 'analytics.executive', 'ai.admin', 'webhook.manage', 'user.view', 'role.view', 'settings.view', 'features.view', 'feedback.view'],
        ],
    ],

    /*
    | Feature flags every tenant starts with. Tenants toggle these; platform adds new ones. A flag marked
    | 'platform' => true is switched per tenant by a platform operator only (FeatureFlags::setForTenant).
    */
    'features' => [
        'employee.import_export' => ['enabled' => false, 'platform' => true, 'description' => 'Bulk employee import and export (CSV in the import template format), switched per organisation by Markedge'],
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
    // Production readiness closure: the load balancer / TLS terminator addresses whose X-Forwarded-* headers are
    // trusted (comma-separated IPs or CIDRs, or * when only the proxy can reach the app). Empty = trust none.
    'http' => [
        'trusted_proxies' => env('PEOPLEOS_TRUSTED_PROXIES', ''),
    ],

    // Production readiness closure: outbound requests to tenant-configured destinations (webhooks, workflow
    // webhook nodes, SSO endpoints) pass the SSRF guard. Operators may exempt exact host names (never tenants).
    'outbound' => [
        'allow_http' => (bool) env('PEOPLEOS_OUTBOUND_ALLOW_HTTP', false),
        'allowed_ports' => array_map('intval', array_filter(explode(',', (string) env('PEOPLEOS_OUTBOUND_ALLOWED_PORTS', '443,80,8443,8080')))),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PEOPLEOS_OUTBOUND_ALLOWED_HOSTS', ''))))),
    ],

    // Production readiness closure: storage roles. Every role defaults to the local private disk for
    // development; production on more than one node points them at shared private object storage (s3)
    // and sets PEOPLEOS_SHARED_STORAGE_REQUIRED=true, so the validator refuses any local role.
    'storage' => [
        // Upload staging (Filament form uploads before the domain stores them).
        'staging_disk' => env('PEOPLEOS_STAGING_DISK', 'local'),
        // Statutory evidence, verification copies and generated return files (platform-level).
        'compliance_disk' => env('PEOPLEOS_COMPLIANCE_DISK', 'local'),
        'shared_required' => (bool) env('PEOPLEOS_SHARED_STORAGE_REQUIRED', false),
    ],

    // Phase 14 observability. The health token reveals counts and timings on /health/ready (never secrets).
    'health' => [
        'token' => env('PEOPLEOS_HEALTH_TOKEN', ''),
        'heartbeat_max_age' => (int) env('PEOPLEOS_HEARTBEAT_MAX_AGE', 180),
        'queue_backlog_warn' => (int) env('PEOPLEOS_QUEUE_BACKLOG_WARN', 1000),
        // SaaS.8: failed jobs and finished job batches older than this are pruned daily (peopleos:operations:prune). Their
        // payloads are serialized jobs; this is an operational window to investigate and retry, not a legal period.
        'failed_jobs_retention_hours' => max(24, (int) env('PEOPLEOS_FAILED_JOBS_RETENTION_HOURS', 720)),
    ],
    'observability' => [
        // Queries slower than this are logged (SQL text only, never bindings). 0 disables.
        'slow_query_ms' => (int) env('PEOPLEOS_SLOW_QUERY_MS', 1000),
    ],

    /*
    | SaaS.2 foundation hardening. Technical defaults for identity and platform-operator governance; the
    | per-tenant rules (security.mfa_required, password length, idle timeout) stay in tenant settings.
    */
    'security' => [
        // Platform operators can reach every tenant, so they must use an authenticator. Production refuses to
        // run with this off (ProductionConfigValidator); a developer may turn it off locally.
        'platform_mfa_required' => (bool) env('PEOPLEOS_PLATFORM_MFA_REQUIRED', true),
        // SaaS.8: Strict-Transport-Security on HTTPS responses (seconds; 0 = off). Subdomains are not included.
        'hsts_max_age' => max(0, (int) env('PEOPLEOS_HSTS_MAX_AGE', 31536000)),
        // SaaS.11: X-Robots-Tag on every response (a private platform is never indexed); empty turns it off.
        'robots_tag' => (string) env('PEOPLEOS_ROBOTS_TAG', 'noindex, nofollow'),
    ],
    /*
    | SaaS.9 data export, offboarding, retention and deletion. Retention PERIODS are not here: they are versioned policies
    | (Platform → Retention policies), approved by a second operator. These are operational settings.
    */
    'offboarding' => [
        // The destructive switch. Off, no deletion is planned, approved or executed (the eligibility preview still shows). It is turned
        // on per environment only after the SaaS.9 validation gate, never for the showcase or a development copy.
        'deletion_enabled' => (bool) env('PEOPLEOS_TENANT_DELETION_ENABLED', false),
        // SaaS.11: the reference of the owner's authorisation to enable deletion (approved policies, rehearsal). The production
        // configuration gate refuses deletion switched on without it.
        'deletion_authorisation' => env('PEOPLEOS_TENANT_DELETION_AUTHORISATION'),
        // SaaS.12: the deletion register, outside the database: one signed certificate per completed deletion, written once.
        // After a restore it tells which deletions the restored data predates (peopleos:deletions:reconcile). Production
        // keeps it on its own private disk (separately backed up, versioned); unset, the local private disk is used, and
        // the production configuration gate refuses deletion switched on.
        'register_disk' => env('PEOPLEOS_DELETION_REGISTER_DISK'),
        // Days between the second operator's approval and the start of a deletion (SaaS.1 Target Architecture §9 proposal).
        'cooling_off_days' => max(0, (int) env('PEOPLEOS_DELETION_COOLING_OFF_DAYS', 14)),
        // A deletion needs an export that was delivered (downloaded, or delivered out of band) or explicitly waived.
        'export_required_before_deletion' => (bool) env('PEOPLEOS_EXPORT_REQUIRED_BEFORE_DELETION', true),
        // A ready export package is deleted after this many days (it stays recorded, with its checksums).
        'export_expiry_days' => max(1, (int) env('PEOPLEOS_EXPORT_EXPIRY_DAYS', 7)),
        // A download link (signed, bound to the person) lives this many minutes.
        'download_link_minutes' => max(1, (int) env('PEOPLEOS_EXPORT_DOWNLOAD_LINK_MINUTES', 5)),
        // An export whose worker has not reported for this long is picked up again; after max attempts it fails.
        'export_lease_minutes' => max(5, (int) env('PEOPLEOS_EXPORT_LEASE_MINUTES', 30)),
        'export_max_attempts' => max(1, (int) env('PEOPLEOS_EXPORT_MAX_ATTEMPTS', 5)),
        // The private disk for export packages (outside every tenant prefix, so a deletion never removes a live package).
        'export_disk' => env('PEOPLEOS_EXPORT_DISK', 'local'),
        // Rows per statement when deleting or anonymising.
        'batch_size' => max(50, (int) env('PEOPLEOS_DELETION_BATCH_SIZE', 1000)),
        // A deletion whose worker has not reported for this long can be resumed by an operator.
        'deletion_lease_minutes' => max(5, (int) env('PEOPLEOS_DELETION_LEASE_MINUTES', 30)),
    ],
    // SaaS.10: self-service signup. Operational and security parameters only; what is offered (plans, trial days,
    // countries), the review policy and the terms are approved commercial configuration (Commercial policies page).
    'signup' => [
        // D10-1: public registration is off until the owner authorises it after a security review. Off: every signup page
        // shows a short notice and every signup action is refused; existing tenants and the operator page are unaffected.
        'enabled' => (bool) env('PEOPLEOS_PUBLIC_SIGNUP_ENABLED', false),
        // SaaS.11: the reference of the owner's written authorisation (D10-1). The production configuration gate refuses
        // registration switched on without it; it is recorded, never inferred.
        'authorisation' => env('PEOPLEOS_PUBLIC_SIGNUP_AUTHORISATION'),
        // How long an e-mail confirmation link works (the link is single-use; a new one replaces it).
        'link_hours' => max(1, (int) env('PEOPLEOS_SIGNUP_LINK_HOURS', 24)),
        // A confirmed application whose details are not submitted within this many days expires (kept, never deleted).
        'completion_days' => max(1, (int) env('PEOPLEOS_SIGNUP_COMPLETION_DAYS', 14)),
        // Abuse limits (per hour unless stated). Answers stay the same whether or not a limit applies.
        'limits' => [
            'start_per_ip' => max(1, (int) env('PEOPLEOS_SIGNUP_START_PER_IP', 10)),
            'start_per_address' => max(1, (int) env('PEOPLEOS_SIGNUP_START_PER_ADDRESS', 3)),
            'confirm_per_ip_per_minute' => max(1, (int) env('PEOPLEOS_SIGNUP_CONFIRM_PER_IP', 10)),
            'activation_resend_seconds' => max(60, (int) env('PEOPLEOS_SIGNUP_ACTIVATION_RESEND_SECONDS', 300)),
        ],
        // Provisioning retries (minutes before attempt 2, 3, …); after max_attempts the application needs a person.
        'provisioning' => [
            'max_attempts' => max(1, (int) env('PEOPLEOS_SIGNUP_PROVISIONING_ATTEMPTS', 5)),
            'backoff_minutes' => [1, 5, 15, 60],
            // A run that has not committed after this long is treated as interrupted (its transaction is gone).
            'lease_minutes' => max(5, (int) env('PEOPLEOS_SIGNUP_LEASE_MINUTES', 15)),
            // Provisioning runs one at a time (a lock in the application cache, shared by every server); a run waits this
            // long for the one before it, then leaves the application for the next tick.
            'wait_seconds' => max(1, (int) env('PEOPLEOS_SIGNUP_PROVISIONING_WAIT_SECONDS', 30)),
            // The owner's invitation e-mail is retried by the tick this many times when the mail transport refused it.
            'invitation_attempts' => 5,
        ],
        // Tenant addresses a company name may never take.
        'reserved_slugs' => ['admin', 'api', 'app', 'www', 'mail', 'help', 'support', 'status', 'login', 'signup', 'platform', 'system', 'root', 'billing', 'security', 'markedge', 'peopleos', 'sso', 'webhooks', 'docs'],
        'max_intended_employees' => 1_000_000,
    ],
    'identity' => [
        // How long an invitation link stays valid.
        'invitation_hours' => max(1, (int) env('PEOPLEOS_INVITATION_HOURS', 72)),
        // SaaS.8: an operator cannot e-mail a tenant's first administrator two invitation links within this many seconds.
        'invitation_resend_seconds' => max(0, (int) env('PEOPLEOS_INVITATION_RESEND_SECONDS', 60)),
        // SaaS.10: the roles a tenant's first administrator is given (operator provisioning: Tenant Super Admin; self-service
        // signup: Account Owner). Used to find a first administrator still waiting for an invitation.
        'first_administrator_roles' => ['tenant-super-admin', 'account-owner'],
    ],
    'platform' => [
        // How long one controlled operator access to a tenant lasts before it ends on its own.
        'tenant_access_minutes' => max(5, (int) env('PEOPLEOS_PLATFORM_TENANT_ACCESS_MINUTES', 60)),
    ],
    'seed' => [
        // The password DatabaseSeeder gives every demo account; when unset, one is generated per run.
        'password' => env('PEOPLEOS_SEED_PASSWORD'),
    ],
    /*
    | ProductionSeeder (`php artisan db:seed --force`): the first platform operator and, optionally, the first tenant,
    | each created once and never changed afterwards. No password exists here: the operator receives a password-reset
    | link and enrols MFA at the first sign-in; the tenant's administrator receives an invitation. Leave empty to skip.
    */
    'bootstrap' => [
        'operator' => [
            'email' => env('PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL'),
            'name' => env('PEOPLEOS_BOOTSTRAP_OPERATOR_NAME'),
        ],
        'tenant' => [
            'name' => env('PEOPLEOS_BOOTSTRAP_TENANT_NAME'),
            'slug' => env('PEOPLEOS_BOOTSTRAP_TENANT_SLUG'),
            'country_code' => env('PEOPLEOS_BOOTSTRAP_TENANT_COUNTRY', 'IN'),
            'timezone' => env('PEOPLEOS_BOOTSTRAP_TENANT_TIMEZONE', 'Asia/Kolkata'),
            'currency' => env('PEOPLEOS_BOOTSTRAP_TENANT_CURRENCY', 'INR'),
            'admin_name' => env('PEOPLEOS_BOOTSTRAP_TENANT_ADMIN_NAME'),
            'admin_email' => env('PEOPLEOS_BOOTSTRAP_TENANT_ADMIN_EMAIL'),
        ],
    ],
    /*
    | SaaS.3 commercial entitlements. SHADOW ONLY: decisions are evaluated and observed, never enforced (there is no
    | enforcing mode). `off` stops observation without touching anything else. The catalogue is the Capability enum.
    */
    'entitlements' => [
        'mode' => env('PEOPLEOS_ENTITLEMENTS_MODE', 'shadow'),
        // How long a tenant's resolved entitlement state stays in the cache (it is also forgotten on every change).
        'cache_seconds' => max(1, (int) env('PEOPLEOS_ENTITLEMENTS_CACHE_SECONDS', 600)),
        'shadow' => [
            // At most one database write per distinct observation per window, across all processes.
            'window_seconds' => max(1, (int) env('PEOPLEOS_ENTITLEMENTS_SHADOW_WINDOW_SECONDS', 600)),
            // Aggregated observations are purged by retention:purge after this many days.
            'retention_days' => max(1, (int) env('PEOPLEOS_ENTITLEMENTS_SHADOW_RETENTION_DAYS', 90)),
        ],
    ],
    /*
    | SaaS.7 billing, tax and payments. No market, price, tax rule, supplier or provider account is configured here:
    | those are operator data and business decisions. Only the sandbox provider (test mode, no money moves) can be
    | enabled, and never in production; its webhook secret comes from the environment only.
    */
    'billing' => [
        'sandbox' => [
            'enabled' => (bool) env('PEOPLEOS_BILLING_SANDBOX', false),
            'webhook_secret' => env('PEOPLEOS_BILLING_SANDBOX_SECRET'),
            'tolerance_seconds' => max(30, (int) env('PEOPLEOS_BILLING_SANDBOX_TOLERANCE', 300)),
        ],
        // Provider webhooks: largest accepted body and requests per minute per IP.
        'webhook_max_bytes' => max(1024, (int) env('PEOPLEOS_BILLING_WEBHOOK_MAX_BYTES', 65536)),
        'webhook_rate_per_minute' => max(10, (int) env('PEOPLEOS_BILLING_WEBHOOK_RATE', 120)),
        // The billing run (drafts only; issue stays an operator step). Off unless enabled: no price exists until B-4.
        'run_enabled' => (bool) env('PEOPLEOS_BILLING_RUN_ENABLED', false),
        // B-10: Razorpay, TEST MODE ONLY. Enabled only with rzp_test_ keys and never in production (ProviderRegistry);
        // the currencies offered are confirmed with Razorpay at merchant onboarding (international payments).
        'razorpay' => [
            'enabled' => (bool) env('PEOPLEOS_RAZORPAY_ENABLED', false),
            'key_id' => env('PEOPLEOS_RAZORPAY_KEY_ID'),
            'key_secret' => env('PEOPLEOS_RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('PEOPLEOS_RAZORPAY_WEBHOOK_SECRET'),
            'base_url' => 'https://api.razorpay.com/v1',
            'timeout_seconds' => 15,
            'currencies' => array_values(array_filter(array_map('trim', explode(',', (string) env('PEOPLEOS_RAZORPAY_CURRENCIES', 'INR,USD,GBP,EUR,AED'))))),
        ],
    ],

    /*
    | SaaS.7 commercial configuration: the SHIPPED DEFAULTS of Markedge's commercial policy (approved decisions). They
    | apply until an operator proposes and a second operator approves a version on Platform > Commercial policies, which
    | then applies from its effective date (no deployment). Statutory values are not here: they ship in the statutory
    | dataset (database/data/statutory) and become usable only once verified. Prices and customer deals are never config.
    */
    'commercial' => [
        'policy_defaults' => [
            'billing.payment_terms_days' => 15,          // B-11: net 15 from the issue date
            'billing.b2b_only' => true,                  // B-5: business customers only at launch
            'billing.price_increase_notice_days' => 30,  // B-15: written notice before an increase for an existing subscriber
            'billing.prices_include_tax' => false,       // B-5: prices are tax-exclusive
            'billing.proration_rounding' => 'half_up',
            // SaaS.10 (D10-3 open; D-9 "sales-led first"): every signup application is reviewed by an operator until an
            // approved version says otherwise. Offers, terms and the privacy notice have no default (not offered).
            'signup.review' => 'all',   // B-3: a prorated line is rounded once, half up
            'settlement.tds_jurisdictions' => [['country' => 'IN', 'currency' => 'INR']], // B-11: customer TDS (India)
        ],
        'statutory_dataset' => [
            'path' => 'database/data/statutory',
            'current' => '2026.10',
        ],
    ],

    'settings' => [
        'branding.display_name' => null,
        'branding.primary_colour' => '#f59e0b',
        'locale.date_format' => 'd M Y',
        'locale.time_format' => 'H:i',
        'employee.code.prefix' => 'EMP',
        'employee.code.padding' => 5,
        'employee.probation.default_months' => 6,
        'employee.probation.reminder_days' => 14,
        'employee.joining.reminder_days' => 3,
        // Birthdays: who sees colleagues' birthdays (organisation | company | team), the built-in greeting ({name},
        // {organisation}), who is nudged on the day (none | manager | team), and the lists for celebration managers: the
        // monthly list goes out digest_lead_days before the month starts (0 = on the 1st), the day's list on the day.
        // HROne setup parity CA-6 (Core HR → Employee → Profile access): which profile sections each audience sees, as an
        // extra condition on top of permissions (it only hides). Rows = peopleos.profile.sections, columns employee (own
        // profile), manager (their managers), others (anyone else who may open the profile; people who maintain employee
        // records are not an audience). Every box ticked = as before.
        'profile.section_visibility' => array_fill_keys(['personal', 'family', 'professional', 'skills', 'bank', 'documents', 'previous_employer', 'other_details', 'history'], ['employee' => true, 'manager' => true, 'others' => true]),
        // HROne parity MS-3 (Core HR → Profile changes; owner decisions SSE-1 / D-MS2 and SSE-2 / D-MS11 open): which parts
        // of their profile employees change themselves on My profile, and whether a change waits for approval. Rows =
        // peopleos.profile.self_edit_sections; default nothing editable = as before. Bank and identifiers always need
        // approval whatever the box says; legal name and date of birth only with legal_identity on, always with approval
        // and a document. lock_during_bgv: refuse a change while a background verification of the employee is open.
        'profile.self_edit.sections' => array_fill_keys(['blood_group', 'contact', 'addresses', 'emergency_contacts', 'identifiers', 'family', 'education', 'experience', 'skills', 'other_details', 'bank'], ['editable' => false, 'needs_approval' => true]),
        'profile.self_edit.legal_identity' => false,
        'profile.self_edit.lock_during_bgv' => false,
        // HROne setup parity CA-6 (Core HR → Employee → Social profile access): extra sections on a person's directory card
        // (peopleos.directory.sections; none = the directory as before), and the ones a person may hide from colleagues
        // on Preferences (people.directory_hidden).
        'directory.sections_visible' => [],
        'directory.sections_employee_may_hide' => [],
        // HROne parity MS-1 (Core HR → My space): items left out of My space (registry keys of peopleos.myspace_items).
        // None = every item the person may open is listed, as the pages themselves are. Hiding never grants anything and
        // never blocks a page: it only leaves the item out of My space, its favourites and its search results.
        'myspace.hidden_items' => [],
        'celebration.birthdays.visible_to' => 'organisation',
        'celebration.birthdays.greeting_enabled' => true,
        'celebration.birthdays.greeting_message' => 'Happy birthday, {name}! Wishing you a wonderful year ahead, from all of us at {organisation}.',
        'celebration.birthdays.announce_to' => 'team',
        'celebration.birthdays.digest_enabled' => true,
        'celebration.birthdays.digest_lead_days' => 0,
        'celebration.birthdays.notify_admins_on_day' => true,
        'celebration.birthdays.email' => true,
        // HROne setup parity HP-9a (Engage → Celebration). Roles whose holders also get the day's list of birthdays they can
        // see (none = as before). Work and wedding anniversaries are off until switched on; each has its greeting ({name},
        // {years} for work anniversaries, {organisation}), who is told on the day, who sees them and the roles told.
        'celebration.birthdays.notify_roles' => [],
        'celebration.work_anniversary.enabled' => false,
        'celebration.work_anniversary.greeting_message' => 'Happy work anniversary, {name}! Thank you for {years} with {organisation}.',
        'celebration.work_anniversary.announce_to' => 'team',
        'celebration.work_anniversary.visible_to' => 'organisation',
        'celebration.work_anniversary.notify_roles' => [],
        'celebration.wedding_anniversary.enabled' => false,
        'celebration.wedding_anniversary.greeting_message' => 'Happy wedding anniversary, {name}! Warm wishes from all of us at {organisation}.',
        'celebration.wedding_anniversary.announce_to' => 'team',
        'celebration.wedding_anniversary.visible_to' => 'organisation',
        'celebration.wedding_anniversary.notify_roles' => [],
        // The first-login welcome on Home (on, PeopleOS's own text when no message is set; {name}, {organisation}).
        'experience.welcome.enabled' => true,
        'experience.welcome.message' => null,
        // HROne setup parity HP-9a (Engage → HR handbook): days to acknowledge a handbook article after it is published
        // (0 = no due date: reminders start after peopleos.servicedesk.policy_acknowledgement_reminder_days, as before),
        // and whether readers may download an article.
        'kb.acknowledgement.due_days' => 0,
        'kb.download_allowed' => false,
        // HROne setup parity HP-9c (Engage → Badges): badges one person may give in a calendar month (0 = no limit), whether
        // custom badges may be created, "Share on LinkedIn" for the receiver of a public badge, and the default certificate
        // (letter template code; none = no certificate unless the badge maps one).
        'engage.badges.per_month' => 10,
        'engage.badges.custom_allowed' => true,
        'engage.badges.linkedin_share' => false,
        'engage.badges.certificate_template' => null,
        // HROne setup parity HP-9d (Engage → Reward points, Social wall → points value): reward points are off. Once on, the
        // reward_points policy credits each eligible giver's points per cycle from the points budget HR deposits; badges may
        // carry points. The money worth of one point and its currency (blank = tenant.base_currency) are a one-time setting:
        // locked once the points ledger has an entry. Redemption modes: none (default) or through payroll (a taxable
        // earning REWARD); employees redeem their own points only when self_redeem is on (HR always may).
        'engage.points.enabled' => false,
        'engage.points.value_per_point' => null,
        'engage.points.currency' => null,
        'engage.points.redeem_modes' => [],
        'engage.points.self_redeem' => false,
        // HROne setup parity HP-9e (Engage → Social wall): the wall is off. Once on: who sees whose posts (organisation or
        // the viewer's company), the post types colleagues may not comment on, the festive banner on holidays, whether
        // achievements (completed goals, learning certificates) are posted, and whether HR posts may carry attachments.
        'engage.wall.enabled' => false,
        'engage.wall.scope' => 'organisation',
        'engage.wall.comments_disabled_for' => [],
        'engage.wall.festive_theme' => false,
        'engage.wall.show_achievements' => false,
        'engage.wall.attachments' => true,
        // HROne setup parity HP-9f (Engage → Happiness index): the mood bot is off. Once on: how often employees are asked
        // (daily, weekly, fortnightly, monthly), from which weekday (weekly and fortnightly) and how check-ins are kept
        // (anonymous: no link to the person; confidential: the link only for engagement.confidential_identity, with a
        // reason; identified). Results follow the engagement privacy thresholds (engagement.analytics_min_group).
        'engage.mood.enabled' => false,
        'engage.mood.frequency' => 'weekly',
        'engage.mood.weekday' => 5,
        'engage.mood.privacy' => 'anonymous',
        'documents.expiry.reminder_days' => 30,
        // HROne setup parity (Core HR, CA-2): the organisation's website and industry (the default for new companies
        // and establishments), its logo (a Setup file on the documents disk; none = the PeopleOS mark), the workspace
        // colour (hex; none = the PeopleOS iris; branding.primary_colour above was stored for every tenant but never
        // applied, so it is not the switch) and whether people may switch light / dark themselves.
        'branding.website' => null,
        'organisation.industry' => null,
        'branding.logo_path' => null,
        'branding.theme_colour' => null,
        'branding.employee_may_change_theme' => true,
        // HROne setup parity (Core HR → Document, CA-4): an onboarding employee cannot join while a document type marked
        // "mandatory for onboarding" is missing (off: the flag is informative, as before).
        'documents.block_joining_without_mandatory' => false,
        // HROne setup parity (Core HR → Skill proficiency, CA-3): the skill scale whose levels profile skills and career
        // path steps use (0 = the built-in Beginner … Expert list).
        'skills.profile_scale' => 0,
        'attendance.punch_window_hours' => 4,
        'attendance.regularisation.window_days' => 7,
        'leave.year_start_month' => 1,
        // Pay cycle: dated changes [{from: Y-m-d}], each the first day of the first month on a cycle (from 25 Nov 2026,
        // December runs 25 Nov – 24 Dec). None = calendar months. Set at Payroll → Pay cycle (PayCycle).
        'payroll.pay_cycle' => [],
        'payroll.lop_from_attendance' => true,
        'payroll.proration_basis' => 'calendar_days',
        'payroll.proration_fixed_days' => 30,
        'payroll.negative_net_policy' => 'block',
        // HROne parity PY-11: approved leave encashments are paid in monthly payroll at a day's pay on this basis
        // (basic | basic_da | gross) over this many days.
        'payroll.encashment_basis' => 'basic',
        'payroll.encashment_divisor' => 30,
        // HROne parity PY-02: claims of pay-on-claim reimbursements need bill attachments; claims beyond the balance.
        'payroll.claims.attachment_required' => true,
        'payroll.claims.allow_beyond_balance' => false,
        // HROne parity HP-3 (PY-12, PY-13): tax declaration and proof windows (blank = always open), the payment date
        // from which TDS counts only verified proof amounts (blank = never), regime lock, landlord PAN and landlords.
        'payroll.tax.declaration_from' => null,
        'payroll.tax.declaration_to' => null,
        'payroll.tax.proof_from' => null,
        'payroll.tax.proof_to' => null,
        'payroll.tax.verified_proofs_from' => null,
        'payroll.tax.regime_locked_after_submit' => false,
        'payroll.tax.landlord_pan_required' => false,
        'payroll.tax.max_landlords' => 1,
        // HROne setup parity (PS-2): days of the month the declaration is open, approver edits, Form 12BB, estimator, Form 16.
        'payroll.tax.declaration_day_from' => null,
        'payroll.tax.declaration_day_to' => null,
        'payroll.tax.payroll_may_edit' => true,
        'payroll.tax.form12bb_visibility' => 'always',
        'payroll.tax.show_estimator' => false,
        'payroll.tax.form16_self_service' => false,
        'payroll.claims.new_regime_bills' => 'allow',
        // HROne setup parity (PS-3): what employees see on My compensation.
        'compensation.self_service.show_history' => true,
        'compensation.self_service.show_net_take_home' => false,
        'compensation.self_service.show_wage_split' => false,
        'compensation.self_service.show_perquisites' => false,
        'compensation.self_service.show_insight' => false,
        // HROne setup parity BP-9: claims (one open claim per component, never above the bills, reminders to claim:
        // off | monthly | quarter_end | year_end), the day of the month managers are asked for planned variable pay
        // (0 = never) and whether employees see their variable pay schedule on My compensation.
        'payroll.claims.one_open_per_component' => false,
        'payroll.claims.not_above_bills' => false,
        'payroll.claims.reminder_task' => 'off',
        'payroll.variable.task_day' => 0,
        'compensation.self_service.show_variable_plan' => false,
        // HROne setup parity BP-13: the pay modes in use (online_transfer | cash | cheque; employees without a payment
        // mode are paid by bank transfer) and whether HR sees the IFSC and branch of employees' bank accounts.
        'payroll.pay_modes' => ['online_transfer'],
        'payroll.bank.show_ifsc_branch' => true,
        'payroll.adjustments.require_approval' => false,
        'attendance.process_on_punch' => true,
        // HROne setup parity (TO-3): regularisation, on duty and short leave stay possible on a day that already earned
        // approved overtime or a comp-off (off: refused).
        'attendance.requests_after_benefits' => true,
        // HROne setup parity (TO-6): restricted-holiday quotas count per calendar year or financial year.
        'attendance.holiday_year_basis' => 'calendar',
        // HROne parity HP-1: corrections (regularisations, time-office requests, leave, HR overrides) on days a finalised
        // payroll locked. Off by default: such days stay frozen. On: the locked day is kept and the difference in paid
        // days is recorded as an attendance arrear for the next payroll.
        'attendance.allow_corrections_after_lock' => false,
        // HROne parity CF-08: a weekly off (or also a holiday) between two absent / unpaid-leave days is absent ("sandwich").
        'attendance.sandwich_rule' => 'none',
        // HROne parity CF-08: refuse leave for a day attendance already shows as present.
        'leave.block_on_present_day' => false,
        // Leave donation: which leave types colleagues may give each other (codes) and the most a donor gives per year.
        'leave.donation.allowed_types' => ['EL'],
        'leave.donation.max_days_per_year' => 5,
        'tenant.base_currency' => 'INR',
        // Phase 11: employees see their own approved compensation (with compensation.self).
        'compensation.self_service' => true,
        'tenant.locale' => 'en',
        'security.ip_allowlist' => '',
        'security.session_idle_minutes' => 0,
        'security.password_min_length' => 10,
        'security.password_expiry_days' => 0,
        // HROne setup parity (Security → Password policy): reminders before a password expires (only while expiry is on),
        // a strict password also needs a symbol, a password may not contain the person's name, e-mail or employee code,
        // and how many recent passwords may not be used again (0 = any). Defaults keep the earlier rules.
        'security.password_expiry_warning_days' => 7,
        'security.password_require_symbol' => false,
        'security.password_block_personal' => false,
        'security.password_history_count' => 0,
        // HROne setup parity (Security → Disable login): lifecycle states in which an employee cannot sign in.
        'security.disable_login_states' => [],
        // HROne setup parity (Role → reliever of the line manager): who takes a manager approval when the manager's
        // account is inactive or there is no manager (0 = nobody: the workflow's own fallback applies, as before).
        'workflow.manager_reliever_user_id' => 0,
        // HROne setup parity P3 (Inbox → Inbox actions): the request types (ApprovalCenter::kinds()) whose Approval Center
        // items offer approving several at once, a discussion between requester and approvers, and sending back to the
        // requester. Empty lists keep the earlier inbox (one decision at a time, approve or reject).
        'inbox.bulk_approval.types' => [],
        'inbox.discussion.types' => [],
        'inbox.send_back.types' => [],
        // HROne setup parity P4 (Inbox → Delegate, Move to archive): request types an out-of-office delegate may decide in
        // the delegator's stead, and request types offering "Archive" on each item. Empty: no delegation, no archive.
        'inbox.delegation.types' => [],
        'inbox.archive.types' => [],
        // HROne setup parity P4 (Inbox → Archive inbox): bulk archiving of items waiting more than N days, by the person
        // (manual) or every night (auto, peopleos:inbox:archive), every request type except the excluded ones. Off.
        'inbox.archive.enabled' => false,
        'inbox.archive.mode' => 'manual',
        'inbox.archive.after_days' => 90,
        'inbox.archive.excluded_types' => [],
        // HROne setup parity P4 (Inbox → Employee request lock): an employee's own self-service requests are refused while
        // they have tasks overdue by more than after_days (basis overdue_tasks) and/or during a lock period (period), for
        // the request kinds and employees listed (blank = all; the exempted never). Off by default.
        'inbox.ess_lock.enabled' => false,
        'inbox.ess_lock.basis' => 'overdue_tasks',
        'inbox.ess_lock.after_days' => 7,
        'inbox.ess_lock.task_kinds' => [],
        'inbox.ess_lock.period_from' => null,
        'inbox.ess_lock.period_to' => null,
        'inbox.ess_lock.request_kinds' => [],
        'inbox.ess_lock.employee_ids' => [],
        'inbox.ess_lock.exempt_employee_ids' => [],
        // HROne setup parity P5 (Workflow → conditional workflow): only the first matching workflow of a trigger starts,
        // tried by priority (off: every matching workflow starts, as before).
        'workflow.first_match_only' => false,
        // HROne parity RQ-2 (request chrome): a requester may remind the approvers of their own pending request at most once
        // in this many hours (audited); "Notify others" is offered for the request types listed (registry keys; empty = off).
        'requests.reminder.min_hours' => 24,
        'requests.notify_others.types' => [],
        // HROne setup parity P2 (Data import → Import approval): who approves an upload — self (the person working on it,
        // as before; bank and salary uploads stay with their uploader), other_person (maker-checker: someone else who may
        // work on that upload type) or workflow (a workflow on "Data upload validated"; with none running, maker-checker).
        // The mode covers the listed upload types (empty = every type); the optional role narrows who may check.
        'imports.approval_mode' => 'self',
        'imports.approval_types' => [],
        'imports.approver_role' => null,
        'security.mfa_required' => false,
        'retention.ai_interactions_days' => 365,
        'retention.notification_deliveries_days' => 180,
        'retention.report_runs_days' => 90,
        // HROne parity CA-11: API call logs (method, path, status, duration, IP, user agent) are kept this many days.
        'retention.api_request_logs_days' => 90,
        'exit.notice_days' => 30,
        'exit.clearance_lead_days' => 7,
        'exit.encashment_basis' => 'basic',
        'exit.notice_recovery' => true,
        'exit.it_clearance_role' => 'asset-admin',
        'exit.finance_clearance_role' => 'payroll-admin',
        'exit.hr_clearance_role' => 'tenant-hr-admin',
        // HROne setup parity AS-A (Asset → Settings → Relieving): assets still in custody block the asset clearance until a
        // recoverable amount is entered (true, as before); false lets the stage clear with an audited waiver reason. The
        // amount the clearance form suggests: manual (none) or the assets' book value (cost depreciated over their life).
        'asset.exit.recovery_required' => true,
        'asset.exit.recovery_basis' => 'manual',
        // HROne setup parity AS-A: remind employees every N days to acknowledge assets whose sub type needs it (0 = never).
        'asset.acknowledgement.reminder_days' => 3,
        // HROne setup parity AS-B (Asset → Settings): joiners' entitled assets are due this many days after joining (raised
        // only under an asset entitlement policy); exit returns are due this many days before the last working day; which
        // requests need approval (a workflow on asset.requested / asset.return_requested, else the Approval Center); who
        // may choose the sub type and the product in a request. Owner decision 2026-10-29: return requests are raised at
        // every exit by default (nothing when the employee holds no company asset), except for the exit types and asset
        // types listed (none by default); switching return_requests off stops them altogether.
        'asset.onboarding.due_days' => 7,
        'asset.exit.return_requests' => true,
        'asset.exit.return_excluded_exit_types' => [],
        'asset.exit.return_excluded_categories' => [],
        'asset.exit.due_days' => 0,
        'asset.request.workflow_for' => 'new',
        'asset.request.subtype_visible_to' => ['employee', 'manager', 'hr'],
        'asset.request.model_visible_to' => ['hr'],
        // HROne setup parity HD-A (Helpdesk → Settings): requesters see their request's SLA (resolution due, on track /
        // overdue, paused); a requester closing a resolved request must rate it (where the category takes feedback).
        'servicedesk.show_sla_to_employee' => false,
        'servicedesk.feedback_required' => false,
        // HROne setup parity EX-A/EX-B (Expense → Settings): exceptional expenses (an exceptional-only category, or a claim
        // over its limit where the category allows it) are off until switched on; yearly, half-yearly and quarterly limits
        // and claim frequencies count from this month (April: the Indian financial year).
        'expense.exceptional.enabled' => false,
        'expense.year_start_month' => 4,
        // The expense and travel form field matrices (Expense → Expense and travel forms): only the administrator's
        // changes are stored; unchanged fields follow peopleos.expense.form_fields / travel_form_fields.
        'expense.form.fields' => null,
        'expense.travel_form.fields' => null,
        // HROne setup parity EX-C (Expense → Settings): unreported expenses expire a period after the expense date, the end of
        // its month or the end of its year (0 = never; peopleos:expense:expire); reports are submitted only inside one or two
        // monthly windows when switched on (HR filing on behalf is exempt).
        'expense.expiry.period' => 0,
        'expense.expiry.unit' => 'days',
        'expense.expiry.based_on' => 'expense_date',
        'expense.report_window.enabled' => false,
        'expense.report_window.first_from' => 1,
        'expense.report_window.first_to' => 31,
        'expense.report_window.second_enabled' => false,
        'expense.report_window.second_from' => 16,
        'expense.report_window.second_to' => 31,
        // Mileage distance: typed in, or from a map once a maps provider is connected (EXT); a computed distance (odometer or
        // map) may be edited when allowed, a map one only within the tolerance (beyond it: an exceptional expense).
        'expense.mileage.distance_source' => 'manual',
        'expense.mileage.distance_editable' => true,
        'expense.mileage.map_edit_allowed' => false,
        'expense.mileage.map_edit_tolerance_percent' => 10,
        // What a report may hold, and how it is split for approval: one workflow for everything, or one per expense category
        // or expense group (every one, or only those listed); exceptional expenses always go to their own approval.
        'expense.report.multiple_months' => true,
        'expense.report.mixed_workflow_groups' => true,
        'expense.workflow.split_by' => 'none',
        'expense.workflow.split_scope' => 'listed',
        'expense.workflow.exclusive_categories' => [],
        'expense.workflow.exclusive_groups' => [],
        // Duplicate receipts: the same file is refused; a close match (amount, date, merchant, invoice) at or above the
        // threshold is flagged for the approver. The same invoice number from the same merchant: refuse, flag or allow.
        'expense.duplicates.check' => true,
        'expense.duplicates.threshold_percent' => 80,
        'expense.duplicates.same_invoice_number' => 'flag',
        // Employees see how and when their reports were paid; reports offer a printable cover letter when switched on.
        'expense.payment.show_to_employee' => true,
        'expense.report.cover_letter' => false,
        // HROne setup parity EX-E: is a payment step required after final approval? Yes (as before): approved reports wait on
        // Pay expenses, which pays them outside payroll or schedules them for payroll. No: an approved report goes straight
        // to the next payroll. Reports still waiting for approval at exit may hold the finance clearance (ignore: as before).
        'expense.payment.step_required' => true,
        'expense.exit.pending_reports' => 'ignore',
        // HROne setup parity EX-F (Expense → Settings → Travel): travel requests (trips) — expense reports holding expenses
        // of several trips; one approval per trip, split like reports by category or expense group (the trip's workflow
        // group); several workflow groups in one trip (owner decision 2026-10-29: one trip approved by every group, each
        // deciding its own legs); backdated trips (days, 0 = none) and their delay reason; several
        // legs; an advance asked for with the trip; overlapping trips (a trip may start the day another ends); approved
        // trips on the attendance calendar (off: as before).
        'expense.report.multiple_travel' => false,
        'expense.travel.workflow.split_by' => 'none',
        'expense.travel.workflow.split_scope' => 'listed',
        'expense.travel.workflow.exclusive_categories' => [],
        'expense.travel.workflow.exclusive_groups' => [],
        'expense.travel.mixed_categories' => true,
        'expense.travel.backdated_days' => 0,
        'expense.travel.backdated_reason_required' => true,
        'expense.travel.multi_leg' => true,
        'expense.travel.advance_allowed' => true,
        'expense.travel.block_overlap' => true,
        'expense.travel.same_day_turnaround' => true,
        'expense.travel.on_attendance_calendar' => false,
        'payroll.payslip.prefix' => 'PS',
        'leave.exclude_weekly_offs' => true,
        'leave.exclude_holidays' => true,
        // Lowest risk level that needs approval when the configuration.approval feature is on.
        'configuration.approval.minimum_risk' => 'medium',
        // Phase 14: the warehouse feed carries non-sensitive dataset fields unless this is explicitly enabled.
        'warehouse.include_sensitive' => false,
        // SaaS.8: the daily warehouse feed is opt-in per tenant (it exported every tenant), and its daily snapshots are
        // deleted after this many days by the retention purge (they were never deleted). Operational defaults, not legal
        // retention periods (D-8 is open); each tenant can change both on Security & retention policy.
        'warehouse.export_enabled' => false,
        'warehouse.retention_days' => 30,
        // Phase 14: what may leave the tenant for an external AI provider: none | allowed | restricted (prohibited data never does).
        'ai.external_data_policy' => 'allowed',
        // HROne setup parity HP-8a (Performance → Goal → Performance terminology): the organisation's own name for each
        // performance term (blank = PeopleOS's wording; PerformanceLabels). "Use OKR terms" on Setup → Performance fills
        // KRA, KPI and achievement. Key results may carry a due date; employees may add their own units of measurement.
        'performance.labels.kra' => null,
        'performance.labels.kpi' => null,
        'performance.labels.competency' => null,
        'performance.labels.perspective' => null,
        'performance.labels.uom' => null,
        'performance.labels.target' => null,
        'performance.labels.feedback' => null,
        'performance.labels.achievement' => null,
        'performance.labels.kpi_due_date' => null,
        'performance.goals.kpi_due_date' => false,
        'performance.units.employee_can_add' => false,
        // HROne setup parity HP-8b (Performance → Settings). Defaults keep the earlier behaviour: new cycles are annual
        // with dates typed by hand; ratings reach the employee when finalized (auto); goals change until reviewing starts;
        // the final rating is a scale level, nearest level for labels; one-on-ones are held with one's team; nobody is
        // asked for one-on-one feedback; no scheduled goal check-in prompts; the profile shows no 360 feedback.
        'performance.cycle.default_type' => 'annual',
        'performance.year_start_month' => 0,
        'performance.rating_publish' => 'auto',
        'performance.goals.edit_only_in_goal_stage' => false,
        'performance.profile.show_360' => false,
        'performance.rating.final_decimals' => 0,
        'performance.rating.rounding' => 'round',
        'performance.one_on_one.scope' => 'team',
        'performance.one_on_one.feedback_from' => [],
        'performance.checkin.company.interval' => 'none',
        'performance.checkin.company.day' => 1,
        'performance.checkin.team.interval' => 'none',
        'performance.checkin.team.day' => 1,
        'performance.checkin.employee.interval' => 'none',
        'performance.checkin.employee.day' => 1,
        'performance.checkin.send_at' => 9,
        // HROne setup parity HP-8c (Performance → Review → KPI rating formula): show the formula ("custom") rating of each
        // goal on the review form and how it is used (suggest / prefill / lock), and mark reverse KPIs (lower is better).
        'performance.review.show_formula_rating' => false,
        'performance.review.formula_rating_mode' => 'suggest',
        'performance.review.show_reverse_rating' => false,
        // HROne setup parity HP-8d (Performance settings → isRatingCommentCopyToNextLevel): the next review level's form
        // starts from the previous level's ratings and comments (self → manager → approver). A goal sheet policy may
        // decide it per employee group (copy_goal_inputs / copy_competency_inputs).
        'performance.review.copy_previous_level' => false,
        // HROne setup parity HP-8g (Performance → Bell curve, 9 box). Defaults keep the earlier behaviour: no check of the
        // rating distribution against its targets (no forced distribution), 5 points of tolerance once a check is chosen;
        // the 9-box places potential by the appraisal's competency rating (talent = the potential of the latest final
        // talent assessment, shown only to people who may see talent data).
        'performance.distribution.check' => 'off',
        'performance.distribution.tolerance_pct' => 5,
        'performance.nine_box.potential_source' => 'competencies',
        // HROne parity GO-1 (HROne "Review Reinitiate", owner decision PER-1 / D-GO1 open): HR may send a submitted self,
        // manager or approver review back to its reviewer (performance.manage, reason required, before finalization; the
        // earlier submission stays in the audit trail and the reviewer is told; HR never writes the review). Off = today.
        'performance.review.hr_reopen' => false,
        // HROne parity CA-9 (Access rights → Widget access): Home cards switched off for everyone (HomeComposer block keys;
        // none = every card as before) and the order of the cards the organisation wants first (comma-separated keys; blank
        // = the built-in order). Each person may still hide cards for themselves.
        'home.hidden_blocks' => [],
        'home.block_order' => null,
        // HROne parity CA-9 (Access rights → Calendar access): the events the Team calendar shows to managers (their team)
        // and to HR (their access scope). Default: every event kind (peopleos.calendar.events).
        'calendar.manager_events' => ['birthday', 'work_anniversary', 'wedding_anniversary', 'confirmation', 'joining', 'last_working_day', 'document_expiry', 'leave', 'overtime', 'comp_off', 'one_on_one', 'pip_end', 'initiative_due', 'travel', 'expense', 'badge'],
        'calendar.hr_events' => ['birthday', 'work_anniversary', 'wedding_anniversary', 'confirmation', 'joining', 'last_working_day', 'document_expiry', 'leave', 'overtime', 'comp_off', 'one_on_one', 'pip_end', 'initiative_due', 'travel', 'expense', 'badge'],
        // HROne parity CA-9 (Access rights → Geo access): who sees today's located check-ins and the headcount by location
        // on the Geo dashboard (manager: their team; hr: their access scope). Check-in coordinates are shown on no screen
        // today, so nobody sees them until the organisation chooses (HROne's default is HR; owner decision PRV-14); the
        // headcount by location (people HR already sees with their location) defaults to HR.
        'geo_dashboard.checkins_visible_to' => [],
        'geo_dashboard.headcount_visible_to' => ['hr'],
        // HROne parity CA-10 (Access rights → CXO access): a shared view's periodic notice (its link, never figures) also goes
        // by e-mail, besides the in-app notice. Only views that name e-mail recipients send anything.
        'analytics.views.email' => true,
    ],

    /*
    | Organisation tree. Node types wrap organisation masters; `children` lists which node types
    | may sit directly beneath each type (blueprint §9: hierarchy is configuration, not code).
    */
    'organisation' => [
        // ADR-0001 vocabularies.
        'legal_forms' => ['private_limited' => 'Private limited company', 'public_limited' => 'Public limited company', 'llp' => 'Limited liability partnership', 'partnership' => 'Partnership firm', 'proprietorship' => 'Sole proprietorship', 'trust' => 'Trust', 'society' => 'Society', 'branch_of_foreign_company' => 'Branch of a foreign company', 'government' => 'Government body', 'other' => 'Other'],
        'establishment_types' => ['registered_office' => 'Registered office', 'office' => 'Office', 'branch' => 'Branch', 'factory' => 'Factory', 'warehouse' => 'Warehouse', 'shop' => 'Shop / commercial establishment', 'site' => 'Project site', 'other' => 'Other'],
        // Compliance reports: which government is the "appropriate government" for the establishment under the Labour
        // Codes (Code on Wages s.2(d)). The Central Government for its own establishments, railways, mines, oil fields,
        // major ports, air transport, telecom, banking and insurance companies, central public sector undertakings and
        // their contractors; the State Government for most other private establishments. It decides which rules and forms
        // apply; it is never assumed.
        'appropriate_governments' => ['central' => 'Central Government', 'state' => 'State Government'],
        // Broad activity, for applicability hints (factories, mines, plantations, construction and IT/ITES carry their own rules).
        'industries' => ['manufacturing' => 'Manufacturing', 'it_ites' => 'IT / ITES', 'retail' => 'Retail / trade', 'hospitality' => 'Hotels and restaurants',
            'financial_services' => 'Banking, insurance and financial services', 'telecom' => 'Telecom', 'construction' => 'Building and construction',
            'mining' => 'Mining and oil fields', 'plantation' => 'Plantation', 'transport' => 'Transport, ports and aviation', 'healthcare' => 'Healthcare',
            'education' => 'Education', 'services' => 'Other services', 'other' => 'Other'],
        // HROne setup parity (Enterprise → Contact details): whom the organisation names for HR, decisions and technical
        // administration (security and release notices go to the technical administrator).
        'contact_types' => ['hr' => 'HR contact', 'decision_maker' => 'Decision maker', 'technical_admin' => 'Technical administrator'],
        // Company social links (URLs) shown on the company card.
        'social_networks' => ['linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'x' => 'X (Twitter)', 'instagram' => 'Instagram', 'youtube' => 'YouTube'],
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
        // HROne setup parity (Employee → Qualification): the level of a qualification master.
        'qualification_levels' => ['school' => 'School', 'diploma' => 'Diploma', 'graduate' => 'Graduate', 'postgraduate' => 'Postgraduate', 'doctorate' => 'Doctorate', 'other' => 'Other'],
        // HROne setup parity (Employee → Separation reasons): the kind of a separation reason, for attrition analytics.
        'separation_kinds' => ['voluntary' => 'Voluntary', 'involuntary' => 'Involuntary'],
        'position_change_types' => ['hire' => 'Hire', 'transfer' => 'Transfer', 'promotion' => 'Promotion', 'demotion' => 'Demotion', 'reassignment' => 'Reassignment', 'correction' => 'Data correction'],
    ],

    /*
    | HROne setup parity CA-6 / MS-2: the sections of a person's profile (rows of the profile.section_visibility matrix, the
    | sections of My profile) and the audiences of the matrix. Health insurance joins with MS-5; candidate profile is RMS.
    */
    'profile' => [
        'sections' => [
            'personal' => 'Personal (identification, contact, addresses, emergency contacts)',
            'family' => 'Family',
            'professional' => 'Professional (experience and education)',
            'skills' => 'Skills',
            'bank' => 'Bank',
            'documents' => 'Documents',
            'previous_employer' => 'Previous employer',
            'other_details' => 'Other details',
            'history' => 'History',
        ],
        'audiences' => ['employee' => 'For employee', 'manager' => 'For manager', 'others' => 'For others'],
        // HROne parity MS-3: the parts of a profile employees may be allowed to change on My profile (rows of the
        // profile.self_edit.sections matrix; behaviour in App\Domain\People\Support\ProfileEditSections).
        'self_edit_sections' => [
            'blood_group' => 'Blood group',
            'contact' => 'Personal e-mail and phone',
            'addresses' => 'Addresses',
            'emergency_contacts' => 'Emergency contacts',
            'identifiers' => 'PAN, Aadhaar reference and UAN (always with approval)',
            'family' => 'Marital status and family members',
            'education' => 'Education',
            'experience' => 'Work experience',
            'skills' => 'Skills',
            'other_details' => 'Other details (fields marked "Employee may change it")',
            'bank' => 'Bank account (always with approval)',
        ],
    ],

    /*
    | HROne setup parity CA-6: sections a directory card may show (directory.sections_visible). About, hobbies and badges wait
    | for the social profile (MS-6). Goals show only goals the viewer may already open (sharing with colleagues is MS-6).
    */
    /*
    | HROne parity CA-9 / SV-7 (Access rights). Data-access group modules (HROne Data access → module): a group of the
    | `workforce` module narrows everything its members see about people (the employee master and every employee-linked
    | record); a group of another module narrows only the employee-linked records of that module's domains further.
    | Domains not listed belong to no module (only Workforce groups apply to them). Role membership rules can never
    | grant the roles in `rule_locked_roles`, nor any role carrying a platform permission (tenant.*).
    */
    'access' => [
        'modules' => [
            'workforce' => ['label' => 'Workforce (people everywhere)', 'domains' => ['Employment', 'People', 'Organisation', 'Documents', 'Exit', 'Onboarding', 'Lifecycle', 'Workforce', 'Letters', 'Alumni', 'Bgv']],
            'time_office' => ['label' => 'Time office', 'domains' => ['Attendance', 'Leave']],
            'payroll' => ['label' => 'Payroll', 'domains' => ['Payroll', 'Compensation', 'Tax', 'Loans', 'Claims', 'Compliance', 'ComplianceReports', 'Payments']],
            'performance' => ['label' => 'Performance', 'domains' => ['Performance', 'Feedback', 'Learning', 'Development', 'Career', 'Skills', 'Talent', 'Succession']],
            'engage' => ['label' => 'Engage', 'domains' => ['Engagement', 'Recognition', 'Communication', 'Knowledge']],
            'expense' => ['label' => 'Expense', 'domains' => ['Expense']],
            'asset' => ['label' => 'Asset', 'domains' => ['Assets']],
        ],
        // How many reporting levels "all reportees" follows (data-access groups) and how deep sub-departments are followed.
        'max_depth' => 10,
        'rule_locked_roles' => ['tenant-super-admin', 'account-owner'],
    ],

    /*
    | HROne parity CA-9 (Access rights → Calendar access): the events the Team calendar can show. Each is read through its
    | own domain's rules (birthdays and anniversaries as the celebrations allow; every other event only when the viewer
    | may open the record). Settings calendar.manager_events / calendar.hr_events choose them per audience.
    */
    'calendar' => [
        'events' => [
            'birthday' => 'Birthdays',
            'work_anniversary' => 'Work anniversaries',
            'wedding_anniversary' => 'Marriage anniversaries',
            'confirmation' => 'Confirmation due (probation ends)',
            'joining' => 'New joiners',
            'last_working_day' => 'Last working day',
            'document_expiry' => 'Document expiry (passport, visa …)',
            'leave' => 'Leave',
            'overtime' => 'Overtime',
            'comp_off' => 'Comp-off',
            'one_on_one' => 'One-on-ones',
            'pip_end' => 'Improvement plan ends',
            'initiative_due' => 'Initiative due dates',
            'travel' => 'Travel plans',
            'expense' => 'Expense claims (amount or mileage)',
            'badge' => 'Badges received',
        ],
    ],

    'directory' => [
        'sections' => [
            'experience' => 'Work experience',
            'education' => 'Education',
            'skills' => 'Skills',
            'goals' => 'Goals',
            'planned_leaves' => 'Planned leaves',
            'last_checkin' => 'Last check-in',
            'off_days' => 'Holidays and week-offs',
        ],
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
            // HROne setup parity EX-A (Expense form / Travel plan form "Field one … Field ten"): fields can be defined now and
            // are placed per expense type on Expense → Expense and travel forms; the receipt (EX-C) and travel request (EX-F)
            // models that store the values carry these class names.
            'expense_receipt' => ['label' => 'Expense receipt', 'model' => 'App\\Domain\\Expense\\Models\\ExpenseReceipt'],
            'travel_request' => ['label' => 'Travel request', 'model' => 'App\\Domain\\Expense\\Models\\TravelRequest'],
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
            // HROne setup parity (sub-departments): the parent of the employee's department, so a rule can target either level.
            'parent_department_id' => ['label' => 'Parent department', 'type' => 'model', 'model' => Department::class],
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
            'employee_id' => ['label' => 'Employee', 'type' => 'employee'],
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
                    ['key' => 'accrual_frequency', 'label' => 'Accrual', 'type' => 'select', 'options' => ['annual' => 'Annual (credited at year start)', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly']],
                    // HROne parity CF-09: what a joiner gets for the month / quarter / half-year they join in (periodic accrual).
                    ['key' => 'joiner_credit', 'label' => 'Credit for the period of joining (monthly, quarterly, half-yearly)', 'type' => 'select', 'options' => ['full' => 'Full credit', 'rule_of_15' => 'Rule of 15: all if joined by the 15th, none after', 'rule_of_15_half' => 'Rule of 15: all if joined by the 15th, half after', 'exclude' => 'No credit for the period of joining', 'proportionate' => 'Proportionate to the days left']],
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
                    // HROne setup parity (TO-7): leave-type rules from HROne's leave type steps, per leave type in the policy.
                    ['key' => 'esi_applicability', 'label' => 'Applicable to', 'type' => 'select', 'options' => ['any' => 'ESI and non-ESI', 'esi' => 'ESI members only', 'non_esi' => 'Non-ESI only']],
                    ['key' => 'min_age', 'label' => 'Minimum age (0 = none)', 'type' => 'number'],
                    ['key' => 'max_age', 'label' => 'Maximum age (0 = none)', 'type' => 'number'],
                    ['key' => 'marital_status', 'label' => 'Marital status', 'type' => 'select', 'options' => ['any' => 'Any', 'married' => 'Married only']],
                    ['key' => 'max_requests_per_year', 'label' => 'Most requests a year (0 = any number)', 'type' => 'number'],
                    ['key' => 'max_requests_per_month', 'label' => 'Most requests a month (0 = any number)', 'type' => 'number'],
                    ['key' => 'max_requests_in_tenure', 'label' => 'Most requests in the whole tenure (0 = any number)', 'type' => 'number'],
                    ['key' => 'max_days_per_year', 'label' => 'Most days a year (0 = no limit)', 'type' => 'number'],
                    ['key' => 'max_days_per_month', 'label' => 'Most days a month (0 = no limit)', 'type' => 'number'],
                    ['key' => 'backdated_days', 'label' => 'Employees may apply backdated leave for (days; blank = no limit, 0 = none)', 'type' => 'number'],
                    ['key' => 'manager_backdated_days', 'label' => 'Managers may apply backdated leave for (days; blank = as employees)', 'type' => 'number'],
                    ['key' => 'hr_backdated_days', 'label' => 'HR may apply backdated leave for (days; blank = as employees)', 'type' => 'number'],
                    ['key' => 'future_allowed', 'label' => 'Future-dated leave allowed', 'type' => 'select', 'options' => ['yes' => 'Yes', 'no' => 'No']],
                    ['key' => 'not_while_resigning', 'label' => 'Refuse requests while a resignation is pending', 'type' => 'boolean'],
                    ['key' => 'privacy', 'label' => 'Privacy of requests', 'type' => 'select', 'options' => ['public' => 'Always public', 'employee' => 'Employee decides', 'private' => 'Always private']],
                    ['key' => 'birthday_window_before', 'label' => 'Birthday leave: from N days before the birthday (blank = not a birthday leave)', 'type' => 'number'],
                    ['key' => 'birthday_window_after', 'label' => 'Birthday leave: until N days after the birthday', 'type' => 'number'],
                    ['key' => 'not_clubbed_with', 'label' => 'Cannot be taken next to these leave types', 'type' => 'multiselect', 'options_from' => 'leave_types'],
                    ['key' => 'carry_forward_mode', 'label' => 'Carry forward to next year', 'type' => 'select', 'options' => ['specific' => 'Up to the limit', 'all' => 'All of it', 'zero' => 'None']],
                    ['key' => 'credit_cap', 'label' => 'Do not credit beyond a balance of (days, 0 = no cap)', 'type' => 'number'],
                    ['key' => 'credit_round_off', 'label' => 'Round credits to', 'type' => 'select', 'options' => ['none' => 'No rounding', 'half' => 'Nearest half day', 'whole' => 'Nearest whole day']],
                    ['key' => 'max_encash_per_request', 'label' => 'Most days per encashment request (0 = no limit)', 'type' => 'number'],
                    ['key' => 'min_balance_after_encash', 'label' => 'Balance to keep after encashment (days)', 'type' => 'number'],
                ]],
            ]],
            'attendance' => ['label' => 'Attendance policy', 'fields' => [
                // HROne setup parity (TO-4): how the day is counted.
                ['key' => 'calculation', 'label' => 'Calculation', 'type' => 'section', 'fields' => [
                    ['key' => 'punch_mode', 'label' => "How would you like to count 'check-in'?", 'type' => 'radio', 'options' => ['double' => 'Double (in and out)', 'single' => 'Single (one punch is a full day)', 'auto' => 'Auto (present unless on leave)', 'none' => 'None (attendance not marked)'],
                        'help' => 'Auto and None mark working days present without punches; leave, on duty and HR overrides still apply.'],
                    ['key' => 'timing', 'label' => 'How would you like to keep the timings?', 'type' => 'radio', 'options' => ['strict' => 'Strict (late and early marks)', 'flexi' => 'Flexi (only the hours count)']],
                    ['key' => 'count_within_shift', 'label' => 'Count working hours only within the shift?', 'type' => 'yes_no'],
                    ['key' => 'weekly_off_paid', 'label' => 'Is weekly off paid?', 'type' => 'radio', 'options' => ['paid' => 'Paid', 'unpaid' => 'Unpaid']],
                ]],
                ['key' => 'entitlement', 'label' => 'Weekly off and holiday entitlement', 'type' => 'section', 'fields' => [
                    ['key' => 'weekly_off_entitlement', 'label' => 'Enable weekly off for entitlement (earned only with enough days present in the week)?', 'type' => 'yes_no'],
                    ['key' => 'weekly_off_min_days', 'label' => 'Days present (Mon–Sun) needed to earn the weekly off', 'type' => 'number', 'min' => 1, 'max' => 6, 'visible_when' => ['key' => 'weekly_off_entitlement', 'in' => [true, 1, '1']]],
                    ['key' => 'holiday_entitlement', 'label' => 'Enable holidays for entitlement?', 'type' => 'yes_no'],
                    ['key' => 'holiday_min_days', 'label' => 'Days present (Mon–Sun) needed to earn the holiday', 'type' => 'number', 'min' => 1, 'max' => 6, 'visible_when' => ['key' => 'holiday_entitlement', 'in' => [true, 1, '1']]],
                    ['key' => 'entitlement_counts_leave', 'label' => 'Do paid leave days count as present for entitlement?', 'type' => 'yes_no'],
                    ['key' => 'entitlement_skip_joining_month', 'label' => 'Exclude entitlement in the joining month?', 'type' => 'yes_no'],
                ]],
                ['key' => 'deduction', 'label' => 'Late and early', 'type' => 'section', 'fields' => [
                    ['key' => 'grace_minutes', 'label' => 'Grace (minutes)', 'type' => 'number'],
                    ['key' => 'late_marks_threshold', 'label' => 'Late marks before action', 'type' => 'number'],
                    ['key' => 'late_mark_action', 'label' => 'Action on threshold', 'type' => 'select', 'options' => ['warn' => 'Warn', 'half_day' => 'Deduct half day', 'lop' => 'Loss of pay']],
                    // HROne parity CF-04: a day is a half day when the employee comes in or leaves too far off the shift.
                    ['key' => 'half_day_if_late_beyond', 'label' => 'Half day when late by more than (minutes, 0 = never)', 'type' => 'number'],
                    ['key' => 'half_day_if_early_beyond', 'label' => 'Half day when leaving early by more than (minutes, 0 = never)', 'type' => 'number'],
                ]],
                // HROne setup parity (TO-3): attendance regularisation (AR) rules from HROne's check-in policy.
                ['key' => 'ar', 'label' => 'Attendance regularisation (AR)', 'type' => 'section', 'fields' => [
                    ['key' => 'regularisation_allowed', 'label' => 'Regularisation allowed', 'type' => 'boolean'],
                    ['key' => 'regularisation_window_days', 'label' => 'Employees may apply backdated AR for (days)', 'type' => 'number'],
                    ['key' => 'ar_manager_backdated_days', 'label' => 'Managers may apply backdated AR for (days; blank = as employees)', 'type' => 'number', 'min' => 0],
                    ['key' => 'ar_hr_backdated_days', 'label' => 'HR may apply backdated AR for (days; blank = as employees)', 'type' => 'number', 'min' => 0],
                    ['key' => 'ar_future_allowed', 'label' => 'Allow future-dated AR?', 'type' => 'yes_no'],
                    ['key' => 'ar_after_process', 'label' => 'Can AR be raised after attendance is processed for payroll?', 'type' => 'yes_no'],
                    ['key' => 'ar_requires_punch', 'label' => 'Allow AR only when at least one punch is available?', 'type' => 'yes_no'],
                    ['key' => 'ar_within_shift', 'label' => 'Allow AR only within the shift-defined range?', 'type' => 'yes_no'],
                    ['key' => 'ar_not_on', 'label' => 'Employees cannot apply AR on', 'type' => 'checkboxes', 'options' => ['weekly_off' => 'Week-offs', 'holiday' => 'Holidays']],
                    ['key' => 'ar_limit_period', 'label' => 'AR limit applies per', 'type' => 'radio', 'options' => ['none' => 'No limit', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year']],
                    ['key' => 'ar_limit', 'label' => 'Most AR requests in the period', 'type' => 'number', 'min' => 1, 'visible_when' => ['key' => 'ar_limit_period', 'in' => ['week', 'month', 'year']]],
                    ['key' => 'ar_attachment_required', 'label' => 'Is an attachment mandatory for AR?', 'type' => 'yes_no'],
                ]],
                ['key' => 'manager_approval_required', 'label' => 'Manager approval required', 'type' => 'boolean'],
                // HROne setup parity (TO-5): shift allowance for days worked on shifts that earn it (paid through payroll).
                ['key' => 'shift_allowance', 'label' => 'Shift allowance', 'type' => 'section', 'fields' => [
                    ['key' => 'shift_allowance_rate', 'label' => 'Shift allowance a day (0 = none)', 'type' => 'number', 'min' => 0],
                    ['key' => 'shift_allowance_min_days', 'label' => 'Minimum days in the month before it is paid (0 = none)', 'type' => 'number', 'min' => 0, 'max' => 31],
                    ['key' => 'shift_allowance_max_days', 'label' => 'Most days paid in a month (0 = no limit)', 'type' => 'number', 'min' => 0, 'max' => 31],
                    ['key' => 'shift_allowance_on_off_days', 'label' => 'Pay shift allowance for work on weekly offs and holidays?', 'type' => 'yes_no'],
                    ['key' => 'shift_allowance_off_day_minutes', 'label' => 'Minimum minutes worked on such a day', 'type' => 'number', 'min' => 0, 'visible_when' => ['key' => 'shift_allowance_on_off_days', 'in' => [true, 1, '1']]],
                ]],
                // HROne setup parity (TO-4): alerts and the request lock while attendance and payroll are processed.
                ['key' => 'alerts', 'label' => 'Alerts and lock', 'type' => 'section', 'fields' => [
                    ['key' => 'absence_alert_days', 'label' => 'Send an absenteeism alert after this many working days absent in a row (0 = off)', 'type' => 'number', 'min' => 0, 'max' => 60],
                    ['key' => 'request_lock', 'label' => 'Restrict requesting and approving leave, AR, OD and short leave during attendance and payroll processing?', 'type' => 'yes_no'],
                    ['key' => 'request_lock_from_day', 'label' => 'Locked from day of the month', 'type' => 'number', 'min' => 1, 'max' => 31, 'visible_when' => ['key' => 'request_lock', 'in' => [true, 1, '1']]],
                    ['key' => 'request_lock_to_day', 'label' => 'Locked until day of the month', 'type' => 'number', 'min' => 1, 'max' => 31, 'visible_when' => ['key' => 'request_lock', 'in' => [true, 1, '1']],
                        'help' => 'Requests for days of the month before are refused in these days.'],
                ]],
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
            // HROne parity HP-1: hourly permission ("short leave"), the other time-office requests and check-in controls.
            'short_leave' => ['label' => 'Short leave policy', 'fields' => [
                ['key' => 'enabled', 'label' => 'Short leave allowed', 'type' => 'boolean'],
                ['key' => 'min_minutes', 'label' => 'Minimum duration (minutes)', 'type' => 'number'],
                ['key' => 'max_minutes', 'label' => 'Maximum duration per short leave (minutes)', 'type' => 'number'],
                ['key' => 'max_per_month', 'label' => 'Maximum short leaves per month (0 = no limit)', 'type' => 'number'],
                ['key' => 'max_minutes_per_month', 'label' => 'Maximum minutes per month (0 = no limit)', 'type' => 'number'],
                ['key' => 'window_days', 'label' => 'May be requested up to N days after the date', 'type' => 'number'],
                ['key' => 'manager_window_days', 'label' => 'Managers may raise it on behalf up to N days back', 'type' => 'number'],
                ['key' => 'admin_window_days', 'label' => 'HR may raise it on behalf up to N days back', 'type' => 'number'],
                ['key' => 'multiple_per_day', 'label' => 'More than one short leave on a day', 'type' => 'boolean'],
                ['key' => 'over_limit', 'label' => 'Beyond the monthly limit', 'type' => 'select', 'options' => ['refuse' => 'Refuse the request', 'deduct_leave' => 'Allow it and deduct leave']],
                ['key' => 'deduct_leave_code', 'label' => 'Leave deducted beyond the limit', 'type' => 'select', 'options_from' => 'leave_types'],
                ['key' => 'deduct_days', 'label' => 'Days deducted per short leave beyond the limit', 'type' => 'number'],
                ['key' => 'approval_required', 'label' => 'Approval required', 'type' => 'boolean'],
                // HROne setup parity (TO-3).
                ['key' => 'short_leave_future_allowed', 'label' => 'Allow future-dated short leave?', 'type' => 'yes_no'],
                ['key' => 'short_leave_after_process', 'label' => 'Can short leave be raised after attendance is processed for payroll?', 'type' => 'yes_no'],
            ]],
            // HROne setup parity (TO-3): HROne's on-duty and change shift / week-off rules, in sections; keys stay flat.
            'time_office' => ['label' => 'Time-office requests policy', 'fields' => [
                ['key' => 'on_duty', 'label' => 'On duty', 'type' => 'section', 'fields' => [
                    ['key' => 'on_duty_allowed', 'label' => 'On-duty requests allowed', 'type' => 'boolean'],
                    ['key' => 'on_duty_types', 'label' => 'On-duty types employees choose from (blank = none asked)', 'type' => 'tags', 'placeholder' => 'Client visit, Training, Field work'],
                    ['key' => 'on_duty_max_days', 'label' => 'Maximum days per on-duty request (0 = no limit)', 'type' => 'number'],
                    ['key' => 'on_duty_hours_allowed', 'label' => 'Part-day (hourly) on duty allowed', 'type' => 'boolean'],
                    ['key' => 'on_duty_manager_backdated_days', 'label' => 'Managers may apply backdated on duty for (days; blank = as employees)', 'type' => 'number', 'min' => 0],
                    ['key' => 'on_duty_hr_backdated_days', 'label' => 'HR may apply backdated on duty for (days; blank = as employees)', 'type' => 'number', 'min' => 0],
                    ['key' => 'on_duty_future_allowed', 'label' => 'Allow future-dated on duty?', 'type' => 'yes_no'],
                    ['key' => 'on_duty_after_process', 'label' => 'Can on duty be raised after attendance is processed for payroll?', 'type' => 'yes_no'],
                    ['key' => 'on_duty_not_on', 'label' => 'Employees cannot apply on duty on', 'type' => 'checkboxes', 'options' => ['weekly_off' => 'Week-offs', 'holiday' => 'Holidays']],
                    ['key' => 'on_duty_max_per_week', 'label' => 'On-duty requests per week (0 = no limit)', 'type' => 'number'],
                    ['key' => 'on_duty_max_per_month', 'label' => 'On-duty requests per month (0 = no limit)', 'type' => 'number'],
                    ['key' => 'on_duty_limits_bind_managers', 'label' => 'Do the limits also stop managers applying on behalf?', 'type' => 'yes_no'],
                    ['key' => 'on_duty_limits_bind_hr', 'label' => 'Do the limits also stop HR applying on behalf?', 'type' => 'yes_no'],
                    ['key' => 'on_duty_attachment_required', 'label' => 'Is an attachment mandatory for on duty?', 'type' => 'yes_no'],
                ]],
                ['key' => 'shift_change', 'label' => 'Change shift', 'type' => 'section', 'fields' => [
                    ['key' => 'shift_change_allowed', 'label' => 'Shift change requests allowed', 'type' => 'boolean'],
                    ['key' => 'shift_change_shifts', 'label' => 'Shifts employees can change to (blank = all shifts)', 'type' => 'multiselect', 'options_from' => 'shifts'],
                    ['key' => 'shift_change_notice_days', 'label' => 'Shift change notice (days, 0 = same day)', 'type' => 'number'],
                    ['key' => 'shift_change_backdated_days', 'label' => 'Allow backdated shift changes for (days, 0 = none)', 'type' => 'number', 'min' => 0],
                    ['key' => 'shift_change_future_days', 'label' => 'Allow shift changes up to (days ahead, 0 = no limit)', 'type' => 'number', 'min' => 0],
                    ['key' => 'shift_change_max_per_week', 'label' => 'Shift change requests per week (0 = no limit)', 'type' => 'number'],
                    ['key' => 'shift_change_max_per_month', 'label' => 'Shift change requests per month (0 = no limit)', 'type' => 'number'],
                ]],
                ['key' => 'week_off_change', 'label' => 'Change week off', 'type' => 'section', 'fields' => [
                    ['key' => 'week_off_change_allowed', 'label' => 'Week-off change requests allowed (work a weekly off, take another day off)?', 'type' => 'yes_no'],
                    ['key' => 'week_off_change_backdated_days', 'label' => 'Allow backdated week-off changes for (days, 0 = none)', 'type' => 'number', 'min' => 0, 'visible_when' => ['key' => 'week_off_change_allowed', 'in' => [true, 1, '1']]],
                    ['key' => 'week_off_change_future_days', 'label' => 'Allow week-off changes up to (days ahead, 0 = no limit)', 'type' => 'number', 'min' => 0, 'visible_when' => ['key' => 'week_off_change_allowed', 'in' => [true, 1, '1']]],
                    ['key' => 'week_off_change_max_per_week', 'label' => 'Week-off changes per week (0 = no limit)', 'type' => 'number', 'visible_when' => ['key' => 'week_off_change_allowed', 'in' => [true, 1, '1']]],
                    ['key' => 'week_off_change_max_per_month', 'label' => 'Week-off changes per month (0 = no limit)', 'type' => 'number', 'visible_when' => ['key' => 'week_off_change_allowed', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'claims', 'label' => 'Overtime and comp-off claims', 'type' => 'section', 'fields' => [
                    ['key' => 'overtime_claims_allowed', 'label' => 'Overtime claims allowed', 'type' => 'boolean'],
                    ['key' => 'overtime_max_per_month', 'label' => 'Overtime claims per month (0 = no limit)', 'type' => 'number'],
                    ['key' => 'comp_off_allowed', 'label' => 'Comp-off claims allowed', 'type' => 'boolean'],
                    ['key' => 'comp_off_full_day_minutes', 'label' => 'Minutes worked on an off day for a full comp-off', 'type' => 'number'],
                    ['key' => 'comp_off_half_day_minutes', 'label' => 'Minutes worked on an off day for a half comp-off', 'type' => 'number'],
                    ['key' => 'comp_off_validity_days', 'label' => 'Comp-off expires after (days, 0 = never)', 'type' => 'number'],
                    ['key' => 'comp_off_max_per_month', 'label' => 'Comp-off claims per month (0 = no limit)', 'type' => 'number'],
                    // HROne setup parity (TO-4): generate comp-off from work on an off day without a claim.
                    ['key' => 'comp_off_auto', 'label' => 'Generate comp-off automatically for work on a weekly off or holiday?', 'type' => 'yes_no'],
                    ['key' => 'comp_off_auto_approve', 'label' => 'Approve generated comp-off without review?', 'type' => 'yes_no', 'visible_when' => ['key' => 'comp_off_auto', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'general', 'label' => 'General', 'type' => 'section', 'fields' => [
                    ['key' => 'claim_window_days', 'label' => 'Claims and past-dated requests allowed up to N days back', 'type' => 'number'],
                    ['key' => 'cancellation_needs_approval', 'label' => 'Cancelling an approved request needs approval', 'type' => 'boolean'],
                ]],
            ]],
            // HROne parity HP-2: loans and salary advances.
            'loan' => ['label' => 'Loan and advance policy', 'fields' => [
                ['key' => 'enabled', 'label' => 'Loans offered', 'type' => 'boolean'],
                ['key' => 'allowed_types', 'label' => 'Loan types offered (empty = all)', 'type' => 'multiselect', 'options_from' => 'loan_types'],
                ['key' => 'max_amount', 'label' => 'Largest amount (0 = no limit)', 'type' => 'number'],
                ['key' => 'max_installments', 'label' => 'Most instalments', 'type' => 'number'],
                ['key' => 'interest_rate', 'label' => 'Interest a year (%, reducing balance; 0 = interest free)', 'type' => 'number'],
                ['key' => 'min_service_months', 'label' => 'Available after (months of service)', 'type' => 'number'],
                ['key' => 'max_active', 'label' => 'Open loans at a time', 'type' => 'number'],
                ['key' => 'guarantor_required', 'label' => 'A colleague must stand guarantor', 'type' => 'boolean'],
                ['key' => 'disbursement', 'label' => 'Paid out', 'type' => 'select', 'options' => ['payroll' => 'With the next payroll', 'bank' => 'By bank, on approval']],
                // HROne setup parity BP-9: request limits, the approver's edits and guarantors (every default keeps the earlier behaviour).
                ['key' => 'requests', 'label' => 'Requests', 'type' => 'section', 'help' => 'When employees may ask for a loan and how much they may repay each month.', 'fields' => [
                    ['key' => 'request_window', 'label' => 'Do you want to restrict loan requests to certain days of the month?', 'type' => 'yes_no'],
                    ['key' => 'request_day_from', 'label' => 'Requests from day', 'type' => 'number', 'min' => 1, 'max' => 31, 'visible_when' => ['key' => 'request_window', 'in' => [true, 1, '1']]],
                    ['key' => 'request_day_to', 'label' => 'Requests until day', 'type' => 'number', 'min' => 1, 'max' => 31, 'visible_when' => ['key' => 'request_window', 'in' => [true, 1, '1']]],
                    ['key' => 'max_requests_per_year', 'label' => 'How many loan requests may an employee make in a financial year? (0 = no limit)', 'type' => 'number', 'min' => 0, 'max' => 99],
                    ['key' => 'max_emi_percent_of_net', 'label' => 'Monthly recovery of all open loans at most (% of the latest net pay; 0 = no limit)', 'type' => 'number', 'min' => 0, 'max' => 100,
                        'help' => 'Before the first salary is paid, monthly CTC is used.'],
                    ['key' => 'allow_exception', 'label' => 'Do you want to let a request beyond these limits go to the approver as an exception?', 'type' => 'yes_no',
                        'help' => 'Largest amount, most instalments, monthly recovery, requests a year and request days. No: such a request is refused.'],
                ]],
                ['key' => 'approval', 'label' => 'Approval', 'type' => 'section', 'fields' => [
                    ['key' => 'approver_may_edit', 'label' => 'Can the loan be edited by the approver?', 'type' => 'yes_no', 'help' => 'Amount, instalments and first recovery month, within this policy.'],
                    ['key' => 'interest_editable', 'label' => 'Can the interest rate be edited by the approver?', 'type' => 'yes_no'],
                ]],
                ['key' => 'guarantors', 'label' => 'Guarantors', 'type' => 'section', 'visible_when' => ['key' => 'guarantor_required', 'in' => [true, 1, '1']], 'fields' => [
                    ['key' => 'guarantors_required', 'label' => 'How many guarantors must an employee name?', 'type' => 'number', 'min' => 1, 'max' => 5],
                    ['key' => 'max_guarantees_per_guarantor', 'label' => 'For how many open loans can one employee stand guarantor? (0 = no limit)', 'type' => 'number', 'min' => 0, 'max' => 50],
                    ['key' => 'guarantor_must_accept', 'label' => 'Must each guarantor accept before the loan is approved?', 'type' => 'yes_no'],
                ]],
            ]],
            // HROne setup parity (Time office → Check in → Check in policy): sources, web and mobile check-in controls.
            // Requests (regularisation, on duty, shift / week-off change) are in the attendance and time-office policies.
            'checkin' => ['label' => 'Check-in policy', 'fields' => [
                ['key' => 'allowed_sources', 'label' => 'Allowed check-in sources (empty = all)', 'type' => 'multiselect', 'options' => ['web' => 'Web', 'mobile' => 'Mobile', 'api' => 'API', 'biometric' => 'Biometric']],
                ['key' => 'web', 'label' => 'Web check-in', 'type' => 'section', 'fields' => [
                    ['key' => 'web_max_punches_enabled', 'label' => 'Set maximum punches allowed?', 'type' => 'yes_no'],
                    ['key' => 'web_max_punches', 'label' => 'Number of punches allowed in a day', 'type' => 'number', 'min' => 1, 'max' => 50, 'visible_when' => ['key' => 'web_max_punches_enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'web_window_enabled', 'label' => 'Restrict employees to mark attendance only in a defined window?', 'type' => 'yes_no'],
                    ['key' => 'web_window_start', 'label' => 'Allow punch from', 'type' => 'time', 'visible_when' => ['key' => 'web_window_enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'web_window_end', 'label' => 'Allow punch until', 'type' => 'time', 'visible_when' => ['key' => 'web_window_enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'web_ip_validation', 'label' => 'Enable IP validation?', 'type' => 'yes_no', 'help' => 'Web check-ins only from the office network.'],
                    ['key' => 'web_allowed_ips', 'label' => 'Allowed IP addresses or ranges', 'type' => 'tags', 'placeholder' => '203.0.113.10 or 203.0.113.0/24', 'visible_when' => ['key' => 'web_ip_validation', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'mobile', 'label' => 'Mobile check-in', 'type' => 'section', 'fields' => [
                    ['key' => 'require_location', 'label' => 'Capture location while marking attendance? (web and mobile check-ins without a location are refused)', 'type' => 'yes_no'],
                    ['key' => 'photo_capture', 'label' => 'Capture a photograph while marking attendance?', 'type' => 'radio', 'options' => ['none' => 'None', 'photo' => 'Photo', 'facial' => 'Facial (photo verified by the app)']],
                    ['key' => 'photo_count', 'label' => 'Number of photos', 'type' => 'number', 'min' => 1, 'max' => 5, 'visible_when' => ['key' => 'photo_capture', 'in' => ['photo', 'facial']]],
                    ['key' => 'geo_fence_required', 'label' => 'Advanced location tracking: check-ins must be inside a geo location (radius- or polygon-based)', 'type' => 'yes_no'],
                    ['key' => 'outside_fence_action', 'label' => 'Check-in outside every geo location', 'type' => 'radio', 'options' => ['flag' => 'Accept and flag', 'reject' => 'Reject'], 'visible_when' => ['key' => 'geo_fence_required', 'in' => [true, 1, '1']]],
                    ['key' => 'continuous_tracking', 'label' => 'Continuous location tracking during the working day (from the mobile app)?', 'type' => 'yes_no'],
                    ['key' => 'temporary_locations', 'label' => 'Should employee work locations be approved only for a specific period?', 'type' => 'yes_no'],
                    ['key' => 'temporary_location_max_days', 'label' => 'Maximum days a location approval can be requested for', 'type' => 'number', 'min' => 1, 'max' => 3650, 'visible_when' => ['key' => 'temporary_locations', 'in' => [true, 1, '1']]],
                    ['key' => 'mobile_max_punches_enabled', 'label' => 'Set maximum punches allowed?', 'type' => 'yes_no'],
                    ['key' => 'mobile_max_punches', 'label' => 'Number of punches allowed in a day', 'type' => 'number', 'min' => 1, 'max' => 50, 'visible_when' => ['key' => 'mobile_max_punches_enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'mobile_window_enabled', 'label' => 'Restrict employees to mark attendance only in a defined window?', 'type' => 'yes_no'],
                    ['key' => 'mobile_window_start', 'label' => 'Allow punch from', 'type' => 'time', 'visible_when' => ['key' => 'mobile_window_enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'mobile_window_end', 'label' => 'Allow punch until', 'type' => 'time', 'visible_when' => ['key' => 'mobile_window_enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'wifi_attendance', 'label' => 'WiFi attendance: mobile check-ins only on approved WiFi networks?', 'type' => 'yes_no'],
                    ['key' => 'wifi_networks', 'label' => 'Approved WiFi networks (SSID or BSSID)', 'type' => 'tags', 'placeholder' => 'Office-WiFi or 00:11:22:33:44:55', 'visible_when' => ['key' => 'wifi_attendance', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'devices', 'label' => 'Devices', 'type' => 'section', 'fields' => [
                    ['key' => 'single_device', 'label' => 'Only approved devices of the employee (one employee per device)', 'type' => 'yes_no'],
                    ['key' => 'auto_approve_first_device', 'label' => 'Approve the first device automatically', 'type' => 'yes_no', 'visible_when' => ['key' => 'single_device', 'in' => [true, 1, '1']]],
                ]],
            ]],
            'work_schedule' => ['label' => 'Working hours policy', 'fields' => [
                ['key' => 'daily_hours', 'label' => 'Daily hours', 'type' => 'number'],
                ['key' => 'weekly_hours', 'label' => 'Weekly hours', 'type' => 'number'],
                ['key' => 'working_days', 'label' => 'Working days', 'type' => 'multiselect', 'options' => ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun']],
                ['key' => 'flexible_timing', 'label' => 'Flexible timing', 'type' => 'boolean'],
            ]],
            // HROne setup parity (Workforce → Separation policy): notice by probation status, backdated resignation, retirement.
            'exit' => ['label' => 'Exit (separation) policy', 'fields' => [
                ['key' => 'notice_period_days', 'label' => 'Notice period for confirmed employees (days)', 'type' => 'number'],
                ['key' => 'notice_days_probation', 'label' => 'Notice period during probation (days; blank = as confirmed)', 'type' => 'number'],
                ['key' => 'notice_buyout_allowed', 'label' => 'Notice buy-out allowed', 'type' => 'boolean'],
                ['key' => 'employee_backdate_days', 'label' => 'Employees may backdate their resignation by (days; 0 = today only)', 'type' => 'number', 'min' => 0],
                ['key' => 'hr_backdate_days', 'label' => 'HR and managers may backdate a resignation by (days; blank = no limit)', 'type' => 'number', 'min' => 0],
                ['key' => 'retirement_age', 'label' => 'Retirement age (years; 0 = none)', 'type' => 'number', 'min' => 0, 'max' => 80],
                ['key' => 'clearance_checklist_id', 'label' => 'Map clearance checklist', 'type' => 'select', 'options_from' => 'clearance_checklists', 'help' => 'Blank: the standard clearance items.'],
                ['key' => 'letter_on_exit', 'label' => 'Letter drafted when the exit starts', 'type' => 'select', 'options_from' => 'letter_templates'],
                ['key' => 'letter_on_completion', 'label' => 'Letter drafted when the exit completes', 'type' => 'select', 'options_from' => 'letter_templates'],
                ['key' => 'retire_on', 'label' => 'Retire on', 'type' => 'radio', 'options' => ['birthday' => 'The birthday', 'month_end' => 'The end of the birthday month'], 'visible_when' => ['key' => 'retirement_age', 'in' => [55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, '55', '56', '57', '58', '59', '60', '61', '62', '63', '64', '65', '66', '67', '68', '69', '70']]],
                // HROne setup parity P13 (08.3 #10–#12): exit interviews answered on feedback templates, each a task in My tasks
                // raised when the exit starts and due on the last working day (FeedbackRequests::raiseForExit).
                ['key' => 'interviews', 'label' => 'Exit interviews', 'type' => 'section', 'help' => 'Each exit interview is a task in My tasks for the person who gives it, raised when the exit starts and due on the last working day. HR with "Conduct and read exit interviews" reads every answer; the employee and the manager see only their own.', 'fields' => [
                    ['key' => 'interview_employee_template_id', 'label' => 'Exit interview from the employee: feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'exit_interview', 'help' => 'Blank: the standard exit interview (reason, ratings and comments).'],
                    ['key' => 'interview_manager', 'label' => 'Exit interview from the manager?', 'type' => 'yes_no'],
                    ['key' => 'interview_manager_template_id', 'label' => 'Manager\'s feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'exit_interview_manager', 'visible_when' => ['key' => 'interview_manager', 'in' => [true, 1, '1']]],
                    ['key' => 'interview_hr', 'label' => 'Exit interview from HR?', 'type' => 'yes_no'],
                    ['key' => 'interview_hr_template_id', 'label' => 'HR\'s feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'exit_interview_hr', 'visible_when' => ['key' => 'interview_hr', 'in' => [true, 1, '1']]],
                    ['key' => 'interview_hr_role', 'label' => 'Role that conducts the HR exit interview', 'type' => 'select', 'options_from' => 'roles', 'help' => 'Blank: the HR clearance role.', 'visible_when' => ['key' => 'interview_hr', 'in' => [true, 1, '1']]],
                ]],
            ]],
            // HROne setup parity (Workforce → Confirmation policy).
            'confirmation' => ['label' => 'Confirmation policy', 'fields' => [
                ['key' => 'probation_months', 'label' => 'Probation period (months; blank = the organisation default)', 'type' => 'number', 'min' => 0, 'max' => 36],
                ['key' => 'review_lead_days', 'label' => 'Open the confirmation review this many days before the probation ends', 'type' => 'number', 'min' => 0, 'max' => 90],
                ['key' => 'allow_extend', 'label' => 'Give an option to extend the probation?', 'type' => 'yes_no'],
                ['key' => 'extension_months', 'label' => 'Longest extension (months)', 'type' => 'number', 'min' => 1, 'max' => 24, 'visible_when' => ['key' => 'allow_extend', 'in' => [true, 1, '1']]],
                ['key' => 'max_extensions', 'label' => 'Number of extensions allowed (0 = any)', 'type' => 'number', 'min' => 0, 'max' => 10, 'visible_when' => ['key' => 'allow_extend', 'in' => [true, 1, '1']]],
                ['key' => 'allow_terminate', 'label' => 'During confirmation, give an option to terminate?', 'type' => 'yes_no'],
                ['key' => 'letter_on_confirm', 'label' => 'Letter drafted on confirmation', 'type' => 'select', 'options_from' => 'letter_templates'],
                ['key' => 'letter_on_extend', 'label' => 'Letter drafted on extension', 'type' => 'select', 'options_from' => 'letter_templates'],
                ['key' => 'letter_on_terminate', 'label' => 'Letter drafted on termination', 'type' => 'select', 'options_from' => 'letter_templates'],
                // HROne setup parity P13 (08.1 #11–#12): feedback before the decision, on feedback templates (ConfirmationReviews).
                ['key' => 'feedback', 'label' => 'Feedback', 'type' => 'section', 'help' => 'Raised as tasks in My tasks when the review opens, due on the probation end date.', 'fields' => [
                    ['key' => 'feedback_template_id', 'label' => 'Would you like to add a feedback? The manager answers this template before the decision', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'confirmation', 'help' => 'The review cannot be decided while the manager\'s feedback is pending (HR may cancel it with a reason).'],
                    ['key' => 'self_review_template_id', 'label' => 'Ask the employee for a probation self-review on this template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'probation'],
                    ['key' => 'share_feedback_with_next_level', 'label' => 'Copy the feedback to the next level (the manager\'s own manager can read it)?', 'type' => 'yes_no', 'help' => 'Those who decide confirmations always read it.'],
                ]],
            ]],
            // HROne setup parity (Workforce → Transfer policy): what a transfer may change.
            'transfer' => ['label' => 'Transfer policy', 'fields' => [
                ['key' => 'changeable_dimensions', 'label' => 'A transfer may change', 'type' => 'checkboxes', 'options' => ['company_id' => 'Company', 'business_unit_id' => 'Business unit', 'division_id' => 'Division', 'department_id' => 'Department', 'location_id' => 'Location', 'cost_centre_id' => 'Cost centre', 'grade_id' => 'Grade', 'designation_id' => 'Designation', 'manager' => 'Reporting manager']],
                ['key' => 'notice_days', 'label' => 'Transfers need this many days notice (0 = immediate)', 'type' => 'number', 'min' => 0],
                ['key' => 'backdate_days', 'label' => 'Transfers may be backdated by (days)', 'type' => 'number', 'min' => 0],
                ['key' => 'reason_required', 'label' => 'A transfer reason is required?', 'type' => 'yes_no'],
            ]],
            // HROne setup parity HD-C (Helpdesk → Helpdesk policy): which categories and services an employee group may
            // raise, and whether its managers raise requests for their team. No policy: everything as before.
            'helpdesk' => ['label' => 'Helpdesk access policy', 'fields' => [
                ['key' => 'access', 'label' => 'Categories and services', 'type' => 'section', 'help' => 'What employees of this policy can raise for themselves (and their managers for them). HR raising on someone\'s behalf is not limited.', 'fields' => [
                    ['key' => 'categories', 'label' => 'Which ticket categories can they raise? (empty = all)', 'type' => 'multiselect', 'options_from' => 'ticket_categories'],
                    ['key' => 'services', 'label' => 'Which services (sub-categories) can they request? (empty = every service of the categories above)', 'type' => 'multiselect', 'options_from' => 'service_definitions'],
                ]],
                ['key' => 'on_behalf', 'label' => 'Raising on behalf', 'type' => 'section', 'fields' => [
                    ['key' => 'raise_on_behalf_of_team', 'label' => 'Can managers of this policy raise requests on behalf of their team?', 'type' => 'yes_no', 'help' => 'Not answered: yes, as before.'],
                ]],
            ]],
            // HROne setup parity EX-B (Expense → Expense policy): the expense categories an employee group may claim, each
            // with optional overrides of the category's own limits (blank = the category's), and a limit across all of them.
            // Read by ExpenseLimits; an employee with no expense policy can claim no category.
            'expense' => ['label' => 'Expense policy', 'fields' => [
                ['key' => 'categories', 'label' => 'Expense categories', 'type' => 'repeater', 'item_label' => 'options',
                    'help' => 'The categories employees of this policy can claim. Changes made here apply to this policy only; they do not change the category\'s own settings. Leave an override blank to use the category\'s.',
                    'fields' => [
                        ['key' => 'category', 'label' => 'Expense category', 'type' => 'select', 'options_from' => 'expense_categories'],
                        ['key' => 'exceptional_only', 'label' => 'Exceptional category (can be claimed only as an exceptional expense)', 'type' => 'boolean'],
                        ['key' => 'limit_amount', 'label' => 'Limit amount (blank = the category\'s)', 'type' => 'number', 'min' => 0],
                        ['key' => 'km_limit', 'label' => 'Distance limit in km, mileage categories (blank = the category\'s)', 'type' => 'number', 'min' => 0],
                        ['key' => 'limit_period', 'label' => 'Limit time period (blank = the category\'s)', 'type' => 'select', 'options_from' => 'expense_periods'],
                        ['key' => 'receipt_above', 'label' => 'Receipt needed above this amount (blank = the category\'s)', 'type' => 'number', 'min' => 0],
                        ['key' => 'claim_frequency_count', 'label' => 'Claims allowed per frequency period (blank = the category\'s)', 'type' => 'number', 'min' => 1],
                        ['key' => 'limit_exception_allowed', 'label' => 'File as exceptional when out of limit? (blank = the category\'s)', 'type' => 'select', 'options' => ['yes' => 'Yes', 'no' => 'No']],
                        ['key' => 'location_limits', 'label' => 'Location-wise limit amounts (for places in these location groups)', 'type' => 'repeater', 'fields' => [
                            ['key' => 'location_group', 'label' => 'Location group', 'type' => 'select', 'options_from' => 'expense_location_groups'],
                            ['key' => 'amount', 'label' => 'Limit amount', 'type' => 'number', 'min' => 0],
                        ]],
                    ]],
                ['key' => 'overall', 'label' => 'Limit across all categories', 'type' => 'section', 'fields' => [
                    ['key' => 'overall_limit_enabled', 'label' => 'Do you want to apply limit across all categories?', 'type' => 'yes_no'],
                    ['key' => 'overall_limit_amount', 'label' => 'Amount', 'type' => 'number', 'min' => 0, 'visible_when' => ['key' => 'overall_limit_enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'overall_limit_period', 'label' => 'Frequency', 'type' => 'select', 'options_from' => 'expense_periods', 'visible_when' => ['key' => 'overall_limit_enabled', 'in' => [true, 1, '1']]],
                ]],
            ]],
            // HROne setup parity EX-B (Expense → Expense advance policy): who may request advances for work expenses, how
            // much, how they are paid, settled and recovered. Read through ExpenseAdvanceRules (advances arrive with EX-D);
            // without a policy an employee cannot request an advance. Blank answers take the defaults named in the labels.
            'expense_advance' => ['label' => 'Expense advance policy', 'fields' => [
                ['key' => 'requests', 'label' => 'Advance requests', 'type' => 'section', 'fields' => [
                    ['key' => 'enabled', 'label' => 'Can employees of this policy request advances for work-related expenses?', 'type' => 'yes_no'],
                    ['key' => 'max_amount', 'label' => 'Largest advance (0 = no limit)', 'type' => 'number', 'min' => 0, 'visible_when' => ['key' => 'enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'max_open', 'label' => 'Advances open at a time (blank = 1)', 'type' => 'number', 'min' => 1, 'visible_when' => ['key' => 'enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'max_outstanding_total', 'label' => 'Most outstanding across open advances (0 = no limit)', 'type' => 'number', 'min' => 0, 'visible_when' => ['key' => 'enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'purpose_required', 'label' => 'Is a purpose mandatory?', 'type' => 'yes_no', 'visible_when' => ['key' => 'enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'attachment_required', 'label' => 'Is an attachment mandatory?', 'type' => 'yes_no', 'visible_when' => ['key' => 'enabled', 'in' => [true, 1, '1']]],
                    ['key' => 'cancellation_needs_approval', 'label' => 'Does cancelling an approved advance need approval? (blank = yes)', 'type' => 'yes_no', 'visible_when' => ['key' => 'enabled', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'payout', 'label' => 'Payout', 'type' => 'section', 'fields' => [
                    ['key' => 'disbursement', 'label' => 'How are approved advances paid? (blank = by bank)', 'type' => 'radio', 'options' => ['bank' => 'By bank transfer, recorded by finance', 'payroll' => 'With the next payroll']],
                ]],
                ['key' => 'settlement', 'label' => 'Settlement and recovery', 'type' => 'section', 'fields' => [
                    ['key' => 'settle_within_days', 'label' => 'Settle the advance within (days after the trip ends, or after it is paid when there is no trip; blank = 30)', 'type' => 'number', 'min' => 0, 'max' => 365],
                    ['key' => 'recovery', 'label' => 'An advance not settled in time is (blank = recovered through payroll)', 'type' => 'radio', 'options' => ['payroll' => 'Recovered through payroll', 'exit_only' => 'Recovered only at exit (full and final settlement)', 'none' => 'Not recovered automatically']],
                    ['key' => 'recovery_after_days', 'label' => 'Recover it this many days after the settle-by date (blank = 15)', 'type' => 'number', 'min' => 0, 'max' => 365, 'visible_when' => ['key' => 'recovery', 'in' => ['payroll', null, '']]],
                ]],
            ]],
            // HROne setup parity HP-8d (Goal → Goal sheet policy): how an employee group's goal sheet is created, weighted,
            // filled in, reviewed and approved. Read through GoalSheetRules and GoalFieldRules (the total KRA weightage takes
            // precedence over the review template's and the cycle's). Without a policy goals work exactly as before; a
            // blank answer keeps today's behaviour. `validator` checks the answers before they are saved or published.
            'goal_sheet' => ['label' => 'Goal sheet policy', 'validator' => 'App\\Domain\\Performance\\Services\\GoalSheetRules', 'fields' => [
                ['key' => 'creation', 'label' => 'Goal creation', 'type' => 'section', 'help' => 'By default goals are created by hand. With system mapping, a goal template creates the draft goal sheet when a cycle is launched or reaches goal setting, and the reporting manager owns the final goal sheet: the employee proposes additions and changes, which the manager confirms (or changes directly).', 'fields' => [
                    ['key' => 'goal_creation', 'label' => 'How are goals created? (blank = manually)', 'type' => 'radio', 'options' => ['manual' => 'Manually create goals', 'system' => 'Mapped by the system from a goal template']],
                    ['key' => 'goal_template_code', 'label' => 'Choose template for system mapping', 'type' => 'select', 'options_from' => 'goal_templates', 'help' => 'Blank: the first active goal template whose "Applies to" matches the employee.', 'visible_when' => ['key' => 'goal_creation', 'equals' => 'system']],
                    // Owner decision 2026-10-29: the reporting manager always confirms the mapped sheet; this only says who fills it in
                    // first (who is told it is ready). Stored values keep their meaning.
                    ['key' => 'goal_sheet_owner', 'label' => 'Choose role for system mapping: who fills in the mapped goal sheet first? (blank = the employee)', 'type' => 'radio', 'options' => ['employee' => 'The employee', 'manager' => 'The reporting manager'], 'visible_when' => ['key' => 'goal_creation', 'equals' => 'system'],
                        'help' => 'Told the goal sheet is ready. Either way the reporting manager owns the final sheet: the employee\'s additions and changes wait for the manager\'s confirmation (goal sheet approval).'],
                    ['key' => 'plan_approval', 'label' => 'Does a submitted goal sheet need approval? (blank = no)', 'type' => 'radio', 'options' => ['none' => 'No: submitted goals become active', 'manager' => 'Yes: the reporting manager (or HR) approves it in the Approval Center', 'workflow' => 'Yes: a workflow on "Goal plan submitted" decides (the Approval Center when none runs)']],
                ]],
                ['key' => 'kra_weightage', 'label' => 'KRA weightage', 'type' => 'section', 'help' => 'Rules for the goals (KRAs) on an employee\'s goal sheet of a cycle. Blank = no rule.', 'fields' => [
                    ['key' => 'goal_weight_mode', 'label' => 'KRA details: weightage (blank = any)', 'type' => 'radio', 'options' => ['any' => 'Any', 'specific' => 'Specific (each KRA carries its own weightage)', 'equal' => 'Equal (all KRAs of equal weightage, set by the system)']],
                    ['key' => 'total_goal_weight', 'label' => 'Total KRA weightage', 'type' => 'number', 'min' => 1, 'max' => 1000],
                    ['key' => 'min_goal_weight', 'label' => 'Single KRA weightage: minimum value', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'max_goal_weight', 'label' => 'Single KRA weightage: maximum value', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'min_goals', 'label' => 'Number of KRAs: minimum number (checked when the goal sheet is submitted)', 'type' => 'number', 'min' => 0, 'max' => 50],
                    ['key' => 'max_goals', 'label' => 'Number of KRAs: maximum number', 'type' => 'number', 'min' => 0, 'max' => 50],
                ]],
                ['key' => 'kpi_weightage', 'label' => 'KPI weightage', 'type' => 'section', 'help' => 'Rules for the KPIs (key results) inside each KRA. Blank = no rule.', 'fields' => [
                    ['key' => 'kpi_weight_mode', 'label' => 'KPI details: weightage (blank = any)', 'type' => 'radio', 'options' => ['any' => 'Any', 'specific' => 'Specific (each KPI carries its own weightage)', 'equal' => 'All KPIs of equal weightage (set by the system)']],
                    ['key' => 'kpi_total_weight', 'label' => 'Total KPI weightage within a KRA', 'type' => 'select', 'options' => ['none' => 'No rule', 'equal_to_goal' => 'Equal to KRA weightage', 'hundred' => '100']],
                    ['key' => 'min_kpi_weight', 'label' => 'Single KPI weightage: minimum value', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'max_kpi_weight', 'label' => 'Single KPI weightage: maximum value', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'min_kpis', 'label' => 'Number of KPIs in a KRA: minimum number', 'type' => 'number', 'min' => 0, 'max' => 50],
                    ['key' => 'max_kpis', 'label' => 'Number of KPIs in a KRA: maximum number', 'type' => 'number', 'min' => 0, 'max' => 50],
                    ['key' => 'unit_and_target_required', 'label' => 'Are the UOM and the target mandatory?', 'type' => 'yes_no'],
                ]],
                ['key' => 'competency_weightage', 'label' => 'Competencies', 'type' => 'section', 'help' => 'Which competencies employees of this policy are rated on, and their weightage.', 'fields' => [
                    ['key' => 'include_competencies', 'label' => 'Include competency? (blank = yes)', 'type' => 'yes_no'],
                    ['key' => 'competency_codes', 'label' => 'Competency details (blank = the cycle\'s competencies)', 'type' => 'multiselect', 'options_from' => 'competencies'],
                    ['key' => 'competency_block_weight', 'label' => 'Total competency weightage in the overall rating (blank = the cycle\'s)', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'min_competency_weight', 'label' => 'Single competency weightage: minimum value', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'max_competency_weight', 'label' => 'Single competency weightage: maximum value', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'min_competencies', 'label' => 'Number of competencies: minimum number', 'type' => 'number', 'min' => 0, 'max' => 50],
                    ['key' => 'max_competencies', 'label' => 'Number of competencies: maximum number', 'type' => 'number', 'min' => 0, 'max' => 50],
                ]],
                ['key' => 'goal_creation_configuration', 'label' => 'Goal creation configuration', 'type' => 'section', 'help' => 'Per goal field, what the goal creator, the employee, the manager and the approver (while the goal sheet waits for approval) may do. Blank = view and edit (as before). Performance administrators are not limited.', 'fields' => [
                    ['key' => 'field_rules', 'label' => 'Field rights', 'type' => 'repeater', 'item_label' => 'options', 'fields' => [
                        ['key' => 'field', 'label' => 'Goal field', 'type' => 'select', 'options' => ['title' => 'Title', 'description' => 'Description', 'kra' => 'From the KRA library', 'perspective' => 'Perspective', 'measure_type' => 'Measure', 'unit' => 'UOM', 'start_value' => 'Start value', 'target_value' => 'Target', 'weight' => 'Weightage', 'start_date' => 'Start date', 'due_date' => 'Due date', 'key_results' => 'KPIs (key results)']],
                        ['key' => 'creator', 'label' => 'Goal creator', 'type' => 'select', 'options' => ['edit' => 'View and edit', 'required' => 'Mandatory', 'view' => 'View only', 'hidden' => 'Hidden']],
                        ['key' => 'employee', 'label' => 'Employee', 'type' => 'select', 'options' => ['edit' => 'View and edit', 'required' => 'Mandatory', 'view' => 'View only', 'hidden' => 'Hidden']],
                        ['key' => 'manager', 'label' => 'Manager', 'type' => 'select', 'options' => ['edit' => 'View and edit', 'required' => 'Mandatory', 'view' => 'View only', 'hidden' => 'Hidden']],
                        ['key' => 'approver', 'label' => 'Approver', 'type' => 'select', 'options' => ['edit' => 'View and edit', 'required' => 'Mandatory', 'view' => 'View only', 'hidden' => 'Hidden']],
                    ]],
                ]],
                ['key' => 'review_configuration', 'label' => 'Performance review configuration', 'type' => 'section', 'help' => 'For each review level: what is rated (each KRA, or each KPI of a KRA), how (on the rating scale, or as an achievement percentage) and whether evidence is uploaded. Blank = KRA, rating scale, no upload (as before).', 'fields' => [
                    ['key' => 'self_review_level', 'label' => 'Employee (self review): rate', 'type' => 'select', 'options' => ['goal' => 'Each KRA', 'key_result' => 'Each KPI (the KRA takes their weighted rating)']],
                    ['key' => 'self_review_input', 'label' => 'Employee: input type', 'type' => 'select', 'options' => ['scale' => 'Rating scale', 'percentage' => 'Achievement percentage']],
                    ['key' => 'self_evidence', 'label' => 'Employee: upload evidence', 'type' => 'select', 'options' => ['off' => 'No upload', 'optional' => 'Optional', 'required' => 'Mandatory']],
                    ['key' => 'manager_review_level', 'label' => 'Manager: rate', 'type' => 'select', 'options' => ['goal' => 'Each KRA', 'key_result' => 'Each KPI (the KRA takes their weighted rating)']],
                    ['key' => 'manager_review_input', 'label' => 'Manager: input type', 'type' => 'select', 'options' => ['scale' => 'Rating scale', 'percentage' => 'Achievement percentage']],
                    ['key' => 'manager_evidence', 'label' => 'Manager: upload evidence', 'type' => 'select', 'options' => ['off' => 'No upload', 'optional' => 'Optional', 'required' => 'Mandatory']],
                    ['key' => 'approver_review_level', 'label' => 'Approver (cycles with an "Approver review" stage): rate', 'type' => 'select', 'options' => ['goal' => 'Each KRA', 'key_result' => 'Each KPI (the KRA takes their weighted rating)']],
                    ['key' => 'approver_review_input', 'label' => 'Approver: input type', 'type' => 'select', 'options' => ['scale' => 'Rating scale', 'percentage' => 'Achievement percentage']],
                    ['key' => 'approver_evidence', 'label' => 'Approver: upload evidence', 'type' => 'select', 'options' => ['off' => 'No upload', 'optional' => 'Optional', 'required' => 'Mandatory']],
                ]],
                ['key' => 'scorecard', 'label' => 'Performance scorecard configuration', 'type' => 'section', 'help' => 'What the employee does not see on their own appraisal and profile (HR and their managers see everything). The final rating and its label always show once published.', 'fields' => [
                    ['key' => 'scorecard_hidden', 'label' => 'Hide from the employee', 'type' => 'checkboxes', 'options' => ['reviewer_ratings' => 'Reviewer ratings', 'goal_details' => 'KRA details', 'competency_details' => 'Competency details', 'goal_score' => 'KRA score', 'competency_score' => 'Competency score', 'computed_rating' => 'Computed rating']],
                ]],
                ['key' => 'other_setting', 'label' => 'Other setting', 'type' => 'section', 'help' => 'Blank = the organisation setting "Copy the previous review level\'s inputs" (Setup → Performance).', 'fields' => [
                    ['key' => 'copy_goal_inputs', 'label' => 'Do you want to copy last level review inputs (achievement, comments etc.) to the next level?', 'type' => 'yes_no'],
                    ['key' => 'copy_competency_inputs', 'label' => 'Do you want to copy last level competency inputs (achievement, comments etc.) to the next level?', 'type' => 'yes_no'],
                ]],
            ]],
            // HROne setup parity HP-8f (PIP → PIP policy): the plan's period and extensions, letters when it starts and when
            // its outcome is recorded, start and close checklists, and approval of the plan and of its outcome. Read by
            // ImprovementPlans through ImprovementPlanRules; without a policy plans work exactly as before.
            'pip' => ['label' => 'PIP policy', 'validator' => 'App\\Domain\\Performance\\Services\\ImprovementPlanRules', 'fields' => [
                ['key' => 'period', 'label' => 'Settings', 'type' => 'section', 'fields' => [
                    ['key' => 'duration_days', 'label' => 'PIP period (in days; blank = about two months)', 'type' => 'number', 'min' => 7, 'max' => 365, 'help' => 'A new plan ends this many days after it starts unless its end date is changed.'],
                    ['key' => 'max_extensions', 'label' => 'Number of extensions allowed (blank or 0 = any)', 'type' => 'number', 'min' => 0, 'max' => 10],
                ]],
                ['key' => 'letters', 'label' => 'Letters', 'type' => 'section', 'help' => 'Letters are drafted for review like any other letter (their approval is unchanged); a missing template never blocks the plan.', 'fields' => [
                    ['key' => 'letter_on_activate', 'label' => 'Would you like to send a letter when the PIP starts?', 'type' => 'yes_no'],
                    ['key' => 'activate_letter_template', 'label' => 'PIP letter template', 'type' => 'select', 'options_from' => 'letter_templates', 'letter_types' => ['pip', 'performance'], 'visible_when' => ['key' => 'letter_on_activate', 'in' => [true, 1, '1']]],
                    ['key' => 'letter_on_close', 'label' => 'Would you like to send a letter when the PIP outcome is recorded?', 'type' => 'yes_no'],
                    ['key' => 'close_letter_template', 'label' => 'PIP evaluation (outcome) letter template', 'type' => 'select', 'options_from' => 'letter_templates', 'letter_types' => ['pip_outcome', 'performance'], 'visible_when' => ['key' => 'letter_on_close', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'checklists', 'label' => 'Advance settings', 'type' => 'section', 'help' => 'Checklist items become checkpoints of the plan: start items when the plan starts, close items once its outcome is recorded (the plan is closed only after they are reviewed).', 'fields' => [
                    ['key' => 'map_start_checklist', 'label' => 'Would you like to map a pre (start) checklist?', 'type' => 'yes_no'],
                    ['key' => 'start_checklist', 'label' => 'Start checklist', 'type' => 'repeater', 'visible_when' => ['key' => 'map_start_checklist', 'in' => [true, 1, '1']], 'fields' => [
                        ['key' => 'title', 'label' => 'Item', 'type' => 'text'],
                        ['key' => 'due_offset_days', 'label' => 'Due (days after the start)', 'type' => 'number', 'min' => 0, 'max' => 365],
                        ['key' => 'owner', 'label' => 'Owner', 'type' => 'select', 'options' => ['manager' => 'Manager', 'hr' => 'HR', 'employee' => 'Employee']],
                    ]],
                    ['key' => 'map_close_checklist', 'label' => 'Would you like to map a post (close) checklist?', 'type' => 'yes_no'],
                    ['key' => 'close_checklist', 'label' => 'Close checklist', 'type' => 'repeater', 'visible_when' => ['key' => 'map_close_checklist', 'in' => [true, 1, '1']], 'fields' => [
                        ['key' => 'title', 'label' => 'Item', 'type' => 'text'],
                        ['key' => 'due_offset_days', 'label' => 'Due (days after the outcome)', 'type' => 'number', 'min' => 0, 'max' => 365],
                        ['key' => 'owner', 'label' => 'Owner', 'type' => 'select', 'options' => ['manager' => 'Manager', 'hr' => 'HR', 'employee' => 'Employee']],
                    ]],
                ]],
                ['key' => 'review', 'label' => 'Approval', 'type' => 'section', 'help' => 'Approvals are decided by HR with "Create and manage performance improvement plans" (never the person who asked), or by the steps of a published workflow on the trigger named.', 'fields' => [
                    ['key' => 'approval', 'label' => 'Does opening a PIP need approval? (blank = no)', 'type' => 'radio', 'options' => ['none' => 'No: the plan starts at once', 'workflow' => 'Yes: a workflow on "Improvement plan requested" decides (the Approval Center when none runs)']],
                    ['key' => 'evaluation_approval', 'label' => 'Does the PIP evaluation (the outcome) need approval?', 'type' => 'yes_no', 'help' => 'Yes: the outcome waits for approval (a workflow on "Improvement plan evaluation requested", else the Approval Center) before it is recorded.'],
                ]],
            ]],
            // HROne setup parity HP-8h (SV-12, PF-1 … PF-5; Performance → 360 Feedback → 360 policy): how often 360 feedback
            // runs and its window, who gives feedback (manager, functional manager, peers and subordinates matched by up to
            // three organisation attributes, clients by e-mail), how many, whether each source is anonymous and which
            // feedback template (P13, process feedback_360) it answers, and who nominates reviewers from which pool, with
            // or without approval. "Results and privacy" holds PeopleOS's anonymity rules (owner decision: anonymous peer
            // feedback is shown to the employee aggregated, only after the round closes). Read through Feedback360Rules;
            // without a policy no 360 round runs and appraisals are unchanged.
            'feedback_360' => ['label' => '360 feedback policy', 'validator' => 'App\\Domain\\Performance\\Services\\Feedback360Rules', 'fields' => [
                ['key' => 'frequency_duration', 'label' => 'Frequency & duration', 'type' => 'section', 'help' => 'A round covers one period of the frequency (e.g. a quarter). Its window opens on the day of the period chosen below and stays open for the number of days given; HR may extend it.', 'fields' => [
                    ['key' => 'frequency', 'label' => 'Frequency (blank = yearly)', 'type' => 'radio', 'options' => ['yearly' => 'Yearly', 'half_yearly' => 'Half yearly', 'quarterly' => 'Quarterly', 'monthly' => 'Monthly']],
                    ['key' => 'window_start_day', 'label' => 'Window starts on day … of the cycle (blank = the first day)', 'type' => 'number', 'min' => 1, 'max' => 366],
                    ['key' => 'window_days', 'label' => 'Window to complete 360 feedback (days; blank = 14)', 'type' => 'number', 'min' => 1, 'max' => 120],
                    ['key' => 'auto_launch', 'label' => 'Launch each round automatically when its window opens? (blank = no: HR launches it)', 'type' => 'yes_no'],
                ]],
                ['key' => 'manager_section', 'label' => 'Manager feedback', 'type' => 'section', 'fields' => [
                    ['key' => 'manager_feedback', 'label' => 'Collect feedback from manager', 'type' => 'yes_no'],
                    ['key' => 'functional_manager_feedback', 'label' => 'Collect feedback from functional manager', 'type' => 'yes_no'],
                    ['key' => 'manager_anonymous', 'label' => 'Anonymous feedback (blank = no)', 'type' => 'yes_no'],
                    ['key' => 'manager_template_id', 'label' => 'Feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'feedback_360'],
                ]],
                ['key' => 'peer_section', 'label' => 'Peer feedback', 'type' => 'section', 'fields' => [
                    ['key' => 'peer_feedback', 'label' => 'Collect feedback from peers', 'type' => 'yes_no'],
                    ['key' => 'peer_match', 'label' => 'Peers are employees having the same … as the assessed employee (up to three; blank = anyone)', 'type' => 'checkboxes', 'options' => ['company_id' => 'Company', 'location_id' => 'Location', 'business_unit_id' => 'Business unit', 'division_id' => 'Division', 'department_id' => 'Department', 'team_id' => 'Team', 'designation_id' => 'Designation', 'level_id' => 'Level', 'grade_id' => 'Grade'], 'visible_when' => ['key' => 'peer_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'peer_max', 'label' => 'Maximum number of peers', 'type' => 'number', 'min' => 1, 'max' => 20, 'visible_when' => ['key' => 'peer_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'peer_anonymous', 'label' => 'Anonymous feedback (blank = yes)', 'type' => 'yes_no', 'visible_when' => ['key' => 'peer_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'peer_template_id', 'label' => 'Feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'feedback_360', 'visible_when' => ['key' => 'peer_feedback', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'subordinate_section', 'label' => 'Subordinate feedback', 'type' => 'section', 'fields' => [
                    ['key' => 'subordinate_feedback', 'label' => 'Collect feedback from subordinates', 'type' => 'yes_no'],
                    ['key' => 'subordinate_match', 'label' => 'Subordinates are employees having the same … as the assessed employee (up to three; blank = any report)', 'type' => 'checkboxes', 'options' => ['company_id' => 'Company', 'location_id' => 'Location', 'business_unit_id' => 'Business unit', 'division_id' => 'Division', 'department_id' => 'Department', 'team_id' => 'Team', 'designation_id' => 'Designation', 'level_id' => 'Level', 'grade_id' => 'Grade'], 'visible_when' => ['key' => 'subordinate_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'subordinate_max', 'label' => 'Maximum number of subordinates', 'type' => 'number', 'min' => 1, 'max' => 20, 'visible_when' => ['key' => 'subordinate_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'subordinate_anonymous', 'label' => 'Anonymous feedback (blank = yes)', 'type' => 'yes_no', 'visible_when' => ['key' => 'subordinate_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'subordinate_template_id', 'label' => 'Feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'feedback_360', 'visible_when' => ['key' => 'subordinate_feedback', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'client_section', 'label' => 'Client feedback', 'type' => 'section', 'help' => 'Clients answer through a signed link e-mailed to them, without signing in; only their name and e-mail are kept. The link expires when the window closes and works once.', 'fields' => [
                    ['key' => 'client_feedback', 'label' => 'Collect feedback from clients', 'type' => 'yes_no'],
                    ['key' => 'client_max', 'label' => 'Maximum number of clients', 'type' => 'number', 'min' => 1, 'max' => 20, 'visible_when' => ['key' => 'client_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'client_anonymous', 'label' => 'Anonymous feedback (blank = no)', 'type' => 'yes_no', 'visible_when' => ['key' => 'client_feedback', 'in' => [true, 1, '1']]],
                    ['key' => 'client_template_id', 'label' => 'Feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'feedback_360', 'visible_when' => ['key' => 'client_feedback', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'nomination_settings', 'label' => 'Nomination settings', 'type' => 'section', 'help' => 'The same role nominates for peers, subordinates and clients feedback. Managers and functional managers are asked by the system.', 'fields' => [
                    ['key' => 'nominator', 'label' => 'Which role will nominate 360 feedback stakeholders? (blank = the employee)', 'type' => 'radio', 'options' => ['employee' => 'The employee', 'manager' => 'The reporting manager', 'role' => 'A role']],
                    ['key' => 'nominator_role', 'label' => 'Nominating role', 'type' => 'select', 'options_from' => 'roles', 'visible_when' => ['key' => 'nominator', 'equals' => 'role']],
                    ['key' => 'nomination_scope', 'label' => 'To whom do you want to send nomination requests? (blank = across the organisation)', 'type' => 'radio', 'options' => ['organisation' => 'Across the organisation', 'direct_reportees' => 'Direct reportees (peers share the reporting manager; subordinates report to the employee)']],
                    ['key' => 'min_nominations', 'label' => 'Minimum number of reviewers to nominate (peers, subordinates and clients together)', 'type' => 'number', 'min' => 0, 'max' => 40],
                    ['key' => 'nomination_approval', 'label' => 'Do nominations need approval? (blank = no)', 'type' => 'radio', 'options' => ['none' => 'No: the reviewers are asked at once', 'manager' => 'Yes: the reporting manager (or HR) approves them in the Approval Center', 'hr' => 'Yes: HR approves them in the Approval Center']],
                ]],
                ['key' => 'results_privacy', 'label' => 'Results and privacy', 'type' => 'section', 'help' => 'Anonymous sources are only ever shown aggregated, and only once enough people have answered; nobody, HR included, sees who gave an anonymous answer.', 'fields' => [
                    ['key' => 'employee_sees', 'label' => 'When does the employee see their 360 feedback? (blank = after the round closes)', 'type' => 'radio', 'options' => ['after_close' => 'After the round closes', 'never' => 'Never (HR and managers only)']],
                    ['key' => 'manager_sees', 'label' => 'Do the employee\'s performance managers see it after the round closes? (blank = yes)', 'type' => 'yes_no'],
                    ['key' => 'min_responses', 'label' => 'Minimum answers before an anonymous source is shown (blank = 3)', 'type' => 'number', 'min' => 2, 'max' => 20],
                    ['key' => 'include_in_appraisal', 'label' => 'Blend the 360 rating into the appraisal rating? (blank = no)', 'type' => 'yes_no', 'help' => 'Rounds linked to a performance cycle: the average 360 score (peers, subordinates, functional managers and clients) is placed on the cycle\'s scale and weighed into the computed rating.'],
                    ['key' => 'appraisal_weight', 'label' => 'Weight of the 360 rating in the computed rating (%)', 'type' => 'number', 'min' => 1, 'max' => 50, 'visible_when' => ['key' => 'include_in_appraisal', 'in' => [true, 1, '1']]],
                ]],
            ]],
            // HROne setup parity HP-8i (SV-11, PF-6 … PF-11; Performance → Performance → Performance policy): goal and review
            // cycles with their windows, how review ratings are calculated, who in the review chain sees which ratings and
            // comments, reviewers' recommendations, competency review rules and how the appraisal rating is built from the
            // periodic reviews, with eligibility by joining date. Read through PerformancePolicyRules; every blank answer
            // (and no policy) keeps today's appraisal behaviour. Answers marked "(recorded)" are kept on the policy for
            // the processes that will read them; the rest are applied.
            'performance' => ['label' => 'Performance policy', 'validator' => 'App\\Domain\\Performance\\Services\\PerformancePolicyRules', 'fields' => [
                ['key' => 'goal_step', 'label' => 'Goal', 'type' => 'section', 'help' => 'Goal cycle, its window and new joiners. Goals of a cycle are set inside the goal window when one is given (performance administrators are not limited).', 'fields' => [
                    ['key' => 'goal_cycle', 'label' => 'Goal cycle (blank = one goal sheet per performance cycle) (recorded)', 'type' => 'radio', 'options' => ['yearly' => 'Yearly', 'half_yearly' => 'Half yearly', 'quarterly' => 'Quarterly', 'monthly' => 'Monthly']],
                    ['key' => 'goal_window_timing', 'label' => 'When do you want to start the goal cycle window? (blank = after the cycle starts)', 'type' => 'radio', 'options' => ['before' => 'Before the cycle starts', 'after' => 'After the cycle starts']],
                    ['key' => 'goal_window_offset_days', 'label' => 'Days before / after the cycle starts', 'type' => 'number', 'min' => 0, 'max' => 365],
                    ['key' => 'goal_window_days', 'label' => 'How many days should the goal cycle run? (goal window; blank = no window)', 'type' => 'number', 'min' => 1, 'max' => 366],
                    ['key' => 'goal_manual_start', 'label' => 'Manually initiate goal cycle (recorded; cycles are launched by HR)', 'type' => 'yes_no'],
                    ['key' => 'goal_start_roles', 'label' => 'The goal cycle will be initiated by', 'type' => 'multiselect', 'options_from' => 'roles', 'visible_when' => ['key' => 'goal_manual_start', 'in' => [true, 1, '1']]],
                    ['key' => 'joiner_goals', 'label' => 'Set goals for new joiners who join after goal cycle assignment (recorded)', 'type' => 'yes_no'],
                    ['key' => 'joiner_goal_cutoff_days', 'label' => 'If an employee joins … days before the end of goal cycle then assign goal sheet', 'type' => 'number', 'min' => 0, 'max' => 366, 'visible_when' => ['key' => 'joiner_goals', 'in' => [true, 1, '1']]],
                    ['key' => 'start_month', 'label' => 'Performance cycle start month (recorded; blank = Setup → Performance)', 'type' => 'select', 'options' => [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December']],
                    ['key' => 'review_feedback', 'label' => 'Do you want to map the feedback template on the performance review?', 'type' => 'yes_no', 'help' => 'The manager answers it with the manager review (My tasks, Me → Feedback).'],
                    ['key' => 'review_feedback_template_id', 'label' => 'Feedback template', 'type' => 'select', 'options_from' => 'feedback_templates', 'process' => 'performance_review', 'visible_when' => ['key' => 'review_feedback', 'in' => [true, 1, '1']]],
                ]],
                ['key' => 'review_step', 'label' => 'Goal review', 'type' => 'section', 'help' => 'Review cycles shorter than the appraisal cycle add periodic reviews (rated by the reporting manager) inside it. If the goal cycle is half-yearly the review cycle cannot be yearly.', 'fields' => [
                    ['key' => 'review_cycle', 'label' => 'Review cycle (blank = yearly: one review per performance cycle, as before)', 'type' => 'radio', 'options' => ['yearly' => 'Yearly', 'half_yearly' => 'Half yearly', 'quarterly' => 'Quarterly', 'monthly' => 'Monthly']],
                    ['key' => 'review_window_timing', 'label' => 'When do you want to start the review cycle window? (blank = after the period ends)', 'type' => 'radio', 'options' => ['before' => 'Before the period ends', 'after' => 'After the period ends']],
                    ['key' => 'review_window_offset_days', 'label' => 'Days before / after the period ends', 'type' => 'number', 'min' => 0, 'max' => 90],
                    ['key' => 'review_window_days', 'label' => 'How many days should the review cycle run? (review window; blank = 15)', 'type' => 'number', 'min' => 1, 'max' => 90],
                    ['key' => 'review_manual_start', 'label' => 'Manually initiate review cycle (blank = opened automatically when the window starts)', 'type' => 'yes_no'],
                    ['key' => 'review_start_roles', 'label' => 'The review cycle will be initiated by (besides performance administrators)', 'type' => 'multiselect', 'options_from' => 'roles', 'visible_when' => ['key' => 'review_manual_start', 'in' => [true, 1, '1']]],
                    ['key' => 'rating_method', 'label' => 'How do you want to calculate review rating? (blank = weighted average)', 'type' => 'radio', 'options' => ['weighted' => 'Weighted average', 'sum' => 'Sum of all goals (rating share × weightage ÷ 100)']],
                    ['key' => 'show_rating', 'label' => 'Do you want to calculate / show rating while doing performance review? (recorded)', 'type' => 'yes_no'],
                    ['key' => 'edit_kpi_rating', 'label' => 'Allow the user to edit the system calculated KPI rating? (recorded; Setup → Performance decides)', 'type' => 'yes_no'],
                    ['key' => 'allow_above_max', 'label' => 'Allow a goal rating above the maximum of the goal rating scale?', 'type' => 'yes_no'],
                    ['key' => 'max_goal_rating', 'label' => 'Highest goal rating allowed', 'type' => 'number', 'min' => 1, 'max' => 100, 'visible_when' => ['key' => 'allow_above_max', 'in' => [true, 1, '1']]],
                    ['key' => 'change_system_rating', 'label' => 'Allow the user to change the system-calculated review rating? (recorded)', 'type' => 'yes_no'],
                    ['key' => 'ack_review_rating', 'label' => 'Send an acknowledgement of the review rating to the employee? (recorded)', 'type' => 'yes_no'],
                    ['key' => 'ack_review_role', 'label' => 'Acknowledgement taken by', 'type' => 'select', 'options_from' => 'roles', 'visible_when' => ['key' => 'ack_review_rating', 'in' => [true, 1, '1']]],
                    ['key' => 'lock_system_achievement', 'label' => 'Restrict the user from editing the system achievement? (recorded)', 'type' => 'yes_no'],
                    ['key' => 'copy_last_level', 'label' => 'Copy last level inputs (achievement, comments etc.) to the next level? (blank = Setup → Performance)', 'type' => 'yes_no', 'help' => 'A goal sheet policy\'s own answer comes first.'],
                    ['key' => 'round_review_rating', 'label' => 'Round off review-wise rating? (recorded; Setup → Performance rounding applies)', 'type' => 'yes_no'],
                ]],
                ['key' => 'visibility', 'label' => 'Visibility', 'type' => 'section', 'help' => 'Who in the review chain (employee → manager → approver) sees the ratings and comments of the other levels. HR always sees everything; blank = all (as before).', 'fields' => [
                    ['key' => 'ratings_visible_to', 'label' => 'Ratings and comments are visible to', 'type' => 'radio', 'options' => ['all' => 'All (the employee once the rating is published)', 'preceding_level' => 'Preceding level (each level sees the level before it)', 'last_level' => 'Last level (only the final reviewer sees the other levels)', 'reviewer' => 'Reviewers (not the employee)', 'none' => 'None (HR only)']],
                    ['key' => 'final_rating_visible_to', 'label' => 'Final rating is visible to', 'type' => 'radio', 'options' => ['all' => 'All (the employee once published)', 'all_except_employee' => 'All except employee', 'last_level' => 'Last level (the final reviewer)', 'none' => 'None (HR only)']],
                    ['key' => 'final_comments_visible_to', 'label' => 'Final comments are visible to', 'type' => 'radio', 'options' => ['all' => 'All (the employee once published)', 'all_except_employee' => 'All except employee', 'last_level' => 'Last level (the final reviewer)', 'none' => 'None (HR only)']],
                ]],
                ['key' => 'advance', 'label' => 'Advance', 'type' => 'section', 'help' => 'Recommendations are entered with the manager (and approver) review and kept on the appraisal. They are suggestions: compensation cycles show the increment, finalized training needs become development needs; nothing is paid or decided by them.', 'fields' => [
                    ['key' => 'recommend_training', 'label' => 'Do you want to enable TNI recommendation?', 'type' => 'yes_no'],
                    ['key' => 'recommend_promotion', 'label' => 'Do you want to enable promotion recommendation?', 'type' => 'yes_no'],
                    ['key' => 'recommend_increment', 'label' => 'Do you want to enable salary increment recommendations?', 'type' => 'yes_no'],
                    ['key' => 'increment_type', 'label' => 'How do you want to define salary increment? (blank = percentage)', 'type' => 'radio', 'options' => ['amount' => 'Amount', 'percentage' => 'Percentage'], 'visible_when' => ['key' => 'recommend_increment', 'in' => [true, 1, '1']]],
                    ['key' => 'recommend_variable', 'label' => 'Do you want to enable variable recommendations?', 'type' => 'yes_no'],
                ]],
                ['key' => 'competency_step', 'label' => 'Competency review', 'type' => 'section', 'help' => 'Blank weightages = the goal sheet policy\'s competency weightage, else the cycle\'s weights.', 'fields' => [
                    ['key' => 'competency_with_review', 'label' => 'Is the competency cycle the same as the review cycle? (recorded)', 'type' => 'yes_no'],
                    ['key' => 'copy_competency_inputs', 'label' => 'Copy last level competency inputs to the next level? (blank = Setup → Performance)', 'type' => 'yes_no'],
                    ['key' => 'competency_above_max', 'label' => 'Allow a competency rating above the maximum of the competency rating scale?', 'type' => 'yes_no'],
                    ['key' => 'max_competency_rating', 'label' => 'Highest competency rating allowed', 'type' => 'number', 'min' => 1, 'max' => 100, 'visible_when' => ['key' => 'competency_above_max', 'in' => [true, 1, '1']]],
                    ['key' => 'competency_weight', 'label' => 'Competency weightage (%)', 'type' => 'number', 'min' => 0, 'max' => 100],
                    ['key' => 'goal_weight', 'label' => 'Goal weightage (%)', 'type' => 'number', 'min' => 0, 'max' => 100],
                ]],
                ['key' => 'appraisal_step', 'label' => 'Appraisal', 'type' => 'section', 'help' => 'How the appraisal rating is built from the periodic reviews (blank = the year-end review alone, as before) and who is eligible.', 'fields' => [
                    ['key' => 'eligibility', 'label' => 'Enable appraisal eligibility criteria', 'type' => 'yes_no'],
                    ['key' => 'joiner_cutoff_days', 'label' => 'If an employee joins after … days from the start of the performance cycle, they are not eligible for the current appraisal', 'type' => 'number', 'min' => 0, 'max' => 366, 'visible_when' => ['key' => 'eligibility', 'in' => [true, 1, '1']]],
                    ['key' => 'ack_overall_rating', 'label' => 'Send an acknowledgement of the overall review rating to the employee? (recorded; employees acknowledge published ratings)', 'type' => 'yes_no'],
                    ['key' => 'ack_overall_role', 'label' => 'Acknowledgement taken by', 'type' => 'select', 'options_from' => 'roles', 'visible_when' => ['key' => 'ack_overall_rating', 'in' => [true, 1, '1']]],
                    ['key' => 'round_final_rating', 'label' => 'Round off final PMS rating? (recorded; Setup → Performance rounding applies)', 'type' => 'yes_no'],
                    ['key' => 'appraisal_basis', 'label' => 'Calculate appraisal rating based on (blank = the year-end review only)', 'type' => 'radio', 'options' => ['average' => 'Average of all reviews', 'last' => 'Last review', 'custom' => 'Custom (a weightage per review)']],
                    ['key' => 'custom_weights', 'label' => 'Weightage of each periodic review, in order (e.g. 20, 20, 30, 30; adding up to 100)', 'type' => 'tags', 'visible_when' => ['key' => 'appraisal_basis', 'equals' => 'custom']],
                    ['key' => 'include_appraisal', 'label' => 'Include the year-end appraisal review with the periodic reviews?', 'type' => 'yes_no', 'visible_when' => ['key' => 'appraisal_basis', 'in' => ['average', 'last', 'custom']]],
                    ['key' => 'appraisal_weight', 'label' => 'Appraisal weightage (%)', 'type' => 'number', 'min' => 0, 'max' => 100, 'visible_when' => ['key' => 'include_appraisal', 'in' => [true, 1, '1']]],
                    ['key' => 'performance_letter', 'label' => 'Generate performance letter when the rating is published?', 'type' => 'yes_no'],
                    ['key' => 'letter_template_id', 'label' => 'Letter template', 'type' => 'select', 'options_from' => 'letter_templates', 'letter_types' => ['performance'], 'visible_when' => ['key' => 'performance_letter', 'in' => [true, 1, '1']]],
                ]],
            ]],
            // HROne setup parity AS-B (Asset → Asset policy): the assets an employee group is entitled to (raised as requests
            // for joiners, due asset.onboarding.due_days after joining), what employees may request (only what they are
            // entitled to, or any asset) and whether HR may assign assets outside the entitlement. Read by AssetEntitlements;
            // no policy = as before (any asset requested and assigned, nothing raised for joiners).
            'asset' => ['label' => 'Asset entitlement policy', 'fields' => [
                ['key' => 'entitlements', 'label' => 'Asset assignment', 'type' => 'repeater', 'item_label' => 'options',
                    'help' => 'Please select the assets to be assigned to employees of this policy. Each row is raised as an asset request when an employee joins.',
                    'fields' => [
                        ['key' => 'category', 'label' => 'Asset type', 'type' => 'select', 'options_from' => 'asset_categories'],
                        ['key' => 'subtype', 'label' => 'Asset sub type (blank = any)', 'type' => 'select', 'options_from' => 'asset_subtypes'],
                        ['key' => 'model', 'label' => 'Product (blank = any)', 'type' => 'select', 'options_from' => 'asset_models'],
                        ['key' => 'quantity', 'label' => 'Quantity (blank = 1)', 'type' => 'number', 'min' => 1, 'max' => 10],
                    ]],
                ['key' => 'requests', 'label' => 'Asset requests', 'type' => 'section', 'fields' => [
                    ['key' => 'request_scope', 'label' => 'Employee can request for (blank = any asset)', 'type' => 'radio', 'options' => ['mapped' => 'Mapped asset (only the assets above)', 'any' => 'Any asset']],
                ]],
                ['key' => 'assignment', 'label' => 'Assignment by HR', 'type' => 'section', 'fields' => [
                    ['key' => 'hr_assign_scope', 'label' => 'Can HR assign assets outside the mapped assets? (blank = yes)', 'type' => 'radio',
                        'options' => ['any' => 'Yes, any asset', 'warn' => 'Yes, with a warning', 'mapped' => 'No, only the mapped assets']],
                ]],
            ]],
            // HROne setup parity HP-9d (Engage → Reward points policy): the points each employee of the policy gets to give
            // colleagues per credit cycle (from the points budget), the reward limits and the eligibility rows that give some
            // employees a different number of points. Read by RewardPoints (credit job peopleos:engage:credit-points, awards);
            // no policy = nobody is credited points (awards from badges still post from the budget).
            'reward_points' => ['label' => 'Reward points policy', 'validator' => 'App\\Domain\\Recognition\\Services\\RewardPointRules', 'fields' => [
                ['key' => 'basic', 'label' => 'Basic', 'type' => 'section', 'help' => 'The policy name and description are the policy\'s own. Points are credited at the start of each cycle by the daily credit run.', 'fields' => [
                    ['key' => 'credit_cycle', 'label' => 'Credit cycle', 'type' => 'radio', 'options' => ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half yearly', 'yearly' => 'Yearly']],
                ]],
                ['key' => 'points', 'label' => 'Reward points', 'type' => 'section', 'fields' => [
                    ['key' => 'points_per_cycle', 'label' => 'Points per cycle (credited to each employee of this policy to give to colleagues)', 'type' => 'number', 'min' => 0, 'max' => 100000],
                    ['key' => 'max_per_award', 'label' => 'Employee reward limit: most points one award may carry (0 = no limit)', 'type' => 'number', 'min' => 0, 'max' => 100000],
                    ['key' => 'max_received_per_cycle', 'label' => 'Most points one employee may receive from colleagues in a cycle (0 = no limit)', 'type' => 'number', 'min' => 0, 'max' => 1000000],
                    ['key' => 'carry_forward', 'label' => 'Do unused points carry forward to the next cycle?', 'type' => 'yes_no', 'help' => 'No: points not given by the end of the cycle lapse and go back to the points budget.'],
                    ['key' => 'expiry_months', 'label' => 'Points received expire after (months; 0 = never)', 'type' => 'number', 'min' => 0, 'max' => 120, 'help' => 'The oldest points received go first; points redeemed count against them.'],
                ]],
                ['key' => 'eligibility_section', 'label' => 'Eligibility', 'type' => 'section', 'fields' => [
                    ['key' => 'eligibility_rules', 'label' => 'Do you want to give some employees a different number of points per cycle?', 'type' => 'yes_no'],
                    ['key' => 'eligibility', 'label' => 'Eligibility', 'type' => 'repeater', 'visible_when' => ['key' => 'eligibility_rules', 'in' => [true, 1, '1']],
                        'help' => 'The first row an employee matches replaces the points per cycle for them.', 'fields' => [
                            ['key' => 'criterion', 'label' => 'Eligibility', 'type' => 'select', 'options' => ['tenure_months' => 'Months of service', 'direct_reports' => 'Number of direct reports']],
                            ['key' => 'from', 'label' => 'From', 'type' => 'number', 'min' => 0, 'max' => 1000],
                            ['key' => 'to', 'label' => 'To (blank = no upper limit)', 'type' => 'number', 'min' => 0, 'max' => 1000],
                            ['key' => 'points', 'label' => 'Points per cycle', 'type' => 'number', 'min' => 0, 'max' => 100000],
                        ]],
                ]],
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
                AssetCategory::class, AssetModel::class, AssetSubtype::class,
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
            // HROne setup parity P5: the subject employee's current functional manager / HR business partner (reporting relationships).
            'functional_manager' => 'Functional manager of the subject employee', 'hrbp' => 'HR business partner of the subject employee',
        ],
        'approval_modes' => ['single' => 'Single approver', 'sequential' => 'One after another', 'parallel' => 'All must approve', 'majority' => 'Majority decides'],
        'escalation_actions' => ['remind' => 'Send a reminder', 'escalate_to_manager' => "Escalate to the assignee's manager", 'escalate_to_role' => 'Escalate to a role',
            // HROne setup parity P5 (turnaround time at the last level): a system decision, on the last approval step only.
            'auto_decide' => 'Decide automatically (last approval step only)'],
        'trigger_events' => [
            'manual' => 'Started manually',
            'employee.joined' => 'Employee joined', 'employee.probation' => 'Probation started', 'employee.confirmed' => 'Employee confirmed',
            'employee.notice_period' => 'Resignation / notice period', 'employee.exited' => 'Employee exited', 'employee.alumni' => 'Alumni created',
            'form.submitted' => 'Form submitted', 'configuration.change.proposed' => 'Configuration change proposed',
            'employee.pre_employee' => 'Pre-employee created (from RMS or manually)', 'employee.preboarding' => 'Preboarding started',
            'employee.joining_due' => 'Joining date approaching', 'employee.probation_ending' => 'Probation ending soon', 'employee.probation_overdue' => 'Probation end date passed',
            'employee.birthday' => 'Employee birthday (on the day)',
            'employee.work_anniversary' => 'Employee work anniversary (on the day)', 'employee.wedding_anniversary' => 'Employee wedding anniversary (on the day)',
            'employee.transfer_requested' => 'Transfer requested', 'employee.retirement_due' => 'Retirement due (separation policy)',
            'onboarding.completed' => 'Onboarding plan completed', 'bgv.completed' => 'Background verification completed', 'document.expiring' => 'Document expiring',
            'attendance.regularisation_requested' => 'Attendance regularisation requested', 'attendance.overtime_recorded' => 'Overtime recorded',
            'attendance.timeoffice_requested' => 'Time-office request raised (short leave, on duty, shift change, overtime, comp-off, restricted holiday)',
            'attendance.timeoffice_cancel_requested' => 'Cancellation of an approved time-office request', 'attendance.location_requested' => 'Check-in location requested',
            'leave.donation_requested' => 'Leave donation requested', 'leave.encashment_requested' => 'Leave encashment requested',
            'servicedesk.ticket.created' => 'Service desk ticket created', 'grievance.raised' => 'Grievance raised',
            'leave.requested' => 'Leave requested', 'leave.approved' => 'Leave approved', 'leave.cancelled' => 'Leave cancelled',
            'payroll.finalized' => 'Payroll finalized', 'payroll.paid' => 'Payroll paid',
            'performance.appraisal.finalized' => 'Appraisal finalized', 'performance.pip.opened' => 'Improvement plan opened', 'performance.promotion.recommended' => 'Promotion recommended',
            'learning.completed' => 'Learning completed', 'learning.overdue' => 'Learning overdue', 'asset.assigned' => 'Asset assigned', 'asset.returned' => 'Asset returned',
            'servicedesk.ticket.created' => 'Service desk ticket created', 'servicedesk.ticket.resolved' => 'Service desk ticket resolved', 'grievance.raised' => 'Grievance raised',
            'exit.initiated' => 'Exit initiated', 'exit.clearance.pending' => 'Clearance stage pending', 'exit.completed' => 'Exit completed', 'letter.issued' => 'Letter issued', 'alumni.request.created' => 'Alumni request',
            // HROne setup parity EX-C/EX-D: each part of a submitted expense report (context `workflow_group`: all,
            // category:<code>, group:<code>), the exceptional part, advance requests and their cancellations.
            'expense.report_submitted' => 'Expense report submitted (one run per workflow group)', 'expense.exceptional_submitted' => 'Exceptional expense submitted',
            'expense.advance_requested' => 'Expense advance requested', 'expense.advance_cancel_requested' => 'Cancellation of an approved expense advance',
            // HROne setup parity EX-F: travel requests (context `workflow_group` as for reports) and their cancellation. Owner
            // decision 2026-10-29: a trip spanning several workflow groups raises one per group (subject: the trip's part).
            'expense.travel_requested' => 'Travel request submitted (one run per workflow group)', 'expense.travel_cancel_requested' => 'Cancellation of an approved travel request',
            // HROne setup parity AS-B: asset requests that need approval (asset.request.workflow_for).
            'asset.requested' => 'Asset requested (new or replacement)', 'asset.return_requested' => 'Asset return requested',
            // HROne setup parity P2: an upload of a type under workflow approval (imports.approval_mode) is validated.
            'import.validated' => 'Data upload validated (waiting for approval)',
            // HROne setup parity HP-8d / HP-8f: a goal sheet submitted for approval (goal sheet policy: workflow), an
            // improvement plan waiting for approval and its outcome (evaluation) waiting for approval (PIP policy).
            'performance.goal_plan.submitted' => 'Goal plan submitted for approval', 'performance.pip.requested' => 'Improvement plan requested (waiting for approval)',
            'performance.pip.evaluation_requested' => 'Improvement plan evaluation requested (outcome waiting for approval)',
            // HROne setup parity P5: requests that run through an approval workflow decided by the workflow (RequestWorkflows).
            'payroll.loan_requested' => 'Loan or salary advance requested', 'payroll.claim_submitted' => 'Reimbursement claim submitted',
            'payroll.variable_pay_requested' => 'Variable pay requested', 'payroll.tax_declaration_submitted' => 'Investment declaration submitted to payroll',
            'compensation.change.submitted' => 'Compensation change submitted (waiting for review)', 'compensation.change.reviewed' => 'Compensation change reviewed (waiting for approval)',
            'exit.resignation_submitted' => 'Resignation submitted by the employee',
            // HROne parity MS-3: a self-service profile change (context `section`; a run deciding it approves or rejects it).
            'profile.change_requested' => 'Profile change requested by the employee (My profile)',
        ],
        'subject_types' => [
            Employee::class => 'Employee',
            FormSubmission::class => 'Form submission',
            ConfigurationChange::class => 'Configuration change',
            'App\\Domain\\Attendance\\Models\\TimeOfficeRequest' => 'Time-office request',
            'App\\Domain\\Attendance\\Models\\LocationRequest' => 'Check-in location request',
            'App\\Domain\\Leave\\Models\\LeaveDonation' => 'Leave donation',
            'App\\Domain\\Leave\\Models\\LeaveEncashment' => 'Leave encashment',
            'App\\Domain\\Expense\\Models\\ExpenseReportPart' => 'Expense report (part)',
            'App\\Domain\\Expense\\Models\\ExpenseAdvance' => 'Expense advance',
            'App\\Domain\\Expense\\Models\\TravelRequest' => 'Travel request',
            // Owner decision 2026-10-29: one run per workflow group of a trip spanning several groups.
            'App\\Domain\\Expense\\Models\\TravelRequestPart' => 'Travel request (part)',
            'App\\Domain\\Assets\\Models\\AssetRequest' => 'Asset request',
            'App\\Domain\\Employment\\Imports\\EmployeeImport' => 'Data upload',
            'App\\Domain\\Performance\\Models\\GoalPlan' => 'Goal plan',
            'App\\Domain\\Performance\\Models\\ImprovementPlan' => 'Improvement plan',
            // HROne setup parity P5: request types a workflow decides when it runs on them (RequestWorkflows).
            'App\\Domain\\Attendance\\Models\\AttendanceRegularisation' => 'Attendance regularisation',
            'App\\Domain\\Loans\\Models\\Loan' => 'Loan / salary advance',
            'App\\Domain\\Claims\\Models\\ReimbursementClaim' => 'Reimbursement claim',
            'App\\Domain\\Payroll\\Models\\VariablePayRequest' => 'Variable pay request',
            'App\\Domain\\Compensation\\Models\\CompensationChange' => 'Compensation change',
            'App\\Domain\\Letters\\Models\\Letter' => 'Letter',
            'App\\Domain\\Exit\\Models\\ExitCase' => 'Exit case (resignation)',
            'App\\Domain\\Compliance\\Models\\EmployeeTaxDeclaration' => 'Investment declaration',
            // HROne parity MS-3.
            'App\\Domain\\People\\Models\\ProfileChangeRequest' => 'Profile change',
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
        // Phase 14: external channels are delivered by a tenant-bound job after the business transaction commits.
        'async_channels' => ['email', 'sms', 'whatsapp', 'push'],
        // SaaS.8 recovery sweep (peopleos:notifications:recover, every five minutes). A delivery still `sending` after the
        // lease (well above the delivery job's 60 s timeout) lost its worker: it is marked failed with an unknown outcome,
        // never sent again automatically (it may have been received). An async delivery still `queued` after the requeue
        // window lost its job: it is dispatched again (the delivery claim prevents a double send).
        'sending_lease_minutes' => max(5, (int) env('PEOPLEOS_NOTIFICATIONS_SENDING_LEASE_MINUTES', 15)),
        'requeue_after_minutes' => max(15, (int) env('PEOPLEOS_NOTIFICATIONS_REQUEUE_AFTER_MINUTES', 60)),
        'audience_types' => [
            'subject' => 'The subject employee', 'manager' => "Subject's line manager", 'initiator' => 'Who started it',
            'role' => 'Everyone with a role', 'user' => 'A specific user', 'assignee' => 'Task assignee',
            // HROne parity RQ-2: the people the event names (approvers reminded, colleagues informed by the requester).
            'named' => 'People named on the event',
        ],
        'events' => [
            'employee.joined', 'employee.probation', 'employee.confirmed', 'employee.notice_period', 'employee.exited', 'employee.alumni',
            'form.submitted', 'form.reviewed', 'configuration.change.proposed', 'configuration.change.published',
            'workflow.task.assigned', 'workflow.task.reminder', 'workflow.task.escalated', 'workflow.completed',
            // HROne setup parity P3 (inbox actions) and P2 (import approval): references and labels only, never comment text.
            'workflow.task.reassigned', 'workflow.task.sent_back', 'workflow.task.resubmitted', 'workflow.task.commented',
            // HROne setup parity P4 / P5: out of office handed to a delegate; payroll requests and decisions; resignation approval.
            'workflow.task.delegated',
            'payroll.loan_requested', 'payroll.loan_approved', 'payroll.loan_rejected', 'payroll.loan_disbursed',
            'payroll.claim_submitted', 'payroll.claim_approved', 'payroll.claim_rejected', 'payroll.claim_paid',
            'payroll.variable_pay_requested', 'payroll.variable_pay_approved', 'payroll.variable_pay_rejected',
            'payroll.tax_declaration_submitted', 'payroll.tax_declaration_verified', 'payroll.tax_declaration_returned',
            'exit.resignation_submitted', 'exit.resignation_accepted',
            // HROne parity MS-3: self-service profile changes (references and the section only, never values).
            'profile.change_requested', 'profile.change_approved', 'profile.change_rejected',
            'import.validated', 'import.approved', 'import.rejected', 'import.completed',
            'employee.pre_employee', 'employee.preboarding', 'employee.joining_due', 'employee.probation_ending', 'employee.probation_overdue', 'employee.birthday', 'employee.work_anniversary', 'employee.wedding_anniversary',
            'employee.created', 'employee.transferred', 'employee.promoted', 'employee.manager_changed', 'employee.salary_changed', 'employee.department_changed', 'employee.designation_changed', 'employee.location_changed', 'employee.company_changed', 'employee.rehired', 'employee.transfer_requested', 'employee.retirement_due',
            'onboarding.started', 'onboarding.task.assigned', 'onboarding.completed', 'bgv.initiated', 'bgv.completed', 'document.uploaded', 'document.verified', 'document.rejected', 'document.expiring',
            'attendance.regularisation_requested', 'attendance.regularisation_approved', 'attendance.regularisation_rejected', 'attendance.overtime_recorded', 'attendance.exception',
            'attendance.punch_received', 'attendance.processed', 'attendance.status_changed', 'attendance.overtime_approved', 'attendance.overtime_rejected', 'attendance.regularisation_cancelled',
            'attendance.timeoffice_requested', 'attendance.timeoffice_approved', 'attendance.timeoffice_rejected', 'attendance.timeoffice_cancel_requested', 'attendance.timeoffice_cancelled',
            'attendance.location_requested', 'attendance.location_approved', 'attendance.location_rejected', 'attendance.penalty_applied', 'attendance.penalty_waived',
            'attendance.override_set', 'attendance.arrear_recorded', 'attendance.device_registered', 'attendance.punch_rejected', 'attendance.absence_alert',
            'leave.requested', 'leave.approved', 'leave.rejected', 'leave.cancelled', 'leave.cancel_requested', 'leave.cancellation_rejected', 'leave.balance_changed', 'leave.expired', 'leave.carried_forward', 'leave.encashment_requested', 'leave.encashment_approved', 'leave.balance_adjusted', 'leave.accrued',
            'leave.encashment_rejected', 'leave.encashment_cancelled', 'leave.donation_requested', 'leave.donation_approved', 'leave.donation_rejected', 'leave.comp_off_credited', 'leave.comp_off_expired',
            'payroll.calculated', 'payroll.approved', 'payroll.finalized', 'payroll.paid', 'payroll.payslip_generated', 'payroll.exception', 'payroll.run_created', 'payroll.adjusted',
            'performance.cycle.launched', 'performance.cycle.stage_changed', 'performance.review.assigned', 'performance.review.submitted', 'performance.appraisal.finalized',
            'performance.feedback.received', 'performance.feedback.requested', 'performance.goal.assigned', 'performance.pip.opened', 'performance.promotion.recommended',
            // HROne setup parity HP-8b: scheduled goal check-in prompts.
            'performance.reminder.goal_check_in',
            // HROne setup parity HP-8d / HP-8e / HP-8f: goal plan approval, system-mapped goal sheets, PIP approval.
            'performance.goal_plan.submitted', 'performance.goal_plan.approved', 'performance.goal_plan.rejected', 'performance.goal_sheet.assigned',
            'performance.pip.requested', 'performance.pip.approved', 'performance.pip.rejected', 'performance.pip.evaluation_requested',
            // HROne setup parity HP-8j: initiatives assigned or created, feedback asked and given, initiatives completed.
            'performance.initiative.assigned', 'performance.initiative.created', 'performance.initiative.feedback_requested', 'performance.initiative.feedback_given', 'performance.initiative.completed',
            // HROne setup parity HP-8h / HP-8i: 360 nominations and feedback (names and titles only, never answers); periodic reviews.
            'performance.feedback360.nominate', 'performance.feedback360.nominations_submitted', 'performance.feedback360.nominations_returned',
            'performance.feedback360.requested', 'performance.feedback360.results_ready', 'performance.period_review.opened',
            // HROne parity GO-1: a submitted review reopened by HR (owner decision PER-1 / D-GO1; off unless the setting is on).
            'performance.review.reopened',
            'learning.assigned', 'learning.due_soon', 'learning.overdue', 'learning.completed', 'learning.failed', 'learning.certificate_expiring', 'learning.session.registered',
            'asset.assigned', 'asset.returned', 'asset.transferred', 'asset.repair', 'asset.disposed', 'asset.lost', 'asset.acknowledgement_due',
            // HROne setup parity AS-B: asset requests (raised, to fulfil, decided, fulfilled, cancelled, overdue).
            'asset.requested', 'asset.return_requested', 'asset.request_to_fulfil', 'asset.request_approved', 'asset.request_rejected', 'asset.request_fulfilled', 'asset.request_cancelled',
            'servicedesk.ticket.created', 'servicedesk.ticket.assigned', 'servicedesk.ticket.commented', 'servicedesk.ticket.resolved', 'servicedesk.ticket.closed', 'servicedesk.ticket.escalated', 'servicedesk.ticket.reopened',
            // Phase 12 service requests (references and statuses only — never form data, comments or resolutions).
            'servicedesk.ticket.acknowledged', 'servicedesk.ticket.reassigned', 'servicedesk.ticket.waiting_for_employee', 'servicedesk.ticket.cancelled', 'servicedesk.ticket.approval_required',
            'servicedesk.ticket.ready_to_execute', 'servicedesk.ticket.sla_warning', 'servicedesk.reminder.waiting_for_employee', 'servicedesk.reminder.waiting_for_hr',
            // HROne setup parity HD-B: a resolver rule's intimation role hears that a request was assigned.
            'servicedesk.ticket.intimated',
            'kb.article.review_requested', 'kb.policy.acknowledged', 'kb.reminder.acknowledgement',
            // Phase 13 engagement and communication (identity-free for anonymous surveys; never answers or message bodies).
            'survey.published', 'survey.opened', 'survey.closed', 'survey.review_requested', 'campaign.launched', 'communication.review_requested', 'communication.published',
            'grievance.raised', 'grievance.assigned', 'grievance.updated', 'grievance.resolved', 'grievance.escalated',
            'kb.article.published', 'communication.published',
            'exit.initiated', 'exit.withdrawn', 'exit.clearance.pending', 'exit.clearance.cleared', 'exit.clearance.blocked', 'exit.settlement.calculated', 'exit.settlement.approved', 'exit.settlement.paid', 'exit.interview.submitted', 'exit.completed', 'exit.alumni_created',
            'letter.requested', 'letter.approved', 'letter.issued', 'alumni.request.created', 'alumni.request.handled',
            // HROne setup parity EX-C/EX-D (expense report, exceptional expense, advance request / cancellation / dispense).
            'expense.report_submitted', 'expense.exceptional_submitted', 'expense.report_approved', 'expense.report_partly_approved', 'expense.report_rejected',
            'expense.report_returned', 'expense.report_cancelled', 'expense.report_paid', 'expense.report_scheduled',
            'expense.advance_requested', 'expense.advance_approved', 'expense.advance_rejected', 'expense.advance_cancel_requested', 'expense.advance_cancelled',
            'expense.advance_dispensed', 'expense.advance_settled', 'expense.advance_recovered', 'expense.advance_written_off',
            // HROne setup parity EX-F (travel, travel booking, travel cancellation, live trip).
            'expense.travel_requested', 'expense.travel_approved', 'expense.travel_rejected', 'expense.travel_cancel_requested', 'expense.travel_cancelled',
            'expense.travel_booking_requested', 'expense.travel_booked',
            // HROne setup parity BP-9: reminders to claim reimbursements, planned variable pay due, a guarantor asked to accept.
            'payroll.claim_reminder', 'payroll.variable_pay_due', 'payroll.loan_guarantor_requested',
            // HROne parity RQ-2: a requester's reminder to the approvers; colleagues the requester chose to inform.
            'request.reminder', 'request.intimated',
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
        'item_types' => ['task' => 'Checklist item', 'document' => 'Collect a document', 'form' => 'Fill a form', 'acknowledgement' => 'Policy acknowledgement',
            // HROne setup parity P13: complete a section of the profile (checked on completion) and answer a feedback template.
            'profile_section' => 'Complete a profile section', 'feedback' => 'Give feedback'],
        // HROne setup parity 08.2: an induction programme is an onboarding template of kind induction (its own plan, next to the
        // onboarding checklist). Plans of kind task_campaign carry items issued to current employees.
        'kinds' => ['onboarding' => 'Onboarding checklist', 'induction' => 'Induction programme'],
        'plan_kinds' => ['onboarding' => 'Onboarding', 'induction' => 'Induction', 'task_campaign' => 'Issued to current employees'],
        // Profile sections a profile_section item checks before it can be completed (Onboarding\Support\ProfileSections).
        'profile_sections' => ['personal' => 'Personal details', 'address' => 'Address', 'family' => 'Family', 'emergency_contact' => 'Emergency contact', 'bank' => 'Bank details',
            'statutory' => 'Statutory details (PAN)', 'education' => 'Education', 'previous_employment' => 'Previous employment'],
    ],
    // HROne setup parity P13 (Forms & templates → Feedback templates): the processes a feedback template serves, who answers,
    // and whose permission reads the answers. Question types are the survey ones (peopleos.engagement.question_types).
    'feedback' => [
        'modules' => ['workforce' => 'Workforce', 'performance' => 'Performance'],
        'processes' => [
            'exit_interview' => ['label' => 'Exit interview by the employee', 'module' => 'workforce', 'read' => 'exit.interview'],
            'exit_interview_manager' => ['label' => 'Exit interview by the manager', 'module' => 'workforce', 'read' => 'exit.interview'],
            'exit_interview_hr' => ['label' => 'Exit interview by HR', 'module' => 'workforce', 'read' => 'exit.interview'],
            'confirmation' => ['label' => 'Confirmation feedback by the manager', 'module' => 'workforce', 'read' => 'employee.lifecycle'],
            'probation' => ['label' => 'Probation self-review by the employee', 'module' => 'workforce', 'read' => 'employee.lifecycle'],
            'induction' => ['label' => 'Induction evaluation', 'module' => 'workforce', 'read' => 'onboarding.manage'],
            // HROne setup parity HP-8e: the feedback form of a goal template, answered by the manager at the manager review.
            'goal_review' => ['label' => 'Goal sheet feedback by the manager', 'module' => 'performance', 'read' => 'performance.view'],
            // HROne setup parity HP-8h / HP-8i: the 360 feedback forms (one per source, chosen on the 360 policy; answered in
            // 360 feedback rounds, never as feedback requests) and the feedback form mapped on the performance review
            // (performance policy), asked of the manager with the manager review.
            'feedback_360' => ['label' => '360 feedback', 'module' => 'performance', 'read' => 'performance.feedback_360'],
            'performance_review' => ['label' => 'Performance review feedback by the manager', 'module' => 'performance', 'read' => 'performance.view'],
        ],
        'respondents' => ['employee' => 'The employee', 'manager' => 'The manager', 'hr' => 'HR', 'owner' => 'The task owner'],
    ],
    'documents' => [
        'categories' => ['identity' => 'Identity', 'education' => 'Education', 'employment' => 'Previous employment', 'tax' => 'Tax', 'statutory' => 'Statutory', 'company' => 'Company', 'training' => 'Training', 'medical' => 'Medical', 'exit' => 'Exit', 'other' => 'Other'],
        'disk' => env('PEOPLEOS_DOCUMENTS_DISK', 'local'),
        'max_kb' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
        'sensitive_categories' => ['identity', 'tax', 'statutory', 'medical'],
        // HROne setup parity (Document → map to a profile field): the profile fields a document type can support. When a
        // type is linked to one and marked "required with the field", the field cannot be entered or changed while the
        // employee has no current (pending or verified) document of that type.
        'linked_fields' => ['statutory.pan' => 'PAN', 'statutory.aadhaar' => 'Aadhaar', 'statutory.uan' => 'UAN', 'bank_account' => 'Bank account', 'address' => 'Address'],
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
        /*
        | SaaS.2: requests per minute for one API key (the limiter buckets by the key's public prefix, or by
        | IP when no key is presented). 120 is the value that always applied while this key was undefined;
        | it is a technical default, not a commercial quota (per-plan API quotas are future entitlement work).
        */
        'rate_limit_per_minute' => max(1, (int) env('PEOPLEOS_API_RATE_LIMIT_PER_MINUTE', 120)),
        /*
        | SaaS.2: how long an Idempotency-Key is remembered. A retry inside the window replays the stored
        | response; after it, the key may be used again, and retention:purge deletes the expired rows.
        */
        'idempotency_ttl_hours' => max(1, (int) env('PEOPLEOS_API_IDEMPOTENCY_TTL_HOURS', 24)),
        'scopes' => [
            'rms.write' => 'Create pre-employees from recruitment', 'rms.read' => 'Read pre-employee status', 'bgv.write' => 'Post background verification results', 'attendance.write' => 'Push attendance punches (devices or any source) and raise regularisations',
            'employees.read' => 'Read employees and positions', 'employees.write' => 'Create employees and change lifecycle state', 'employees.sensitive.read' => 'Read sensitive employee fields (personal contacts, statutory ids, bank) — audited', 'organisation.read' => 'Read organisation reference data by code', 'attendance.read' => 'Read attendance records, exceptions, regularisations, shifts and schedules', 'leave.read' => 'Read leave types, balances, transactions, requests and the leave calendar', 'leave.write' => 'Submit and cancel leave requests on behalf of employees', 'payroll.read' => 'Read payroll runs and payslips (sensitive)', 'compliance.read' => 'Read establishments, statutory registrations (masked), rule versions, statutory returns, entries (masked) and reconciliations',
            'documents.read' => 'Read document metadata', 'assets.read' => 'Read the asset register', 'performance.read' => 'Read performance cycles, goals, goal progress, reviews (final outcomes only), check-in / one-on-one / feedback / PIP metadata, competencies and suppressed analytics', 'performance.write' => 'Record goal progress (idempotent)', 'learning.read' => 'Read the learning catalogue, paths, programs, enrolments, assignments, completions, certificates (no codes or documents), skills, finalized assessment levels (no comments), development plan metadata and suppressed analytics', 'learning.write' => 'Enrol employees and record learning progress (idempotent)', 'learning.costs' => 'Include learning costs in learning API responses', 'career.read' => 'Read career architecture, career profiles (shared fields only), goals, skill gaps and mobility interests', 'talent.read' => 'Read talent pools and memberships, talent reviews (decisions only) and suppressed talent analytics — never confidential notes or assessments', 'succession.read' => 'Read critical positions, succession plans, successors and readiness — never confidential notes or deliberations', 'positions.read' => 'Read positions, their effective-dated versions, occupancy (employee codes) and vacancies', 'workforce.read' => 'Read workforce plans, scenarios, headcount, vacancies, snapshots and analytics (no costs)', 'workforce.costs' => 'Include planned, budget and actual workforce costs in workforce API responses', 'workflows.read' => 'Read workflow instances and tasks',
            'reports.run' => 'Run saved reports', 'scim' => 'SCIM 2.0 user provisioning', 'webhooks.read' => 'Read webhook deliveries',
            // Phase 11: compensation definitions; employee amounts need the second scope (audited reads).
            // Phase 13: engagement (survey definitions, own participation for identified surveys, suppressed aggregates) and communications (published content, preferences).
            'integrations.write' => 'Post signed events to an integration registered in PeopleOS (Integration Hub inbound endpoint)',
            'integrations.read' => 'Look up an integration\'s external references and the status of its inbound events',
            'engagement.read' => 'Read survey definitions, an employee\'s surveys, aggregate participation and overall privacy-suppressed results — never responses, comments, respondents or anyone\'s participation in anonymous or confidential surveys',
            'communications.read' => 'Read published communications with aggregate delivery counts and an employee\'s communication preferences — never audience criteria, recipient lists or delivery records',
            // Phase 12: the HR service desk read API — never internal or restricted notes, confidential cases, sensitive form fields or attachments.
            'servicedesk.read' => 'Read the HR service catalogue, service requests (status and employee-visible fields only), employee-visible comments, published knowledge and request tasks',
            'compensation.read' => 'Read compensation structures, grades, pay ranges and cycles (read-only)', 'compensation.sensitive' => 'With compensation.read: read employee compensation and history by employee code (audited)',
        ],
    ],

    /*
    | Payroll (§29–§31). Component vocabulary and the default (editable) component catalogue.
    | Statutory components are created by the compliance pack and locked for tenants (§32).
    */
    'payroll' => [
        // Phase 5 Part F: payroll may not be finalized on a statutory rule version that is not
        // VERIFIED (and intact). On by default everywhere; switch off only for development or
        // tests with PEOPLEOS_ENFORCE_VERIFIED_RULES=false.
        'enforce_verified_rules' => (bool) env('PEOPLEOS_ENFORCE_VERIFIED_RULES', true),
        'component_types' => ['earning' => 'Earning', 'deduction' => 'Deduction', 'employer_contribution' => 'Employer contribution', 'reimbursement' => 'Reimbursement (non-taxable)'],
        // HROne setup parity BP-2: `input` is a per-employee value captured on the compensation (like a fixed amount) that
        // formulas read by its lowercase code (HROne's CTC formula tags); it is never paid or printed.
        'calculation_methods' => ['fixed' => 'Fixed amount from the salary assignment', 'formula' => 'Formula', 'input' => 'Employee input used by formulas, never paid', 'statutory' => 'Statutory (compliance pack)'],
        'classifications' => [
            'basic' => 'Basic', 'da' => 'Dearness allowance', 'hra' => 'House rent allowance', 'allowance' => 'Allowance', 'bonus' => 'Bonus',
            'incentive' => 'Incentive / commission', 'reimbursement' => 'Reimbursement', 'pf_employee' => 'PF (employee)', 'pf_employer' => 'PF (employer)',
            'esi_employee' => 'ESI (employee)', 'esi_employer' => 'ESI (employer)', 'pt' => 'Professional tax', 'lwf_employee' => 'LWF (employee)',
            'lwf_employer' => 'LWF (employer)', 'tds' => 'Income tax (TDS)', 'gratuity' => 'Gratuity provision', 'other_deduction' => 'Other deduction', 'other' => 'Other',
            // HROne parity PY-16: NPS through payroll; TDS counts the employee's share with 80C and the employer's under 80CCD(2).
            'nps_employee' => 'NPS (employee)', 'nps_employer' => 'NPS (employer)',
        ],
        'run_statuses' => ['draft' => 'Draft', 'calculated' => 'Calculated', 'validated' => 'Validated', 'approved' => 'Approved', 'finalized' => 'Finalized', 'paid' => 'Paid'],
        'exception_types' => ['no_salary' => 'No salary assignment', 'no_bank' => 'No primary bank account', 'no_pan' => 'PAN missing (TDS applies)', 'negative_net' => 'Negative net pay', 'attendance_missing' => 'Attendance not processed', 'no_structure' => 'Salary structure has no components', 'formula_error' => 'Formula error', 'statutory_override' => 'Statutory amount overridden'],
        // Advisory structure checks shown in the breakup preview (never blocking). wage_share: Code on
        // Wages, 2019 s.2(y): allowances above this share of total remuneration count as wages for PF,
        // gratuity and bonus. Verify against current notifications; null switches the check off.
        'guardrails' => [
            'wage_share' => ['min_percent' => 50, 'classifications' => ['basic', 'da']],
        ],
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
        'stages' => ['goal_setting' => 'Goal setting', 'self_review' => 'Self review', 'manager_review' => 'Manager review', 'approver_review' => 'Approver review', 'peer_review' => 'Peer / 360 review', 'calibration' => 'Calibration', 'final' => 'Final rating'],
        'goal_levels' => ['company' => 'Company goal', 'business' => 'Business goal', 'department' => 'Department goal', 'team' => 'Team goal', 'employee' => 'Employee goal'],
        'goal_types' => ['goal' => 'Goal', 'okr' => 'Objective (OKR)', 'kra' => 'KRA', 'kpi' => 'KPI'],
        'measure_types' => ['percentage' => 'Percentage', 'numeric' => 'Number', 'currency' => 'Amount', 'boolean' => 'Done / not done', 'milestone' => 'Milestones'],
        // HP-8d: pending_approval = on a goal sheet submitted for approval (goal sheet policy); never chosen by hand.
        'goal_statuses' => ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
        // HP-8d: approver = the third review level (the manager's own line manager) of cycles with an "Approver review" stage.
        'review_types' => ['self' => 'Self', 'manager' => 'Manager', 'peer' => 'Peer', 'upward' => 'Upward (direct report)', 'skip_level' => 'Skip level', 'external' => 'External stakeholder', 'approver' => 'Approver (next level)'],
        // HP-8d: goal sheets submitted for approval (goal_plans).
        'goal_plan_statuses' => ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Sent back'],
        'appraisal_statuses' => ['pending' => 'Pending', 'in_review' => 'In review', 'calibration' => 'Calibration', 'finalized' => 'Finalized', 'acknowledged' => 'Acknowledged'],
        'feedback_types' => ['praise' => 'Praise', 'constructive' => 'Constructive', 'request' => 'Feedback request'],
        'feedback_visibility' => ['private' => 'Only the recipient', 'manager' => 'Recipient and their manager', 'public' => 'Everyone in the tenant'],
        'competency_categories' => ['core' => 'Core', 'functional' => 'Functional', 'leadership' => 'Leadership', 'behavioural' => 'Behavioural'],
        // 'withdrawn' is kept only for plans closed before Phase 7; new plans use 'cancelled'.
        // HP-8f: pending_approval = opened under a PIP policy that needs approval; pending_outcome = outcome waiting for approval.
        'pip_statuses' => ['draft' => 'Draft', 'pending_approval' => 'Waiting for approval', 'active' => 'Active', 'pending_outcome' => 'Outcome waiting for approval', 'extended' => 'Extended', 'completed' => 'Successfully completed', 'unsuccessful' => 'Unsuccessful', 'closed' => 'Closed', 'cancelled' => 'Cancelled', 'withdrawn' => 'Withdrawn'],
        'default_weights' => ['goals' => 70, 'competencies' => 30],
        // Phase 7: which reporting relationships make someone a performance manager of an employee
        // (goals, reviews, check-ins, one-on-ones, PIPs). Mentors, buddies and project leads are not.
        'manager_relationship_types' => array_values(array_filter(explode(',', (string) env('PEOPLEOS_PERFORMANCE_MANAGER_TYPES', 'line,functional,dotted,secondary,hrbp')))),
        'check_in_cadences' => ['weekly' => 'Weekly', 'biweekly' => 'Every two weeks', 'monthly' => 'Monthly', 'custom' => 'Custom'],
        'check_in_statuses' => ['draft' => 'Draft', 'submitted' => 'Submitted', 'reviewed' => 'Reviewed'],
        'goal_sources' => ['manual' => 'Manual', 'check_in' => 'Check-in', 'api' => 'API', 'import' => 'Import', 'system' => 'System'],
        'calibration_statuses' => ['open' => 'Open', 'closed' => 'Closed'],
        'development_need_priorities' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
        'development_need_statuses' => ['open' => 'Open', 'in_progress' => 'In progress', 'addressed' => 'Addressed', 'closed' => 'Closed'],
        'checkpoint_statuses' => ['pending' => 'Pending', 'met' => 'Met', 'partially_met' => 'Partially met', 'not_met' => 'Not met'],
        // HP-8f: checkpoints a PIP policy's start / close checklist adds, next to those added by hand.
        'checkpoint_kinds' => ['checkpoint' => 'Checkpoint', 'start' => 'Start checklist', 'close' => 'Close checklist'],
        // HROne setup parity HP-8h (360 policy): sources of feedback, round and nomination states, and the organisation
        // attributes that define peers and subordinates ("Peers are employees having [criterion] as the assessed employee").
        'feedback_360' => [
            'relations' => ['manager' => 'Manager', 'functional_manager' => 'Functional manager', 'peer' => 'Peer', 'subordinate' => 'Subordinate', 'client' => 'Client'],
            'round_statuses' => ['open' => 'Open', 'closed' => 'Closed', 'cancelled' => 'Cancelled'],
            'nomination_statuses' => ['draft' => 'Nominated', 'requested' => 'Asked', 'submitted' => 'Given', 'declined' => 'Chose not to participate', 'cancelled' => 'Cancelled'],
            'participant_statuses' => ['draft' => 'To nominate', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'returned' => 'Sent back', 'not_needed' => 'Nothing to nominate'],
            'match_dimensions' => ['company_id' => 'Company', 'location_id' => 'Location', 'business_unit_id' => 'Business unit', 'division_id' => 'Division', 'department_id' => 'Department', 'team_id' => 'Team', 'designation_id' => 'Designation', 'level_id' => 'Level', 'grade_id' => 'Grade'],
            'frequency_months' => ['yearly' => 12, 'half_yearly' => 6, 'quarterly' => 3, 'monthly' => 1],
            // Defaults when the policy leaves an answer blank.
            'default_window_days' => 14,
            'default_min_responses' => 3,
        ],
        // HROne setup parity HP-8i (performance policy): periodic review states and the review-chain visibility options.
        'period_review_statuses' => ['scheduled' => 'Scheduled', 'open' => 'Open', 'submitted' => 'Submitted', 'missed' => 'Missed'],
        // Analytics never shows a group smaller than this (privacy of individual ratings).
        'analytics_min_group' => (int) env('PEOPLEOS_PERFORMANCE_MIN_GROUP', 5),
        // Reminder lead time before a stage window or check-in closes.
        'reminder_days_before' => 3,
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
            // HROne setup parity HP-8a: the standard performance masters, as HROne lists them (system rows: they can be
            // deactivated, not deleted). Units: the amount unit takes the organisation's base currency as its code.
            'lookups' => [
                'perspective' => [
                    ['code' => 'CUSTOMER', 'name' => 'Customer'],
                    ['code' => 'EXTERNAL_PROCESS', 'name' => 'External Process'],
                    ['code' => 'FINANCIAL', 'name' => 'Financial'],
                    ['code' => 'INTERNAL_PROCESS', 'name' => 'Internal process'],
                    ['code' => 'LEARNING_GROWTH', 'name' => 'Learning and growth'],
                ],
                'initiative_type' => [
                    ['code' => 'BUDDY', 'name' => 'BUDDY'],
                    ['code' => 'INDUCTION', 'name' => 'INDUCTION'],
                    ['code' => 'INITIATIVE', 'name' => 'INITIATIVE'],
                    ['code' => 'PIP', 'name' => 'PIP'],
                    ['code' => 'TNI', 'name' => 'TNI', 'description' => 'Training need identification'],
                ],
                'pip_reason' => [
                    ['code' => 'ABSENTEEISM', 'name' => 'Absenteeism'],
                    ['code' => 'ATTITUDE', 'name' => 'Inappropriate attitude'],
                    ['code' => 'INSUBORDINATION', 'name' => 'Insubordination'],
                    ['code' => 'PUNCTUALITY', 'name' => 'Punctuality'],
                    ['code' => 'PERFORMANCE', 'name' => 'Unsatisfactory performance'],
                ],
            ],
            'units' => [
                ['code' => '%', 'name' => 'Percent', 'measure_type' => 'percentage', 'default_target' => 100],
                ['code' => '{currency}', 'name' => 'Amount', 'measure_type' => 'currency', 'default_target' => null],
                ['code' => 'RATING', 'name' => 'Rating', 'measure_type' => 'numeric', 'default_target' => null],
                ['code' => 'UNITS', 'name' => 'Units', 'measure_type' => 'numeric', 'default_target' => null],
            ],
        ],
        // HP-8a: the kinds of performance lookups (Performance → Performance masters).
        'lookup_kinds' => ['perspective' => 'Perspective', 'initiative_type' => 'Initiative type', 'pip_reason' => 'PIP reason'],
        // HP-8c: how a KPI rating formula compares achievement and target (reverse KPIs always use target ÷ achievement).
        'kpi_formula_bases' => ['achievement_over_target' => 'Achievement ÷ target', 'target_over_achievement' => 'Target ÷ achievement'],
        // HP-8g: what checking the rating distribution against its targets does when a cycle is closed (and the warning
        // shown when an appraisal is finalized); off = no check (the default: no forced distribution).
        'distribution_checks' => ['off' => 'Off (no check)', 'warn' => 'Warn (show the gap; the cycle still closes)', 'block' => 'Block closing the cycle outside the tolerance'],
        // HP-8g: the 9-box. Performance = the appraisal's rating that stands (final, else calibrated, else computed) on the
        // cycle's rating scale; potential = the source chosen in performance.nine_box.potential_source. Boxes are keyed
        // potential band _ performance band.
        'nine_box' => [
            'potential_sources' => ['competencies' => 'Competency rating of the appraisal', 'talent' => 'Potential of the latest final talent assessment (talent data: people with talent.view only)'],
            'bands' => ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High'],
            'boxes' => [
                'high_low' => 'Enigma', 'high_moderate' => 'Growth employee', 'high_high' => 'Future leader',
                'moderate_low' => 'Dilemma', 'moderate_moderate' => 'Core employee', 'moderate_high' => 'High-impact performer',
                'low_low' => 'Under-performer', 'low_moderate' => 'Effective employee', 'low_high' => 'Trusted professional',
            ],
        ],
        // HP-8j: initiatives and the feedback asked about them.
        'initiative_statuses' => ['planned' => 'Planned', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
        'initiative_feedback_statuses' => ['requested' => 'Requested', 'given' => 'Given', 'ignored' => 'Ignored'],
    ],

    /*
    | Enterprise & international (§87, §96, §109, §110).
    */
    /*
    | Phase 14 Integration Hub (ADR-0011, ADR-0012, ADR-0014). Generic: vendor adapters are handlers
    | registered here, each applying its events through the owning domain's actions.
    */
    'integration' => [
        'kinds' => ['recruitment' => 'Recruitment / ATS', 'payroll_provider' => 'Payroll provider', 'attendance' => 'Attendance devices', 'finance' => 'Finance / ERP', 'identity' => 'Identity provider', 'benefits' => 'Benefits provider', 'learning' => 'Learning provider', 'bgv' => 'Background verification', 'hr_system' => 'External HR system', 'other' => 'Other'],
        // event type => handler (App\Domain\Integration\Contracts\InboundEventHandler)
        'handlers' => [
            'reference.link' => LinkReferenceHandler::class,
            'reference.retire' => RetireReferenceHandler::class,
            // Production readiness closure: background-verification results (signed, idempotent; `bgv` integrations only).
            'bgv.results' => BgvResultsHandler::class,
        ],
        'max_attempts' => (int) env('PEOPLEOS_INTEGRATION_MAX_ATTEMPTS', 5),
        // Payload bodies of finished events are purged after this many days; metadata stays.
        'payload_retention_days' => (int) env('PEOPLEOS_INTEGRATION_PAYLOAD_DAYS', 7),
    ],

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
            'payroll.calculated', 'payroll.approved', 'payroll.finalized', 'payroll.paid', 'performance.appraisal.finalized', 'performance.cycle.published', 'performance.goal.created', 'performance.goal.progress_updated', 'performance.review.submitted', 'performance.check_in.submitted', 'performance.feedback.received', 'learning.completed', 'learning.enrolment.requested', 'learning.enrolment.approved', 'learning.started', 'learning.certificate.issued', 'learning.certificate_expired', 'skill.assessed', 'development.plan.created', 'development.plan.completed',
            'asset.assigned', 'asset.returned', 'servicedesk.ticket.created', 'servicedesk.ticket.resolved', 'grievance.raised', 'exit.initiated', 'exit.completed',
            // Phase 12: request lifecycle facts (number, service code, status, priority — never form data, comments or resolutions).
            'servicedesk.ticket.assigned', 'servicedesk.ticket.closed', 'servicedesk.ticket.cancelled', 'servicedesk.ticket.escalated', 'kb.article.published', 'kb.policy.acknowledged',
            // Phase 13: lifecycle facts only (codes, versions, counts) — never respondents, answers, audience members or message bodies.
            'survey.published', 'survey.opened', 'survey.closed', 'campaign.launched', 'communication.published',
            'letter.issued', 'workflow.completed', 'document.expiring',
            'compliance.establishment_verified', 'compliance.return_reconciled', 'compliance.return_approved', 'compliance.return_exported', 'compliance.return_filed',
            // Phase 9: architecture-level events only. Candidacy, pool membership, readiness and talent
            // assessments are confidential and are never published as webhooks.
            'career.path.published', 'succession.critical_position.created', 'talent.review.completed',
            // Phase 10: capacity facts an integration (e.g. RecruitmentEdge, outside PeopleOS) may consume. No costs, no people.
            'workforce.position.approved', 'workforce.position.opened', 'workforce.position.vacated', 'workforce.position.frozen', 'workforce.position.abolished', 'workforce.position.closed',
            // Phase 11: compensation lifecycle facts (reference, type, dates, employee code — never amounts).
            'compensation.change.approved', 'compensation.change.scheduled', 'compensation.change.effective', 'compensation.change.cancelled', 'compensation.structure.approved', 'compensation.cycle.executed',
        ],
        // Context keys never sent in webhook payloads (names of feedback authors, free text).
        'webhook_redacted_context' => ['from', 'note', 'reason', 'outcome', 'summary', 'comment'],
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
            'workforce' => ['label' => 'Workforce Analyst', 'permission' => 'ai.workforce', 'feature' => 'ai.workforce_intelligence', 'description' => 'Trends, cost, skills, capacity — aggregate facts, never individual predictions'],
        ],
        'payroll_audit' => ['net_change_pct' => 30, 'deduction_share_pct' => 60, 'lop_days' => 10, 'tds_jump_pct' => 100, 'salary_revision_pct' => 50],
        // Phase 14: the per-employee attrition-risk score was retired (no employee scoring or prediction).
        // AI data boundary: what may be sent to an external provider (ADR-0016). Prohibited data never
        // leaves PeopleOS and is also never stored in the AI log; restricted data leaves only when the
        // tenant setting ai.external_data_policy is "restricted"; everything else is allowed.
        'data_policy' => [
            'policies' => ['none' => 'Never send anything to an external AI provider', 'allowed' => 'Send allowed facts only (default)', 'restricted' => 'Also send restricted personal data (never prohibited data)'],
            'prohibited_keys' => ['password', 'passcode', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'encryption', 'credential', 'bearer', 'signature', 'otp', 'totp', 'mfa', 'recovery_code'],
            'prohibited_values' => ['/\\b(?:pk|sk|whsec|sk-ant)[_-][A-Za-z0-9_\\-]{8,}/', '/\\beyJ[A-Za-z0-9_\\-]{10,}\\.[A-Za-z0-9_\\-]{10,}\\.[A-Za-z0-9_\\-]{5,}/', '/\\bbase64:[A-Za-z0-9+\\/=]{20,}/', '/-----BEGIN [A-Z ]*PRIVATE KEY-----/', '/(?i)\\b(?:password|passcode|pwd)\\s*[:=]\\s*\\S+/'],
            'restricted_keys' => ['bank', 'account_number', 'ifsc', 'pan', 'uan', 'aadhaar', 'esic', 'passport', 'tax', 'salary', 'ctc', 'amount', 'gross', 'net_pay', 'cost', 'compensation', 'rating', 'grievance', 'medical', 'health', 'date_of_birth', 'dob', 'personal_email', 'phone', 'address', 'note'],
            'restricted_values' => ['/\\b[A-Z]{5}[0-9]{4}[A-Z]\\b/', '/\\b[0-9]{4}\\s?[0-9]{4}\\s?[0-9]{4}\\b/', '/\\b[A-Z]{4}0[A-Z0-9]{6}\\b/', '/\\b[0-9]{9,18}\\b/', '/[A-Za-z0-9._%+\\-]+@[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}/'],
        ],
        'rate_limit_per_minute' => (int) env('PEOPLEOS_AI_RATE_LIMIT', 20),
        // "What do you want to configure?" (§103): every configuration screen, with the words people use for it and the
        // module card it sits on in the finder (App\Filament\Pages\ConfigurationFinder::MODULES).
        'config_search' => [
            ['keywords' => 'department team unit division organisation structure', 'label' => 'Organisation designer', 'url' => '/admin/organisation-designer', 'module' => 'organisation'],
            ['keywords' => 'company entity legal', 'label' => 'Companies', 'url' => '/admin/companies', 'module' => 'organisation'],
            ['keywords' => 'legal entity registered company incorporation', 'label' => 'Legal entities', 'url' => '/admin/legal-entities', 'module' => 'organisation'],
            ['keywords' => 'establishment branch factory site', 'label' => 'Establishments', 'url' => '/admin/establishments', 'module' => 'organisation'],
            ['keywords' => 'location office city', 'label' => 'Locations', 'url' => '/admin/locations', 'module' => 'organisation'],
            ['keywords' => 'department function unit', 'label' => 'Departments', 'url' => '/admin/departments', 'module' => 'organisation'],
            ['keywords' => 'business unit vertical', 'label' => 'Business units', 'url' => '/admin/business-units', 'module' => 'organisation'],
            ['keywords' => 'division', 'label' => 'Divisions', 'url' => '/admin/divisions', 'module' => 'organisation'],
            ['keywords' => 'team squad pod', 'label' => 'Teams', 'url' => '/admin/teams', 'module' => 'organisation'],
            ['keywords' => 'cost centre center accounting', 'label' => 'Cost centres', 'url' => '/admin/cost-centres', 'module' => 'organisation'],
            ['keywords' => 'profit centre center accounting', 'label' => 'Profit centres', 'url' => '/admin/profit-centres', 'module' => 'organisation'],
            ['keywords' => 'designation title role job', 'label' => 'Designations', 'url' => '/admin/designations', 'module' => 'people'],
            ['keywords' => 'level band', 'label' => 'Levels', 'url' => '/admin/levels', 'module' => 'people'],
            ['keywords' => 'grade pay band', 'label' => 'Grades', 'url' => '/admin/grades', 'module' => 'people'],
            ['keywords' => 'job family function', 'label' => 'Job families', 'url' => '/admin/job-families', 'module' => 'people'],
            ['keywords' => 'employee category staff worker', 'label' => 'Employee categories', 'url' => '/admin/employee-categories', 'module' => 'people'],
            ['keywords' => 'employment type permanent contract intern consultant', 'label' => 'Employment types', 'url' => '/admin/employment-types', 'module' => 'people'],
            ['keywords' => 'employee code id number series prefix suffix sequence numbering', 'label' => 'Employee code series', 'url' => '/admin/employee-code-series', 'module' => 'people'],
            ['keywords' => 'work mode remote hybrid office', 'label' => 'Work modes', 'url' => '/admin/work-modes', 'module' => 'people'],
            ['keywords' => 'document type', 'label' => 'Document types', 'url' => '/admin/document-types', 'module' => 'people'],
            ['keywords' => 'custom field', 'label' => 'Custom fields', 'url' => '/admin/custom-fields', 'module' => 'people'],
            ['keywords' => 'form builder', 'label' => 'Forms', 'url' => '/admin/forms', 'module' => 'people'],
            ['keywords' => 'onboarding template checklist', 'label' => 'Onboarding templates', 'url' => '/admin/onboarding-templates', 'module' => 'lifecycle'],
            ['keywords' => 'letter template experience relieving', 'label' => 'Letter templates', 'url' => '/admin/letter-templates', 'module' => 'lifecycle'],
            ['keywords' => 'letter signature signatory signing authority', 'label' => 'Signing authorities', 'url' => '/admin/letter-signatories', 'module' => 'lifecycle'],
            ['keywords' => 'working hours shift timing schedule roster', 'label' => 'Shifts', 'url' => '/admin/shifts', 'module' => 'time'],
            ['keywords' => 'working hours schedule week pattern roster', 'label' => 'Work schedules', 'url' => '/admin/work-schedules', 'module' => 'time'],
            ['keywords' => 'holiday calendar', 'label' => 'Holiday calendars', 'url' => '/admin/holiday-calendars', 'module' => 'time'],
            ['keywords' => 'leave type earned casual sick', 'label' => 'Leave types', 'url' => '/admin/leave-types', 'module' => 'time'],
            ['keywords' => 'overtime attendance rules grace late policy', 'label' => 'Policies (attendance, overtime, leave)', 'url' => '/admin/policies', 'module' => 'time'],
            ['keywords' => 'attendance device biometric punch machine', 'label' => 'Attendance devices', 'url' => '/admin/attendance-devices', 'module' => 'time'],
            ['keywords' => 'salary component allowance basic hra', 'label' => 'Salary components', 'url' => '/admin/salary-components', 'module' => 'pay'],
            ['keywords' => 'salary structure ctc', 'label' => 'Salary structures', 'url' => '/admin/salary-structures', 'module' => 'pay'],
            ['keywords' => 'pay range salary band minimum maximum midpoint', 'label' => 'Pay ranges', 'url' => '/admin/compensation-ranges', 'module' => 'pay'],
            ['keywords' => 'pay cycle payroll period attendance month cut-off cutoff 25th 26th start day', 'label' => 'Pay cycle', 'url' => '/admin/pay-cycle', 'module' => 'pay'],
            ['keywords' => 'pf esi pt lwf statutory establishment', 'label' => 'Statutory profiles', 'url' => '/admin/establishment-statutory-profiles', 'module' => 'compliance'],
            ['keywords' => 'statutory registration pf esi pt number code', 'label' => 'Statutory registrations', 'url' => '/admin/statutory-registrations', 'module' => 'compliance'],
            ['keywords' => 'statutory rules compliance rates', 'label' => 'Statutory rules', 'url' => '/admin/compliance-rules', 'module' => 'compliance'],
            ['keywords' => 'tds tan deductor income tax', 'label' => 'TDS deductor profiles', 'url' => '/admin/tds-profiles', 'module' => 'compliance'],
            ['keywords' => 'export layout file format bank return', 'label' => 'Export layouts', 'url' => '/admin/export-layouts', 'module' => 'compliance'],
            ['keywords' => 'pf esi pt tds statutory registration', 'label' => 'Company statutory profiles (legacy)', 'url' => '/admin/statutory-profiles', 'module' => 'compliance'],
            ['keywords' => 'performance cycle appraisal review', 'label' => 'Performance cycles', 'url' => '/admin/performance-cycles', 'module' => 'growth'],
            ['keywords' => 'appraisal review form template', 'label' => 'Performance templates', 'url' => '/admin/performance-templates', 'module' => 'growth'],
            ['keywords' => 'rating scale', 'label' => 'Rating scales', 'url' => '/admin/rating-scales', 'module' => 'growth'],
            ['keywords' => 'competency skill framework', 'label' => 'Competencies', 'url' => '/admin/competencies', 'module' => 'growth'],
            ['keywords' => 'kra kpi library', 'label' => 'KRA library', 'url' => '/admin/kras', 'module' => 'growth'],
            ['keywords' => 'skill library', 'label' => 'Skills', 'url' => '/admin/skills', 'module' => 'growth'],
            ['keywords' => 'skill level proficiency scale', 'label' => 'Skill levels', 'url' => '/admin/skill-scales', 'module' => 'growth'],
            ['keywords' => 'course training learning lms', 'label' => 'Courses', 'url' => '/admin/courses', 'module' => 'growth'],
            ['keywords' => 'learning path curriculum course', 'label' => 'Learning paths', 'url' => '/admin/learning-paths', 'module' => 'growth'],
            ['keywords' => 'career path ladder', 'label' => 'Career paths', 'url' => '/admin/career-paths', 'module' => 'growth'],
            ['keywords' => 'career architecture track ladder', 'label' => 'Career architecture', 'url' => '/admin/career-tracks', 'module' => 'growth'],
            ['keywords' => 'role requirement skills position', 'label' => 'Role requirements', 'url' => '/admin/role-requirements', 'module' => 'growth'],
            ['keywords' => 'service catalogue request type hr', 'label' => 'Service catalogue', 'url' => '/admin/service-definitions', 'module' => 'service'],
            ['keywords' => 'ticket category sla service desk', 'label' => 'Service desk categories & SLAs', 'url' => '/admin/ticket-categories', 'module' => 'service'],
            ['keywords' => 'sla response resolution time target', 'label' => 'SLA policies', 'url' => '/admin/service-sla-policies', 'module' => 'service'],
            ['keywords' => 'grievance category posh committee', 'label' => 'Grievance categories & handlers', 'url' => '/admin/grievance-categories', 'module' => 'service'],
            ['keywords' => 'asset category laptop', 'label' => 'Asset categories', 'url' => '/admin/asset-categories', 'module' => 'service'],
            ['keywords' => 'asset model laptop make', 'label' => 'Asset models', 'url' => '/admin/asset-models', 'module' => 'service'],
            ['keywords' => 'workflow approval flow', 'label' => 'Workflows', 'url' => '/admin/workflows', 'module' => 'workflows'],
            ['keywords' => 'notification template email rule', 'label' => 'Notification rules', 'url' => '/admin/notification-rules', 'module' => 'workflows'],
            ['keywords' => 'notification template email message', 'label' => 'Notification templates', 'url' => '/admin/notification-templates', 'module' => 'workflows'],
            ['keywords' => 'audience target group segment', 'label' => 'Audiences', 'url' => '/admin/audiences', 'module' => 'workflows'],
            ['keywords' => 'user login', 'label' => 'Users', 'url' => '/admin/users', 'module' => 'access'],
            ['keywords' => 'role permission access', 'label' => 'Roles', 'url' => '/admin/roles', 'module' => 'access'],
            ['keywords' => 'sso single sign-on saml oidc login', 'label' => 'Single sign-on', 'url' => '/admin/sso-connections', 'module' => 'access'],
            ['keywords' => 'security password mfa session ip', 'label' => 'Security policy', 'url' => '/admin/security-policy-page', 'module' => 'access'],
            ['keywords' => 'api key integration webhook', 'label' => 'API keys', 'url' => '/admin/api-keys', 'module' => 'data'],
            ['keywords' => 'webhook callback event', 'label' => 'Webhooks', 'url' => '/admin/webhook-endpoints', 'module' => 'data'],
            ['keywords' => 'integration hub connector sync', 'label' => 'Integration hub', 'url' => '/admin/integration-systems', 'module' => 'data'],
            ['keywords' => 'exchange rate currency fx', 'label' => 'Exchange rates', 'url' => '/admin/exchange-rates', 'module' => 'data'],
            ['keywords' => 'report builder export schedule', 'label' => 'Reports', 'url' => '/admin/reports', 'module' => 'data'],
            ['keywords' => 'dashboard widget', 'label' => 'Dashboard builder', 'url' => '/admin/dashboards', 'module' => 'data'],
            ['keywords' => 'setting notice period leave year payroll', 'label' => 'Settings', 'url' => '/admin/tenant-settings', 'module' => 'general'],
            ['keywords' => 'feature flag toggle', 'label' => 'Features', 'url' => '/admin/tenant-features', 'module' => 'general'],
            ['keywords' => 'country pack localisation statutory', 'label' => 'Country packs', 'url' => '/admin/country-packs-page', 'module' => 'general'],
            ['keywords' => 'pack blueprint import export', 'label' => 'Configuration packs', 'url' => '/admin/configuration-packs', 'module' => 'general'],
            ['keywords' => 'change centre approval configuration history rollback', 'label' => 'Configuration change centre', 'url' => '/admin/configuration-changes', 'module' => 'general'],
        ],
    ],

    /*
    | Analytics (§84–§85, §56): report builder vocabulary and dashboard widget types.
    */
    'analytics' => [
        'operators' => ['equals' => 'is', 'not_equals' => 'is not', 'in' => 'is any of', 'not_in' => 'is none of', 'contains' => 'contains', 'gt' => 'greater than', 'gte' => 'at least', 'lt' => 'less than', 'lte' => 'at most', 'between' => 'between', 'is_empty' => 'is empty', 'not_empty' => 'is not empty', 'last_days' => 'in the last N days', 'next_days' => 'in the next N days', 'today' => 'today', 'yesterday' => 'yesterday', 'this_week' => 'this week', 'this_month' => 'this month', 'last_month' => 'last month', 'this_year' => 'this year', 'this_financial_year' => 'this financial year', 'last_financial_year' => 'last financial year'],
        'aggregations' => ['count' => 'Count', 'sum' => 'Sum', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum'],
        'visualizations' => ['table' => 'Table', 'bar' => 'Bar chart', 'line' => 'Line chart', 'pie' => 'Pie chart', 'kpi' => 'Single number'],
        'formats' => ['csv' => 'CSV (Excel-compatible)'],
        'max_rows' => 10000,
        /*
        | Reports whose result goes to no signed-in user (the read API, which runs a shared report as its owner). Default
        | deny: only these datasets and only these fields, and the API key must also hold every listed scope. Each list
        | was reviewed field by field (2026-10-28) against what those scopes already return through their own endpoints:
        | a field is listed only when those endpoints return it or it is computed from such fields or from reference data.
        | Left out: exits (no API scope covers exit cases; exit-interview reason, rehire eligibility, settlement amount),
        | every report-library dataset, and in the lists below anything derived from date of birth (age, birthday),
        | marital status, nationality, free-text notes, appraisal scores other than the final outcome, asset costs, the
        | payment date and taxable earnings. Add a dataset or field only after the same review.
        */
        'unattended_datasets' => [
            'employees' => ['scopes' => ['employees.read'], 'fields' => ['employee_code', 'name', 'gender', 'work_email', 'work_phone', 'lifecycle_state', 'source', 'joining_date', 'joining_month', 'probation_end_date', 'confirmation_date', 'exit_date', 'tenure_months', 'years_of_service', 'tenure_band', 'work_anniversary_this_year', 'next_work_anniversary', 'anniversary_years', 'completing_years', 'company', 'business_unit', 'division', 'location', 'department', 'team', 'designation', 'level', 'grade', 'employment_type', 'employee_category', 'work_mode', 'manager', 'manager_code', 'headcount']],
            'attendance' => ['scopes' => ['attendance.read', 'employees.read'], 'fields' => ['date', 'day', 'month', 'employee_code', 'name', 'employee', 'company', 'location', 'department', 'designation', 'employee_status', 'status', 'shift', 'scheduled_start', 'scheduled_end', 'first_in', 'last_out', 'scheduled_hours', 'worked_hours', 'late_minutes', 'early_leave_minutes', 'is_late', 'is_early_leave', 'overtime_claimed_hours', 'overtime_hours', 'overtime_status', 'is_absent', 'is_present', 'is_on_leave', 'missed_punch', 'is_regularised', 'holiday_name', 'exceptions']],
            'leave' => ['scopes' => ['leave.read', 'employees.read'], 'fields' => ['from_date', 'to_date', 'month', 'employee_code', 'name', 'employee', 'company', 'location', 'department', 'designation', 'employee_status', 'leave_type', 'leave_code', 'is_paid', 'from_session', 'to_session', 'days', 'status', 'reason', 'reviewed_on', 'review_note', 'on_leave_today']],
            'payroll' => ['scopes' => ['payroll.read', 'employees.read'], 'fields' => ['period', 'period_start', 'employee_code', 'name', 'employee', 'company', 'location', 'department', 'designation', 'employee_status', 'days_in_period', 'paid_days', 'lop_days', 'gross', 'total_earnings', 'total_deductions', 'net_pay', 'employer_cost', 'basic', 'hra', 'pf_employee', 'pf_employer', 'esi_employee', 'esi_employer', 'professional_tax', 'lwf_employee', 'lwf_employer', 'tds', 'status', 'run_status']],
            'performance' => ['scopes' => ['performance.read', 'employees.read'], 'fields' => ['cycle', 'employee_code', 'name', 'department', 'status', 'final_rating', 'final_label', 'promotion_recommended']],
            'learning' => ['scopes' => ['learning.read', 'employees.read'], 'fields' => ['course', 'category', 'employee_code', 'name', 'department', 'status', 'is_mandatory', 'progress', 'score', 'due_on', 'completed_at', 'is_completed', 'is_overdue']],
            'assets' => ['scopes' => ['assets.read'], 'fields' => ['asset_tag', 'name', 'category', 'status', 'condition', 'location', 'warranty_until', 'count']],
            'servicedesk' => ['scopes' => ['servicedesk.read'], 'fields' => ['number', 'service', 'category', 'employee_code', 'priority', 'status', 'raised_on', 'month', 'resolution_hours', 'breached', 'count']],
        ],
        // Phase 14: the privacy threshold for cross-domain People analytics (counts below it are suppressed).
        'min_group' => (int) env('PEOPLEOS_ANALYTICS_MIN_GROUP', 5),
        'schedule_frequencies' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'],
        'widget_types' => ['kpi' => 'KPI number', 'trend' => 'Trend (last 12 months)', 'chart' => 'Chart from a report', 'table' => 'Table from a report', 'leaderboard' => 'Leaderboard (top rows of a report)', 'alerts' => 'Needs attention counts',
            // HROne parity CA-10: the CXO views' own widgets (aggregates only, small groups suppressed).
            'payroll' => 'Payroll summary (last finalized month)', 'nine_box' => '9-box counts (latest cycle)', 'survey' => 'Latest survey results'],
        // HROne parity CA-10: survey kinds a "Latest survey results" widget can show (pulse = surveys of type pulse).
        'survey_widget_kinds' => ['survey' => 'Surveys (every type but pulse)', 'pulse' => 'Pulse surveys'],
        'widget_sizes' => ['1' => 'Small', '2' => 'Half width', '4' => 'Full width'],
        'metrics' => [
            'headcount' => 'Headcount', 'joiners_30d' => 'Joiners (last 30 days)', 'exits_30d' => 'Exits (last 30 days)', 'attrition_rate' => 'Attrition rate (12 months, %)',
            'absenteeism_rate' => 'Absenteeism (last 30 days, %)', 'on_leave_today' => 'On leave today', 'people_cost' => 'People cost (last finalized payroll)', 'cost_per_head' => 'Cost per head (last payroll)',
            'avg_tenure_months' => 'Average tenure (months)', 'women_share' => 'Women in workforce (%)', 'high_performers' => 'High performers (latest cycle)', 'learning_completion' => 'Mandatory learning completion (%)',
            'open_tickets' => 'Open service desk tickets', 'open_grievances' => 'Open grievances', 'exits_in_progress' => 'Exits in progress', 'pending_approvals' => 'Pending approvals',
            'expense_reports_pending' => 'Expense reports waiting for approval', 'expense_payable' => 'Expenses to pay (approved, unpaid)', 'expense_advances_outstanding' => 'Expense advances outstanding', 'expense_advances_overdue' => 'Expense advances past the settle-by date',
            // HROne parity CA-10 (HR Ops view): approval turnaround over the last 30 days (workflow tasks; organisation-wide only).
            'approvals_on_time' => 'Approvals decided on time (last 30 days, %)', 'approval_turnaround_days' => 'Average days to decide an approval (last 30 days)',
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
        'types' => ['offer' => 'Offer', 'appointment' => 'Appointment', 'confirmation' => 'Confirmation', 'promotion' => 'Promotion', 'increment' => 'Increment', 'transfer' => 'Transfer', 'relieving' => 'Relieving', 'experience' => 'Experience', 'salary_certificate' => 'Salary certificate', 'employment_certificate' => 'Employment certificate', 'noc' => 'No objection certificate', 'warning' => 'Warning', 'show_cause' => 'Show cause', 'appreciation' => 'Appreciation', 'expense_cover' => 'Expense cover letter', 'badge_certificate' => 'Badge certificate',
            // HROne setup parity HP-8f: letters the PIP policy drafts when a plan starts and when its outcome is recorded.
            'pip' => 'PIP letter', 'pip_outcome' => 'PIP evaluation letter', 'performance' => 'Performance letter', 'custom' => 'Custom'],
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
        /*
        | Phase 12 request / case lifecycle. A status moves only along `transitions` (RequestLifecycle).
        | Every move is authorised, locked, audited, timestamped and attributed, and some need a reason.
        | `awaiting_approval` extends the suggested foundation, so that a workflow approval wait is not
        | counted as service time.
        */
        'statuses' => [
            'draft' => 'Draft', 'submitted' => 'Submitted', 'acknowledged' => 'Acknowledged', 'assigned' => 'Assigned', 'in_progress' => 'In progress',
            'awaiting_approval' => 'Awaiting approval', 'waiting_employee' => 'Waiting for employee', 'waiting_hr' => 'Waiting for HR',
            'resolved' => 'Resolved', 'closed' => 'Closed', 'cancelled' => 'Cancelled',
        ],
        'transitions' => [
            'draft' => ['submitted', 'cancelled'],
            'submitted' => ['acknowledged', 'assigned', 'in_progress', 'awaiting_approval', 'waiting_employee', 'resolved', 'cancelled'],
            'acknowledged' => ['submitted', 'assigned', 'in_progress', 'awaiting_approval', 'waiting_employee', 'resolved', 'cancelled'],
            'assigned' => ['acknowledged', 'submitted', 'in_progress', 'awaiting_approval', 'waiting_employee', 'waiting_hr', 'resolved', 'cancelled'],
            'in_progress' => ['acknowledged', 'submitted', 'awaiting_approval', 'waiting_employee', 'waiting_hr', 'resolved', 'cancelled'],
            'awaiting_approval' => ['in_progress', 'resolved', 'cancelled'],
            'waiting_employee' => ['in_progress', 'waiting_hr', 'resolved', 'cancelled'],
            'waiting_hr' => ['in_progress', 'waiting_employee', 'resolved', 'cancelled'],
            'resolved' => ['closed', 'in_progress'],
            'closed' => ['in_progress'],
            'cancelled' => [],
        ],
        // Transitions that need a reason (cancel, reopen); resolving needs a resolution.
        'reason_required' => ['cancelled', 'reopen'],
        // Statuses in which the SLA clock stops (configurable; an SLA policy may override).
        'sla_pause_statuses' => ['waiting_employee', 'awaiting_approval'],
        /*
        | Default service hours for business-hours SLAs (an SLA policy may override). Holidays come from
        | the employee's attendance holiday calendar, and the timezone from their work location. There
        | is no second holiday calendar.
        */
        'business_hours' => ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '18:00'],
        'confidentiality' => ['standard' => 'Standard', 'sensitive' => 'Sensitive (protected fields)', 'restricted' => 'Restricted (explicit access only)'],
        'comment_visibilities' => ['employee' => 'Visible to the employee', 'internal' => 'Internal HR note', 'restricted' => 'Restricted (people with explicit case access)'],
        'sources' => ['web' => 'Employee self-service', 'hr' => 'Raised by HR', 'manager' => 'Raised by a manager', 'api' => 'API', 'system' => 'System'],
        'field_classes' => ['standard' => 'Standard', 'sensitive' => 'Sensitive', 'restricted' => 'Restricted'],
        'attachment_rules' => ['none' => 'No attachments', 'optional' => 'Optional', 'required' => 'Required'],
        'domain_action_statuses' => ['awaiting_approval' => 'Awaiting approval', 'ready' => 'Ready to execute', 'executed' => 'Executed', 'rejected' => 'Not approved', 'refused' => 'Approval refused (separation of duties)', 'cancelled' => 'Cancelled'],
        /*
        | Domain actions a service may hand off to. Service Delivery never writes another module's
        | tables: each handler calls the owning domain's action / contract (see
        | docs/architecture/employee-experience.md).
        */
        'domain_actions' => [
            'leave.request' => LeaveRequestAction::class,
            'attendance.regularisation' => RegularisationAction::class,
            'letter.request' => LetterRequestAction::class,
            'compensation.proposal' => CompensationProposalLink::class,
            'profile.bank_account' => BankAccountChange::class,
            'profile.statutory_identity' => StatutoryIdentityChange::class,
            'profile.address' => AddressChange::class,
            'profile.emergency_contact' => EmergencyContactChange::class,
            'profile.family_member' => FamilyMemberChange::class,
            'employment.manager_change' => ManagerChange::class,
        ],
        // HROne setup parity HD-A: what a ticket category is for (the expense travel desk and asset requests find their desk by it).
        'category_purposes' => ['general' => 'General', 'travel_booking' => 'Travel booking', 'accommodation_booking' => 'Accommodation booking', 'asset' => 'Asset requests'],
        // Root-cause codes recorded with the RCA when a request is resolved.
        'root_causes' => ['process' => 'Process gap', 'system' => 'System issue', 'data' => 'Data error', 'policy' => 'Policy clarification', 'training' => 'User error / training', 'external' => 'External dependency', 'other' => 'Other'],
        'auto_close_days' => 5,
        // Reminders (idempotent through service_desk_reminder_logs; a reminder never triggers another).
        'waiting_employee_reminder_days' => 3,
        'waiting_employee_max_reminders' => 3,
        'waiting_hr_reminder_hours' => 24,
        'sla_risk_hours' => 8,
        'policy_acknowledgement_reminder_days' => 7,
        'analytics_min_group' => (int) env('PEOPLEOS_SERVICEDESK_MIN_GROUP', 5),
        'defaults' => [
            ['code' => 'LETTER', 'name' => 'Letters & certificates', 'description' => 'Experience, salary, address, NOC and other letters', 'sla_hours' => 72],
            ['code' => 'PAYROLL', 'name' => 'Payroll & tax query', 'sla_hours' => 48],
            ['code' => 'ATTENDANCE', 'name' => 'Attendance & leave query', 'sla_hours' => 24],
            ['code' => 'PROFILE', 'name' => 'Profile / data correction', 'sla_hours' => 48],
            ['code' => 'IT', 'name' => 'IT & assets', 'sla_hours' => 24],
            ['code' => 'POLICY', 'name' => 'Policy question', 'sla_hours' => 48],
            ['code' => 'OTHER', 'name' => 'Something else', 'sla_hours' => 72],
        ],
        // Starter SLA policy (business hours; HR may replace it). Examples only — configurable, not code.
        'sla_defaults' => [
            'code' => 'STANDARD', 'name' => 'Standard HR service', 'calendar' => 'business', 'warn_percent' => 75,
            'targets' => [
                'low' => ['first_response_hours' => 8, 'resolution_hours' => 27],
                'normal' => ['first_response_hours' => 4, 'resolution_hours' => 18],
                'high' => ['first_response_hours' => 2, 'resolution_hours' => 9],
                'urgent' => ['first_response_hours' => 1, 'resolution_hours' => 4],
            ],
        ],
        // Starter catalogue: seeded as DRAFT service versions; HR completes, submits and approves them.
        'service_defaults' => [
            ['code' => 'LEAVE_QUERY', 'name' => 'Leave query', 'category' => 'ATTENDANCE'],
            ['code' => 'ATTENDANCE_CORRECTION', 'name' => 'Attendance correction', 'category' => 'ATTENDANCE', 'domain_action' => 'attendance.regularisation'],
            ['code' => 'SALARY_CERTIFICATE', 'name' => 'Salary certificate', 'category' => 'LETTER', 'domain_action' => 'letter.request'],
            ['code' => 'EMPLOYMENT_CERTIFICATE', 'name' => 'Employment certificate', 'category' => 'LETTER', 'domain_action' => 'letter.request'],
            ['code' => 'ADDRESS_CHANGE', 'name' => 'Address change', 'category' => 'PROFILE', 'domain_action' => 'profile.address', 'confidentiality' => 'sensitive'],
            ['code' => 'BANK_ACCOUNT_CHANGE', 'name' => 'Bank account change', 'category' => 'PROFILE', 'domain_action' => 'profile.bank_account', 'confidentiality' => 'sensitive', 'approval_required' => true],
            ['code' => 'PAN_UPDATE', 'name' => 'PAN / UAN update', 'category' => 'PROFILE', 'domain_action' => 'profile.statutory_identity', 'confidentiality' => 'sensitive', 'approval_required' => true],
            ['code' => 'EMERGENCY_CONTACT_UPDATE', 'name' => 'Emergency contact update', 'category' => 'PROFILE', 'domain_action' => 'profile.emergency_contact', 'confidentiality' => 'sensitive'],
            ['code' => 'PAYROLL_QUERY', 'name' => 'Payroll query', 'category' => 'PAYROLL'],
            ['code' => 'COMPENSATION_QUERY', 'name' => 'Compensation query', 'category' => 'PAYROLL', 'confidentiality' => 'sensitive'],
            ['code' => 'HR_POLICY_QUERY', 'name' => 'HR policy query', 'category' => 'POLICY'],
            ['code' => 'EXPERIENCE_LETTER', 'name' => 'Experience letter request', 'category' => 'LETTER', 'domain_action' => 'letter.request'],
            ['code' => 'MANAGER_CHANGE', 'name' => 'Manager change request', 'category' => 'PROFILE', 'domain_action' => 'employment.manager_change', 'approval_required' => true, 'availability' => ['employee' => false, 'manager' => true, 'hr' => true]],
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
    /*
    | Phase 12 employee experience: the domains that contribute to "My tasks" (each reads its own
    | records through its own authorisation; the experience layer stores nothing). Surveys plug in
    | through the SurveyTaskProvider binding in Phase 13.
    */
    /*
    | HROne parity WO-1: bulk actions on registers (BulkRuns). A run acts on the records selected, or on every record matching
    | the register's filters, one by one through the owning service (each authorised and audited on its own, all under one
    | audit operation). Over `confirm_over` records the person types the number of records to confirm. A run never takes more
    | than `max_records` (narrow the filters instead). Saved views: at most `saved_views_per_register` per person and register.
    */
    'bulk' => [
        'confirm_over' => 25,
        'max_records' => 5000,
        'chunk' => 100,
        'saved_views_per_register' => 20,
        'pasted_codes_max' => 1000,
    ],

    'experience' => [
        'task_sources' => [
            WorkflowTaskSource::class,
            ServiceDeskTaskSource::class,
            PolicyAcknowledgementTaskSource::class,
            CommunicationTaskSource::class,
            // HROne setup parity AS-A: assets whose sub type needs the employee's acknowledgement.
            AssetAcknowledgementTaskSource::class,
            // HROne setup parity HP-8b: the feedback card after a held one-on-one (performance.one_on_one.feedback_from).
            OneOnOneFeedbackTaskSource::class,
            // HROne setup parity P13: feedback asked of the person (exit interviews, confirmation, probation, induction).
            FeedbackTaskSource::class,
            // HROne setup parity HP-8j: feedback on a colleague's initiative asked of the person.
            InitiativeFeedbackTaskSource::class,
            // HROne setup parity HP-8h / HP-8i: 360 nominations to make and feedback to give; periodic reviews to rate.
            Feedback360TaskSource::class,
        ],
    ],
    'kb' => [
        'categories' => ['hr_policy' => 'HR policies', 'attendance' => 'Attendance', 'leave' => 'Leave', 'travel' => 'Travel', 'posh' => 'PoSH', 'wfh' => 'Work from home', 'it' => 'IT', 'conduct' => 'Code of conduct', 'expense' => 'Expenses', 'benefits' => 'Benefits', 'payroll' => 'Payroll & tax', 'other' => 'Other'],
    ],
    'communication' => [
        'types' => ['announcement' => 'Announcement', 'circular' => 'Circular', 'newsletter' => 'Newsletter', 'policy' => 'Policy publication', 'instruction' => 'Instruction'],
        /*
        | Phase 13. Communication is an intentional organisational message, delivered through the
        | existing Notifier (no second notification engine). Mandatory types cannot be switched off in
        | preferences; transactional notifications are never subject to these preferences at all.
        */
        'mandatory_types' => ['policy', 'instruction'],
        'priorities' => ['normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'],
        'statuses' => ['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived', 'cancelled' => 'Cancelled'],
        // Real channels only: SMS / WhatsApp / push are log stubs in notifications.channels and are not offered.
        'channels' => ['in_app' => 'In-app', 'email' => 'Email'],
        'delivery_batch' => 200,
        'recipient_statuses' => ['pending' => 'Pending', 'queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped (preference)'],
    ],

    /*
    | Phase 13 engagement: surveys, feedback, campaigns. Descriptive only — no employee scores, no
    | sentiment or attrition inference, no AI. Anonymity is architectural (docs/architecture/
    | engagement-communication.md §3).
    */
    'engagement' => [
        'survey_types' => ['engagement' => 'Engagement', 'pulse' => 'Pulse', 'feedback' => 'Feedback', 'culture' => 'Culture', 'onboarding' => 'Onboarding feedback', 'exit' => 'Exit feedback', 'event' => 'Event feedback', 'custom' => 'Custom'],
        'categories' => ['engagement' => 'Engagement', 'wellbeing' => 'Wellbeing', 'culture' => 'Culture', 'operations' => 'Operations', 'events' => 'Events', 'other' => 'Other'],
        'question_types' => ['single_choice' => 'Single choice', 'multiple_choice' => 'Multiple choice', 'rating' => 'Rating', 'likert' => 'Agreement scale (5 points)', 'yes_no' => 'Yes / No', 'text' => 'Free text', 'number' => 'Number', 'date' => 'Date'],
        'likert_options' => ['1' => 'Strongly disagree', '2' => 'Disagree', '3' => 'Neutral', '4' => 'Agree', '5' => 'Strongly agree'],
        'anonymity_modes' => ['anonymous' => 'Anonymous — nobody can link answers to a person', 'confidential' => 'Confidential — identity kept apart, revealed only by a reasoned, audited request', 'identified' => 'Identified'],
        'response_rules' => ['once' => 'One response', 'multiple' => 'Several responses (identified only)', 'per_period' => 'One response per period (identified only)'],
        'response_periods' => ['week' => 'Week', 'month' => 'Month', 'quarter' => 'Quarter'],
        'statuses' => ['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'open' => 'Open', 'closed' => 'Closed', 'archived' => 'Archived'],
        'breakdown_dimensions' => ['company' => 'Company', 'location' => 'Location', 'department' => 'Department', 'business_unit' => 'Business unit', 'grade' => 'Grade', 'manager' => 'Line manager'],
        'participation_statuses' => ['invited' => 'Invited', 'opened' => 'Opened', 'submitted' => 'Submitted', 'expired' => 'Expired'],
        // Small-group privacy (the PeopleOS principle); free text needs more respondents.
        'analytics_min_group' => (int) env('PEOPLEOS_ENGAGEMENT_MIN_GROUP', 5),
        'text_min_group' => (int) env('PEOPLEOS_ENGAGEMENT_TEXT_MIN_GROUP', 10),
        // Default reminder policy (a version may override): reminders N days after opening, one closing reminder, then stop.
        'reminders' => ['after_days' => [3], 'closing_days_before' => 2, 'max' => 2],
        'feedback_modes' => ['identified' => 'Identified', 'confidential' => 'Confidential', 'anonymous' => 'Anonymous'],
        'feedback_categories' => ['workplace' => 'Workplace', 'process' => 'Processes & tools', 'wellbeing' => 'Wellbeing', 'communication' => 'Communication', 'idea' => 'Idea / suggestion', 'other' => 'Other'],
        'feedback_statuses' => ['new' => 'New', 'in_review' => 'In review', 'referred' => 'Referred to HR service desk', 'closed' => 'Closed'],
        'campaign_statuses' => ['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
        // Optional approval workflows (existing engine); empty = maker-checker by a second person.
        'approval_workflows' => ['survey' => null, 'announcement' => null, 'campaign' => null],
    ],

    /*
    | Learning / LMS (§37).
    */
    'learning' => [
        'course_types' => ['video' => 'Video', 'document' => 'Document / reading', 'elearning' => 'E-learning (external link)', 'classroom' => 'Classroom training', 'virtual' => 'Virtual training', 'assessment' => 'Assessment only'],
        'categories' => ['compliance' => 'Compliance', 'onboarding' => 'Onboarding', 'technical' => 'Technical', 'leadership' => 'Leadership', 'soft_skills' => 'Soft skills', 'product' => 'Product', 'safety' => 'Health & safety', 'other' => 'Other'],
        'module_types' => ['video' => 'Video', 'document' => 'Document', 'link' => 'External link', 'text' => 'Text', 'assessment' => 'Assessment'],
        'enrolment_statuses' => ['assigned' => 'Assigned', 'requested' => 'Requested', 'pending_approval' => 'Pending approval', 'approved' => 'Approved', 'enrolled' => 'Enrolled', 'waitlisted' => 'Waitlisted', 'in_progress' => 'Started', 'overdue' => 'Overdue', 'completed' => 'Completed', 'failed' => 'Failed', 'withdrawn' => 'Withdrawn', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'],
        // Phase 8 catalogue taxonomy — all configurable per installation, nothing hard-coded in services.
        'delivery_modes' => ['classroom' => 'Classroom', 'virtual' => 'Virtual', 'self_paced' => 'Self-paced', 'blended' => 'Blended', 'on_the_job' => 'On-the-job', 'external' => 'External', 'workshop' => 'Workshop', 'conference' => 'Conference', 'certification' => 'Certification'],
        'difficulties' => ['introductory' => 'Introductory', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'expert' => 'Expert'],
        'languages' => ['en' => 'English', 'hi' => 'Hindi', 'ar' => 'Arabic'],
        'provider_types' => ['internal' => 'Internal L&D', 'external' => 'External provider'],
        'priorities' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'],
        'grades' => ['distinction' => 'Distinction', 'merit' => 'Merit', 'pass' => 'Pass', 'fail' => 'Fail'],
        'attendance' => ['full' => 'Attended in full', 'partial' => 'Partially attended', 'none' => 'Did not attend'],
        // Delivery modes whose learner may report their own progress (instructor-led progress comes from attendance).
        'self_reported_progress_modes' => ['self_paced', 'on_the_job', 'blended', 'external'],
        // A course must be approved by a second person (learning.publish) before it is published.
        'require_catalogue_approval' => (bool) env('PEOPLEOS_LEARNING_REQUIRE_APPROVAL', true),
        'recertification_lead_days' => 60,
        'analytics_min_group' => (int) env('PEOPLEOS_LEARNING_MIN_GROUP', 5),
        'reminder_days_before' => 3,
        'overdue_reminder_every_days' => 7,
        // Render an HTML certificate document onto the private disk (queued) when a certificate is issued.
        'generate_certificate_documents' => (bool) env('PEOPLEOS_LEARNING_CERTIFICATE_DOCUMENTS', true),
        // Assignment populations are processed in chunks so large tenants are never loaded at once.
        'assignment_chunk' => 500,
        'evidence_max_kb' => 10240,
        'evidence_mimes' => ['application/pdf', 'image/png', 'image/jpeg'],
        'session_statuses' => ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
        'attendee_statuses' => ['registered' => 'Registered', 'attended' => 'Attended', 'absent' => 'Absent', 'cancelled' => 'Cancelled'],
        'due_soon_days' => 7,
        'certificate_expiry_notice_days' => 30,
        'certificate_prefix' => 'CERT',
    ],

    /*
    | Skills & development (Phase 8). Taxonomies are configurable; nothing is inferred from job titles.
    */
    'skills' => [
        'types' => ['technical' => 'Technical', 'behavioural' => 'Behavioural', 'functional' => 'Functional', 'leadership' => 'Leadership', 'domain' => 'Domain', 'digital' => 'Digital', 'language' => 'Language'],
        'confidence' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
        // Seeded once per tenant as version 1 of the default scale; edits are new versions.
        'default_scale' => ['code' => 'PROFICIENCY', 'name' => 'Proficiency', 'levels' => [
            ['value' => 1, 'label' => 'Beginner', 'description' => 'Learning the basics; needs guidance', 'indicator' => 'Completes simple tasks with support'],
            ['value' => 2, 'label' => 'Intermediate', 'description' => 'Works independently on routine work', 'indicator' => 'Handles common cases without help'],
            ['value' => 3, 'label' => 'Advanced', 'description' => 'Handles complex work; guides others', 'indicator' => 'Solves non-routine problems; reviews others'],
            ['value' => 4, 'label' => 'Expert', 'description' => 'Recognised authority; shapes practice', 'indicator' => 'Sets standards; teaches the skill'],
        ]],
    ],

    /*
    | Career, talent & succession (Phase 9). Every label is configuration; nothing here is computed
    | into a recommendation. Readiness and criticality are recorded by people.
    */
    'career' => [
        'track_types' => ['individual_contributor' => 'Individual contributor', 'people_manager' => 'People manager', 'technical_specialist' => 'Technical specialist', 'functional_specialist' => 'Functional specialist', 'leadership' => 'Leadership'],
        'aspiration_terms' => ['short' => 'Short term (≤ 1 year)', 'medium' => 'Medium term (1–3 years)', 'long' => 'Long term (3+ years)'],
        'goal_types' => ['role' => 'Reach a role', 'skill' => 'Build a skill', 'capability' => 'Build a capability', 'move' => 'Move function / location', 'assignment' => 'Assignment / experience'],
        'goal_statuses' => ['active' => 'Active', 'achieved' => 'Achieved', 'paused' => 'Paused', 'abandoned' => 'Abandoned'],
        'mobility_interest_types' => ['position' => 'Position', 'job_family' => 'Job family', 'department' => 'Department', 'location' => 'Location', 'career_track' => 'Career track'],
        'mobility_options' => ['relocation' => 'Open to relocation', 'international' => 'Open to international moves', 'role_change' => 'Open to a role change', 'travel' => 'Open to travel'],
    ],

    'talent' => [
        'readiness_levels' => ['ready_now' => 'Ready now', 'lt_1_year' => 'Ready < 1 year', '1_2_years' => 'Ready 1–2 years', 'longer_term' => 'Longer term', 'not_assessed' => 'Not assessed'],
        'readiness_validity_months' => 12,
        'criticality_levels' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'],
        'impact_levels' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
        'vacancy_risk_levels' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
        'mobility_levels' => ['not_mobile' => 'Not mobile', 'within_location' => 'Within location', 'national' => 'National', 'international' => 'International'],
        'review_decisions' => ['retain_and_develop' => 'Retain and develop', 'accelerate_development' => 'Accelerate development', 'add_to_pool' => 'Add to a talent pool', 'nominate_successor' => 'Nominate as successor', 'no_change' => 'No change', 'revisit' => 'Revisit next review'],
        'development_action_types' => ['learning_path' => 'Complete a learning path', 'certification' => 'Gain a certification', 'skill' => 'Build a skill', 'project' => 'Project assignment', 'mentoring' => 'Mentoring', 'coaching' => 'Coaching', 'stretch' => 'Stretch assignment', 'rotation' => 'Job rotation'],
        // A default assessment model; tenants configure their own (a 9-box is one possible configuration).
        'default_model' => ['code' => 'PERF_POTENTIAL', 'name' => 'Performance and potential', 'dimensions' => [
            ['key' => 'performance', 'label' => 'Performance', 'levels' => [['value' => 1, 'label' => 'Below'], ['value' => 2, 'label' => 'Meets'], ['value' => 3, 'label' => 'Exceeds']]],
            ['key' => 'potential', 'label' => 'Potential', 'levels' => [['value' => 1, 'label' => 'Limited'], ['value' => 2, 'label' => 'Moderate'], ['value' => 3, 'label' => 'Strong']]],
        ]],
        // Employees do not see their own succession candidacy unless granted succession.own_candidacy.
        'analytics_min_group' => (int) env('PEOPLEOS_TALENT_MIN_GROUP', 5),
        'review_reminder_days' => 14,
        // Optional approval workflows (keys of published workflows); empty = people decide directly.
        'review_workflow_key' => env('PEOPLEOS_TALENT_REVIEW_WORKFLOW'),
        'succession_plan_workflow_key' => env('PEOPLEOS_SUCCESSION_PLAN_WORKFLOW'),
    ],

    /*
    | Phase 10: workforce planning and position management. A position is capacity, never a person;
    | "occupied" is derived from employee assignments, never typed.
    */
    'workforce' => [
        'position_statuses' => ['draft' => 'Draft', 'proposed' => 'Proposed', 'approved' => 'Approved', 'planned' => 'Planned', 'open' => 'Open', 'frozen' => 'Frozen', 'on_hold' => 'On hold', 'abolished' => 'Abolished', 'closed' => 'Closed'],
        // Allowed lifecycle moves. Tenants with other conventions change this map; "occupied" is never a target.
        'position_transitions' => [
            'draft' => ['proposed', 'closed'],
            'proposed' => ['approved', 'draft', 'closed'],
            'approved' => ['planned', 'open', 'closed'],
            'planned' => ['open', 'frozen', 'on_hold', 'abolished', 'closed'],
            'open' => ['frozen', 'on_hold', 'abolished', 'closed'],
            'frozen' => ['open', 'abolished', 'closed'],
            'on_hold' => ['open', 'frozen', 'abolished', 'closed'],
            'abolished' => [],
            'closed' => [],
        ],
        // Statuses in which a position counts as approved organisational capacity on a date.
        'effective_statuses' => ['approved', 'planned', 'open', 'frozen', 'on_hold'],
        // Statuses that accept a new employee assignment.
        'assignable_statuses' => ['open'],
        'worker_types' => ['employee' => 'Employee', 'contractor' => 'Contractor', 'apprentice' => 'Apprentice', 'intern' => 'Intern', 'consultant' => 'Consultant'],
        'occupancy_modes' => ['single' => 'Single occupant', 'multiple' => 'Multiple occupants'],
        // Position attribute groups whose change needs a second person's approval (true) or applies directly (false).
        'change_approval' => ['headcount' => true, 'fte' => true, 'status' => false, 'organisation' => true, 'location' => false, 'grade' => true, 'definition' => false],
        // Statuses reached only through approval (proposer ≠ approver).
        'approval_statuses' => ['approved'],
        'plan_statuses' => ['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'approved' => 'Approved', 'active' => 'Active', 'superseded' => 'Superseded', 'archived' => 'Archived', 'rejected' => 'Rejected'],
        'period_types' => ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_year' => 'Half-year', 'annual' => 'Annual', 'custom' => 'Custom'],
        // Plan line movements and their direction on planned headcount (+1 adds, -1 removes, 0 neutral).
        'movement_types' => [
            'baseline' => ['label' => 'Existing capacity (baseline)', 'sign' => 1],
            'new_position' => ['label' => 'New position', 'sign' => 1],
            'expansion' => ['label' => 'Position expansion', 'sign' => 1],
            'transfer_in' => ['label' => 'Transfer in', 'sign' => 1],
            'reduction' => ['label' => 'Position reduction', 'sign' => -1],
            'position_closure' => ['label' => 'Position closure', 'sign' => -1],
            'transfer_out' => ['label' => 'Transfer out', 'sign' => -1],
            'retirement' => ['label' => 'Retirement', 'sign' => -1],
            'known_exit' => ['label' => 'Known exit', 'sign' => -1],
        ],
        'cost_bases' => ['annual_salary' => 'Annualised salary', 'monthly_salary' => 'Monthly salary', 'employer_cost' => 'Employer cost', 'position_cost' => 'Configured position cost'],
        // Planning assumptions a scenario may state explicitly (labelled as assumptions, never predictions).
        'scenario_assumptions' => ['attrition_rate_percent' => 'Expected attrition % per year (planning assumption)', 'growth_rate_percent' => 'Expected growth % (planning assumption)', 'notes' => 'Other assumptions'],
        'analytics_min_group' => (int) env('PEOPLEOS_WORKFORCE_MIN_GROUP', 5),
        'approval_reminder_days' => 3,
        'plan_expiry_reminder_days' => 30,
        'vacancy_reminder_days' => 30,
        // Optional approval workflows (keys of published workflows); empty = people decide directly.
        'plan_workflow_key' => env('PEOPLEOS_WORKFORCE_PLAN_WORKFLOW'),
    ],

    /*
    | Phase 11: compensation. Compensation owns employee compensation (employee_salary_assignments is
    | its canonical history) and reaches Payroll only through the CompensationOutput contract. Every
    | change is proposed, reviewed, approved and executed by four different people.
    */
    'compensation' => [
        // Change types tenants may use (labels may be renamed; "correction" replaces a row on the same date).
        'change_types' => [
            'hire' => 'Hire / first compensation', 'annual_increment' => 'Annual increment', 'promotion' => 'Promotion-related increase',
            'market_adjustment' => 'Market adjustment', 'correction' => 'Correction', 'allowance_change' => 'Allowance change',
            'role_grade_adjustment' => 'Role / grade adjustment', 'retention_adjustment' => 'Retention adjustment', 'transfer' => 'Transfer',
            'rehire' => 'Rehire', 'revision' => 'Revision', 'other' => 'Other',
        ],
        'sources' => ['manual' => 'Proposed by a person', 'cycle' => 'Compensation cycle', 'position' => 'From a position', 'plan' => 'From a workforce plan', 'import' => 'Salary upload'],
        // Salary uploads (Compensation › Salary uploads): rows per file; each valid row becomes one cycle line.
        'import_max_rows' => 5000,
        // Payroll calculates monthly; other frequencies are refused until Payroll supports them.
        'pay_frequencies' => ['monthly' => 'Monthly'],
        // ISO 4217 codes accepted for compensation amounts.
        'currencies' => ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'QAR', 'KWD', 'OMR', 'BHD', 'SGD', 'MYR', 'IDR', 'THB', 'PHP', 'VND', 'JPY', 'CNY', 'HKD', 'KRW', 'AUD', 'NZD', 'CAD', 'MXN', 'BRL', 'ZAR', 'KES', 'NGN', 'EGP', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'TRY', 'ILS', 'LKR', 'BDT', 'NPR', 'PKR'],
        /*
        | Lifecycle (§41). "current": may receive compensation effective today or earlier; "future": may
        | receive compensation effective later; "cycles": take part in compensation cycles. Exited and
        | alumni employees receive nothing new (rehire returns the same employee record to an employed
        | state and keeps every earlier row); a correction effective on or before the exit date is still
        | allowed for the states in "correction_after_exit".
        */
        'lifecycle' => [
            'current' => ['preboarding', 'onboarding', 'joined', 'probation', 'confirmed', 'active', 'on_leave', 'suspended', 'notice_period'],
            'future' => ['pre_employee', 'preboarding', 'onboarding', 'joined', 'probation', 'confirmed', 'active', 'on_leave', 'suspended', 'notice_period'],
            'cycles' => ['probation', 'confirmed', 'active', 'on_leave'],
            'correction_after_exit' => ['exited'],
        ],
        // Structure version components: fixed or variable pay, and how often the component is paid.
        'pay_natures' => ['fixed' => 'Fixed', 'variable' => 'Variable'],
        'component_frequencies' => ['monthly' => 'Monthly', 'annual' => 'Annual', 'one_time' => 'One-time'],
        // Range model: "min_mid_max" requires a midpoint; "min_max" makes it optional (compa-ratio then undefined).
        'range_model' => env('PEOPLEOS_COMPENSATION_RANGE_MODEL', 'min_mid_max'),
        // Bulk cycles and the change type their lines carry.
        'cycle_types' => [
            'annual_increment' => ['label' => 'Annual increment cycle', 'change_type' => 'annual_increment'],
            'promotion' => ['label' => 'Promotion cycle', 'change_type' => 'promotion'],
            'market_adjustment' => ['label' => 'Market adjustment cycle', 'change_type' => 'market_adjustment'],
            // Prepared from a salary file, never populated or created by hand; each line carries its own change type.
            'salary_upload' => ['label' => 'Salary upload', 'change_type' => 'revision', 'upload' => true],
        ],
        'analytics_min_group' => (int) env('PEOPLEOS_COMPENSATION_MIN_GROUP', 5),
        // Reminders: changes waiting for a decision longer than this (days); throttled per change.
        'approval_reminder_days' => 3,
    ],

    'development' => [
        'item_statuses' => ['open' => 'Open', 'done' => 'Done', 'cancelled' => 'Cancelled'],
        'milestone_reminder_days' => 7,
    ],

    /*
    | Assets (§38).
    */
    'assets' => [
        'statuses' => ['in_stock' => 'In stock', 'assigned' => 'Assigned', 'in_transit' => 'In transit', 'in_repair' => 'In repair', 'lost' => 'Lost', 'retired' => 'Retired', 'disposed' => 'Disposed'],
        'conditions' => ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'damaged' => 'Damaged', 'not_working' => 'Not working'],
        'movement_types' => ['procured' => 'Procured', 'assigned' => 'Assigned', 'transferred' => 'Transferred', 'returned' => 'Returned', 'repair_out' => 'Sent for repair', 'repair_in' => 'Back from repair', 'lost' => 'Reported lost', 'found' => 'Found', 'retired' => 'Retired', 'disposed' => 'Disposed', 'location' => 'Location change'],
        'disposal_methods' => ['sale' => 'Sale', 'scrap' => 'Scrap', 'donation' => 'Donation', 'return_to_vendor' => 'Returned to vendor', 'write_off' => 'Write-off'],
        // HROne setup parity AS-A (Asset → Products): how a product is deployed, and what a licence covers.
        'deployment_types' => ['hardware' => 'Hardware', 'on_premise_software' => 'On-premise software', 'cloud_saas' => 'Cloud / SaaS subscription', 'service' => 'Service or subscription'],
        'licence_bases' => ['per_user' => 'Per user', 'per_device' => 'Per device', 'organisation' => 'Organisation-wide'],
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
    | HROne setup parity (Expense): vocabularies of the expense masters (EX-A) and policies (EX-B), the standard expense
    | and travel form fields, and the starter data ExpenseDefaults seeds. No statutory value lives here: tax types are
    | names only (the receipt carries the tax actually charged).
    */
    'expense' => [
        'types' => ['amount' => 'Amount', 'mileage' => 'Mileage'],
        // Limit and frequency periods. Yearly, half-yearly and quarterly ones count from expense.year_start_month; a trip
        // period needs the travel request's dates (EX-F) and otherwise counts like per claim.
        'periods' => ['per_claim' => 'Per claim', 'day' => 'Daily', 'week' => 'Weekly (Monday to Sunday)', 'month' => 'Monthly', 'quarter' => 'Quarterly', 'half_year' => 'Half-yearly', 'year' => 'Yearly', 'trip' => 'Per trip'],
        'limit_bases' => [
            'amount' => ['none' => 'No limit', 'amount' => 'Amount', 'percent_of_ctc' => 'Percentage (%) of CTC'],
            'mileage' => ['none' => 'No distance limit', 'km' => 'Distance (km)'],
        ],
        'location_inputs' => ['none' => 'Not asked', 'text' => 'Typed in (from and to)', 'map' => 'Picked on a map (needs a maps provider; typed in until one is connected)'],
        'travel_links' => ['none' => 'None', 'reference' => 'Travel reference (may point to an approved trip)', 'plan' => 'Travel plan (needs an approved trip covering the date)'],
        'travel_forms' => ['travel' => 'Travel booking form', 'accommodation' => 'Accommodation booking form'],
        'booked_by' => ['employee' => 'Always booked by employee', 'company' => 'Always booked by company', 'either' => 'Booked by employee or company'],
        // HROne setup parity EX-F: how a journey is made (stays have no mode).
        'travel_modes' => ['air' => 'Air', 'rail' => 'Rail', 'road' => 'Road', 'sea' => 'Sea', 'other' => 'Other'],
        'gst_supply_types' => ['goods' => 'Goods', 'services' => 'Services'],
        // What a receipt's checks noted for the approver (EX-C).
        'receipt_flags' => ['over_limit' => 'Over the limit', 'suspected_duplicate' => 'Suspected duplicate', 'same_invoice_number' => 'Invoice number already claimed', 'distance_edited' => 'Distance edited'],
        'attendance_modes' => ['present' => 'Present on the expense date', 'min_hours' => 'Worked at least a minimum time that day', 'on_duty' => 'On duty that day'],
        'on_duty_kinds' => ['any' => 'Any on duty', 'specific' => 'Specific on-duty types'],
        // The four places a receipt field can appear (amount and mileage categories; the travel and accommodation booking
        // sections of travel-linked categories).
        'form_sections' => ['amount' => 'Amount', 'mileage' => 'Mileage', 'accommodation' => 'Accommodation', 'travel' => 'Travel details'],
        // Standard expense form fields (HROne's set; "Field one … ten" are custom fields of the expense_receipt entity).
        // The project field arrives with projects (HP-5).
        'form_fields' => [
            'purpose' => ['label' => 'Purpose', 'visible' => ['amount', 'mileage', 'accommodation', 'travel'], 'mandatory' => []],
            'location' => ['label' => 'Location', 'visible' => ['amount', 'mileage', 'accommodation', 'travel'], 'mandatory' => []],
            'participants' => ['label' => 'Other participants', 'visible' => ['amount'], 'mandatory' => []],
            'merchant' => ['label' => 'Merchant', 'visible' => ['amount', 'accommodation', 'travel'], 'mandatory' => []],
            'invoice_number' => ['label' => 'Invoice number', 'visible' => ['amount', 'accommodation', 'travel'], 'mandatory' => []],
        ],
        // Standard travel plan form fields; custom fields of the travel_request entity follow them.
        'travel_form_fields' => [
            'currency' => ['label' => 'Currency', 'visible' => true, 'mandatory' => false],
            'estimated_cost' => ['label' => 'Estimated cost', 'visible' => true, 'mandatory' => false],
            'international_travel' => ['label' => 'International travel', 'visible' => true, 'mandatory' => false],
            'visa_number' => ['label' => 'Visa number', 'visible' => true, 'mandatory' => false],
            'passport_number' => ['label' => 'Passport number', 'visible' => true, 'mandatory' => false],
            'attachment' => ['label' => 'Attachment', 'visible' => true, 'mandatory' => false],
        ],
        'defaults' => [
            'groups' => [
                ['code' => 'TRAVEL', 'name' => 'Travel'],
                ['code' => 'STAY', 'name' => 'Accommodation'],
                ['code' => 'MEALS', 'name' => 'Meals'],
                ['code' => 'CONVEYANCE', 'name' => 'Local conveyance'],
                ['code' => 'OTHER', 'name' => 'Other'],
            ],
            // Seeded only for tenants in these countries. Names, never rates.
            'tax_types' => [
                'IN' => [
                    ['code' => 'CGST', 'name' => 'CGST'],
                    ['code' => 'SGST', 'name' => 'SGST'],
                    ['code' => 'UTGST', 'name' => 'UTGST'],
                    ['code' => 'IGST', 'name' => 'IGST'],
                ],
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
        // Phase 5 Part F/G: rule version statuses. Only VERIFIED rules may be used for production
        // finalization (see payroll.enforce_verified_rules).
        'verification_statuses' => ['draft' => 'Draft', 'review' => 'In review', 'verified' => 'Verified against an official source', 'superseded' => 'Superseded', 'rejected' => 'Rejected'],
        // Evidence for a rule must come from an official source: the URL host must end with one of
        // these suffixes. Blogs, payroll vendors and articles are never authoritative.
        'authoritative_domains' => [
            'IN' => ['gov.in', 'nic.in', 'epfindia.gov.in', 'epfo.gov.in', 'esic.gov.in', 'incometax.gov.in', 'incometaxindia.gov.in', 'tdscpc.gov.in', 'egazette.gov.in', 'indiacode.nic.in', 'labour.gov.in'],
            'AE' => ['gov.ae'],
        ],
        'jurisdictions' => ['IN' => 'India'],
        // Every state and union territory (codes as the statutory rules use them; Telangana is TG). Compliance reports
        // also read TS, CT, OR, UT, DN and DD as their aliases (ReportCatalogue::canonicalState).
        'states' => [
            'AN' => 'Andaman and Nicobar Islands', 'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh', 'AS' => 'Assam', 'BR' => 'Bihar', 'CH' => 'Chandigarh',
            'CG' => 'Chhattisgarh', 'DH' => 'Dadra and Nagar Haveli and Daman and Diu', 'DL' => 'Delhi', 'GA' => 'Goa', 'GJ' => 'Gujarat', 'HR' => 'Haryana',
            'HP' => 'Himachal Pradesh', 'JK' => 'Jammu and Kashmir', 'JH' => 'Jharkhand', 'KA' => 'Karnataka', 'KL' => 'Kerala', 'LA' => 'Ladakh', 'LD' => 'Lakshadweep',
            'MP' => 'Madhya Pradesh', 'MH' => 'Maharashtra', 'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram', 'NL' => 'Nagaland', 'OD' => 'Odisha',
            'PY' => 'Puducherry', 'PB' => 'Punjab', 'RJ' => 'Rajasthan', 'SK' => 'Sikkim', 'TN' => 'Tamil Nadu', 'TG' => 'Telangana', 'TR' => 'Tripura',
            'UP' => 'Uttar Pradesh', 'UK' => 'Uttarakhand', 'WB' => 'West Bengal',
        ],
        'tax_regimes' => ['new' => 'New regime (default)', 'old' => 'Old regime'],
        'financial_year_start_month' => 4,
        // HROne parity PY-17: perquisite types (Form 12BA heads) for perquisites recorded against TDS.
        'perquisite_types' => [
            'accommodation' => 'Accommodation', 'car' => 'Cars / other automotive', 'domestic_help' => 'Sweeper, gardener, watchman or personal attendant',
            'utilities' => 'Gas, electricity, water', 'concessional_loan' => 'Interest-free or concessional loans', 'holiday' => 'Holiday expenses',
            'travel' => 'Free or concessional travel', 'meals' => 'Free meals', 'education' => 'Free education', 'gift' => 'Gifts, vouchers, etc.',
            'credit_card' => 'Credit card expenses', 'club' => 'Club expenses', 'movable_assets' => 'Use of movable assets by the employee',
            'asset_transfer' => 'Transfer of assets to the employee', 'esop' => 'Stock options (ESOP)', 'retirement_contribution' => 'Employer contribution to funds above the limit (s.17(2)(vii))',
            'other' => 'Other benefits or amenities',
        ],

        // Phase 5 Part B: extensible registration catalogue. level = establishment (required) or
        // entity (establishment optional). Adding a type needs no schema change.
        'authorities' => ['EPFO' => 'Employees\' Provident Fund Organisation', 'ESIC' => 'Employees\' State Insurance Corporation', 'INCOME_TAX' => 'Income Tax Department', 'STATE_PT' => 'State professional tax authority', 'STATE_LWF' => 'State labour welfare board', 'STATE_LABOUR' => 'State labour department', 'GST' => 'Goods and Services Tax Network'],
        'registration_types' => [
            'epf_establishment_code' => ['label' => 'EPF establishment code', 'authority' => 'EPFO', 'level' => 'establishment', 'statute' => 'EPF'],
            'epf_exemption' => ['label' => 'EPF exemption (exempted establishment)', 'authority' => 'EPFO', 'level' => 'establishment', 'statute' => 'EPF'],
            'eps_exemption' => ['label' => 'EPS exemption', 'authority' => 'EPFO', 'level' => 'establishment', 'statute' => 'EPF'],
            'edli_exemption' => ['label' => 'EDLI exemption', 'authority' => 'EPFO', 'level' => 'establishment', 'statute' => 'EPF'],
            'esic_employer_code' => ['label' => 'ESIC employer code', 'authority' => 'ESIC', 'level' => 'establishment', 'statute' => 'ESI'],
            'esic_region' => ['label' => 'ESIC regional / sub-regional office', 'authority' => 'ESIC', 'level' => 'establishment', 'statute' => 'ESI'],
            'tan' => ['label' => 'TAN', 'authority' => 'INCOME_TAX', 'level' => 'entity', 'statute' => 'TDS'],
            'pan' => ['label' => 'PAN (deductor)', 'authority' => 'INCOME_TAX', 'level' => 'entity', 'statute' => 'TDS'],
            'pt_registration_certificate' => ['label' => 'PT registration certificate (employer)', 'authority' => 'STATE_PT', 'level' => 'establishment', 'statute' => 'PT', 'state_required' => true],
            'pt_enrolment_certificate' => ['label' => 'PT enrolment certificate', 'authority' => 'STATE_PT', 'level' => 'entity', 'statute' => 'PT', 'state_required' => true],
            'lwf_registration' => ['label' => 'Labour welfare fund registration', 'authority' => 'STATE_LWF', 'level' => 'establishment', 'statute' => 'LWF', 'state_required' => true],
            'shops_establishment' => ['label' => 'Shops & establishments registration', 'authority' => 'STATE_LABOUR', 'level' => 'establishment', 'statute' => null, 'state_required' => true],
            // HROne setup parity (Company → GST numbers): one GSTIN per state registration. Its format is data checked by
            // App\Domain\Compliance\Support\RegistrationNumberFormat: 15 characters, the Luhn mod 36 check character, and the
            // state code it starts with (the GST state code list; Andhra Pradesh 37, earlier 28; the merged Dadra and Nagar
            // Haveli and Daman and Diu 26, earlier 25 for Daman and Diu). An identifier format, not a statutory rate; no payroll
            // statute reads it.
            'gstin' => ['label' => 'GSTIN (GST registration)', 'authority' => 'GST', 'level' => 'establishment', 'statute' => null, 'state_required' => true,
                'pattern' => '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', 'format_hint' => '15 characters: the 2-digit state code, the PAN, the entity number, Z and a check character',
                'check' => 'luhn_mod36',
                'state_prefixes' => [
                    'JK' => ['01'], 'HP' => ['02'], 'PB' => ['03'], 'CH' => ['04'], 'UK' => ['05'], 'HR' => ['06'], 'DL' => ['07'], 'RJ' => ['08'], 'UP' => ['09'],
                    'BR' => ['10'], 'SK' => ['11'], 'AR' => ['12'], 'NL' => ['13'], 'MN' => ['14'], 'MZ' => ['15'], 'TR' => ['16'], 'ML' => ['17'], 'AS' => ['18'],
                    'WB' => ['19'], 'JH' => ['20'], 'OD' => ['21'], 'CG' => ['22'], 'MP' => ['23'], 'GJ' => ['24'], 'DH' => ['26', '25'], 'MH' => ['27'], 'AP' => ['37', '28'],
                    'KA' => ['29'], 'GA' => ['30'], 'LD' => ['31'], 'KL' => ['32'], 'TN' => ['33'], 'PY' => ['34'], 'AN' => ['35'], 'TG' => ['36'], 'LA' => ['38'],
                ]],
        ],
        // HROne setup parity (Designation → statutory skill): the minimum-wage skill class of a designation. A vocabulary
        // only: minimum wage rates per class come from the state rule versions, never from here.
        'wage_skill_categories' => ['unskilled' => 'Unskilled', 'semi_skilled' => 'Semi-skilled', 'skilled' => 'Skilled', 'highly_skilled' => 'Highly skilled'],
        // Phase 5 statutory outputs: one generator per return type (StatutoryReturnGenerator).
        'return_types' => [
            'EPF' => ['label' => 'EPF — Electronic Challan-cum-Return', 'form_code' => 'ECR', 'authority' => 'EPFO', 'scope' => 'establishment', 'generator' => EpfReturns::class, 'filing_totals' => ['ee_share', 'eps_share', 'er_share']],
            'ESI' => ['label' => 'ESI — monthly contribution', 'form_code' => 'ESI_MC', 'authority' => 'ESIC', 'scope' => 'establishment', 'generator' => EsiReturns::class, 'filing_totals' => ['ee_contribution', 'er_contribution']],
            'PT' => ['label' => 'Professional tax return', 'form_code' => 'PT', 'authority' => 'STATE_PT', 'scope' => 'establishment', 'generator' => ProfessionalTaxReturns::class, 'filing_totals' => ['pt_amount']],
            'TDS' => ['label' => 'TDS on salary — Form No. 138 (earlier Form 24Q)', 'form_code' => 'FORM_138', 'authority' => 'INCOME_TAX', 'scope' => 'legal_entity', 'generator' => TdsQuarterlyReturns::class, 'filing_totals' => ['tax_deducted', 'tax_deposited']],
            'LWF' => ['label' => 'Labour welfare fund return', 'form_code' => 'LWF', 'authority' => 'STATE_LWF', 'scope' => 'establishment', 'generator' => LwfReturns::class, 'filing_totals' => ['ee_contribution', 'er_contribution']],
        ],
        // Compliance reports: the builder behind each catalogue entry's `builder` key (database/data/compliance/reports/in.php).
        'report_builders' => [
            'employee_register' => EmployeeRegister::class, 'muster_roll' => MusterRoll::class, 'wage_register' => WageRegister::class,
            'deduction_register' => DeductionRegister::class, 'overtime_register' => OvertimeRegister::class, 'leave_register' => LeaveRegister::class,
            'pf_challan' => PfChallan::class, 'esi_challan' => EsiChallan::class, 'esi_contribution_period' => EsiContributionPeriod::class,
            'pt_statement' => ProfessionalTaxStatement::class, 'lwf_statement' => LabourWelfareFundStatement::class, 'tds_statement' => TdsStatement::class,
            'salary_variance' => SalaryVariance::class, 'joiners_leavers' => JoinersAndLeavers::class, 'maternity_register' => MaternityRegister::class,
            'bonus_register' => BonusRegister::class, 'gratuity_register' => GratuityRegister::class, 'holiday_list' => HolidayList::class,
            'equal_remuneration' => EqualRemuneration::class, 'return_working_sheet' => ReturnWorkingSheet::class, 'employment_return' => EmploymentReturn::class,
            'posh_annual_report' => PoshAnnualReport::class, 'combined_muster_wages' => CombinedMusterWages::class,
        ],
        // Phase 6.2: export layouts (fields, order, formats, encoding) live in the versioned
        // statutory_export_layouts registry (database/data/compliance/layouts). Only validation
        // settings that are not part of a file layout remain here.
        'formats' => [
            'ESI_MC' => ['ip_pattern' => '/^\d{10}$/'],
            'TDS_FORM_138' => ['section_code' => '392'],
        ],
        'return_statuses' => ['draft' => 'Draft', 'calculated' => 'Calculated', 'validated' => 'Validated', 'approved' => 'Approved', 'exported' => 'Exported (not filed)', 'submitted' => 'Submitted (filing recorded)', 'acknowledged' => 'Acknowledged', 'reconciliation_required' => 'Reconciliation required', 'reconciled' => 'Reconciled', 'revised' => 'Revised', 'cancelled' => 'Cancelled'],
        'registration_statuses' => ['active' => 'Active', 'inactive' => 'Inactive', 'cancelled' => 'Cancelled', 'superseded' => 'Superseded'],
        'registration_verification_statuses' => ['unverified' => 'Unverified', 'verified' => 'Verified against the certificate', 'rejected' => 'Rejected'],
        // Phase 5 Part D: the only options an establishment profile may carry (yes/no, never rates).
        'profile_settings' => [
            'EPF' => ['restrict_to_ceiling' => ['label' => 'Restrict PF to the statutory wage ceiling', 'default' => true]],
            'ESI' => [],
            'PT' => [],
            'LWF' => [],
            'TDS' => [],
        ],
    ],

    /*
    | HROne setup parity (Forms & templates → Code series): the document numbers a tenant can shape (NumberSeries).
    | Each code type has a built-in format equal to the one used before code series existed; `applicable_on` says
    | whether a company may have its own series (company) or only the organisation (enterprise); `model` and `column`
    | are where issued numbers live (a new counter continues after the highest one already used). Letter types get
    | their own optional `letter.<type>` code types, which fall back to `letter`. Prefix and suffix may hold {YYYY},
    | {YY}, {MM} and {FY} (financial year from April, e.g. 2026-27); numbering restarts when they change.
    */
    'numbering' => [
        'types' => [
            'loan' => ['label' => 'Loan code', 'module' => 'Payroll', 'applicable_on' => 'company', 'model' => Loan::class, 'column' => 'number', 'prefix' => 'LN-{YYYY}-', 'digits' => 5],
            'letter' => ['label' => 'Letter code (every letter type)', 'module' => 'Workforce', 'applicable_on' => 'company', 'model' => Letter::class, 'column' => 'number', 'prefix_from' => 'peopleos.letters.number_prefix', 'prefix' => 'LTR-{YYYY}-', 'digits' => 5],
            'exit_case' => ['label' => 'Exit case code', 'module' => 'Workforce', 'applicable_on' => 'company', 'model' => ExitCase::class, 'column' => 'number', 'prefix' => 'EXIT-{YYYY}-', 'digits' => 5],
            'alumni_request' => ['label' => 'Alumni request code', 'module' => 'Workforce', 'applicable_on' => 'enterprise', 'model' => AlumniRequest::class, 'column' => 'number', 'prefix' => 'ALR-{YYYY}-', 'digits' => 5],
            'ticket' => ['label' => 'Helpdesk ticket code', 'module' => 'Helpdesk', 'applicable_on' => 'enterprise', 'model' => Ticket::class, 'column' => 'number', 'prefix' => 'TKT-{YYYY}-', 'digits' => 5],
            // HROne setup parity EX-C/EX-D: expense receipts, reports and advances.
            'expense_receipt' => ['label' => 'Expense receipt code', 'module' => 'Expense', 'applicable_on' => 'company', 'model' => 'App\\Domain\\Expense\\Models\\ExpenseReceipt', 'column' => 'number', 'prefix' => 'RCP-{YYYY}-', 'digits' => 5],
            'expense_report' => ['label' => 'Expense report code', 'module' => 'Expense', 'applicable_on' => 'company', 'model' => 'App\\Domain\\Expense\\Models\\ExpenseReport', 'column' => 'number', 'prefix' => 'EXR-{YYYY}-', 'digits' => 5],
            'expense_advance' => ['label' => 'Expense advance code', 'module' => 'Expense', 'applicable_on' => 'company', 'model' => 'App\\Domain\\Expense\\Models\\ExpenseAdvance', 'column' => 'number', 'prefix' => 'EXA-{YYYY}-', 'digits' => 5],
            // HROne setup parity EX-F / AS-B: travel requests and asset requests.
            'travel_request' => ['label' => 'Travel request code', 'module' => 'Expense', 'applicable_on' => 'company', 'model' => 'App\\Domain\\Expense\\Models\\TravelRequest', 'column' => 'number', 'prefix' => 'TRV-{YYYY}-', 'digits' => 5],
            'asset_request' => ['label' => 'Asset request code', 'module' => 'Asset', 'applicable_on' => 'company', 'model' => 'App\\Domain\\Assets\\Models\\AssetRequest', 'column' => 'number', 'prefix' => 'AR-{YYYY}-', 'digits' => 5],
        ],
    ],

    /*
    | Leave engine (§25, §26). Types every new tenant receives; policies decide entitlements.
    */
    /*
    | HROne parity HP-2: loans and salary advances (types offered by the loan policy).
    */
    'loans' => [
        'types' => ['salary_advance' => 'Salary advance', 'personal' => 'Personal loan', 'emergency' => 'Emergency loan', 'vehicle' => 'Vehicle loan', 'housing' => 'Housing loan', 'education' => 'Education loan'],
    ],

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
            // HROne setup parity BP-13: the company's debit account numbers (encrypted, hidden, masked in audit).
            'App\\Domain\\Payroll\\Models\\CompanyBankAccount' => ['account_number'],
            EmployeeStatutoryDetail::class => ['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number'],
            StatutoryRegistration::class => ['registration_number'],
            EpfReturnEntry::class => ['uan'],
            EsiReturnEntry::class => ['ip_number'],
            TdsQuarterlyReturnEntry::class => ['pan'],
            TdsProfile::class => ['responsible_person_pan'],
            User::class => ['password', 'app_authentication_secret', 'app_authentication_recovery_codes'],
            SsoConnection::class => ['client_secret'],
            // HROne parity SV-26: the previous signing secret during a rotation's grace period (encrypted, hidden, audit-excluded).
            WebhookEndpoint::class => ['secret', 'previous_secret'],
            // Phase 7: manager-private one-on-one notes (encrypted, hidden, masked in audit).
            OneOnOne::class => ['private_notes'],
            // Phase 8: assessor / manager private notes (encrypted, hidden, masked in audit).
            SkillAssessment::class => ['private_notes'],
            DevelopmentPlan::class => ['private_notes'],
            // Phase 9: confidential talent and succession notes (encrypted, hidden, masked in audit).
            TalentProfile::class => ['confidential_notes'],
            TalentAssessment::class => ['confidential_notes'],
            SuccessionPlan::class => ['confidential_notes'],
            Successor::class => ['confidential_notes'],
            // Phase 11: internal compensation proposal notes (encrypted, masked in audit, never shown to the employee).
            CompensationChange::class => ['internal_notes'],
            // HROne setup parity EX-F: the visa and passport numbers on a travel request (encrypted, masked in audit).
            'App\\Domain\\Expense\\Models\\TravelRequest' => ['visa_number', 'passport_number'],
            // HROne parity MS-3: the new values of a self-service profile change until it is decided (encrypted, hidden,
            // never audited; purged on approval, rejection or withdrawal).
            'App\\Domain\\People\\Models\\ProfileChangeRequest' => ['payload'],
        ],
        'financial' => [
            // Phase 11: employee compensation, compensation changes and pay ranges (amounts masked in audit).
            EmployeeSalaryAssignment::class, CompensationChange::class, CompensationRange::class, CompensationBudget::class,
            PayrollEntry::class, ParallelPayrollLine::class,
            Payslip::class, FinalSettlement::class, LearningCost::class,
            // Phase 10: workforce budgets (amounts masked in audit; workforce.costs only).
            WorkforceBudget::class,
        ],
        'statutory' => [EmployeeTaxDeclaration::class, CompanyStatutoryProfile::class, EstablishmentStatutoryProfile::class, EmployeeEstablishmentAssignment::class, StatutoryReturn::class, StatutorySnapshot::class, EpfReturnEntry::class, TdsAnnualLedger::class, TdsCertificate::class, TdsEmployeeInvestment::class],
        'confidential' => [
            Grievance::class, ImprovementPlan::class,
            OneOnOne::class, BgvCase::class, EmployeeDocument::class,
            FeedbackEntry::class, PerformanceCheckIn::class,
            CalibrationSession::class, CalibrationAdjustment::class,
            ImprovementPlanCheckpoint::class,
            SkillAssessment::class, LearningEvidence::class,
            // Phase 9: talent and succession records are confidential (explicit permissions, audited reads).
            TalentProfile::class, TalentPoolMembership::class,
            TalentAssessment::class, TalentReviewItem::class,
            SuccessionPlan::class, Successor::class,
            ReadinessAssessment::class,
            // Phase 10: workforce plans and scenarios are management-confidential planning records.
            WorkforcePlanVersion::class, WorkforcePlanLine::class, WorkforceScenario::class,
            // Phase 11: compensation cycles (proposed pay for many people) are management-confidential.
            CompensationCycle::class,
        ],
    ],

    'audit' => [
        // Attributes never diffed into audit_event_changes.
        'ignored_attributes' => ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'password'],
        // Attribute names masked in audit_event_changes unless the model says otherwise.
        'sensitive_attributes' => ['password', 'account_number', 'pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number', 'salary'],
        'mask' => '••••',
        // SaaS.8: peopleos:audit:verify runs daily and records each chain's result; older than this, readiness says STALE.
        'verification_max_age_hours' => max(1, (int) env('PEOPLEOS_AUDIT_VERIFICATION_MAX_AGE_HOURS', 48)),
    ],

    // UX.18 visual regression: a frozen clock for the disposable *_visual_showcase database only (never in production),
    // so dates, greetings and "x minutes ago" are identical on every run. See tests/visual/README.md.
    'visual' => [
        'frozen_now' => env('PEOPLEOS_VISUAL_FROZEN_NOW'),
    ],
];
