// The Organisation Administrator course: learning groups, modules, and which application screens belong to each module.
// Screens are assigned from what the application itself shows the organisation administrator (tools/.work/discovery.json,
// tools/.work/crawl.json): by rail section and Filament navigation group, with a few classes moved by name where a
// navigation group mixes topics (Lifecycle, Payroll, Compliance). Nothing provided only to Markedge's platform operators
// is in scope: the application does not show those screens to an organisation administrator.
'use strict';

const GROUPS = [
    { id: 'A', name: 'Getting started' },
    { id: 'B', name: 'Set up your organisation' },
    { id: 'C', name: 'People' },
    { id: 'D', name: 'Time and leave' },
    { id: 'E', name: 'Pay' },
    { id: 'F', name: 'Talent' },
    { id: 'G', name: 'Organisation services' },
    { id: 'H', name: 'Insights and governance' },
    { id: 'I', name: 'Running PeopleOS' },
];

// code, number, group, short name (card / chip), colour key (design.js PALETTE), icon (Heroicons outline name)
const MODULES = [
    { code: 'M01', num: 1, group: 'A', name: 'Sign in, Home and navigation', color: 'iris', icon: 'home', desc: 'Sign in, read Home, find any page, search and act' },
    { code: 'M02', num: 2, group: 'A', name: 'My work and approvals', color: 'gold', icon: 'inbox-stack', desc: 'Inbox, approvals, tasks, requests and notifications' },
    { code: 'M03', num: 3, group: 'B', name: 'Organisation structure', color: 'green', icon: 'building-office-2', desc: 'Companies, entities, sites, units and the Designer' },
    { code: 'M04', num: 4, group: 'B', name: 'Masters and configuration', color: 'olive', icon: 'adjustments-horizontal', desc: 'Designations, grades, codes, fields, settings, packs' },
    { code: 'M05', num: 5, group: 'B', name: 'Users, access and integrations', color: 'violet', icon: 'shield-check', desc: 'Users, roles, scopes, security, SSO, API and webhooks' },
    { code: 'M06', num: 6, group: 'B', name: 'Workflows and policies', color: 'steel', icon: 'arrow-path-rounded-square', desc: 'Approval routes, workflow runs and policy versions' },
    { code: 'M07', num: 7, group: 'C', name: 'Employees and the employee profile', color: 'blue', icon: 'users', desc: 'Directory, register, hiring and the Employee 360' },
    { code: 'M08', num: 8, group: 'C', name: 'Joining, changes and letters', color: 'ocean', icon: 'user-plus', desc: 'Onboarding, checks, confirmation, transfers, letters' },
    { code: 'M09', num: 9, group: 'C', name: 'Exit and alumni', color: 'umber', icon: 'arrow-right-start-on-rectangle', desc: 'Exit cases, clearance, full and final, alumni' },
    { code: 'M10', num: 10, group: 'D', name: 'Attendance and time office', color: 'teal', icon: 'clock', desc: 'Shifts, rosters, punches, corrections, month end' },
    { code: 'M11', num: 11, group: 'D', name: 'Leave', color: 'pine', icon: 'calendar-days', desc: 'Leave types, requests, balances, encashment, year end' },
    { code: 'M12', num: 12, group: 'E', name: 'Compensation and salary structures', color: 'plum', icon: 'currency-rupee', desc: 'Components, structures, salaries and revisions' },
    { code: 'M13', num: 13, group: 'E', name: 'Payroll', color: 'deepIris', icon: 'banknotes', desc: 'Inputs, the guided run, approval, payslips, payment' },
    { code: 'M14', num: 14, group: 'E', name: 'Statutory compliance and tax', color: 'coral', icon: 'scale', desc: 'PF, ESI, PT, LWF, TDS, returns and declarations' },
    { code: 'M15', num: 15, group: 'E', name: 'Expense and travel', color: 'rose', icon: 'receipt-percent', desc: 'Categories, claims, travel, advances and payment' },
    { code: 'M16', num: 16, group: 'F', name: 'Performance', color: 'magenta', icon: 'chart-bar', desc: 'Goals, review cycles, ratings, calibration, 360' },
    { code: 'M17', num: 17, group: 'F', name: 'Learning, skills and talent', color: 'indigo', icon: 'academic-cap', desc: 'Courses, assignments, skills, careers, succession' },
    { code: 'M18', num: 18, group: 'G', name: 'Assets', color: 'bronze', icon: 'computer-desktop', desc: 'Asset types, register, assignment and recovery' },
    { code: 'M19', num: 19, group: 'G', name: 'Workforce planning', color: 'slate', icon: 'squares-2x2', desc: 'Positions, hierarchy, headcount plans, vacancies' },
    { code: 'M20', num: 20, group: 'G', name: 'Helpdesk, grievances and knowledge', color: 'cyan', icon: 'lifebuoy', desc: 'Service catalogue, HR queue, SLAs, cases, articles' },
    { code: 'M21', num: 21, group: 'G', name: 'Communication and engagement', color: 'berry', icon: 'megaphone', desc: 'Announcements, notifications, surveys, recognition' },
    { code: 'M22', num: 22, group: 'H', name: 'Reports and analytics', color: 'navy', icon: 'presentation-chart-line', desc: 'Report library, reports, dashboards and analytics' },
    { code: 'M23', num: 23, group: 'H', name: 'Audit, change history and data import', color: 'graphite', icon: 'document-magnifying-glass', desc: 'Change history, the audit chain and data import' },
    { code: 'M24', num: 24, group: 'I', name: 'Daily, monthly and annual routines', color: 'forest', icon: 'calendar', desc: 'What to check every day, month, quarter and year' },
    { code: 'M25', num: 25, group: 'I', name: 'Troubleshooting', color: 'wine', icon: 'wrench-screwdriver', desc: 'Common problems, their causes and safe fixes' },
    { code: 'M26', num: 26, group: 'I', name: 'Signing out and staying secure', color: 'ink', icon: 'lock-closed', desc: 'Sign out, devices, passwords and sensitive data' },
];

// Class basename → module, where the navigation group alone would put a screen in the wrong module.
const BY_CLASS = {
    // Lifecycle area: employee records vs joining and changes vs exit
    People: 'M07', TeamCalendar: 'M07', EmployeeResource: 'M07', BusinessPartnerResource: 'M07', BankAccountImportResource: 'M07',
    WorkforceUploadResource: 'M07', BulkRunResource: 'M07', WorkforceDesk: 'M07',
    UpcomingJoiners: 'M08', OnboardingPlanResource: 'M08', OnboardingTemplateResource: 'M08', BgvCaseResource: 'M08',
    ConfirmationReviewResource: 'M08', TransferRequestResource: 'M08', EmployeeContractResource: 'M08',
    // Pay: salary set-up and revision vs the payroll run vs tax
    SalaryComponentResource: 'M12', SalaryStructureResource: 'M12', SalaryStructureRegister: 'M12', SalaryImportResource: 'M12',
    IncrementCalculator: 'M12', VariablePayPlanResource: 'M12', VariablePayRequestResource: 'M12', BonusProcessing: 'M12',
    TaxDeclarationResource: 'M14', TaxIncomeEntryResource: 'M14', DeclareInvestments: 'M14', PfOptionResource: 'M14',
    StatutoryOverrideResource: 'M14', StatutoryProfileResource: 'M14',
    ParallelRunResource: 'M13', ExportLayoutResource: 'M13',
    ReimbursementBillResource: 'M15', ReimbursementClaimResource: 'M15',
    // Admin
    ConfigurationChangeResource: 'M04', DataImport: 'M23', RecentUploads: 'M23',
};

// (rail section, navigation group) → module
const BY_GROUP = {
    'work:*': 'M02', 'home:*': 'M01',
    'people:Exit': 'M09', 'people:Alumni': 'M09', 'people:Letters': 'M08', 'people:People': 'M07', 'people:Me': 'M07',
    'people:Attendance': 'M10', 'people:Leave': 'M11',
    'people:Compensation': 'M12', 'people:Payroll': 'M13', 'people:Compliance': 'M14', 'people:Expense & travel': 'M15',
    'people:Performance': 'M16', 'people:Learning': 'M17', 'people:Talent': 'M17',
    'organisation:Organisation': 'M03', 'organisation:Assets': 'M18', 'organisation:Workforce': 'M19',
    'workflows:*': 'M06', 'services:*': 'M20', 'communication:*': 'M21',
    'insights:Analytics': 'M22', 'insights:Audit': 'M23',
    'admin:People Setup': 'M04', 'admin:Customisation': 'M04', 'admin:Configuration': 'M04',
    'admin:Access': 'M05', 'admin:Enterprise': 'M05', 'admin:Integrations': 'M05', 'admin:Platform': 'M05', 'admin:Audit': 'M23',
};

const base = (cls) => String(cls ?? '').split('\\').pop();
/** Module code for a navigation module {class, group} in a rail section. */
function moduleOf(section, mod) {
    return BY_CLASS[base(mod.class)] ?? BY_GROUP[`${section}:${mod.group}`] ?? BY_GROUP[`${section}:*`] ?? null;
}

module.exports = { GROUPS, MODULES, BY_CLASS, BY_GROUP, moduleOf, base };
