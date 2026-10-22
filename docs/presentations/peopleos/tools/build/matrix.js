// Writes FEATURE-TO-PRESENTATION-MATRIX.md: every workflow of WORKFLOW-INVENTORY.md with its final status label,
// its screenshot evidence and the decks that teach it (computed from tools/usage.json, written by build-all.js).
'use strict';
const fs = require('fs');
const path = require('path');
const { manifest, ROOT } = require('./lib');

const usage = JSON.parse(fs.readFileSync(path.join(__dirname, '../usage.json'), 'utf8'));
const usedIn = {};
for (const [deck, shots] of Object.entries(usage)) for (const s of shots) (usedIn[s] ??= new Set()).add(Number(deck));
const all = Object.keys(manifest());

const V = 'VERIFIED IN RUNNING APPLICATION', N = 'IMPLEMENTED BUT NOT VERIFIED END-TO-END', C = 'AVAILABLE ONLY AFTER CONFIGURATION',
    G = 'DISABLED BY SAFETY OR COMMERCIAL GATES', I = 'INCOMPLETE', X = 'NOT IMPLEMENTED', U = 'UNABLE TO VERIFY';

// [id, workflow, status, evidence regex, note]
const F = [
    ['E1', 'Sign in, Home, navigation', V, /^employee\/30-self-0[1-6]/, 'Wrong-password message generic by design'],
    ['E2', 'Search and quick launch', V, /^employee\/30-self-0[7-9]/, ''],
    ['E3', 'Own record (read only)', V, /^employee\/30-self-1[4-7]/, 'Records › Employment errors (new finding)'],
    ['E4', 'Documents and letters', V, /^employee\/30-self-1[89]/, 'Employees cannot upload their own documents'],
    ['E5', 'Request leave → decision → balance', V, /^(employee|manager)\/10-leave-/, 'Executed end to end'],
    ['E6', 'Check in and correct attendance', V, /^(employee|manager)\/20-att-/, 'Executed end to end'],
    ['E7', 'My work and notifications', V, /^employee\/30-self-1[0-3]/, ''],
    ['E8a', 'Payslips', G, /^employee\/30-self-20/, 'None can exist until payroll is finalised (statutory rules unverified)'],
    ['E8b', 'Tax declaration', I, /^employee\/30-self-2[12]/, 'Draft only; no employee submit (F13)'],
    ['E9', 'Goals, appraisal, career', V, /^employee\/30-self-(2[4-9]|3[01])/, 'Check-in and self review executed'],
    ['E10', 'Learning', V, /^employee\/30-self-3[2-4]/, 'Course started'],
    ['E11', 'My compensation', V, /^employee\/30-self-23/, ''],
    ['E12', 'HR requests and knowledge base', V, /^employee\/30-self-(3[5-9]|4[0-2])/, 'Request raised; policy acknowledged'],
    ['E13a', 'Surveys', C, /^employee\/30-self-43/, 'No survey open in the training data'],
    ['E13b', 'Give feedback', I, /^employee\/30-self-4[45]/, 'F17: error shown, HR not notified'],
    ['E14', 'Announcements', V, /^employee\/30-self-4[67]/, 'Acknowledged'],
    ['E15', 'Resignation', N, /^employee\/30-self-5[34]/, 'Form opened and cancelled for the employee; full exit flow in H17'],
    ['E16', 'Account security, preferences, phone, dark mode', V, /^employee\/30-self-(4[89]|5[01])/, 'MFA set-up not opened (secret)'],
    ['E17', 'Restricted access (employee)', V, /^employee\/30-self-52/, '403 "not available"'],
    ['M1', 'Manager Home and team', V, /^manager\/40-team-0[1-5]/, ''],
    ['M2', 'Team member record', V, /^manager\/40-team-0[6-8]/, 'Rewards and Operations not offered to managers'],
    ['M3–M4', 'Approve and reject leave', V, /^manager\/10-leave-/, 'Executed'],
    ['M5', 'Attendance corrections and exceptions', V, /^manager\/20-att-/, 'Executed'],
    ['M6', 'Leave calendar', V, /^manager\/40-team-10/, ''],
    ['M7', 'Goals, reviews, one-on-ones, feedback', V, /^manager\/40-team-(1[1-6]|2[3-5])/, 'Manager review refused until the stage opens (F21)'],
    ['M8–M9', 'Team learning, career, HR requests, tasks', V, /^manager\/40-team-(1[7-9]|2[016])/, ''],
    ['M10', 'Team insights', V, /^manager\/40-team-22/, ''],
    ['M11', 'Restricted access (manager)', V, /^manager\/40-team-09/, '404 "We could not find that"'],
    ['H1–H2', 'Directory, register, Employee 360', V, /^hr-admin\/50-360-/, 'History tab error outside production (F26)'],
    ['H3', 'Hire with duplicate check', V, /^hr-admin\/50-hire-/, 'Executed'],
    ['H4–H5', 'Change manager, transfer, lifecycle state', V, /^hr-admin\/50-chg-/, 'Lifecycle dialog opened and cancelled'],
    ['H6', 'Onboarding plans and tasks', V, /^(hr-admin|manager)\/50-onb-/, 'Template items error (new finding)'],
    ['H7', 'Documents with verification', V, /^(hr-admin|security-compliance)\/50-doc-/, 'SoD refusal shown as server error (new finding)'],
    ['H8', 'Attendance administration', V, /^hr-admin\/20-att-/, 'Executed'],
    ['H9a', 'Leave administration (balances, types, policy, calendar)', V, /^hr-admin\/55-lv-/, 'Balances search error (new finding)'],
    ['H9b', 'Apply on behalf (Leave requests)', I, /^support\/55-lv-err-01/, 'F38; workaround via Employee 360 verified'],
    ['H10a', 'Payroll calculate and check', V, /^hr-admin\/60-pay-(0[1-8]|1[1-7])/, 'Recalculate executed'],
    ['H10b', 'Payroll approve, finalise, payslips, bank file', G, /^hr-admin\/60-pay-09/, 'Blocked by unverified statutory rules'],
    ['H11', 'Statutory configuration', C, /^hr-admin\/60-stat-/, 'Rule verification is a platform task: 0 of 24 verified'],
    ['H12–H13', 'Performance and learning administration', V, /^hr-admin\/65-(grow|learn)-/, 'Opened; cycle not changed'],
    ['H14', 'Workforce planning and positions', V, /^hr-admin\/65-wf-/, 'Draft position created'],
    ['H15', 'Compensation (four-person chain)', V, /^(hr-admin|security-compliance)\/62-comp-/, 'Executed'],
    ['H16a', 'Letters (generate, approve, issue)', V, /^hr-admin\/52-let-/, 'See Deck 3'],
    ['H16b', 'Assets', V, /^hr-admin\/52-ast-/, ''],
    ['H16c', 'HR queue', V, /^hr-admin\/52-tkt-/, ''],
    ['H17', 'Exit and full & final settlement', V, /^(hr-admin|employee)\/70-/, 'Gratuity not calculated'],
    ['H18', 'Audit history', V, /^(analytics\/80-ins-1[0-2]|security-compliance\/80-ins-11)/, ''],
    ['H19', 'Employee import', I, /^(hr-admin\/75-|client-onboarding\/90-onb-9[1-3])/, 'F25 at Map columns'],
    ['C1', 'Tenant provisioning (operator)', V, /^client-onboarding\/90-onb-0[1-6]/, 'Executed (Northwind Retail)'],
    ['C2', 'First administrator activation', V, /^client-onboarding\/90-onb-(0[7-9]|1[0-2])/, 'E-mail sender shows "Laravel" in development'],
    ['C3', 'Admin Centre and configuration finder', V, /^client-onboarding\/(90-onb-1[34]|85-cfg-0[12])/, ''],
    ['C4', 'Company, legal entity, establishment, location', V, /^client-onboarding\/90-onb-(1[5-9]|2[0-3])/, 'Company creates a primary legal entity and establishment'],
    ['C5', 'Organisation structure and designer', V, /^client-onboarding\/90-onb-(2[4-9]|3[0-2])/, ''],
    ['C6', 'Job architecture and positions', V, /^client-onboarding\/90-onb-3[3-7]/, 'FTE defect F8'],
    ['C7', 'Hire and person matching', V, /^client-onboarding\/90-onb-(3[89]|4[0-7])/, ''],
    ['C8', 'Roles, users and scope', V, /^client-onboarding\/(90-onb-(5[4-9]|60)|85-acc-)/, ''],
    ['C9', 'Shifts, schedules, holiday calendars', V, /^client-onboarding\/90-onb-6[1-6]/, 'Holiday type and half day ignored (F39)'],
    ['C10', 'Leave types and policy', V, /^client-onboarding\/90-onb-(6[78]|7[0-2])/, 'Policy published'],
    ['C11', 'Salary components and structures', V, /^client-onboarding\/90-onb-7[3-5]/, ''],
    ['C12', 'Statutory registrations and profiles', V, /^client-onboarding\/90-onb-(76|77|78|79)/, 'Verification blocked (platform)'],
    ['C13', 'Workflows and policies', V, /^client-onboarding\/(90-onb-(8[23]|94)|85-wf-0[1-6])/, 'Builder opened; publish not executed'],
    ['C14a', 'Notification rules', I, /^client-onboarding\/(90-onb-84|85-wf-0[7-9])/, 'No create/delete (F18)'],
    ['C14b', 'Security policy, settings, feature flags', V, /^client-onboarding\/90-onb-8[5-7]/, 'Viewed, not saved'],
    ['C15', 'Change Centre, audit, readiness', V, /^client-onboarding\/90-onb-(8[89]|90)/, 'Chain intact: 287 events'],
    ['C16', 'UAT, go-live, hypercare, handover', X, null, 'Process stages outside the application (checklists provided)'],
    ['P1', 'Operator sign-in with MFA', V, /^platform-admin\/0[1-3]/, 'QR code and key covered'],
    ['P2', 'Tenant lifecycle (suspend, reactivate, enter)', V, /^platform-admin\/95-pl-(0[4-9]|3[56])/, 'Executed on Northwind'],
    ['P3', 'Signups', G, /^platform-admin\/95-pl-10/, 'Public registration off'],
    ['P4', 'Plans, subscriptions, entitlements', G, /^platform-admin\/95-pl-1[1-3]/, 'Shadow mode'],
    ['P5', 'Billing, tax, payments, invoices, approvals', G, /^platform-admin\/95-pl-(1[4-9]|20)/, 'No prices; live payments refused'],
    ['P6', 'Operations', V, /^platform-admin\/95-pl-21/, 'No scheduler or worker locally'],
    ['P7', 'Platform audit trail', V, /^platform-admin\/95-pl-(22|37)/, 'Chains not recently verified (no scheduler)'],
    ['P8', 'Readiness', V, /^platform-admin\/95-pl-23/, ''],
    ['P9a', 'Legal hold', V, /^platform-admin\/95-pl-(2[5-7]|3[34])/, 'Release needs a second operator (pending)'],
    ['P9b', 'Tenant export', N, /^(platform-admin\/95-pl-32|security-compliance\/85-sec-07)/, 'Dialogs only; generation not run'],
    ['P9c', 'Retention policies', C, /^platform-admin\/95-pl-28/, 'None in force (D-8)'],
    ['P9d', 'Tenant deletion', G, /^platform-admin\/95-pl-24/, 'Switched off'],
    ['P10', 'Statutory rule verification', U, /^platform-admin\/95-pl-(29|3[01])/, 'Needs official evidence and two operators'],
    ['R1', 'Dashboards, People analytics, Workforce pulse', V, /^analytics\/80-ins-0[1-4]/, 'Executive datasets missing (F45)'],
    ['R2', 'Reports run and CSV export', V, /^analytics\/80-ins-0[5-7]/, 'Export audited'],
    ['R3', 'Report builder', I, /^analytics\/80-ins-08/, 'F44'],
    ['R4', 'Area dashboards (pay, growth, workforce, exit, service)', V, /^analytics\/(62-comp-11|65-|80-ins-1[4-6])/, 'Small groups suppressed'],
    ['R5', 'Ask PeopleOS', V, /^analytics\/80-ins-17/, 'Language model off; rule-based answers'],
    ['S1', 'Integrations: API keys, webhooks, SSO, hub, inbound events', N, /^security-compliance\/85-sec-0[2-6]/, 'Forms only; no round trip; F37'],
    ['S2', 'Markedge internal tenant', V, /^internal-operations\//, 'Provisioned and staffed with fictional employees'],
    ['S3', 'Sign-in support (reset, invited, suspended)', V, /^support\/99-sup-/, 'Executed'],
];

const rows = F.map(([id, name, status, re, note]) => {
    const shots = re ? all.filter((n) => re.test(n)) : [];
    const decks = new Set(); for (const s of shots) for (const dk of usedIn[s] ?? []) decks.add(dk);
    return { id, name, status, shots, decks: [...decks].sort((a, b) => a - b), note };
});
const esc = (t) => String(t).replace(/\|/g, '\\|');
let md = `# PeopleOS training library: feature-to-presentation matrix

**Generated:** ${new Date().toISOString().slice(0, 10)} by \`tools/build/matrix.js\`. **For:** reviewers checking coverage and status.
Each row is a workflow from \`WORKFLOW-INVENTORY.md\` with its status label after capture, the number of genuine
screenshots that evidence it, and the decks that actually embed those screenshots (from the build). Screenshot names
are in \`SCREENSHOT-INVENTORY.md\`; claims and sources are in \`PEOPLEOS-PRESENTATION-ACCURACY-REGISTER.md\`.

| Status | Workflows |
|---|---|
${[V, N, C, G, I, X, U].map((s) => `| ${s} | ${rows.filter((r) => r.status === s).length} |`).join('\n')}

| ID | Workflow | Status | Screenshots | Decks | Note |
|---|---|---|---|---|---|
${rows.map((r) => `| ${r.id} | ${esc(r.name)} | ${r.status} | ${r.shots.length} | ${r.decks.join(', ') || '—'} | ${esc(r.note)} |`).join('\n')}

Rows with 0 screenshots or no deck are gaps by design (C16 is a process outside the application) or must be reviewed.
`;
fs.writeFileSync(path.join(ROOT, 'FEATURE-TO-PRESENTATION-MATRIX.md'), md);
const gaps = rows.filter((r) => r.id !== 'C16' && (!r.shots.length || !r.decks.length));
console.log(`matrix: ${rows.length} workflows; without screenshots or deck: ${gaps.map((g) => g.id).join(', ') || 'none'}`);
