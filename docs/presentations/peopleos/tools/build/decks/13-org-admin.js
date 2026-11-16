// Deck 13 — Organisation Administrator Training (modular, navigable).
//
// The customer's organisation administrator sets the tenant up and then runs it. This deck joins the set-up screens
// captured for deck 04 (a fictional customer, Northwind Retail, built from an empty tenant) with the operations
// screens of deck 03 (the fictional Demo Group), and re-presents them as 13 modules in five learning groups with
// the navigation system of build/modular.js. Module colours and brand values live in build/design.js.
//
// Screens are the genuine 21 October 2026 captures (tools/capture); nothing here is a mock-up.
'use strict';
const common = require('./common');
const flows = require('./flows');
const { ModularDeck } = require('../modular');

const COURSE = {
    groups: [
        { id: 'A', name: 'Set up the organisation' },
        { id: 'B', name: 'Manage people' },
        { id: 'C', name: 'Time and pay' },
        { id: 'D', name: 'Talent and rewards' },
        { id: 'E', name: 'Service and exit' },
    ],
    modules: [
        { code: 'M01', num: 1, group: 'A', color: 'deepIris', icon: 'rocket-launch', name: 'Start here',
            desc: 'Your role, this course, first sign-in, Admin Centre',
            intro: 'What an organisation administrator does, how to read this course, and the first sign-in to a new tenant.',
            who: 'The customer\'s first administrator (Account Owner or Tenant Super Admin) and anyone who will administer PeopleOS.',
            where: 'Your invitation e-mail, Home and Admin › All modules (Admin Centre).',
            objectives: ['Move around this course with Contents, the module bar and Previous / Next', 'Say what your role may and may not do', 'Activate your account and find any setting from the Admin Centre'],
            subs: [
                { id: '1.1', name: 'Using this course', desc: 'Navigation, the seven status labels and the release status.' },
                { id: '1.2', name: 'Your role', desc: 'What an organisation administrator owns; the administrator roles compared.' },
                { id: '1.3', name: 'First sign-in', desc: 'Accept the invitation, set a password, meet the empty tenant.' },
                { id: '1.4', name: 'Find your way', desc: 'The Admin Centre and the configuration finder.' },
            ], related: ['M02', 'M03'] },
        { code: 'M02', num: 2, group: 'A', color: 'blue', icon: 'building-office-2', name: 'Organisation structure',
            desc: 'Company, entities, sites, units, jobs, positions',
            intro: 'Build the structure people will sit in: the company, its legal employers and registered sites, the units and the job architecture.',
            who: 'Account Owner, Tenant Super Admin or Tenant HR Admin (company.*, organisation.*, people_setup.*).',
            where: 'Organisation › Companies, Legal entities, Establishments, Locations, units; Organisation Designer; Admin › Setup › Designations.',
            objectives: ['Create the company and complete its legal entity and establishments', 'Build the unit tree in the Organisation Designer', 'Set up designations and, if needed, positions'],
            subs: [
                { id: '2.1', name: 'Company', desc: 'The first record of a new tenant: name, code, legal name, effective date.' },
                { id: '2.2', name: 'Legal entities and establishments', desc: 'Legal employers and the registered places of work that drive state rules.' },
                { id: '2.3', name: 'Locations', desc: 'Physical sites for people, assets, attendance and holiday rules.' },
                { id: '2.4', name: 'Units and the Organisation Designer', desc: 'Business units and departments, placed in one effective-dated tree.' },
                { id: '2.5', name: 'Job architecture and positions', desc: 'Seeded reference lists, designations, and positions for seat-based headcount.' },
            ], related: ['M03', 'M04', 'M05'] },
        { code: 'M03', num: 3, group: 'A', color: 'violet', icon: 'shield-check', name: 'Users, roles & access',
            desc: 'Roles, invitations, access tests, security and audit',
            intro: 'Decide who may do what, invite people with the right reach, prove it by signing in as them, and keep the audit trail.',
            who: 'Tenant Super Admin (role permissions) and Account Owner or Tenant HR Admin (users, scopes, security settings).',
            where: 'Admin › Setup › Roles and Users; Security policy; Settings; Insights › Audit › Change history.',
            objectives: ['Invite a user with the right roles and access scope', 'Test access by signing in as each role', 'Require MFA and verify the audit chain'],
            subs: [
                { id: '3.1', name: 'Roles and permissions', desc: 'The fourteen system roles and what each permission key allows.' },
                { id: '3.2', name: 'Invite users', desc: 'Work e-mail, roles and access scope; the invitation and the Users list.' },
                { id: '3.3', name: 'Test access', desc: 'Sign in as each role: a refusal is a pass.' },
                { id: '3.4', name: 'Security and settings', desc: 'Security policy, settings and feature flags, and their known limits.' },
                { id: '3.5', name: 'Audit and change governance', desc: 'Change history, one event in detail, the Change Centre and the chain check.' },
            ], related: ['M01', 'M02', 'M05'] },
        { code: 'M04', num: 4, group: 'A', color: 'teal', icon: 'adjustments-horizontal', name: 'Policies & set-up',
            desc: 'Shifts, holidays, leave, pay, statutory, workflows',
            intro: 'Configure the rules people work under: time, leave, pay components, statutory registrations, workflows and notifications.',
            who: 'Tenant HR Admin with the attendance, leave, payroll and workflow permissions; Payroll Admin for pay.',
            where: 'Time & leave › Shifts, Work schedules, Holiday calendars; Policies; Pay › Salary components; Statutory registrations; Workflows.',
            objectives: ['Set shifts, a work schedule and a holiday calendar', 'Publish a leave policy version with entitlements', 'Record statutory registrations and build a workflow'],
            subs: [
                { id: '4.1', name: 'Shifts, schedules and holidays', desc: 'Shift times and grace, the weekly pattern, and holiday calendars by rule.' },
                { id: '4.2', name: 'Leave policy', desc: 'Seeded leave types, a draft policy version, entitlements, publish.' },
                { id: '4.3', name: 'Pay components and structures', desc: 'Formula-based salary components and versioned salary structures.' },
                { id: '4.4', name: 'Statutory registrations', desc: 'Registrations per establishment, statutory profiles, rule verification.' },
                { id: '4.5', name: 'Workflows and notifications', desc: 'Triggers, the step builder, versions, and the notification gap.' },
            ], related: ['M07', 'M08', 'M09'] },
        { code: 'M05', num: 5, group: 'B', color: 'iris', icon: 'users', name: 'People records',
            desc: 'Employee 360, hiring, job changes and bulk import',
            intro: 'One lifetime record per person: find it, read it, hire without duplicates and change jobs and managers with an audit trail.',
            who: 'Tenant HR Admin, HR Manager and HR Executive (employee.*); imports need employee.import.',
            where: 'People › Directory; People › Lifecycle › Employees; the Employee 360 of each person.',
            objectives: ['Read an Employee 360 and know which tabs need which permission', 'Hire a person and stop a duplicate at the first step', 'Change a job or a manager, effective-dated and audited'],
            subs: [
                { id: '5.1', name: 'Directory and register', desc: 'Find anyone you may see; the preview and the Employees register.' },
                { id: '5.2', name: 'Employee 360', desc: 'Header, Action and More menus, Journey and the Records tabs.' },
                { id: '5.3', name: 'Hire an employee', desc: 'Four guided steps with a duplicate check before anything is created.' },
                { id: '5.4', name: 'Job, manager and lifecycle changes', desc: 'Context, Change, Review; same-day changes; lifecycle states.' },
                { id: '5.5', name: 'Bulk import', desc: 'Upload, inspect, map, validate, approve, run: and where it stops today.' },
                { id: '5.6', name: 'Troubleshooting', desc: 'Errors and refusals you may meet on people records, and what to do.' },
            ], related: ['M02', 'M06', 'M13'] },
        { code: 'M06', num: 6, group: 'B', color: 'olive', icon: 'user-plus', name: 'Onboarding & documents',
            desc: 'Onboarding plans and tasks; upload and verify documents',
            intro: 'A plan starts on hire and sends tasks to HR, the manager and the new joiner; documents are uploaded by one person and verified by another.',
            who: 'Tenant HR Admin and HR Manager (onboarding.*, document.*); managers complete their own tasks.',
            where: 'People › Onboarding › Plans and Templates; Employee 360 › Records › Operations › Documents.',
            objectives: ['Read an onboarding plan and complete or skip tasks', 'Maintain an onboarding template', 'Upload a document and have a second person verify it'],
            subs: [
                { id: '6.1', name: 'Onboarding plans and tasks', desc: 'Plans, templates, tasks by phase, and the manager\'s inbox.' },
                { id: '6.2', name: 'Employee documents', desc: 'Upload, pending verification, and separation of duties.' },
            ], related: ['M05', 'M03'] },
        { code: 'M07', num: 7, group: 'C', color: 'green', icon: 'clock', name: 'Attendance',
            desc: 'Manual punches, day processing and exceptions',
            intro: 'Punches become attendance records when a day is processed; HR records missing punches and works the exceptions.',
            who: 'Tenant HR Admin and Attendance Admin (attendance.manage).',
            where: 'People › Time & leave › Attendance records, Punches and the Exception centre.',
            objectives: ['Record a manual punch with a reason', 'Process a day and read the records it creates', 'Work the exception centre and the raw punches'],
            subs: [
                { id: '7.1', name: 'Daily attendance administration', desc: 'The daily routine, manual punches, processing a day and exceptions.' },
            ], related: ['M04', 'M08', 'M09'] },
        { code: 'M08', num: 8, group: 'C', color: 'gold', icon: 'calendar-days', name: 'Leave',
            desc: 'Balances, ledger, types, policies and requests',
            intro: 'Balances come from an append-only ledger; types and versioned policies set the rules; HR sees and acts on every request.',
            who: 'Tenant HR Admin and HR Manager (leave.*).',
            where: 'People › Time & leave › Balances, Ledger, Leave types, Policies, Leave requests, Leave calendar.',
            objectives: ['Read and adjust a balance, and explain how it is calculated', 'Maintain leave types and policy versions', 'Handle requests, including on an employee\'s behalf'],
            subs: [
                { id: '8.1', name: 'Balances and the ledger', desc: 'What each balance figure means, adjustments, and the append-only ledger.' },
                { id: '8.2', name: 'Leave types and policies', desc: 'Type flags, locked codes, and entitlements on policy versions.' },
                { id: '8.3', name: 'Leave requests', desc: 'Everyone\'s requests, applying on behalf, the calendar and known defects.' },
            ], related: ['M04', 'M07', 'M09'] },
        { code: 'M09', num: 9, group: 'C', color: 'coral', icon: 'banknotes', name: 'Payroll & statutory',
            desc: 'Runs, structures, inputs, rule verification',
            intro: 'Configure, calculate and check payroll runs. Finalising stays blocked until the platform verifies the statutory rules.',
            who: 'Payroll Admin and Tenant HR Admin (payroll.*, compliance.*).',
            where: 'People › Pay › Payroll control room, Payroll runs, Salary components and structures, Adjustments; Compliance control room.',
            objectives: ['Open, calculate and validate a payroll run', 'Read issues, the breakdown and the Payroll Auditor', 'Explain why finalising is blocked and who unblocks it'],
            subs: [
                { id: '9.1', name: 'The payroll run', desc: 'Control room, runs, calculate, issues, validate, and the auditor.' },
                { id: '9.2', name: 'Structures and inputs', desc: 'Components, structure versions, adjustments and tax declarations.' },
                { id: '9.3', name: 'Statutory compliance', desc: 'Rules, verification, registrations, profiles, notices and returns.' },
            ], related: ['M04', 'M11', 'M13'] },
        { code: 'M10', num: 10, group: 'D', color: 'plum', icon: 'chart-bar', name: 'Performance & workforce',
            desc: 'Cycles, calibration, goals, learning, positions',
            intro: 'Run review cycles and calibration, follow goals and learning, and manage positions and the organisation map.',
            who: 'Tenant HR Admin and Performance Admin; HR Manager for learning and goals views.',
            where: 'Growth › Performance cycles, Appraisals, Calibration, Goals; Learning; Workforce › Positions, Org map.',
            objectives: ['Read a performance cycle and its appraisals', 'Use the calibration board before ratings are final', 'Create a draft position and read the hierarchy'],
            subs: [
                { id: '10.1', name: 'Performance cycles and calibration', desc: 'Cycles, the new-cycle form, appraisals and the calibration board.' },
                { id: '10.2', name: 'Goals and learning', desc: 'The goal cascade and mandatory learning assignments.' },
                { id: '10.3', name: 'Positions and the org map', desc: 'Draft positions, the org map and the position hierarchy.' },
            ], related: ['M02', 'M11'] },
        { code: 'M11', num: 11, group: 'D', color: 'ocean', icon: 'currency-rupee', name: 'Compensation',
            desc: 'Propose, review, approve and execute pay changes',
            intro: 'A pay change goes through four different people: propose, review, approve and execute, each step audited.',
            who: 'Propose and review: HR Manager or Tenant HR Admin. Approve: a third person. Execute: Payroll Admin.',
            where: 'Employee 360 › Records › Rewards › Propose compensation change; People › Pay › Compensation changes.',
            objectives: ['Propose a compensation change with a reason', 'Explain why the reviewer cannot also approve', 'Check the new CTC and the size of the change'],
            subs: [
                { id: '11.1', name: 'The four-person flow', desc: 'Refusals, propose, review, approve and execute, verified end to end.' },
                { id: '11.2', name: 'Worked example', desc: 'The training change from 24,00,000 to 26,40,000, step by step.' },
            ], related: ['M09', 'M10'] },
        { code: 'M12', num: 12, group: 'E', color: 'slate', icon: 'lifebuoy', name: 'Letters, assets & requests',
            desc: 'Letters, asset custody and the HR request queue',
            intro: 'Issue letters with approval where a template needs it, keep custody of company assets, and answer employees\' requests.',
            who: 'Tenant HR Admin and HR Manager (letter.*, asset.*, servicedesk.*); Asset Admin for the register.',
            where: 'People › Letters; Assets › Register; Services › HR requests (the queue).',
            objectives: ['Generate a letter and have it approved by someone else', 'Assign an asset and see custody history', 'Take, answer and resolve an HR request'],
            subs: [
                { id: '12.1', name: 'Letters', desc: 'Generate from a template, approval by a second person, issue.' },
                { id: '12.2', name: 'Assets', desc: 'The register, assigning custody, and the employee\'s My assets.' },
                { id: '12.3', name: 'HR requests', desc: 'Raised by an employee, worked in the queue, resolved with an answer.' },
            ], related: ['M06', 'M13'] },
        { code: 'M13', num: 13, group: 'E', color: 'umber', icon: 'arrow-right-start-on-rectangle', name: 'Exit & alumni',
            desc: 'Clearance, full and final settlement, alumni',
            intro: 'From resignation to clearance, full and final settlement, completion and a lifetime alumni record.',
            who: 'The employee resigns; manager, IT, finance, assets and HR clear stages; HR settles and completes.',
            where: 'You › My exit; People › Lifecycle › Exit cases; Alumni.',
            objectives: ['Take a leaver through clearance, including assets', 'Check a full and final settlement', 'Complete the exit and serve the alumni portal'],
            subs: [
                { id: '13.1', name: 'Resignation and clearance', desc: 'The resignation, the exit case, clearance stages and the asset block.' },
                { id: '13.2', name: 'Full and final settlement', desc: 'The settlement, its calculation, and completing the exit.' },
                { id: '13.3', name: 'Alumni', desc: 'The alumni list, the record after exit, and the alumni portal.' },
            ], related: ['M12', 'M09', 'M05'] },
    ],
};

module.exports = async (lib) => {
    const { has, entry } = lib;
    const d = new ModularDeck({
        num: 13, file: '13-PeopleOS-Organisation-Administrator-Training.pptx', short: 'Organisation administrator training',
        title: 'Organisation Administrator Training',
        subtitle: 'Set up your organisation in PeopleOS and run it: structure, access, policies, people, time, pay, talent, services and exits.',
        audience: 'The customer\'s organisation administrators: Account Owner, Tenant Super Admin and Tenant HR Admin, with HR and payroll leads',
    }, COURSE);
    const miss = (n) => lib.WARN.push(`deck 13: missing ${n}`);
    const shot = (name, o) => (has(name) ? d.shot({ img: name, ...o }) : miss(name));
    const wide = (name, o) => (has(name) ? d.wide({ img: name, ...o }) : miss(name));
    const cmp = (o) => (has(o.left.img) && has(o.right.img) ? d.compare(o) : miss(`${o.left.img} / ${o.right.img}`));
    const C = (n) => `client-onboarding/${n}`, H = (n) => `hr-admin/${n}`, E = (n) => `employee/${n}`;
    const NW = 'Executed on the training database on 21 Oct 2026: tenant "Northwind Retail Pvt Ltd" (fictional) provisioned by the platform operator and configured by its first administrator, Farah Khan (fictional).';
    const SRC = 'Executed on the training database on 21 Oct 2026 as Kavya Menon (Tenant HR Admin), Neha Kapoor (HR Manager) and Arjun Bose (Payroll Admin), fictional people at the fictional Demo Group.';
    const DEBUG = 'Shown here as the local debug page (development mode). In production the user sees a generic server-error dialog; the cause is the same.';
    const ROLES = 'SOURCE: config/peopleos.php role templates (account-owner, tenant-super-admin, tenant-hr-admin) and app/Filament/Resources/Users/Schemas/UserForm.php.';

    d.cover({ shot: C('90-onb-31-designer-tree'), kicker: 'MARKEDGE PEOPLEOS  ·  TRAINING LIBRARY  ·  DECK 13', date: 'Built from the running application (local, disposable database, fictional people). Screens captured 21 October 2026.',
        notes: `SAY: This course is for the people who set up an organisation in PeopleOS and then administer it. Every screen is a real capture from a practice tenant with invented people.\nNAVIGATE: Start opens the contents; How to use this course explains the navigation bar.\nEVIDENCE: ${NW} ${SRC}` });
    d.toc({ title: 'Contents' });

    // ================================================================ M01 Start here
    d.mod('M01');
    d.sub('1.1');
    d.howto();
    d.statusLegend();
    common.statusDetailed(d, 'Release status');
    d.sub('1.2');
    d.numbered({ title: 'What you will be able to do after this course', items: [
        ['Build the organisation', 'company, legal entities, establishments, locations, units, jobs'],
        ['Control access', 'roles, users and scopes; MFA; the audit trail'],
        ['Configure policies', 'time, leave, pay components, statutory registrations, workflows'],
        ['Run people operations', 'records, hiring, changes, onboarding, documents'],
        ['Administer time and pay', 'attendance, leave, payroll runs, statutory screens'],
        ['Handle talent, services and exits', 'cycles, compensation, letters, assets, requests, exits'],
    ], notes: 'SAY: The modules follow the order an organisation administrator needs them: structure before people, people before policies, everything before go-live. Group A is set-up; groups B to E are running the organisation.' });
    d.table({ title: 'The administrator roles compared', columns: ['Task', 'Account Owner', 'Tenant Super Admin', 'Tenant HR Admin'], colW: [4.43, 2.6, 2.5, 2.6], rows: [
        ['Company and organisation set-up', 'Yes', 'Yes', 'Yes'],
        ['Invite users and give roles', 'Yes (never Tenant Super Admin)', 'Yes', 'Yes (never Tenant Super Admin)'],
        ['Edit role permissions', 'No', 'Yes', 'No'],
        ['Settings and security policy', 'Yes', 'Yes', 'Yes'],
        ['Payroll, statutory, compensation', 'No: needs a specialist role', 'Yes', 'Yes'],
        ['Sensitive personal data (bank, identifiers)', 'No: needs a specialist role', 'Yes', 'Yes'],
        ['Audit trail; data export', 'Audit yes; export yes', 'Yes; yes', 'Audit yes; export no'],
    ], fontSize: 12, notes: `${ROLES}\nSAY: A self-service tenant's first administrator is the Account Owner: set-up and access, but payroll, statutory, compensation and sensitive data need a specialist role, given explicitly. A tenant provisioned by Markedge starts with a Tenant Super Admin. Only a Tenant Super Admin edits role permissions or gives that role.` });
    cmp({ title: 'Two administrators, two different jobs',
        left: { label: 'Markedge platform operator', img: C('90-onb-01-platform-home'), callouts: false, caption: 'Signs in with MFA and works outside any tenant: creates tenants, watches platform health. Never configures your HR data.' },
        right: { label: 'Your organisation administrator', img: C('90-onb-12-empty-tenant-home'), callouts: false, caption: 'Invited by the operator; configures the organisation, people, policies and access inside your tenant.' },
        notes: `SAY: The operator console and your tenant are the same application, but different accounts with different permissions. Operators enter a tenant only explicitly, and every action is audited.\nEVIDENCE: ${NW}` });
    d.sub('1.3');
    cmp({ title: 'Activate your account',
        left: { label: 'Accept your invitation', img: C('90-onb-08-accept-invitation'), callouts: ['1', '2', '3'], caption: 'Choose a password, confirm it, then Activate my account.' },
        right: { label: 'Password rules', tone: 'bad', img: C('90-onb-09-password-rules'), callouts: ['1'], caption: '"The password field must be at least 8 characters." The account stays invited until a valid password is set.' } });
    cmp({ title: 'Ready to sign in; the link cannot be reused',
        left: { label: 'After activation', tone: 'good', img: C('90-onb-10-account-ready-sign-in'), callouts: false, caption: '"Your account is ready. Sign in with your new password."' },
        right: { label: 'Same link opened again', tone: 'bad', img: C('90-onb-11-invitation-used'), callouts: false, caption: '"This invitation link is invalid or has expired. Ask your administrator to send a new one."' },
        notes: 'SUPPORT: an expired link is re-sent by the operator (Send invitation on the Tenants list) or, for users you invited, from Admin › Setup › Users.' });
    shot(C('90-onb-12-empty-tenant-home'), { title: 'First sign-in: an empty tenant',
        steps: ['Open Admin Centre: the administrator\'s starting point.', 'Governance warnings to act on (MFA not required; unscoped approvers).', 'At a glance: 1 active user, 14 system roles.'],
        result: 'The tenant comes with system roles, default settings, starter policies and seeded reference lists, but no organisation or people.',
        notes: 'SAY: Act on the governance items before inviting anyone else: require MFA (module 03) and plan who sees which company (access scopes, module 03).' });
    d.sub('1.4');
    d.navigate({ title: 'Every module, by area: the Admin Centre', path: ['Admin', 'All modules'], img: C('90-onb-13-admin-centre'), callouts: false,
        steps: ['Open Admin in the navigation and choose All modules.', 'Areas such as Organisation and positions, Access and security, and the Change Centre group the set-up screens.', 'Type a module name in the search box to jump straight to it.'],
        tip: 'Bookmark the Admin Centre: every set-up module in this course starts from it.' });
    shot(C('90-onb-14-configuration-finder'), { title: 'Not sure where a setting is? Ask the finder',
        steps: ['Organisation structure.', 'Statutory compliance.', 'Access and security.'], result: 'Admin › Setup › What do you want to configure? groups set-up screens and individual settings by topic.' });
    d.checklist({ items: [
        ['Account activated with a strong password', 'Invitation accepted once; the link cannot be reused.'],
        ['Governance warnings read on Home', 'MFA requirement and unscoped approvers noted for module 03.'],
        ['Role decided for each administrator', 'Who is Account Owner, Tenant Super Admin, Tenant HR Admin, Payroll Admin.'],
        ['Admin Centre and finder located', 'You can reach any set-up screen from Admin › All modules.'],
        ['Release status understood', 'Payroll finalising and imports are blocked today; plan around them.'],
    ] });
    d.summary({ points: [
        'Contents (top left) is on every slide; the module chip returns to the module start; the section name opens the module contents.',
        'Your administrator role decides what you can configure; payroll and sensitive data need a specialist role.',
        'Invitations are single-use; passwords need at least 8 characters.',
        'A new tenant has roles, settings and starter policies, but no organisation or people.',
        'Start every set-up task from the Admin Centre or the configuration finder.',
    ] });

    // ================================================================ M02 Organisation structure
    d.mod('M02');
    d.sub('2.1');
    d.navigate({ title: 'Where the organisation lives', path: ['Organisation', 'Companies'], img: C('90-onb-15-companies-empty'), callouts: false,
        steps: ['Open Organisation, then Companies.', 'A new tenant has no company yet: start with New company.', 'Legal entities, establishments, locations and units follow, in that order.'],
        tip: 'Structure first, then people, then policies: each step uses what the one before created.' });
    if (has(C('90-onb-16-new-company'))) d.fields({ title: 'Create the company', img: C('90-onb-16-new-company'), crop: { x: 384, y: 330, w: 1316, h: 1080 }, fields: [
        { n: 1, name: 'Name', rule: 'Required', what: 'The company\'s everyday name, as people should see it in lists and on records.' },
        { n: 2, name: 'Code', rule: 'Required', what: 'Short and unique: letters, numbers and dashes only (e.g. NWR). Used in lists and codes.' },
        { n: 3, name: 'Legal name', rule: 'Optional', what: 'The registered name, if it differs from the everyday name.' },
        { n: 4, name: 'Effective from', rule: 'Optional', what: 'The date the company exists in PeopleOS from; history is kept by date.' },
        { n: 5, name: 'Create company', what: 'Saves the company and creates its primary legal entity automatically.' },
    ], notes: `SOURCE: app/Filament/Resources/Companies/Schemas/CompanyForm.php (name and code required; code alpha-dash, max 32). EVIDENCE: ${NW}` });
    shot(C('90-onb-17-company-saved'), { title: 'Company saved', steps: ['The new company with its code and effective date.'], result: 'Northwind Retail (NWR), effective 01 Apr 2026.' });
    d.sub('2.2');
    shot(C('90-onb-18-new-legal-entity'), { title: 'Add a further legal employer (only if needed)', steps: [], result: 'Creating the company already created a primary legal entity; complete it, and add another only for an additional legal employer. A review of the values appears before Create.' });
    shot(C('90-onb-19-legal-entity-saved'), { title: 'Legal entities listed', steps: ['The legal entity with its company and status.'], result: 'The training run shows the automatic primary entity and one added by hand. For a single employer, complete the automatic entity instead.' });
    shot(C('90-onb-20-new-establishment'), { title: 'Add a registered establishment', steps: [], result: 'A registered place of work; its state drives state-level rules (professional tax, labour welfare fund). A principal establishment already exists for the company.' });
    shot(C('90-onb-21-establishment-saved'), { title: 'Establishment listed', steps: ['Bengaluru head office under the legal entity.'], result: 'Statutory registrations and profiles attach to establishments (module 04).' });
    d.sub('2.3');
    shot(C('90-onb-22-new-location'), { title: 'Add the physical location', callouts: false, steps: [], result: 'A physical site for people, assets and attendance; used in the hire wizard and in holiday and schedule rules.' });
    shot(C('90-onb-23-location-saved'), { title: 'Location listed', steps: ['Bengaluru HQ (BLR) for the company.'], result: 'Known limit: a location cannot be linked to an establishment on screen (F10).' });
    d.sub('2.4');
    d.diagram({ title: 'The order in which the structure is built', flow: [['COMPANY', 'name and code'], ['LEGAL ENTITY', 'legal employer'], ['ESTABLISHMENT', 'registered site, state'], ['LOCATION', 'physical site'], ['UNITS', 'business units, departments'], ['DESIGNER', 'one tree']],
        caption: 'Each record uses the one before it: establishments hang off a legal entity, statutory registrations off an establishment, and the Organisation Designer places the units under the company. Units are effective-dated, so a reorganisation keeps its history.' });
    shot(C('90-onb-24-new-business-unit'), { title: 'Create a business unit', steps: ['Business unit details for the company.'], result: 'Retail Operations: the parent of the store departments.' });
    shot(C('90-onb-25-new-department'), { title: 'Create organisation units', steps: ['Company.', 'Name.', 'Effective from.'], result: 'Units are effective-dated; their place in the hierarchy is set in the Organisation Designer.' });
    shot(C('90-onb-26-departments'), { title: 'Departments listed', steps: ['Store Operations and People & Culture with their dates.'], result: 'Business units, divisions, teams, cost and profit centres follow the same pattern.' });
    shot(C('90-onb-29-designer-add-root'), { title: 'Organisation Designer: add the root', steps: ['Unit type: Company.', 'Use an existing unit.', 'Reason for change.'], result: 'The tree starts from the company.' });
    shot(C('90-onb-30-designer-add-child'), { title: 'Add units under their parents', steps: ['Unit type: Business Unit.', 'Existing unit: Retail Operations.', 'Reason for change.'], result: 'Each placement is recorded in the audit trail.' });
    cmp({ title: 'Check the tree and the chart',
        left: { label: 'Tree', img: C('90-onb-31-designer-tree'), callouts: ['1', '2'], caption: 'Company → business unit → department → team. Each node: Add child, Rename, Move, Deactivate, Remove from tree.' },
        right: { label: 'Chart', img: C('90-onb-32-designer-chart'), callouts: false, caption: 'The same structure as a chart. The Org map adds people once they are hired.' } });
    d.sub('2.5');
    shot(C('90-onb-33-seeded-employment-types'), { title: 'Start from the seeded reference lists', steps: ['Seeded value.', 'Add your own.'], result: 'Employment types, categories, work modes and levels L1–L6 are seeded; designations, grades and job families start empty.' });
    if (has(C('90-onb-34-new-designation'))) d.fields({ title: 'Add a designation', img: C('90-onb-34-new-designation'), crop: { x: 384, y: 300, w: 1416, h: 900 }, fields: [
        { n: 1, name: 'Name', rule: 'Required', what: 'The job title people hold, e.g. Store Manager.' },
        { n: 2, name: 'Code', rule: 'Required', what: 'A short code for the designation, e.g. SM.' },
        { n: 3, name: 'Level', rule: 'Optional', what: 'One of the seeded levels L1–L6 (or your own); used in reporting and access scopes.' },
        { n: 4, name: 'Effective from', rule: 'Optional', what: 'When the designation starts to exist; history is kept by date.' },
    ], notes: `SOURCE: app/Filament/Resources/Designations/Schemas/DesignationForm.php (name and code required). EVIDENCE: ${NW}` });
    shot(C('90-onb-35-designations'), { title: 'Designations listed', steps: ['Store Manager (L4), Retail Associate (L2), HR Executive (L3).'], result: 'These appear in the hire wizard\'s Position step.' });
    shot(C('90-onb-36-new-position'), { title: 'Positions (optional)', steps: ['Code.', 'Title.', 'Grade.', 'Seats.', 'FTE: leave blank (defect F8 refuses whole numbers).'], result: 'A draft position; it is proposed, approved and opened before it is filled.' });
    shot(C('90-onb-37-positions'), { title: 'Draft position listed', steps: ['POS-SM-01 in Draft.'], result: 'Use positions when you manage headcount by seat; otherwise skip them.' });
    d.checklist({ items: [
        ['Company created with a unique code', 'And its automatic primary legal entity completed.'],
        ['Further legal entities only for extra legal employers', 'One entity per legal employer, no more.'],
        ['Establishments for every registered place of work', 'Their state drives PT and LWF rules.'],
        ['Locations for every physical site', 'Used by attendance, holidays and assets.'],
        ['Units created and placed in the Designer', 'Tree and chart checked against your organisation chart.'],
        ['Designations (and grades) set up', 'Positions only if you manage headcount by seat.'],
    ] });
    d.summary({ points: [
        'Build in order: company, legal entity, establishment, location, units, then the Designer tree.',
        'The company creates its primary legal entity; add more only for extra legal employers.',
        'Establishments carry the state used by statutory rules; locations are physical sites.',
        'Units are effective-dated and every placement in the Designer is audited.',
        'Designations feed the hire wizard; positions are optional and start as drafts.',
    ] });

    // ================================================================ M03 Users, roles & access
    d.mod('M03');
    d.sub('3.1');
    shot(C('90-onb-54-roles'), { title: 'Roles: 14 system roles arrive with the tenant', steps: ['A system role with its permission and user counts.', 'New role (custom roles).'], result: '14 roles, from Account Owner to Tenant Super Admin. Only a Tenant Super Admin edits role permissions.' });
    shot(C('90-onb-55-role-permissions'), { title: 'What a role may do', steps: [], result: 'Permission keys grouped by module. Changes are audited with the keys granted and removed.' });
    d.cards({ title: 'Rules for giving roles', cards: [
        { head: 'Tenant Super Admin', body: 'Only an existing Tenant Super Admin (or Markedge) can give this role.', tone: 'warn' },
        { head: 'Custom roles', body: 'You can give a custom role only if you hold every permission it contains.', tone: 'info' },
        { head: 'Specialist roles', body: 'Payroll, statutory, compensation and sensitive data need a role given explicitly, never by default.', tone: 'info' },
        { head: 'Least privilege', body: 'Give each person the narrowest role that lets them work; add, never widen, when in doubt.', tone: 'good' },
    ], notes: `${ROLES}` });
    d.sub('3.2');
    if (has(C('90-onb-56-new-user'))) d.fields({ title: 'Invite a user with a role and a scope', img: C('90-onb-56-new-user'), crop: { x: 384, y: 300, w: 1776, h: 1200 }, fields: [
        { n: 1, name: 'Work e-mail', rule: 'Required', what: 'Unique across PeopleOS; the invitation goes here. Use the person\'s company address.' },
        { n: 2, name: 'Roles', rule: 'Optional', what: 'One or more roles; a custom role only if you hold all its permissions.' },
        { n: 3, name: 'Access scope', rule: 'Optional', what: 'Empty = whole tenant. Each choice narrows reach; choices combine with AND.' },
        { n: 4, name: 'Create user', what: 'Saves the user and sends the invitation; the user chooses their own password.' },
    ], notes: `SOURCE: app/Filament/Resources/Users/Schemas/UserForm.php (e-mail required and unique; access scope help text). EVIDENCE: ${NW}` });
    cmp({ title: 'Invited, then listed',
        left: { label: 'After Create user (development mode)', tone: 'bad', img: C('90-onb-57-user-created'), callouts: false, caption: 'The History panel errors (F26, non-production strict mode). The user was still created and invited, as the list shows.' },
        right: { label: 'Users', img: C('90-onb-58-users'), callouts: ['1'], caption: 'Farah Khan (Tenant Super Admin, Active) and Rohit Menon (HR Executive, Invited).' } });
    d.sub('3.3');
    cmp({ title: 'Check what the role really sees',
        left: { label: 'HR Executive Home', img: C('90-onb-59-hr-executive-home'), callouts: false, caption: 'A narrower menu than the administrator; Home shows people operations.' },
        right: { label: 'Roles, as an HR Executive', tone: 'bad', img: C('90-onb-60-hr-executive-roles-denied'), callouts: false, caption: 'Refused: the HR Executive role does not include role.view.' },
        notes: 'SAY: Always test access by signing in as each role (with test accounts), not only as the super administrator.' });
    shot('security-compliance/85-acc-08-role-edit-denied-hr-admin', { title: 'A refusal is a pass', steps: ['Access refused.'], result: '"This page is not available to you." A Tenant HR Admin cannot edit role permissions; only a Tenant Super Admin can.' });
    d.sub('3.4');
    d.warning({ title: 'Require MFA for every administrator', level: 'important', headline: 'Turn on "Require MFA" in the security policy before you invite anyone else.',
        body: 'Administrators can change structure, access and pay rules for the whole organisation. A password alone is not enough protection for that reach.',
        points: ['The new tenant\'s Home lists "MFA not required" as a governance warning until you act.', 'Session and idle time-outs, password rules and retention days are set on the same page.', 'Password expiry is stored but not enforced today (F2): do not rely on it.'],
        todo: 'Admin › Platform › Security policy: require MFA, set the session and idle time-outs, then Save policy.',
        img: C('90-onb-85-security-policy'), notes: 'SOURCE: deck 04 stage 20 and the governance warnings on the empty tenant Home (90-onb-12). F2 from docs/reports/documentation-gaps.md.' });
    cmp({ title: 'Settings and feature flags',
        left: { label: 'Settings', img: C('90-onb-86-settings'), callouts: false, caption: 'Seeded keys (attendance windows, probation months, code padding…). Several keys have no effect (F30).' },
        right: { label: 'Feature flags', img: C('90-onb-87-feature-flags'), callouts: ['1', '2'], caption: 'AI assistants on; external language model off; configuration approval off by default.' } });
    d.sub('3.5');
    shot(H('50-aud-01-change-history'), { title: 'Change history', callouts: false, steps: [], result: 'Every audited event, newest first, with action, module, record, who and reason.' });
    shot(H('50-aud-02-event-detail'), { title: 'One event in detail', steps: [], result: 'Action Transferred, reason, effective date 22 Oct 2026; field change team Platform → Mobile; who and where; integrity hash.' });
    shot(H('50-aud-03-all-changes-person'), { title: 'All changes for one person', steps: [], result: 'Employee 360 › More › All changes opens Change intelligence for that person: hire, documents, transfer, reporting change, onboarding, each with Explain.' });
    cmp({ title: 'Govern changes after go-live',
        left: { label: 'Change Centre', img: C('90-onb-88-change-centre'), callouts: false, caption: 'Pending approval, scheduled, published, rejected. Empty here: configuration approval is off and creates apply immediately (F29).' },
        right: { label: 'Change history', img: C('90-onb-89-audit-trail'), callouts: ['1', '2'], caption: 'Every create, change, invitation, sign-in and role change of the set-up, newest first.' } });
    shot(C('90-onb-90-audit-verified'), { title: 'Verify the audit chain', steps: ['The result.'], result: '"Chain intact: 287 events verified" for the whole Northwind set-up. Keep this as evidence at handover and at each quarterly review.' });
    d.checklist({ items: [
        ['Each administrator holds the narrowest suitable role', 'Tenant Super Admin kept to one or two named people.'],
        ['Users invited with work e-mails and scopes', 'Empty scope only for people who really need the whole tenant.'],
        ['Access tested by signing in as each role', 'Expected refusals recorded as passes.'],
        ['MFA required for administrators', 'Time-outs and password rules set; F2 noted.'],
        ['Feature flags reviewed', 'Configuration approval decided on purpose (F29).'],
        ['Audit chain verified and the result kept', 'Repeat at handover and every quarter.'],
    ] });
    d.summary({ points: [
        'Fourteen system roles arrive with the tenant; only a Tenant Super Admin edits permissions or gives that role.',
        'Invite users by work e-mail with roles and an access scope; empty scope means the whole tenant.',
        'Prove access by signing in as each role: a refusal where one is expected is a pass.',
        'Require MFA for administrators; password expiry is not enforced (F2).',
        'Change history and the chain check are your evidence of who changed what.',
    ] });

    // ================================================================ M04 Policies & set-up
    d.mod('M04');
    d.sub('4.1');
    shot(C('90-onb-61-new-shift'), { title: 'Define shifts', result: 'Start and end times, breaks and grace rules; a review panel shows the shift before it is created.' });
    shot(C('90-onb-62-new-work-schedule'), { title: 'Create a work schedule', callouts: false, steps: [], result: 'The weekly pattern of shifts and weekly offs.' });
    shot(C('90-onb-63-work-schedule-saved'), { title: 'Schedule saved: assign it by rule', steps: [], result: 'Applies-to rules decide who works the schedule; individuals can be assigned from the Employee 360.' });
    shot(C('90-onb-64-new-holiday-calendar'), { title: 'Create a holiday calendar', steps: [], result: 'One calendar per location or group; assigned by rule.' });
    cmp({ title: 'Add holidays and assign the calendar',
        left: { label: 'New holiday', img: C('90-onb-65-new-holiday'), callouts: false, caption: 'The date counts as a holiday for everyone the calendar applies to.' },
        right: { label: 'Calendar saved', img: C('90-onb-66-holiday-calendar-saved'), crop: { x: 384, y: 1240, w: 1776, h: 660 }, callouts: false, caption: 'Holidays Kannada Rajyotsava (1 Nov) and Christmas; Applies to and History tabs.' } });
    d.warning({ title: 'Half-day holidays count as full days', headline: 'Holiday type and the half-day switch are stored but ignored: every calendar date counts as a full holiday (F39).',
        points: ['Attendance and payroll treat a half-day holiday as a full day off.', 'The calendar still shows the half-day setting, so the screen looks right.'],
        todo: 'Enter only full-day holidays. Record half days as a shift or schedule exception, and tell employees how the day is treated.',
        notes: 'SOURCE: deck 04 stage 14 note, defect F39 (docs/reports/documentation-gaps.md).' });
    d.sub('4.2');
    shot(C('90-onb-67-leave-types'), { title: 'Leave types arrive seeded', steps: ['A seeded type with its flags.', 'New leave type.'], result: 'Earned, Casual, Sick, Maternity, Paternity, Bereavement, Compensatory off, Restricted holiday, Leave without pay.' });
    d.procedure({ title: 'Set up a leave policy in four steps', steps: [
        ['Create the policy', 'Policies › New policy, type Leave policy, with a name. The rules do not go on the policy itself.'],
        ['Edit the draft version', 'Versions & settings: one row per leave type with days per year, accrual and carry-forward.'],
        ['Publish the version', 'It becomes active from its effective date; earlier versions are kept.'],
        ['Check two balances', 'Open Balances for two sample employees; accruals run monthly, Run accrual now is on the Balances page.'],
    ], side: [['Before you start', 'Leave types exist (they are seeded) and you know your entitlements per type.'], ['Result', 'A published version that assignment rules apply to the right people.'], ['Watch for', 'Nothing applies until the version is published.']] });
    shot(C('90-onb-68-new-leave-policy'), { title: 'Create the leave policy', steps: ['Policy type: Leave policy.', 'Name.'], result: 'The rules go on a draft version of the saved policy, which is then published.' });
    if (has(C('90-onb-70-leave-entitlements'))) d.fields({ title: 'Set the entitlements on the draft version', img: C('90-onb-70-leave-entitlements'), crop: { x: 820, y: 250, w: 1100, h: 950 }, fields: [
        { n: 1, name: 'Leave type', what: 'One row per type the policy grants (Earned, Casual, Sick…).' },
        { n: 2, name: 'Days per year', what: 'The yearly entitlement for that type.' },
        { n: 3, name: 'Accrual', what: 'How the days are credited over the year (credited in the monthly accrual run).' },
        { n: 4, name: 'Carry-forward', what: 'What happens to unused days at year end: all, none, or up to a limit.' },
    ], notes: `EVIDENCE: ${NW} Carry-forward modes come from app/Domain/Leave/Services/LeaveAccrual.php (all, zero, or up to carry_forward_limit).` });
    cmp({ title: 'Publish the version',
        left: { label: 'Publish draft', img: C('90-onb-71-publish-policy'), callouts: false, caption: 'The version becomes active from its effective date; earlier versions are kept.' },
        right: { label: 'Published', tone: 'good', img: C('90-onb-72-policy-published'), crop: { x: 384, y: 1380, w: 1776, h: 580 }, callouts: false, caption: 'v1 Published by Farah Khan, effective 21 Oct 2026. Assignment rules decide who it applies to.' } });
    d.sub('4.3');
    shot(C('90-onb-73-salary-components'), { title: 'Salary components arrive seeded', steps: ['A formula-based component.', 'New salary component.'], result: 'Basic (40% of monthly CTC), HRA (50% of basic), Conveyance, Special allowance (balancing), Bonus…; statutory lines are never configured here.' });
    cmp({ title: 'Edit a component; review the structure',
        left: { label: 'Basic (edit)', img: C('90-onb-74-salary-component-edit'), callouts: false, caption: 'The formula is validated on save; a reason is required and approval may apply (Change Centre).' },
        right: { label: 'Salary structures', img: C('90-onb-75-salary-structures'), callouts: ['1'], caption: 'Standard structure v1 is seeded; new versions are drafted, submitted and approved.' } });
    d.sub('4.4');
    d.warning({ title: 'Registrations belong to an establishment', level: 'warning', headline: '"A EPF establishment code registration belongs to an establishment." Nothing is saved.',
        body: 'Statutory registrations (EPF, ESIC, PT, LWF, TAN) are recorded against an establishment of a legal entity. Without one, the form refuses to save.',
        todo: 'Create the establishments first (module 02, submodule 2.2), then record each registration against the right one.',
        img: C('90-onb-76a-registration-needs-establishment') });
    if (has(C('90-onb-76-new-statutory-registration'))) d.fields({ title: 'Record a statutory registration', img: C('90-onb-76-new-statutory-registration'), crop: { x: 0, y: 60, w: 1344, h: 700 }, fields: [
        { n: 1, name: 'Legal entity', what: 'The legal employer the registration belongs to.' },
        { n: 2, name: 'Establishment', what: 'The registered place of work under that entity.' },
        { n: 3, name: 'Registration type', what: 'EPF, ESIC, PT, LWF or TAN.' },
        { n: 4, name: 'Number', what: 'The number from the registration certificate; registrations are effective-dated records.' },
    ] });
    cmp({ title: 'Registrations and statutory profiles',
        left: { label: 'Statutory registrations', img: C('90-onb-77-statutory-registrations'), callouts: false, caption: 'The EPF establishment code with its state and effective date.' },
        right: { label: 'New statutory profile', img: C('90-onb-78-new-establishment-statutory-profile'), callouts: ['1', '2', '3'], caption: 'Tells payroll which statutes apply where. Record the registration first: a profile saved earlier has no registration linked.' } });
    cmp({ title: 'Rule verification is Markedge\'s job, and it is not done',
        left: { label: 'Compliance control room', tone: 'bad', img: C('90-onb-80-compliance-control-room'), callouts: false, caption: 'Rules this tenant needs per establishment and whether each is verified.' },
        right: { label: 'Compliance rules (read only)', tone: 'bad', img: C('90-onb-81-compliance-rules'), callouts: false, caption: 'Rule versions shipped by the platform with their verification status: 0 of 24 verified.' },
        notes: 'SAY: Payroll can be configured and calculated, but not finalised, until two platform administrators verify each rule against official evidence (release gate 5).' });
    d.sub('4.5');
    cmp({ title: 'Create a workflow',
        left: { label: 'New workflow', img: C('90-onb-82-new-workflow'), callouts: ['1', '2', '3'], caption: 'Name, trigger event (here: probation ending soon) and subject.' },
        right: { label: 'Workflow saved', img: C('90-onb-83-workflow-saved'), callouts: false, caption: 'Versions, the step builder and history.' } });
    shot(C('90-onb-94-workflow-builder'), { title: 'Add steps in the builder, then publish', steps: [], result: 'Start → End; approval, task, notification and condition steps go between them. Running instances keep the version they started on. Known limit: "Generate document" steps are skipped (F33).' });
    shot(C('85-wf-03-workflow-versions'), { title: 'A published workflow (Demo tenant)', crop: { x: 390, y: 620, w: 1770, h: 700 }, steps: [], result: 'Probation confirmation v1 in the Demo tenant: 6 nodes, manager then HR approval.' });
    shot(C('90-onb-84-notification-rules'), { title: 'Notification rules: none, and none can be created', status: 'INCOMPLETE', steps: [], result: 'No rules and no New action (F18). E-mail notices for leave and approvals are not sent unless a rule exists. Markedge must seed or enable rules until this is fixed.' });
    d.checklist({ items: [
        ['Shifts, a work schedule and its applies-to rule', 'Checked on two sample employees.'],
        ['Holiday calendars per location', 'Full days only (F39).'],
        ['Leave policy version published', 'Balances checked for two employees after publishing.'],
        ['Salary components and structure reviewed', 'Formulas validated; statutory lines left to the platform.'],
        ['Registrations recorded per establishment', 'Statutory profiles linked to the registrations.'],
        ['Workflows published; notification rules requested', 'Markedge seeds rules until F18 is fixed.'],
    ] });
    d.summary({ points: [
        'Shifts and schedules are assigned by rule; holiday calendars by location (full days only, F39).',
        'Leave rules live on a policy version: draft, entitlements, publish, then check balances.',
        'Salary components are formulas; statutory lines are never configured by the tenant.',
        'Registrations need an establishment; rule verification is Markedge\'s job (0 of 24 today).',
        'Workflows are versioned; notification rules must be seeded by Markedge (F18).',
    ] });

    // ================================================================ M05 People records
    d.mod('M05');
    d.sub('5.1');
    shot(H('50-dir-01-directory-cards'), { title: 'People Directory', steps: ['Search.', 'Filters.', 'Cards / Table.', 'Employee register.'], result: 'Everyone you may see, with department, location, status and grouping filters.' });
    cmp({ title: 'Preview and register',
        left: { label: 'Person preview', img: H('50-dir-02-person-preview'), callouts: ['1', '2'], caption: 'Role, manager and quick actions: Open profile, Message, Org map, Start a change.' },
        right: { label: 'Employees register', img: H('50-dir-03-employee-register'), callouts: ['1', '4'], caption: 'Table with ID, designation, department, manager, status; Hire employee; View and Edit.' } });
    d.sub('5.2');
    shot(H('50-360-01-header-now'), { title: 'Employee 360: the header and Now', callouts: false, steps: [], result: 'Status, tenure, manager; Summarise, Message, Request, Action and More; views Now, Journey, Work, Growth, Rewards, Documents and Records. Now shows what is next for the person.' });
    d.actions({ title: 'What the Action and More menus do', img: H('50-360-02-action-menu'), actions: [
        ['Transfer / promote', 'A guided change of position or team, effective-dated, with a reason.', 'employee.position'],
        ['Change manager', 'Replace the line manager, or a dotted, project or mentor relationship.', 'employee.position'],
        ['Change lifecycle state', 'Move to an allowed next state, with an effective date and a reason.'],
        ['Start onboarding', 'Start a plan from a template, or the best match by its rules.', 'onboarding.manage'],
        ['Edit profile', 'From More: the person\'s profile fields; changes are audited.'],
        ['All changes', 'From More: every audited change for this person.', 'audit.view'],
    ], intro: 'Menus show only what your role may do. If an item hides under the sticky view bar (F27), scroll slightly.' });
    shot(H('50-360-04-journey'), { title: 'Journey: the effective-dated history', steps: [], result: 'Joining, onboarding, probation, assets, leave and manager assignment in order. Select a stage to see what happened in it.' });
    cmp({ title: 'Records: employment and time',
        left: { label: 'Employment', img: H('50-360-05-records-employment'), callouts: false, caption: 'Position assignments and reporting lines, each with from/to dates and change type.' },
        right: { label: 'Time', img: H('50-360-06-records-time'), callouts: false, caption: 'Attendance and leave for this person; Apply for leave on their behalf.' } });
    cmp({ title: 'Records: rewards and operations',
        left: { label: 'Rewards', img: H('50-360-07-records-rewards'), callouts: false, caption: 'Compensation, compensation changes and payslips, only with the matching permission.' },
        right: { label: 'Operations', img: H('50-360-08-records-operations'), callouts: false, caption: 'Documents (Upload, Verify, Reject), letters and requests.' } });
    cmp({ title: 'Different HR roles, different record tabs',
        left: { label: 'Tenant HR Admin', img: 'security-compliance/50-sec-01-360-records-tenant-hr-admin', callouts: ['1'], caption: 'Timeline, Employment, Time, Growth, Rewards, Operations, Bank & personal, History.' },
        right: { label: 'HR Manager', img: 'security-compliance/50-sec-02-360-records-hr-manager', callouts: ['1'], caption: 'No Growth; Personal instead of Bank & personal: no bank or identity details.' } });
    shot(H('50-360-09-records-bank-personal'), { title: 'Bank & personal: sensitive and permission-controlled', steps: [], result: 'Addresses, family and nominees, emergency contacts, bank accounts and identifiers. Sensitive fields need *.sensitive.* permissions and are masked otherwise.' });
    d.sub('5.3');
    d.purpose({ title: 'Hire an employee', goal: 'Create one lifetime record for a new joiner, placed in the organisation with a manager, without duplicating a person who already exists.',
        who: 'Tenant HR Admin, HR Manager, HR Executive (employee.create)', where: 'People › Lifecycle › Employees › Hire employee', before: 'Organisation, designations and the manager\'s record exist (modules 02 and 03).',
        flow: [['START', 'Employees'], ['PERSON', 'duplicate check'], ['EMPLOYMENT', 'dates, code'], ['POSITION', 'placement'], ['MANAGER', 'reason → create']], status: 'VERIFIED IN RUNNING APPLICATION' });
    shot(H('50-hire-01-duplicate-refused'), { title: 'The duplicate check stops a second record', callouts: ['1', '2'], steps: [], result: '"This person already exists: Priya Nair (EMP00004), same personal email." The wizard does not move on. Re-use the existing person or correct the details.' });
    if (has(H('50-hire-02-person-step'))) d.fields({ title: 'Step 1: Person', img: H('50-hire-02-person-step'), crop: { x: 384, y: 300, w: 1776, h: 1100 }, fields: [
        { n: 1, name: 'First name', rule: 'Required', what: 'With last name and other personal details of the new joiner.' },
        { n: 2, name: 'Personal e-mail', what: 'Matched against existing people: the same e-mail or phone stops the wizard.' },
        { n: 3, name: 'Next', what: 'Runs the duplicate check before the Employment step opens.' },
    ] });
    for (const [n, t] of [['50-hire-03-employment-step', 'Step 2: Employment'], ['50-hire-04-position-step', 'Step 3: Position'], ['50-hire-05-manager-step', 'Step 4: Manager']]) {
        if (has(H(n))) shot(H(n), { title: t, steps: (entry(H(n)).callouts ?? []).map((c) => c.label ?? ''), result: entry(H(n)).expected });
    }
    shot(H('50-hire-07-created-record'), { title: 'Created: the new Employee 360', steps: [], result: 'Tanvi Joshi (EMP00023), Senior Software Engineer, Probation; onboarding started automatically and the hire is audited.' });
    d.sub('5.4');
    shot(H('50-chg-01-transfer-context'), { title: 'Transfer / promote: 1 Context', steps: ['Kind of change.', 'Effective from.', 'Next.'], result: 'Three guided steps: Context, Change, Review.' });
    shot(H('50-chg-02-transfer-change'), { title: 'Transfer / promote: 2 Change', steps: ['New team.'], result: 'Only the field that changes is edited.' });
    shot(H('50-chg-03-transfer-review'), { title: 'Transfer / promote: 3 Review', callouts: ['2', '3'], renumber: { 2: 1, 3: 2 }, steps: [['·', 'Before → After panel: today\'s team and the new team.'], [1, 'Reason (audited).'], [2, 'Confirm.']], result: 'Confirming with today\'s date is refused (next slide).' });
    cmp({ title: 'Same-day changes are refused',
        left: { label: 'Effective today (the message)', tone: 'bad', img: H('50-chg-04-transfer-refused-same-day'), crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: false, caption: '"Not allowed: The new position must start after the current one (2026-10-21)." Nothing changes.' },
        right: { label: 'Effective tomorrow (the message)', tone: 'good', img: H('50-chg-04b-transfer-done'), crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: ['1'], caption: '"Position assigned": scheduled for 22 Oct and shown on the Journey.' } });
    shot(H('50-chg-05-manager-context'), { title: 'Change manager: 1 Context', result: 'Choose which relationship changes: line, dotted, project, mentor…' });
    shot(H('50-chg-06-manager-change'), { title: 'Change manager: 2 Change', steps: [], result: 'New line manager Ravi Kumar and the date it takes effect. Only employed people are offered.' });
    shot(H('50-chg-07-manager-review'), { title: 'Change manager: 3 Review', steps: [], result: 'Amit Verma → Ravi Kumar, with the reason. Confirm reporting change.' });
    shot(H('50-chg-08-manager-done'), { title: 'Recorded on the record', callouts: false, steps: [], result: 'Header: Reports to Ravi Kumar. History is never overwritten: the previous line stays with its end date.' });
    shot(H('50-chg-09-lifecycle-modal'), { title: 'Change lifecycle state', steps: [], result: 'Only allowed next states; effective date and reason required. Exit uses the exit case (module 13), not this dialog.' });
    d.sub('5.5');
    shot(H('75-imp-01-imports'), { title: 'Employee imports', status: 'INCOMPLETE', steps: ['Upload CSV.'], result: 'Import batches with their status (Tenant HR Admin and Account Owner only). The list works; imports are blocked at Map columns (F25).' });
    cmp({ title: 'Upload and inspect',
        left: { label: 'Uploaded', img: H('75-imp-03-uploaded'), callouts: ['1'], caption: 'Status Uploaded; next: Inspect file.' },
        right: { label: 'Inspected', img: H('75-imp-04-inspected'), callouts: ['1', '2'], caption: '"3 row(s) staged; 5 column(s) mapped automatically"; next: Map columns.' } });
    cmp({ title: 'Map columns fails (F25)',
        left: { label: 'Map columns', img: H('75-imp-05-map-columns'), callouts: ['1', '2', '3'], caption: 'Column → field; what to do when the person exists.' },
        right: { label: 'Submit', tone: 'bad', img: 'support/75-imp-06-map-columns-fails-f25', callouts: ['1'], caption: '"Failed: Undefined variable $svc". The batch stays Inspected; Validate, Approve and Run never become available.' } });
    cmp({ title: 'Discard, and who may import',
        left: { label: 'Discarded', img: H('75-imp-07-discarded'), callouts: ['1'], caption: 'Nothing was imported.' },
        right: { label: 'HR Manager', tone: 'bad', img: 'security-compliance/75-imp-08-hr-manager-not-available', callouts: ['1'], caption: '403 "This page is not available to you": employee.import is not in the HR Manager role.' } });
    d.sub('5.6');
    d.troubleshoot({ title: 'People records: problems and fixes', rows: [
        ['The wizard says "This person already exists".', 'The personal e-mail, phone or work e-mail matches a person already in PeopleOS.', 'Re-use the existing person, or correct the details if they were mistyped.', ''],
        ['A transfer is refused: "must start after the current one".', 'Changes cannot take effect on the day the current position started or earlier.', 'Use tomorrow\'s date (or later) as Effective from.', ''],
        ['The line manager search finds nobody.', 'Only employed people can be managers; the manager is still Preboarding.', 'Hire without a manager, mark the manager as joined, then use Change manager.', ''],
        ['Map columns fails: "Undefined variable $svc".', 'A defect in the import step after Inspect.', 'Discard the batch; hire with the wizard, or ask Markedge for an API load.', 'F25'],
        ['"This page is not available to you" on Imports.', 'The role lacks employee.import (HR Manager does).', 'Ask a Tenant HR Admin or Account Owner to run the import.', ''],
        ['The History tab shows an error page.', 'Lazy loading is refused in non-production strict mode.', 'Use Insights › Audit › Change history for the same events.', 'F26'],
    ] });
    cmp({ title: 'Two errors you may meet on the 360 (development mode)',
        left: { label: 'History tab', tone: 'bad', img: H('50-360-10-records-history'), callouts: false, caption: 'LazyLoadingViolationException (F26) in non-production strict mode. Use Insights › Audit › Change history instead.' },
        right: { label: 'Employment section on scroll', tone: 'bad', img: 'support/50-360-err-01-records-employment', callouts: false, caption: 'TypeError in a lazily loaded section (finding of 21 Oct 2026).' },
        notes: DEBUG });
    d.summary({ points: [
        'The Employee 360 is the one lifetime record; tabs depend on your permissions.',
        'Hiring checks for duplicates before anything is created.',
        'Job and manager changes are guided, effective-dated (from tomorrow) and audited.',
        'Imports stop at Map columns today (F25): hire with the wizard instead.',
        'When a History tab errors, Change history has the same events.',
    ] });

    // ================================================================ M06 Onboarding & documents
    d.mod('M06');
    d.sub('6.1');
    shot(H('50-onb-01-plans'), { title: 'Onboarding plans', steps: [], result: 'Plans in progress with joining date, progress, open and overdue tasks. The new hire\'s plan started automatically.' });
    shot(H('50-onb-02-template'), { title: 'Onboarding template', steps: [], result: 'Name, priority (lower wins), status and Applies to conditions; checklist items with phase, owner and due days.' });
    shot(H('50-onb-03-plan-tasks'), { title: 'Tasks on the plan', steps: [], result: 'By phase (Preboarding, Day 1, First week, 30 days), with owner, due date and status; Done or Skip per task.' });
    cmp({ title: 'Complete a task',
        left: { label: 'Done', img: H('50-onb-04-task-done-confirm'), callouts: false, caption: 'Confirm completion; document tasks require the document.' },
        right: { label: 'Completed', tone: 'good', img: H('50-onb-05-task-completed'), callouts: false, caption: 'Status done with who and when (the plan\'s progress figure updates after a refresh).' } });
    cmp({ title: 'Manager tasks, and a template error',
        left: { label: 'Manager\'s Task inbox', img: 'manager/50-onb-06-manager-onboarding-tasks', callouts: false, caption: 'Tasks assigned to the manager: meet the team, 30-day goals, check-in; Done / Skip.' },
        right: { label: 'Template › Checklist items', tone: 'bad', img: 'support/50-onb-err-01-template-items-lazy-loading', callouts: false, caption: 'LazyLoadingViolationException in development mode. Items were edited before this error appeared in the demo.' },
        notes: `${DEBUG}\nOBSERVATION: open onboarding tasks stay with the previous manager after a manager change: reassign them.` });
    d.sub('6.2');
    shot(H('50-doc-01-upload-form'), { title: 'Upload a document', steps: [], result: 'PDF, JPEG, PNG or Word, up to 10 MB. Saved as Pending verification.' });
    shot(H('50-doc-02-uploaded-pending'), { title: 'Pending verification', callouts: false, steps: [], result: 'Download (10-minute link), Verify and Reject.' });
    d.warning({ title: 'The uploader cannot verify', level: 'important', headline: '"The person who uploaded a document cannot verify it." Plan a second HR person for every verification.',
        body: 'Separation of duties holds, but today the refusal appears as a server error instead of a message. Nothing is changed when it happens.',
        todo: 'Ask a second HR person (another Tenant HR Admin or an HR Manager) to verify documents you uploaded.',
        img: 'security-compliance/50-doc-03-uploader-cannot-verify', notes: `${DEBUG}\nFINDING (21 Oct 2026): separation of duties works, but the refusal is an unhandled RuntimeException rather than a notification.` });
    cmp({ title: 'A second HR person verifies',
        left: { label: 'Verify', img: H('50-doc-04-verify-confirm'), callouts: false, caption: 'Neha Kapoor (HR Manager) verifies a document Kavya uploaded.' },
        right: { label: 'Verified', tone: 'good', img: H('50-doc-05-verified'), callouts: false, caption: 'Status Verified, with who verified it.' } });
    d.summary({ points: [
        'A plan starts on hire from the matching template; tasks go to HR, the manager and the joiner.',
        'Templates decide tasks by phase, owner and due days; lower priority number wins.',
        'Reassign open tasks after a manager change.',
        'Documents are uploaded by one person and verified by another.',
    ] });

    // ================================================================ M07 Attendance
    d.mod('M07');
    d.sub('7.1');
    d.procedure({ title: 'The daily attendance routine', steps: [
        ['Open the Exception centre', 'Counts for the last 30 days and the rows that need action.'],
        ['Record missing punches', 'Manual punch, with direction and a note, only where a device was offline.'],
        ['Process the day', 'Turns punches and approved corrections into attendance records.'],
        ['Check the records', 'Shift, status, in and out, worked time and exceptions per person.'],
    ], side: [['Who', 'Tenant HR Admin or Attendance Admin (attendance.manage).'], ['Avoid', 'Using manual punches to hide systematic device problems: raise those with your administrator.'], ['Set-up it depends on', 'Shifts, schedules and holiday calendars from module 04.']] });
    flows.attendanceHr(d);
    d.summary({ points: [
        'Punches become attendance records only when the day is processed.',
        'Manual punches need a direction and a note, and are stored with source "manual".',
        'The Exception centre shows what needs action; Punches shows every punch with its source.',
    ] });

    // ================================================================ M08 Leave
    d.mod('M08');
    d.sub('8.1');
    shot(H('55-lv-01-balances'), { title: 'Balances', steps: [], result: 'Per person, type and year: opening, accrued, adjusted, used, pending, encashed, lapsed, closing, available. Adjust, Encash and Run accrual now.' });
    d.calculation({ title: 'How a leave balance is calculated', intro: 'Worked example with illustrative figures for one person, one leave type, one year.', lines: [
        ['', 'Opening balance', '0.00'], ['+', 'Accrued (monthly accrual run)', '6.00'], ['+', 'Adjusted by HR (a correction)', '1.00'], ['-', 'Used (approved leave taken)', '2.00'], ['-', 'Encashed', '0.00'], ['-', 'Lapsed at year end', '0.00'],
    ], total: ['Closing balance', '5.00 days'], explain: [
        ['Formula', 'Closing = opening + accrued + adjusted − used − encashed − lapsed.'],
        ['Available', 'Available = closing − pending. With one day pending approval: 5.00 − 1.00 = 4.00 days.'],
        ['Where you see it', 'Balances (each figure has its own column) and the employee\'s own leave page.'],
    ], source: 'app/Domain/Leave/Services/LeaveBalances.php (figures) and app/Domain/Leave/Models/LeaveBalance.php (available).' });
    cmp({ title: 'Adjust a balance',
        left: { label: 'Adjust', img: H('55-lv-02-adjust-form'), callouts: false, caption: 'Days (+ or −) and a note; posted to the ledger.' },
        right: { label: 'Adjusted', tone: 'good', img: H('55-lv-03-adjusted'), callouts: false, caption: 'Casual leave: adjusted 1.0; Available updated.' } });
    shot(H('55-lv-04-ledger'), { title: 'The ledger records the adjustment', steps: [], result: 'Append-only: the adjustment is its own entry with the note. Nobody edits past entries.' });
    d.sub('8.2');
    cmp({ title: 'Leave types',
        left: { label: 'Leave types', img: H('55-lv-05-leave-types'), callouts: false, caption: 'Category, paid, half day, encashable, unit, approval, eligibility.' },
        right: { label: 'Edit Casual leave', img: H('55-lv-06-leave-type-edit'), callouts: false, caption: 'Code locked on edit; a reason is required for changes.' } });
    cmp({ title: 'Policies and the leave policy',
        left: { label: 'Policies', img: H('55-lv-07-policies'), callouts: false, caption: 'Versioned policies (exit, attendance, working hours, leave) with effective dates.' },
        right: { label: 'Standard leave', img: H('55-lv-08-leave-policy'), callouts: false, caption: 'Entitlements per type live in the version settings (module 04, 4.2).' } });
    d.sub('8.3');
    shot(H('55-lv-09-requests-hr'), { title: 'Everyone\'s leave requests', steps: [], result: 'Status tabs; Approve, Reject, Cancel per row; Apply on behalf; a link to the Approval Center.' });
    shot('support/55-lv-err-01-apply-on-behalf', { title: 'Apply on behalf fails: use the 360 instead', status: 'INCOMPLETE', steps: [], result: 'Defect F38: "Call to a member function getFormData() on array" when the dialog opens.', notes: DEBUG });
    shot(H('55-lv-10-360-time-apply'), { title: 'Workaround: Employee 360 › Records › Time', steps: [], result: 'The Leave section shows balances and Apply for leave.' });
    cmp({ title: 'Apply for leave on the employee\'s behalf',
        left: { label: 'Apply for leave', img: H('55-lv-11-apply-form'), callouts: false, caption: 'The same form and Before → After preview as the employee\'s own request.' },
        right: { label: 'Applied', tone: 'good', img: H('55-lv-12-applied'), callouts: false, caption: 'The request goes to the employee\'s approver like any other.' } });
    cmp({ title: 'Leave calendar and a search defect',
        left: { label: 'Leave calendar', img: H('55-lv-13-leave-calendar'), callouts: false, caption: 'Approved and pending leave this month to the end of next; reasons never shown.' },
        right: { label: 'Balances search', tone: 'bad', img: 'support/55-lv-err-02-balances-search', callouts: false, caption: 'Typing a name in the Balances search box fails with a database error. Use the filters instead.' },
        notes: DEBUG });
    d.summary({ points: [
        'Closing = opening + accrued + adjusted − used − encashed − lapsed; available = closing − pending.',
        'Adjustments are new ledger entries with a note; past entries are never edited.',
        'Leave type codes are locked on edit; entitlements live on policy versions.',
        'Apply on behalf from the list fails (F38): use Employee 360 › Records › Time.',
    ] });

    // ================================================================ M09 Payroll & statutory
    d.mod('M09');
    d.sub('9.1');
    d.diagram({ title: 'The life of a payroll run', flow: [['OPEN', 'company, month'], ['CALCULATE', 'inputs read'], ['VALIDATE', 'blocking issues?'], ['APPROVE', 'second person'], ['FINALIZE', 'blocked today'], ['PAYSLIPS', 'bank file']],
        caption: 'Calculate and validate work today. Validate refuses while statutory rules are unverified, so Approve, Finalize, payslips and bank files are not reachable until the platform verifies the rules (release gate 5).' });
    wide(H('60-pay-01-control-room'), { title: 'Payroll control room', caption: 'Readiness figures, the company card and the run register. The subheading lists statutory rules that are not verified: runs cannot be finalised.' });
    cmp({ title: 'Runs and opening a run',
        left: { label: 'Payroll runs', img: H('60-pay-02-runs-list'), callouts: false, caption: 'One run per company and month.' },
        right: { label: 'Open payroll run', img: H('60-pay-03-open-run-form'), callouts: false, caption: 'Company, year, month (cancelled here; the October run already exists).' } });
    shot(H('60-pay-04-run-view'), { title: 'The run', steps: [], result: 'Totals, status and actions: Calculate / Recalculate, Validate; Approve and Finalize appear only when allowed.' });
    cmp({ title: 'Issues and the breakdown',
        left: { label: 'Employees tab: Issues', img: H('60-pay-05-employee-issues'), callouts: false, caption: 'Issue codes appear raw, e.g. unverified_statutory_rule (F12).' },
        right: { label: 'Breakdown', img: H('60-pay-06-breakdown'), callouts: false, caption: 'Earnings and deductions; statutory lines are exceptions while rules are unverified.' } });
    cmp({ title: 'Recalculate',
        left: { label: 'Confirm', img: H('60-pay-07-recalculate-confirm'), callouts: false, caption: 'Recalculate re-reads attendance, leave, salary and adjustments.' },
        right: { label: 'Recalculated', img: H('60-pay-08-recalculated'), callouts: false, caption: '"Calculated for 19 employee(s); 19 with exceptions." Every salaried employee of the company is included.' } });
    d.warning({ title: 'Validate is refused while rules are unverified', level: 'blocked', headline: '"Not allowed: 19 employee(s) have blocking exceptions. Fix the inputs and recalculate."',
        body: 'The exceptions are the unverified statutory rules (Issues column: unverified_statutory_rule). This is the payroll safeguard working, not a fault.',
        points: ['Approve, Finalize, payslips and bank files are not reachable.', 'Nothing you change in the tenant lifts this block.'],
        todo: 'Calculate and check runs for parallel testing; agree in writing with Markedge when statutory verification (release gate 5) is expected.',
        img: H('60-pay-09-validate-blocked') });
    shot(H('60-pay-11-payroll-auditor'), { title: 'Payroll Auditor', steps: [], result: 'Findings with severity and evidence for the run. Rule-based (despite the "AI" label); some findings show raw technical text.' });
    d.sub('9.2');
    cmp({ title: 'Salary components',
        left: { label: 'Components', img: H('60-pay-12-salary-components'), callouts: false, caption: 'Formula, order and flags; statutory codes are reserved.' },
        right: { label: 'New component', img: H('60-pay-13-new-component-form'), callouts: false, caption: 'Formula validated on save (not saved here).' } });
    cmp({ title: 'Salary structures and versions',
        left: { label: 'Structures', img: H('60-pay-14-salary-structures'), callouts: false, caption: 'Standard structure.' },
        right: { label: 'Versions', img: H('60-pay-15-structure-versions'), crop: { x: 400, y: 1250, w: 1760, h: 480 }, callouts: false, caption: 'Draft → submit → approve; versions cannot start inside a finalised period.' } });
    cmp({ title: 'Adjustments and tax declarations',
        left: { label: 'New payroll adjustment', img: H('60-pay-16-new-adjustment'), callouts: false, caption: 'Employee, period, type, component, amount or days, taxable (cancelled).' },
        right: { label: 'Tax declarations', img: H('60-pay-17-tax-declarations'), callouts: false, caption: 'Empty here. Employees could not submit a declaration themselves at capture time (F13).' } });
    shot(H('60-pay-10-run-history-error-f26'), { title: 'Run History tab error (development mode)', status: 'INCOMPLETE', steps: [], result: 'LazyLoadingViolationException (F26) on the run\'s History tab outside production. Use Change history.', notes: DEBUG });
    d.sub('9.3');
    wide(H('60-stat-01-compliance-control-room'), { title: 'Compliance control room', caption: '0 of 24 rule versions verified; required rules, open notices and missing registrations (ESI, LWF, PT) per establishment.' });
    cmp({ title: 'Rules and verification',
        left: { label: 'Compliance rules', img: H('60-stat-02-compliance-rules'), callouts: false, caption: 'Rule versions shipped by the platform; read only for tenants.' },
        right: { label: 'Rule verification', img: H('60-stat-03-rule-verifications'), callouts: false, caption: 'None verified. Two platform administrators verify each rule with official evidence.' } });
    cmp({ title: 'Registrations and establishment profiles',
        left: { label: 'Statutory registrations', img: H('60-stat-05-statutory-registrations'), callouts: false, caption: 'EPF, ESIC, PT, LWF, TAN numbers with Verify / Correct / New number.' },
        right: { label: 'Statutory profiles', img: H('60-stat-06-establishment-profiles'), callouts: false, caption: 'Which statutes apply at each establishment.' } });
    cmp({ title: 'Notices and returns',
        left: { label: 'Regulatory notices', img: H('60-stat-04-rule-notices'), callouts: false, caption: '5 open notices about statutory changes to resolve.' },
        right: { label: 'EPF returns', img: H('60-stat-07-epf-returns-empty'), callouts: false, caption: 'Empty: returns need finalised payroll.' } });
    d.summary({ points: [
        'Open, calculate, recalculate and validate work; validate refuses while rules are unverified.',
        'Approve, Finalize, payslips and bank files wait for statutory verification by the platform.',
        'Components are formulas; structures are versioned and approved.',
        'Registrations and profiles are yours; rule verification is Markedge\'s (0 of 24).',
    ] });

    // ================================================================ M10 Performance & workforce
    d.mod('M10');
    d.sub('10.1');
    cmp({ title: 'Performance cycles',
        left: { label: 'Cycles', img: H('65-grow-01-performance-cycles'), callouts: false, caption: 'FY 2026-27 annual review in the Self review stage.' },
        right: { label: 'New cycle', img: H('65-grow-02-new-cycle-form'), callouts: false, caption: 'Stages, eligibility rules and options (not saved). Scheduled cycles do not launch on their own (F21).' } });
    cmp({ title: 'Appraisals and calibration',
        left: { label: 'Appraisals', img: H('65-grow-03-appraisals'), callouts: false, caption: 'Each employee\'s appraisal with stage and status.' },
        right: { label: 'Calibration board', img: H('65-grow-04-calibration-board'), callouts: false, caption: 'Rating distribution and adjustments before finalising.' } });
    d.sub('10.2');
    cmp({ title: 'Goals and learning',
        left: { label: 'Goals (HR view)', img: H('65-grow-05-goals-hr-view'), callouts: false, caption: 'Company → department → individual cascade with progress.' },
        right: { label: 'Learning assignments', img: H('65-learn-02-assignments'), callouts: false, caption: 'PoSH for everyone (annual, mandatory).' } });
    d.sub('10.3');
    cmp({ title: 'Positions',
        left: { label: 'New position', img: H('65-wf-02-new-position-form'), callouts: false, caption: 'Code, title, designation, company, unit, grade. FTE left blank (F8).' },
        right: { label: 'Created as a draft', tone: 'good', img: H('65-wf-03-position-created'), callouts: false, caption: '"Position POS-TRN-001 created as a draft."' } });
    cmp({ title: 'Org map and position hierarchy',
        left: { label: 'Org map', img: H('65-wf-05-org-map'), callouts: false, caption: 'Units and people by primary reporting line.' },
        right: { label: 'Position hierarchy', img: H('65-wf-07-position-hierarchy'), callouts: false, caption: 'Positions by reporting position.' } });
    d.summary({ points: [
        'Cycles run through stages; scheduled cycles must be launched by hand (F21).',
        'Calibrate the rating distribution before ratings are final.',
        'Goals cascade from company to individual; mandatory learning is assigned by rule.',
        'Positions start as drafts; leave FTE blank (F8).',
    ] });

    // ================================================================ M11 Compensation
    d.mod('M11');
    d.sub('11.1');
    d.purpose({ title: 'Change an employee\'s pay', goal: 'Propose a pay change with a reason and take it through review, approval and execution by four different people.',
        who: 'Propose, review: HR Manager or Tenant HR Admin. Approve: a third person. Execute: Payroll Admin.', where: 'Employee 360 › Records › Rewards; People › Pay › Compensation changes', before: 'The employee is employed (not alumni) and has a salary structure.',
        flow: [['PROPOSE', 'Neha (HR Manager)'], ['REVIEW', 'Kavya (Tenant HR Admin)'], ['APPROVE', 'Demo Admin'], ['EXECUTE', 'Arjun (Payroll Admin)'], ['RESULT', 'Scheduled']], status: 'VERIFIED IN RUNNING APPLICATION',
        notes: `EVIDENCE: ${SRC} Amit Verma's CTC 24,00,000 → 26,40,000 was taken through all four steps.` });
    shot(H('62-comp-00-refused-alumni'), { title: 'Refused for someone who has left', steps: [], result: '"Cannot propose: An employee who is alumni cannot receive future compensation."' });
    cmp({ title: 'Propose',
        left: { label: 'Rewards tab', img: H('62-comp-01-employee-compensation'), callouts: false, caption: 'Current compensation and earlier changes.' },
        right: { label: 'Propose compensation change', img: H('62-comp-02-propose-form'), callouts: false, caption: 'Change type, effective date, structure, new annual CTC, reason; Submit for review now.' } });
    cmp({ title: 'Submitted, then reviewed',
        left: { label: 'Submitted', img: H('62-comp-04-list-submitted'), callouts: false, caption: 'Listed as submitted for review.' },
        right: { label: 'Reviewed', img: H('62-comp-06-reviewed'), callouts: false, caption: 'Mark reviewed → Confirm (Kavya).' } });
    shot('security-compliance/62-comp-07-reviewer-cannot-approve', { title: 'The reviewer cannot also approve', steps: [], result: 'After reviewing, Kavya\'s menu offers only Reject, Return to proposer and Cancel: no Approve, although her role holds the approve permission.' });
    cmp({ title: 'Approve, then execute',
        left: { label: 'Approved (third person)', img: H('62-comp-09-approved'), callouts: false, caption: 'Demo Admin (Tenant Super Admin) approves.' },
        right: { label: 'Executed (fourth person)', tone: 'good', img: H('62-comp-10-executed'), callouts: false, caption: 'Arjun (Payroll Admin) executes: status Scheduled for the effective date.' } });
    d.sub('11.2');
    d.calculation({ title: 'Worked example: the training pay change', intro: 'Amit Verma (fictional), annual CTC, as proposed, reviewed, approved and executed in training.', lines: [
        ['', 'Current annual CTC', '24,00,000'], ['+', 'Increase proposed', '2,40,000'],
    ], total: ['New annual CTC', '26,40,000'], explain: [
        ['Size of the change', '2,40,000 ÷ 24,00,000 = 10.0 % increase.'],
        ['Monthly CTC', '26,40,000 ÷ 12 = 2,20,000 a month from the effective date.'],
        ['What happens next', 'After execution the change is Scheduled; the new CTC applies from its effective date.'],
    ], source: 'Training run of 21 Oct 2026 (hr-admin/62-comp-* screenshots); figures in rupees.' });
    d.summary({ points: [
        'Four different people: propose, review, approve, execute; the reviewer cannot approve.',
        'Alumni cannot receive future compensation.',
        'Executed changes are Scheduled until their effective date.',
        'Check the size of every change: new CTC − current CTC, as a percentage of current.',
    ] });

    // ================================================================ M12 Letters, assets & requests
    d.mod('M12');
    d.sub('12.1');
    shot(H('52-let-01-letters'), { title: 'Letters', steps: ['Generate letter.'], result: 'Generated letters with number, employee, type and status.' });
    shot(H('52-let-02-generate-form'), { title: 'Generate a letter', steps: ['Employee.', 'Template.', 'Extra fields.', 'Submit.'], result: 'Merge fields come from the employee record. Templates marked "needs approval" go to Pending approval; others are ready to issue.' });
    shot(H('52-let-03-pending'), { title: 'Pending approval and issued', steps: ['Pending approval (a no-objection certificate).', 'Status tabs.'], result: 'An employment certificate (no approval needed) was issued straight away.' });
    cmp({ title: 'The requester cannot approve',
        left: { label: 'Requester tries to approve', tone: 'bad', img: 'security-compliance/52-let-04-requester-cannot-approve', callouts: ['1'], caption: '"Not allowed: The person who requested a letter cannot approve it."' },
        right: { label: 'A second HR person approves', img: H('52-let-05-approver-view'), callouts: ['1'], caption: 'Preview with Approve / Reject.' } });
    cmp({ title: 'Issued, and the employee\'s copy',
        left: { label: 'Issued', tone: 'good', img: H('52-let-06-issued'), callouts: ['1'], caption: 'Issued; Download available.' },
        right: { label: 'My letters (employee)', img: E('52-let-07-my-letters'), callouts: ['1'], caption: 'The employee sees only their own issued letters.' } });
    d.sub('12.2');
    shot(H('52-ast-01-register'), { title: 'Asset register', steps: ['Receive asset.', 'A spare laptop in stock.', 'Status tabs.'], result: 'Tag, category, serial, status, custodian, location and warranty.' });
    cmp({ title: 'Assign an asset',
        left: { label: 'Assign', img: H('52-ast-02-assign'), callouts: ['1', '2', '3'], caption: 'Employee, condition and a note: custody is recorded.' },
        right: { label: 'Assigned', tone: 'good', img: H('52-ast-03-assigned'), callouts: ['1', '2'], caption: '"Asset assigned": LT-0004 to Tanvi Joshi; custody history from today.' } });
    shot(E('52-ast-04-my-assets'), { title: 'The employee\'s My assets', steps: ['My laptop.'], result: 'Only the assets in their custody; the employee acknowledges receipt.' });
    d.sub('12.3');
    cmp({ title: 'An employee raises a request',
        left: { label: 'Ask HR', img: E('52-tkt-01-ask-hr'), callouts: false, caption: 'The request goes to the HR queue with an SLA from its category.' },
        right: { label: 'Raised', tone: 'good', img: E('52-tkt-02-raised'), callouts: false, caption: '"Request TKT-2026-00003 raised"; listed as Submitted.' } });
    shot(H('52-tkt-03-hr-queue'), { title: 'The HR queue', steps: ['New request.', 'SLA due.'], result: 'Category, requester, priority, status, assignee and SLA due date.' });
    cmp({ title: 'Take it, reply, resolve',
        left: { label: 'Reply', img: H('52-tkt-04-reply'), callouts: ['1'], caption: 'The reply is added to the conversation the employee sees.' },
        right: { label: 'Resolved', tone: 'good', img: H('52-tkt-05-resolved'), callouts: ['1', '2'], caption: 'Assignee, SLA and conversation kept on the request.' } });
    cmp({ title: 'What the employee sees',
        left: { label: 'My requests (Resolved tab)', img: E('52-tkt-06-my-requests'), callouts: ['1', '2'], caption: 'Tip: My requests opens with a status filter that hides Resolved; clear it to see answered requests.' },
        right: { label: 'The answer', img: E('52-tkt-07-request-answer'), callouts: ['1', '2'], caption: 'HR\'s resolution and the conversation; the employee can close and rate, or reopen.' } });
    d.summary({ points: [
        'Letters that need approval go to a second person; the requester cannot approve.',
        'Asset custody is recorded on assignment and shown in the employee\'s My assets.',
        'HR requests carry an SLA from their category; replies are visible to the employee.',
    ] });

    // ================================================================ M13 Exit & alumni
    d.mod('M13');
    d.sub('13.1');
    d.purpose({ title: 'Take a leaver through exit', goal: 'Record the resignation, clear every stage, settle full & final, complete the exit and keep the person as an alumnus.',
        who: 'The employee resigns; the manager and HR clear stages; HR settles and completes.', where: 'You › My exit; People › Lifecycle › Exit cases; Alumni', before: 'Assets in the leaver\'s custody must be taken back first.',
        flow: [['RESIGN', 'employee'], ['CLEAR', 'manager, assets, HR'], ['SETTLE', 'full & final'], ['COMPLETE', 'Exited'], ['ALUMNI', 'portal']], status: 'VERIFIED IN RUNNING APPLICATION',
        notes: `EVIDENCE: ${SRC} Rahul Sharma (fictional) resigned and was taken through every step on 21 Oct 2026.` });
    cmp({ title: 'The employee resigns',
        left: { label: 'Resign', img: E('70-exit-01-resign'), maxAspect: 1.3, callouts: ['1', '2', '3'], caption: 'Reason and requested last day; blank = end of the notice period.' },
        right: { label: 'My exit', img: E('70-exit-02-my-exit'), callouts: ['1'], caption: 'The exit case with its status and last working day.' } });
    d.warning({ title: 'Check the last working day', level: 'warning', headline: 'In training, the requested last day (18 Dec) was not applied: the exit case used the end of the notice period (20 Nov).',
        points: ['Clearance, settlement and the Exited date all follow the last working day on the case.', 'The employee sees the case\'s date in My exit, not the date they asked for.'],
        todo: 'Open the exit case and correct the last working day before clearance starts whenever it differs from the agreed date.',
        notes: 'SOURCE: observation during the 21 Oct 2026 capture (deck 03, exit section).' });
    cmp({ title: 'HR picks up the case',
        left: { label: 'Exit cases', img: H('70-exit-03-exit-cases'), callouts: ['1', '2'], caption: 'The resignation appears as a new case; HR can also initiate an exit.' },
        right: { label: 'The case', img: H('70-exit-04-case'), callouts: false, caption: 'Status, dates, notice; start clearance, record the interview, generate letters, complete.' } });
    cmp({ title: 'Clearance',
        left: { label: 'Started', img: H('70-exit-05-clearance-started'), callouts: ['1', '2'], caption: 'Stages created from the exit checklist; owners notified.' },
        right: { label: 'Stages', img: H('70-exit-06-clearance-stages'), callouts: ['1', '2', '3'], caption: 'Manager, IT, finance, assets, HR: Clear, Block or Not applicable.' } });
    cmp({ title: 'Assets block clearance until returned',
        left: { label: 'Asset stage refused', tone: 'bad', img: H('70-exit-08a-asset-stage-blocked'), callouts: ['1', '2'], caption: '"Not allowed: Assets still in custody: LT-0001. Take them back, or record a recoverable amount."' },
        right: { label: 'Take back', img: H('70-exit-08b-take-back-asset'), callouts: false, caption: 'Custody ends; the laptop returns to stock.' } });
    cmp({ title: 'All stages cleared; exit interview',
        left: { label: 'Cleared', tone: 'good', img: H('70-exit-08-clearance-done'), callouts: ['1'], caption: 'Every stage cleared, with who and when.' },
        right: { label: 'Record exit interview', img: H('70-exit-09-exit-interview'), callouts: false, caption: 'Answers feed Exit insights; averages use employee answers only.' } });
    d.sub('13.2');
    cmp({ title: 'Full & final settlement',
        left: { label: 'Settlement', img: H('70-exit-11-ff-settlement'), callouts: ['1', '2'], caption: 'Earnings 43,400.06, deductions 5,146.00, net 38,254.06; Paid with reference.' },
        right: { label: 'Breakdown', img: H('70-exit-12-ff-breakdown'), callouts: false, caption: 'Each line with its basis (shown as raw technical values).' },
        notes: 'OBSERVATIONS (21 Oct 2026): the same HR person calculated and approved the settlement; gratuity was not calculated at capture time; statutory deductions use unverified rules.' });
    d.calculation({ title: 'Reading the full & final figure', intro: 'Rahul Sharma (fictional), from the settlement calculated in training.', lines: [
        ['', 'Earnings (all earning lines of the breakdown)', '43,400.06'], ['-', 'Deductions (all deduction lines)', '5,146.00'],
    ], total: ['Net payable', '38,254.06'], explain: [
        ['Formula', 'Net payable = total earnings − total deductions; every line keeps its basis in the breakdown.'],
        ['Check before paying', 'Last working day on the case, leave balance encashed, assets returned or recovered, and statutory deductions (rules unverified).'],
        ['Separation of duties', 'Have a second HR person approve the settlement: the training run showed one person could do both.'],
    ], source: 'hr-admin/70-exit-11-ff-settlement and 70-exit-12-ff-breakdown (captured 21 Oct 2026).' });
    cmp({ title: 'Complete the exit',
        left: { label: 'Complete exit', img: H('70-exit-14-complete-exit'), callouts: false, caption: 'The employee becomes Exited on the last working day.' },
        right: { label: 'Completed', tone: 'good', img: H('70-exit-15-completed'), callouts: ['1'], caption: '"Exit completed".' } });
    d.sub('13.3');
    cmp({ title: 'Alumni: the record continues',
        left: { label: 'Alumni list', img: H('70-exit-17-alumni-list'), callouts: ['1'], caption: 'The new alumni profile.' },
        right: { label: 'Employee 360 after exit', img: H('70-exit-18-employee-exited'), callouts: ['1'], caption: 'Exited / Alumni; one lifetime record keeps the whole journey.' } });
    cmp({ title: 'Alumni portal',
        left: { label: 'Former employee signs in', img: E('70-alumni-01-portal'), callouts: ['1', '2'], caption: 'My requests, My documents, Payslips; Request a document or reference.' },
        right: { label: 'HR receives the request', img: H('70-alumni-04-hr-requests'), callouts: ['1'], caption: 'Verify identity, Approve & generate, Mark delivered, Reject.' } });
    d.checklist({ title: 'Exit and alumni: administrator checklist', items: [
        ['Last working day checked on the case', 'Corrected before clearance if it differs from the agreed date.'],
        ['Assets taken back or recoverable amount recorded', 'The asset stage refuses otherwise.'],
        ['Every clearance stage cleared or marked not applicable', 'With who and when.'],
        ['Settlement approved by a second HR person', 'Breakdown checked line by line.'],
        ['Exit completed on the last working day', 'The 360 shows Exited / Alumni.'],
        ['Alumni requests answered', 'Identity verified before documents are generated.'],
    ] });
    d.summary({ points: [
        'Check the last working day on the case; the requested day may not be applied.',
        'Assets block clearance until they are taken back or a recoverable amount is recorded.',
        'Net payable = earnings − deductions; have a second person approve the settlement.',
        'After completion the person becomes an alumnus with the same lifetime record and a portal.',
    ] });

    // ================================================================ Course review
    d.review();
    d.cards({ title: 'Common mistakes an organisation administrator makes', cards: [
        { head: 'People before structure', body: 'Hiring before companies, units and designations exist leaves records with "—": build structure first.', tone: 'warn' },
        { head: 'One person doing two steps', body: 'Documents, letters, pay changes and settlements need a second person: plan who holds each role.', tone: 'info' },
        { head: 'Testing only as super admin', body: 'Sign in as each role; a refusal where one is expected is a pass.', tone: 'warn' },
        { head: 'Same-day changes', body: 'A transfer effective today is refused: use tomorrow\'s date.', tone: 'warn' },
        { head: 'Half-day holidays', body: 'Stored but treated as full days (F39): enter full days only.', tone: 'bad' },
        { head: 'Imports', body: 'Blocked at Map columns (F25): do not retry; hire individually or ask Markedge.', tone: 'bad' },
    ], notes: 'SOURCE: observations during the 21 Oct 2026 captures (decks 03 and 04).' });
    d.exercise({ title: 'Practice (practice tenant only)', tasks: [
        'Invite a fictional HR Executive scoped to one company. Sign in as them and open Roles. What happens?',
        'Hire a fictional employee; first try an existing person\'s e-mail and read the message.',
        'Change the new hire\'s manager effective tomorrow. Where does the change appear?',
        'Run an exit for a fictional employee with a laptop assigned. What blocks clearance?',
    ], notes: 'ANSWERS: see the answer key on the next slide.' });
    d.table({ title: 'Answer key', columns: ['#', 'Answer'], colW: [0.6, 11.53], rows: [
        ['1', 'Roles is refused ("This page is not available to you"): the HR Executive role has no role.view. That refusal is the expected result.'],
        ['2', 'The wizard stops: "This person already exists… same personal email." Re-use the person or correct the details.'],
        ['3', 'Header (Reports to), Journey, Records › Employment and Insights › Audit › Change history.'],
        ['4', 'Asset clearance: "Assets still in custody: … Take them back, or record a recoverable amount."'],
    ] });
    d.finale({ points: [
        'Structure first, then access, then policies, then people.',
        'Give the narrowest roles and scopes, require MFA, and test as each role.',
        'Every change is effective-dated and audited; verify the audit chain.',
        'Separation of duties: documents, letters, pay changes and settlements need a second person.',
        'Payroll finalising and imports are blocked today; known defects have workarounds.',
    ], next: ['Deck 03: HR administrator training (operations in more depth)', 'Deck 04: client onboarding and implementation playbook', 'Deck 07: troubleshooting and support', 'Deck 08: security, governance and compliance', 'Your Tenant Super Admin for role changes'] });
    return d;
};
