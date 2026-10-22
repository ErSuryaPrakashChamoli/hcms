// Deck 4 — Client onboarding and implementation playbook (highest priority).
// Screens: a fictional customer, Northwind Retail Pvt Ltd, provisioned and configured from an empty tenant on the
// training database (tools/capture/90-onboarding-*.mjs), plus tenant configuration screens from the Demo tenant.
'use strict';
const common = require('./common');
const { STAGES } = require('../stages');

module.exports = (lib) => {
    const { Deck, has } = lib;
    const d = new Deck({
        num: 4, file: '04-PeopleOS-Client-Onboarding-and-Implementation-Playbook.pptx', short: 'Client onboarding playbook',
        title: 'Client Onboarding and Implementation Playbook',
        subtitle: 'How Markedge takes a new corporate customer from kickoff to an operating PeopleOS tenant, stage by stage, on the real screens.',
        audience: 'Markedge implementation specialists, the customer\'s implementation team, and Markedge platform operators',
    });
    const st = (id) => STAGES.find((s) => s.id === id);
    const C = (n) => `client-onboarding/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 4: missing ${name}`); };
    const wide = (name, o) => { if (has(name)) d.wide({ img: name, ...o }); else lib.WARN.push(`deck 4: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 4: missing ${o.left.img} / ${o.right.img}`); };
    const NW = 'Executed on the training database on 21 Oct 2026: tenant "Northwind Retail Pvt Ltd" (fictional) provisioned by the platform operator and configured by its first administrator, Farah Khan (fictional).';

    d.cover({ shot: C('90-onb-31-designer-tree'), notes: `SAY: This is the playbook for taking a new customer live on PeopleOS. Every screen was produced by doing the work in a fresh tenant for a fictional customer, Northwind Retail.\nEVIDENCE: ${NW}\nAUDIENCE: Markedge implementation specialists and the customer's implementation team. Operator steps are for Markedge platform operators only.` });
    d.statusLegend();
    common.statusDetailed(d, 'Release status');
    common.environment(d, 'About this training', 'hcm_training_showcase: the fictional Demo Group plus a fictional customer, Northwind Retail, provisioned and configured from empty during this training.');
    d.numbered({
        title: 'What you will be able to do', eyebrow: 'Learning objectives',
        items: [
            ['Separate the two administrator roles', 'Markedge platform operator versus the customer\'s tenant administrator'],
            ['Provision and hand over a tenant', 'operator workflow, invitation, first sign-in'],
            ['Build the organisation', 'company, legal entity, establishment, location, units, job architecture'],
            ['Load people safely', 'hire wizard, duplicate check, reporting lines; import status'],
            ['Configure policies and payroll inputs', 'attendance, leave, pay, statutory, workflows, security'],
            ['Validate, test and go live', 'reconciliation, UAT, access checks, go/no-go, handover'],
        ],
        notes: 'SAY: The order of the stages matters: structure before people, people before policies, everything before UAT.\nRESOURCES: supporting/PeopleOS-Customer-Implementation-Checklist.xlsx (one row per stage) and supporting/PeopleOS-Go-Live-Handover-Checklist.xlsx.',
    });
    cmp({ title: 'Two administrators, two different jobs', eyebrow: 'Roles',
        left: { label: 'Markedge platform operator', img: C('90-onb-01-platform-home'), callouts: false, caption: 'Signs in with MFA, works outside any tenant: creates tenants, watches platform health. Never configures the customer\'s HR data.' },
        right: { label: 'Customer tenant administrator', img: C('90-onb-12-empty-tenant-home'), callouts: false, caption: 'Invited by the operator; configures the organisation, people, policies and access inside one tenant.' },
        notes: `SAY: The operator console and the customer's tenant are the same application, but different accounts with different permissions. Operators enter a tenant only explicitly, and every action is audited.\nEVIDENCE: ${NW}` });
    const tone = (m) => (m.startsWith('Blocked') ? 'bad' : m.startsWith('Manual') ? 'manual' : m.startsWith('Operator') ? 'op' : m.startsWith('Automated') ? 'auto' : null);
    const phases = [['Plan', [1]], ['Provision', [2, 3]], ['Structure', [4, 5, 6, 7, 8]], ['People data', [9, 10, 11, 12, 13]], ['Policies and pay', [14, 15, 16, 17, 18, 19, 20]], ['Validate', [21, 22, 23, 24, 25]], ['Go live', [26, 27, 28]]];
    d.phaseMap({ title: '28 stages in seven phases', eyebrow: 'The journey',
        phases: phases.slice(0, 4).map(([name, ids]) => ({ name, items: ids.map((i) => [`${i}. ${st(i).name}`, tone(st(i).mode)]) })),
        caption: 'Light blue = configured in PeopleOS · lilac = Markedge operator action · green = automated · grey = manual, outside PeopleOS · red = blocked.',
        notes: 'SAY: This slide and the next show all 28 stages. Red stages cannot be completed today: data import (defect F25), statutory verification (0 of 24 rules) and go-live approval (release gate NO-GO).' });
    d.phaseMap({ title: '28 stages in seven phases (continued)', eyebrow: 'The journey',
        phases: phases.slice(4).map(([name, ids]) => ({ name, items: ids.map((i) => [`${i}. ${st(i).name}`, tone(st(i).mode)]) })),
        caption: 'Every stage has a card in this deck with: responsible party, inputs, navigation, what to configure, expected outcome, verification, evidence, common errors, dependencies and acceptance criteria.' });

    // ---- Phase 1: Plan
    d.section(1, 'Plan', 'Agree scope, data owners and the timeline before anything is configured.');
    d.stageCard(st(1));
    d.cards({ title: 'Ask the customer for these before kickoff', eyebrow: 'Stage 1 · Discovery',
        cards: [
            { head: 'Organisation', body: 'Companies and legal employers, registered establishments, sites, business units, departments, teams, cost centres.' },
            { head: 'Jobs and people', body: 'Designations, levels, grades, employment types; the employee master with managers; who the HR, payroll and IT owners are.' },
            { head: 'Policies', body: 'Leave types and rules, shifts and weekly patterns, holiday lists per location, approval chains, notification needs.' },
            { head: 'Pay and statutory', body: 'Salary components and structures, registrations (PF, ESI, PT, LWF, TAN). Decide in writing whether payroll is in scope while rules are unverified.', tone: 'warn' },
            { head: 'Access', body: 'Who administers, who approves, which HR users cover which companies; MFA and SSO (OIDC) decisions.' },
            { head: 'Integrations', body: 'Attendance devices, identity provider, API/SCIM consumers, webhooks. Recruitment systems connect only through the Integration Hub.' },
        ],
        notes: 'SAY: Collect personal data through the customer\'s secure channel, never by e-mail.\nAVOID: committing to a payroll go-live date: statutory verification is a release gate (0 of 24 rules verified).' });

    // ---- Phase 2: Provision
    d.section(2, 'Provision', 'A Markedge operator creates the tenant; the customer\'s first administrator activates it.');
    d.stageCard(st(2));
    shot(C('90-onb-02-tenants-list'), { title: 'The operator\'s Tenants list', eyebrow: 'Stage 2 · Provisioning',
        steps: ['New tenant starts provisioning.', 'Enter works inside a tenant (audited).', 'Suspend blocks a tenant\'s sign-ins.'],
        result: 'Admin › Platform › Tenants lists every tenant with slug, status, country and users.',
        notes: `SAY: Only platform operators see this page. Operators must use an authenticator app (MFA) to get here.\nEVIDENCE: ${NW}` });
    shot(C('90-onb-03-new-tenant-form'), { title: 'Create the tenant', eyebrow: 'Stage 2 · Provisioning',
        steps: ['Tier (shared infrastructure).', 'Data region (metadata today).', 'Slug: unique; suggested from the name.', 'Locale defaults: country, zone, currency.', 'First administrator: name, e-mail.', 'Reason for change (audited).'],
        result: 'The first administrator is invited as Tenant Super Admin; operators never see passwords.',
        notes: `OBSERVE: Northwind Retail Pvt Ltd, IN, Asia/Kolkata, INR; first administrator Farah Khan (fictional). The slug is suggested from the name and replaced the typed "northwind" with "northwind-retail-pvt-ltd": check it before saving.\nSAY: Tier and region are recorded but do not route data to a separate database today (deferred).\nEVIDENCE: ${NW}` });
    cmp({ title: 'Duplicates are refused; the new tenant appears', eyebrow: 'Stage 2 · Provisioning',
        left: { label: 'Same tenant submitted again', tone: 'bad', img: C('90-onb-04-duplicate-tenant-refused'), crop: { x: 400, y: 360, w: 900, h: 980 }, callouts: ['1', '2'], caption: '"The slug has already been taken." and "The email has already been taken." Nothing is created.' },
        right: { label: 'Tenants list', tone: 'good', img: C('90-onb-05-tenants-with-northwind'), callouts: ['2'], caption: 'Northwind Retail is Active with 1 user (the invited administrator). Send invitation re-sends the e-mail.' },
        notes: 'SAY: One e-mail address can belong to only one organisation (owner decision D-17 is still open). Use the customer administrator\'s company address.' });
    shot(C('90-onb-06-tenant-edit'), { title: 'Operator-only tenant settings', eyebrow: 'Stage 2 · Provisioning',
        steps: ['Status is read-only here.', 'Lifecycle changes use Suspend / Reactivate on the list.', 'Tier and region metadata.'],
        result: 'Every save asks for a reason and is listed in the tenant\'s History.' });
    d.stageCard(st(3));
    shot(C('90-onb-07-invitation-email'), { title: 'The invitation e-mail', eyebrow: 'Stage 3 · Activation', source: 'E-mail sent by PeopleOS, rendered from the outgoing message (link removed). Not an application screen.',
        steps: ['Accept the invitation opens the activation page.', 'The link works once and expires (72 hours by default).'],
        result: 'Shown here: the outgoing message rendered in a browser, link removed. The sender appears as "Laravel" because the application name and mail sender are not set in this development environment: set APP_NAME and MAIL_FROM_NAME in production.',
        notes: 'NOTE: This is the e-mail PeopleOS sent, rendered from the training server\'s mail log; it is not an application screen.\nACTION FOR MARKEDGE: production mail configuration (sender name, domain) is part of hosting readiness.' });
    cmp({ title: 'Activate the account', eyebrow: 'Stage 3 · Activation',
        left: { label: 'Accept your invitation', img: C('90-onb-08-accept-invitation'), callouts: ['1', '2', '3'], caption: 'Choose a password, confirm it, then Activate my account.' },
        right: { label: 'Password rules', tone: 'bad', img: C('90-onb-09-password-rules'), callouts: ['1'], caption: '"The password field must be at least 8 characters." The account stays invited until a valid password is set.' } });
    cmp({ title: 'Ready to sign in; the link cannot be reused', eyebrow: 'Stage 3 · Activation',
        left: { label: 'After activation', tone: 'good', img: C('90-onb-10-account-ready-sign-in'), callouts: false, caption: '"Your account is ready. Sign in with your new password."' },
        right: { label: 'Same link opened again', tone: 'bad', img: C('90-onb-11-invitation-used'), callouts: false, caption: '"This invitation link is invalid or has expired. Ask your administrator to send a new one."' },
        notes: 'SUPPORT: if a customer reports an expired link, the operator uses Send invitation on the Tenants list (stage 2).' });
    shot(C('90-onb-12-empty-tenant-home'), { title: 'First sign-in: an empty tenant', eyebrow: 'Stage 3 · Activation',
        steps: ['Open Admin Centre: the administrator\'s starting point.', 'Governance warnings to act on (MFA not required; unscoped approvers).', 'At a glance: 1 active user, 14 system roles.'],
        result: 'The tenant comes with system roles, default settings, starter policies and seeded reference lists, but no organisation or people.',
        notes: 'SAY: Act on the governance items before inviting anyone else: require MFA (stage 20) and plan HR scope (stage 12).' });

    // ---- Phase 3: Structure
    d.section(3, 'Structure', 'Build the organisation the people will sit in: companies, legal employers, sites, units and jobs.');
    shot(C('90-onb-13-admin-centre'), { title: 'Admin Centre: every module by area', eyebrow: 'Where configuration lives',
        steps: ['Organisation and positions.', 'Access and security.', 'Change Centre (governed changes).'], result: 'Admin › All modules. Search finds a module by name.' });
    shot(C('90-onb-14-configuration-finder'), { title: 'Not sure where a setting is? Ask the finder', eyebrow: 'Where configuration lives',
        steps: ['Organisation structure.', 'Statutory compliance.', 'Access and security.'], result: 'Admin › Setup › What do you want to configure? groups set-up screens and individual settings by topic.' });
    d.stageCard(st(4));
    for (const [n, f, title, stp, res] of [
        [4, '90-onb-15-companies-empty', 'An empty Companies list', ['Start here: New company.'], 'A new tenant has no company yet.'],
        [4, '90-onb-16-new-company', 'Create the company', ['Company details.'], 'Company name, legal name, code and effective date.'],
        [4, '90-onb-17-company-saved', 'Company saved', ['The new company with its code and effective date.'], 'Northwind Retail (NWR), effective 01 Apr 2026.'],
    ]) shot(C(f), { title, eyebrow: `Stage ${n} · Company`, steps: stp, result: res });
    d.stageCard(st(5));
    shot(C('90-onb-18-new-legal-entity'), { title: 'Add a further legal employer (only if needed)', eyebrow: 'Stage 5 · Legal entity', steps: [], result: 'Creating the company already created a primary legal entity; complete it, and add another only for an additional legal employer. A review of the values appears before Create.' });
    shot(C('90-onb-19-legal-entity-saved'), { title: 'Legal entities listed', eyebrow: 'Stage 5 · Legal entity', steps: ['The legal entity with its company and status.'], result: 'The training run shows the automatic primary entity and one added by hand (the establishment that follows hangs off the added one). For a single employer, complete the automatic entity instead.' });
    d.stageCard(st(6));
    shot(C('90-onb-20-new-establishment'), { title: 'Add a registered establishment', eyebrow: 'Stage 6 · Establishments', steps: [], result: 'A registered place of work; its state drives state-level rules (professional tax, labour welfare fund). A principal establishment already exists for the company.' });
    shot(C('90-onb-21-establishment-saved'), { title: 'Establishment listed', eyebrow: 'Stage 6 · Establishments', steps: ['Bengaluru head office under the legal entity.'], result: 'Statutory profiles attach to establishments (stage 17).' });
    shot(C('90-onb-22-new-location'), { title: 'Add the physical location', eyebrow: 'Stage 6 · Locations', callouts: false, steps: [], result: 'A physical site for people, assets and attendance; used in the hire wizard and in holiday and schedule rules.' });
    shot(C('90-onb-23-location-saved'), { title: 'Location listed', eyebrow: 'Stage 6 · Locations', steps: ['Bengaluru HQ (BLR) for the company.'], result: 'Known limit: a location cannot be linked to an establishment on screen (F10).' });
    d.stageCard(st(7));
    shot(C('90-onb-24-new-business-unit'), { title: 'Create a business unit', eyebrow: 'Stage 7 · Structure', steps: ['Business unit details for the company.'], result: 'Retail Operations: the parent of the store departments.' });
    shot(C('90-onb-25-new-department'), { title: 'Create organisation units', eyebrow: 'Stage 7 · Structure', steps: ['Company.', 'Name.', 'Effective from.'], result: 'Units are effective-dated; their place in the hierarchy is set in the Organisation Designer.' });
    shot(C('90-onb-26-departments'), { title: 'Departments listed', eyebrow: 'Stage 7 · Structure', steps: ['Store Operations and People & Culture with their dates.'], result: 'Business units, divisions, teams, cost and profit centres follow the same pattern.' });
    shot(C('90-onb-29-designer-add-root'), { title: 'Organisation Designer: add the root', eyebrow: 'Stage 7 · Structure', steps: ['Unit type: Company.', 'Use an existing unit.', 'Reason for change.'], result: 'The tree starts from the company.' });
    shot(C('90-onb-30-designer-add-child'), { title: 'Add units under their parents', eyebrow: 'Stage 7 · Structure', steps: ['Unit type: Business Unit.', 'Existing unit: Retail Operations.', 'Reason for change.'], result: 'Each placement is recorded in the audit trail.' });
    cmp({ title: 'Check the tree and the chart', eyebrow: 'Stage 7 · Structure',
        left: { label: 'Tree', img: C('90-onb-31-designer-tree'), callouts: ['1', '2'], caption: 'Company → business unit → department → team. Each node: Add child, Rename, Move, Deactivate, Remove from tree.' },
        right: { label: 'Chart', img: C('90-onb-32-designer-chart'), callouts: false, caption: 'The same structure as a chart. The Org map adds people once they are hired.' } });
    d.stageCard(st(8));
    shot(C('90-onb-33-seeded-employment-types'), { title: 'Start from the seeded reference lists', eyebrow: 'Stage 8 · Job architecture', steps: ['Seeded value.', 'Add your own.'], result: 'Employment types, categories, work modes and levels L1–L6 are seeded; designations, grades and job families start empty.' });
    shot(C('90-onb-34-new-designation'), { title: 'Add designations', eyebrow: 'Stage 8 · Job architecture', steps: ['Name.', 'Code.', 'Level.', 'Effective from.'], result: 'Designations carry the job attributes used on hire.' });
    shot(C('90-onb-35-designations'), { title: 'Designations listed', eyebrow: 'Stage 8 · Job architecture', steps: ['Store Manager (L4), Retail Associate (L2), HR Executive (L3).'], result: 'These appear in the hire wizard\'s Position step.' });
    shot(C('90-onb-36-new-position'), { title: 'Positions (optional)', eyebrow: 'Stage 8 · Positions', steps: ['Code.', 'Title.', 'Grade.', 'Seats.', 'FTE: leave blank (defect F8 refuses whole numbers).'], result: 'A draft position; it is proposed, approved and opened before it is filled.' });
    shot(C('90-onb-37-positions'), { title: 'Draft position listed', eyebrow: 'Stage 8 · Positions', steps: ['POS-SM-01 in Draft (the form on the previous slide was re-shot later with another code and cancelled).'], result: 'Use positions when the customer manages headcount by seat; otherwise skip.' });

    // ---- Phase 4: People data
    d.section(4, 'People data', 'Prepare the data, load people once, set managers, invite users with the right reach.');
    d.stageCard(st(9));
    d.stageCard(st(10));
    shot(C('90-onb-91-import-upload'), { title: 'Employee import: upload the file', eyebrow: 'Stage 10 · Data load', steps: ['Upload CSV.'], result: 'The import then goes Inspect → Map columns → Validate → Approve → Run.' });
    cmp({ title: 'Inspect, then map the columns', eyebrow: 'Stage 10 · Data load',
        left: { label: 'Inspected', img: C('90-onb-92a-import-inspected'), callouts: false, caption: 'Headers and row count read from the file; status inspected.' },
        right: { label: 'Map columns', img: C('90-onb-92-import-map-columns'), callouts: false, caption: 'Each CSV column mapped to a PeopleOS field; on duplicates: update or skip.' } });
    shot(C('90-onb-93-import-map-fails'), { title: 'Data import today: blocked at Map columns', eyebrow: 'Stage 10 · Data load',
        steps: ['Map columns fails with "Undefined variable $svc".'], status: 'INCOMPLETE',
        result: 'Imports cannot pass "inspected" from the screen (defect F25). Load people with the Hire employee wizard, or through POST /api/v1/employees (not exercised in this training) until fixed.' });
    d.stageCard(st(11));
    shot(C('90-onb-38-hire-person'), { title: 'Hire employee: 1 Person', eyebrow: 'Stage 10–11 · Hire wizard', steps: ['Step 1 of 4.', 'Name.', 'Personal e-mail.'], result: 'The person is checked against existing people before you continue.' });
    shot(C('90-onb-45-duplicate-person'), { title: 'Duplicate check before anything is created', eyebrow: 'Stage 11 · Person matching', steps: ['"This person already exists".', 'Re-use the existing person.'], result: 'Definite matches are refused; possible matches are warned. One person, one lifetime record.' });
    shot(C('90-onb-39-hire-employment'), { title: 'Hire employee: 2 Employment', eyebrow: 'Stage 10 · Hire wizard', steps: ['Joining date.', 'Employee code (generated when blank).', 'Work e-mail.'] });
    shot(C('90-onb-40-hire-position'), { title: 'Hire employee: 3 Position', eyebrow: 'Stage 10 · Hire wizard', steps: ['Company (the only required field).', 'Department.', 'Designation.', 'Employment type.'], result: 'Values come from the structure built in stages 4–8.' });
    shot(C('90-onb-41-hire-manager'), { title: 'Hire employee: 4 Manager', eyebrow: 'Stage 10 · Hire wizard', steps: ['Line manager (none for the first manager).', 'Reason for change.', 'Create employee.'] });
    shot(C('90-onb-43-hire-created'), { title: 'Employee created', eyebrow: 'Stage 10 · Hire wizard', steps: ['Confirmation.', 'The new Employee 360: Preboarding, one lifetime record.'], result: 'Vivek Rao (EMP00001), joins Nov 2026.' });
    shot(C('90-onb-46-mark-as-joined'), { title: 'Mark as joined on the first day', eyebrow: 'Stage 10 · Lifecycle', steps: ['Reason for change.'], result: 'Records the actual joining date (here earlier than the planned date) and moves Preboarding → Joined → Probation.' });
    shot(C('90-onb-47-joined-probation'), { title: 'Now in probation', eyebrow: 'Stage 10 · Lifecycle', steps: ['"Joined — Now in probation."'], result: 'Load managers first and mark them as joined, so they can be chosen as line managers.' });
    d.stageCard(st(12));
    shot(C('90-onb-54-roles'), { title: 'Roles: 14 system roles arrive with the tenant', eyebrow: 'Stage 12 · Access', steps: ['A system role with its permission and user counts.', 'New role (custom roles).'], result: '14 roles, from Account Owner to Tenant Super Admin. Only a Tenant Super Admin edits role permissions.' });
    shot(C('90-onb-55-role-permissions'), { title: 'What a role may do', eyebrow: 'Stage 12 · Access', steps: [], result: 'Permission keys grouped by module. Changes are audited with the keys granted and removed.' });
    shot(C('90-onb-56-new-user'), { title: 'Invite a user with a role and a scope', eyebrow: 'Stage 12 · Access', steps: ['Work e-mail.', 'Roles.', 'Access scope: company.', 'Create user.'], result: 'The user receives an invitation e-mail and chooses a password; nobody else ever sees it.' });
    cmp({ title: 'Invited, then listed', eyebrow: 'Stage 12 · Access',
        left: { label: 'After Create user (development mode)', tone: 'bad', img: C('90-onb-57-user-created'), callouts: false, caption: 'The History panel errors (F26, non-production strict mode). The user was still created and invited, as the list shows.' },
        right: { label: 'Users', img: C('90-onb-58-users'), callouts: ['1'], caption: 'Farah Khan (Tenant Super Admin, Active) and Rohit Menon (HR Executive, Invited).' } });
    cmp({ title: 'Check what the role really sees', eyebrow: 'Stage 12 · Access',
        left: { label: 'HR Executive Home', img: C('90-onb-59-hr-executive-home'), callouts: false, caption: 'A narrower menu than the administrator; Home shows people operations.' },
        right: { label: 'Roles, as an HR Executive', tone: 'bad', img: C('90-onb-60-hr-executive-roles-denied'), callouts: false, caption: 'Refused: the HR Executive role does not include role.view.' },
        notes: 'SAY: Always test access by signing in as each role (with test accounts in UAT), not only as the super administrator.' });
    d.stageCard(st(13));
    shot(C('90-onb-44-hire-with-manager'), { title: 'Only employed people can be managers', eyebrow: 'Stage 13 · Reporting lines', callouts: ['1'], steps: ['Line manager search finds nobody.', ['·', '"No options match your search."']], result: '"No options match your search": Vivek Rao was still Preboarding. Hire without a manager, then use Change manager.' });
    shot(C('90-onb-48-change-manager-context'), { title: 'Change manager: 1 Context', eyebrow: 'Stage 13 · Reporting lines', steps: ['Which relationship changes (line, dotted, project…).', 'Next.'] });
    shot(C('90-onb-49-change-manager-change'), { title: 'Change manager: 2 Change', eyebrow: 'Stage 13 · Reporting lines', steps: ['New manager.', 'Effective from.'] });
    shot(C('90-onb-50-change-manager-review'), { title: 'Change manager: 3 Review', eyebrow: 'Stage 13 · Reporting lines', steps: ['Reason for change.', 'Confirm reporting change.'], result: 'Before → After shown side by side.' });
    shot(C('90-onb-51-reporting-changed'), { title: 'Reporting line recorded', eyebrow: 'Stage 13 · Reporting lines', steps: ['Confirmation.', 'Reports to.'], result: 'Ishita Sen now reports to Vivek Rao; the change is in her history.' });
    cmp({ title: 'Verify: org map and directory', eyebrow: 'Stage 13 · Reporting lines',
        left: { label: 'Org map', img: C('90-onb-52-org-map-with-people'), callouts: ['1'], caption: 'Vivek Rao with Ishita Sen reporting to him.' },
        right: { label: 'Directory', img: C('90-onb-53-directory'), callouts: false, caption: 'The three Northwind employees and their manager. Role, department and location show "—": these hires were placed with the company only.' } });

    // ---- Phases 5–7 are appended by 04-onboarding-part2.js once the remaining captures exist.
    require('./onboarding-part2')(d, lib, { st, C, shot, wide, cmp, NW });
    return d;
};
