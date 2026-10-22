// Insights and analytics (Deck 12): analytics pages, dashboards, reports (run + export EXECUTED), report builder (F44),
// audit/change history, AI interactions, Ask PeopleOS (one question EXECUTED). Disposable training database.
import { start, open, go, shot, finish, idle, toasts, closeToasts, cancelModal } from './lib.mjs';
import { mkdirSync } from 'node:fs';

const s = await start('80-insights');
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;
const step = async (name, fn) => { if (ONLY && !ONLY.includes(name)) return; try { await fn(); } catch (e) { console.log(`  ✗ ${name}: ${String(e).split('\n')[0].slice(0, 200)}`); } };
const A = { deck: [12], workflow: 'Analytics and dashboards', prereq: 'Analytics permissions per dataset; privacy minimum group size' };
const R = { deck: [12], workflow: 'Reports: run and export', prereq: 'report.view / report.manage and permission on the report’s dataset' };
const AU = { deck: [12, 8], workflow: 'Audit and change history', prereq: 'audit.view (Tenant HR Admin, HR Manager, Payroll Admin, Auditor, Account Owner)' };

const adm = await open(s, 'admin', '/admin/people-analytics');
await step('people analytics', () => shot(s, adm, 'analytics/80-ins-01-people-analytics', { ...A, deck: [12, 9], screen: 'Insights › Analytics › People analytics', action: 'Open People analytics (Tenant HR Admin)',
    expected: 'Sections People, Workforce, Attendance, Leave, Performance, Learning, Career & talent, Compensation, HR service, Engagement — each with a detail link', fullPage: true }));
await step('command centre', async () => {
    await go(adm, '/admin/workforce-command-centre');
    await shot(s, adm, 'analytics/80-ins-02-workforce-pulse', { ...A, deck: [12, 9], screen: 'Insights › Analytics › Workforce Command Centre (Workforce pulse)', action: 'Open the Workforce Command Centre',
        expected: 'Movement (moves, promotions, joiners, exits vs last month), size, attendance, performance, critical skills, exceptions, decisions and planning', fullPage: true });
});
await step('workforce intelligence', async () => {
    await go(adm, '/admin/workforce-intelligence');
    await shot(s, adm, 'analytics/80-ins-03-workforce-intelligence', { ...A, screen: 'Insights › Analytics › Workforce Intelligence', action: 'Open Workforce Intelligence',
        expected: 'Attrition and capacity signals and critical skills, each with its reason', fullPage: true });
});
await step('dashboard viewer', async () => {
    await go(adm, '/admin/dashboard-viewer');
    await shot(s, adm, 'analytics/80-ins-04-hr-overview-dashboard', { ...A, deck: [12, 9], screen: 'Insights › Analytics › Dashboards (HR overview)', action: 'Open Dashboards',
        expected: 'Tiles: headcount, attrition (12 months), absenteeism (30 days), people cost, headcount trend and by department, needs attention, mandatory learning', fullPage: true });
});
await step('reports list', async () => {
    await go(adm, '/admin/reports');
    await shot(s, adm, 'analytics/80-ins-05-reports-list', { ...R, screen: 'Insights › Analytics › Reports', action: 'Open Reports',
        expected: 'Six starter reports with dataset, chart type and schedule; Run and Edit per report; New report',
        callouts: [{ loc: adm.locator('tbody tr').filter({ hasText: 'Headcount by department' }), n: 1, label: 'Starter report' }, { loc: adm.locator('tbody tr').filter({ hasText: 'Headcount by department' }).getByText('Run', { exact: true }), n: 2, label: 'Run' }, { loc: adm.getByText('New report', { exact: true }), n: 3, label: 'New report' }] });
});
await step('report run', async () => {
    await adm.locator('tbody tr').filter({ hasText: 'Headcount by department' }).getByText('Run', { exact: true }).first().click();
    await idle(adm, 2500);
    await shot(s, adm, 'analytics/80-ins-06-report-result', { ...R, screen: 'Report: Headcount by department', action: 'Run the starter report',
        expected: 'Chart and grouped results computed from the records this user may see; Export CSV',
        callouts: [{ loc: adm.getByText(/GROUPED RESULTS/i), n: 1, label: 'Grouped results' }, { loc: adm.getByRole('button', { name: 'Export CSV' }), n: 2, label: 'Export CSV' }], fullPage: true });
});
await step('report export', async () => {
    await closeToasts(adm);
    const dl = adm.waitForEvent('download', { timeout: 15_000 }).catch(() => null);
    await adm.getByRole('button', { name: 'Export CSV' }).first().click();
    const d = await dl;
    await idle(adm, 1500);
    const t = (await toasts(adm)).join(' / ');
    console.log('    toast:', t, 'download:', d ? d.suggestedFilename() : 'none');
    if (d) { mkdirSync(new URL('../.work/downloads', import.meta.url).pathname, { recursive: true }); await d.saveAs(new URL(`../.work/downloads/${d.suggestedFilename()}`, import.meta.url).pathname); }
    await adm.evaluate(() => window.scrollTo(0, 0));
    await shot(s, adm, 'analytics/80-ins-07-report-exported', { ...R, screen: 'Report (after Export CSV)', action: 'Export CSV (executed)',
        expected: d ? `A CSV file is downloaded (${d.suggestedFilename()}); the export is recorded as a report run` : `Observed: ${t || 'no message'}`,
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
});
await step('report builder', async () => {
    await go(adm, '/admin/reports/create');
    await adm.locator('[id="form.name"]').fill('Leave by department (training example)');
    const ds = adm.locator('[id="form.dataset"]');
    const opts = (await ds.locator('option').allInnerTexts()).map((t) => t.trim()).filter((t) => t && !/Select/.test(t));
    await ds.selectOption({ index: 1 });
    await idle(adm, 2000);
    const groupOpts = (await adm.locator('[id="form.definition.group_by"] option').allInnerTexts()).map((t) => t.trim()).filter((t) => t && !/Select an option/.test(t));
    const sortOpts = (await adm.locator('[id="form.definition.sort.field"] option').allInnerTexts()).map((t) => t.trim()).filter((t) => t && !/Select an option/.test(t));
    console.log('    datasets:', opts.length, 'group options:', groupOpts.length, 'sort options:', sortOpts.length);
    await adm.locator('[id="form.definition.group_by"]').scrollIntoViewIfNeeded();
    await adm.evaluate(() => window.scrollBy(0, -300));
    await idle(adm, 500);
    await shot(s, adm, 'analytics/80-ins-08-report-builder-f44', { ...R, deck: [12, 7], workflow: 'Report builder', screen: 'Insights › Analytics › Reports › New report', action: 'Choose a dataset, then open Group by / Sort by',
        expected: `Observed: after choosing a dataset, Group by offers ${groupOpts.length} field(s) and Sort by ${sortOpts.length}: the field lists for Filters, Group by, aggregation, chart axes and Sort stay empty (finding F44). The Fields list works.`,
        status: 'INCOMPLETE', note: 'Known defect F44 (ReportBuilderSchema: $get(\'dataset\', true)). Nothing was saved.',
        callouts: [{ loc: adm.locator('[id="form.dataset"]'), n: 1, label: 'Dataset chosen' }, { loc: adm.locator('[id="form.definition.group_by"]'), n: 2, label: 'Group by: no fields' }, { loc: adm.locator('[id="form.definition.sort.field"]'), n: 3, label: 'Sort by: no fields' }] });
});
await step('dashboard builder', async () => {
    await go(adm, '/admin/dashboards');
    await shot(s, adm, 'analytics/80-ins-09-dashboard-builder', { ...A, workflow: 'Dashboard builder', screen: 'Insights › Analytics › Dashboard builder', action: 'Open the Dashboard builder',
        expected: 'HR overview dashboard (audience Everyone, 8 widgets); New dashboard, Open, Edit', callouts: [{ loc: adm.getByText('New dashboard', { exact: true }), n: 1, label: 'New dashboard' }] });
});
await step('audit list', async () => {
    await go(adm, '/admin/audit-events');
    await shot(s, adm, 'analytics/80-ins-10-change-history', { ...AU, deck: [12, 8, 3, 10], screen: 'Insights › Audit › Change history (Audit events)', action: 'Open Change history',
        expected: 'Every recorded change: when, action, module, record, who, reason; filter and open any event',
        callouts: [{ loc: adm.locator('th').filter({ hasText: 'When' }), n: 1, label: 'When' }, { loc: adm.locator('tbody tr').first(), n: 2, label: 'Latest event' }] });
});
await step('audit detail', async () => {
    await go(adm, '/admin/audit-events');
    await adm.locator('tbody tr').filter({ hasText: 'Update' }).filter({ hasText: 'Position' }).getByText('View', { exact: true }).first().click();
    await idle(adm, 2000);
    const inModal = await adm.locator('.fi-modal-window:visible').count();
    await shot(s, adm, 'security-compliance/80-ins-11-audit-event-detail', { ...AU, deck: [8, 12, 10], screen: 'Change history › event', action: 'View one event',
        expected: 'Who, when, action, reason and the field-level before → after values; events are chained (hash) and cannot be edited', ...(inModal ? { element: '.fi-modal-window:visible >> nth=-1' } : { fullPage: true }) });
    if (inModal) await cancelModal(adm);
});
await step('change intelligence', async () => {
    await go(adm, '/admin/change-intelligence');
    await shot(s, adm, 'analytics/80-ins-12-change-intelligence', { ...AU, screen: 'Insights › Audit › Change intelligence', action: 'Open Change intelligence',
        expected: 'Recorded changes by person and date with Explain', callouts: [{ loc: adm.getByRole('button', { name: 'Explain' }), n: 1, label: 'Explain' }] });
});
await step('ai interactions', async () => {
    await go(adm, '/admin/ai-interactions');
    await shot(s, adm, 'analytics/80-ins-13-ai-interactions', { ...AU, deck: [12, 8], workflow: 'AI interaction log', screen: 'Insights › Audit › AI interactions', action: 'Open AI interactions',
        expected: 'Every assistant question and answer is logged with the user, assistant and sources (read only)' });
});
await step('performance analytics', async () => {
    await go(adm, '/admin/performance-analytics');
    await shot(s, adm, 'analytics/80-ins-14-performance-analytics', { ...A, deck: [12, 3], screen: 'People › Growth › Analytics (Performance)', action: 'Open Performance analytics for cycle FY27',
        expected: 'Review progress and rating distribution for the cycle', fullPage: true });
});
await step('exit insights', async () => {
    await go(adm, '/admin/exit-insights');
    await shot(s, adm, 'analytics/80-ins-15-exit-insights', { ...A, deck: [12, 3], screen: 'People › Lifecycle › Exit insights', action: 'Open Exit insights',
        expected: 'Reasons for leaving and average exit-interview ratings (employee answers only)', fullPage: true });
});
await step('service analytics', async () => {
    await go(adm, '/admin/service-analytics');
    await shot(s, adm, 'analytics/80-ins-16-service-analytics', { ...A, deck: [12], screen: 'Services › Service Desk › Service analytics', action: 'Open HR service analytics',
        expected: 'With fewer than 5 requesters in the period, no figures are shown (privacy suppression note)', fullPage: true });
});
await step('ask peopleos', async () => {
    await go(adm, '/admin');
    await adm.getByRole('button', { name: 'Got it' }).first().click().catch(() => {});
    await adm.getByText('Ask PeopleOS').first().click();
    await idle(adm, 1500);
    await adm.getByRole('button', { name: 'Workforce Analyst' }).first().click().catch(() => {});
    await idle(adm, 800);
    await adm.locator('#pos-ai-q-panel').fill('What is our attrition?');
    await adm.getByRole('button', { name: 'Send' }).first().click();
    await idle(adm, 4000);
    await shot(s, adm, 'analytics/80-ins-17-ask-peopleos', { deck: [12, 9, 10], workflow: 'Ask PeopleOS (assistant)', screen: 'Ask PeopleOS panel (PeopleOS Assistant)', action: 'Ask "What is our attrition?"',
        expected: 'A deterministic answer computed from records the user can see, with its sources; the assistant explains and never decides. External language model is off by default',
        prereq: 'Assistant permission and feature; ai.llm off (no external model)', callouts: [{ loc: adm.locator('#pos-ai-q-panel'), n: 1, label: 'Question' }] });
});

// Executive view (Meera Iyer).
const exe = await open(s, 'executive', '/admin');
await step('executive home', async () => {
    await exe.getByRole('button', { name: 'Got it' }).first().click().catch(() => {});
    await idle(exe, 600);
    await shot(s, exe, 'executive-demo/80-ins-18-executive-home', { ...A, deck: [9, 12], workflow: 'Executive experience', screen: 'Home (Executive)', action: 'An executive signs in',
        expected: 'Home opens on the workforce lens: pulse, decisions and signals for the company' });
});
await step('executive people analytics', async () => {
    await go(exe, '/admin/people-analytics');
    await shot(s, exe, 'analytics/80-ins-19-people-analytics-executive', { ...A, deck: [12, 9], workflow: 'Analytics and dashboards (executive)', screen: 'Insights › Analytics › People analytics (Executive)',
        action: 'Open People analytics as an executive', expected: 'Aggregated figures only; sections the role has no dataset permission for are not shown', fullPage: true });
});
await step('executive dashboard f45', async () => {
    await go(exe, '/admin/dashboard-viewer');
    await shot(s, exe, 'analytics/80-ins-20-dashboard-executive-f45', { ...A, deck: [12, 7], workflow: 'Analytics and dashboards (executive)', screen: 'Insights › Analytics › Dashboards (Executive)',
        action: 'Open the HR overview dashboard as an executive', expected: 'Tiles show "You cannot report on the … dataset." — the Executive role template has no dataset permission (finding F45)',
        status: 'AVAILABLE ONLY AFTER CONFIGURATION', note: 'Finding F45: give the executive role dataset permissions (role configuration) for these tiles to show figures.', fullPage: true });
});
await step('executive reports', async () => {
    await go(exe, '/admin/reports');
    await shot(s, exe, 'analytics/80-ins-21-reports-executive', { ...R, deck: [12], workflow: 'Reports (executive)', screen: 'Insights › Analytics › Reports (Executive)', action: 'Open Reports as an executive',
        expected: 'Observed list for a role without dataset permissions (F45)', status: 'AVAILABLE ONLY AFTER CONFIGURATION' });
});

await finish(s);
