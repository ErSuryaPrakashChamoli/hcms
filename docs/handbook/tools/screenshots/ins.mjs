// Insights chapter screenshots (prefix ins). Usage: node ins.mjs [part]  (parts: a b c d e; default all)
import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const part = process.argv[2] ?? 'all';
const run = (p) => part === 'all' || part === p;
const s = await start('ins');
const miss = (name, m) => { if (m.length) console.log('MISSING in', name, m); };

if (run('a')) {
  // 1. Workforce pulse (executive)
  let page = await open(s, 'executive', '/admin/workforce-command-centre');
  miss('01', await annotate(page, [
    { loc: page.locator('.fi-sidebar').getByText('Insights', { exact: true }).first(), n: 1 },
    { loc: page.locator('.fi-header-subheading, .fi-header p').first(), n: 2 },
    { loc: page.locator('.pos-figure').filter({ hasText: 'Exits' }).first(), n: 3 },
    { loc: page.locator('.pos-ws-side .pos-section, .pos-ws-side section').filter({ hasText: 'Requires a decision' }).first(), n: 4 },
    { loc: page.locator('.pos-ws-side .pos-panel').last(), n: 5 },
  ]));
  await shot(page, 'ins-01-workforce-pulse');
  record(s, { name: 'ins-01-workforce-pulse', persona: 'executive', path: '/admin/workforce-command-centre', caption: 'Workforce pulse (Workforce Command Centre) for an executive' });
  await clearCallouts(page);
  // 2. Drill-down drawer (aggregated for a viewer without people access)
  await page.locator('.pos-figure').filter({ hasText: 'Exits' }).first().click();
  await page.waitForTimeout(2500);
  await shot(page, 'ins-02-pulse-drilldown');
  record(s, { name: 'ins-02-pulse-drilldown', persona: 'executive', path: '/admin/workforce-command-centre', caption: 'Drill-down drawer: counts by department, small groups shown as "fewer than 5"' });
  await page.close();
  // 4. Dashboards viewer (HR)
  page = await open(s, 'hr', '/admin/dashboard-viewer');
  await page.waitForTimeout(1500);
  miss('04', await annotate(page, [
    { loc: page.locator('.fi-section-header-heading').filter({ hasText: /^\s*Headcount\s*$/ }).first(), n: 1 },
    { loc: page.getByText('Restricted: needs payroll.view or compensation.analytics').first(), n: 2 },
    { loc: page.locator('.fi-section-header-heading').filter({ hasText: /^\s*Headcount trend\s*$/ }).first(), n: 3 },
    { loc: page.getByText('Open report').first(), n: 4 },
    { loc: page.locator('.fi-section-header-heading').filter({ hasText: /^\s*Needs attention\s*$/ }).first(), n: 5 },
    { loc: page.getByText('You cannot report on the Learning enrolments dataset.').first(), n: 6 },
  ]));
  await shot(page, 'ins-03-dashboard-hr-overview', { fullPage: true });
  record(s, { name: 'ins-03-dashboard-hr-overview', persona: 'hr', path: '/admin/dashboard-viewer', caption: 'The default HR overview dashboard as an HR Manager sees it' });
  await page.close();
  // 5. People analytics (HR)
  page = await open(s, 'hr', '/admin/people-analytics');
  miss('05', await annotate(page, [
    { loc: page.locator('input[type=date]').first(), n: 1 },
    { loc: page.locator('.fi-section').filter({ hasText: 'PEOPLE' }).first(), n: 2 },
    { loc: page.getByText('Fewer than 5 employees in scope; no figures are shown.').first(), n: 3 },
    { loc: page.getByText('Open leave detail').first(), n: 4 },
    { loc: page.getByText('Each area appears only with').first(), n: 5 },
  ]));
  await shot(page, 'ins-04-people-analytics', { fullPage: true });
  record(s, { name: 'ins-04-people-analytics', persona: 'hr', path: '/admin/people-analytics', caption: 'People analytics with privacy suppression' });
  await page.close();
  // 6. Workforce Intelligence (executive)
  page = await open(s, 'executive', '/admin/workforce-intelligence');
  miss('06', await annotate(page, [
    { loc: page.getByText('Aggregate facts only.').first(), n: 1 },
    { loc: page.getByText('Restricted: needs exit.view').first(), n: 2 },
    { loc: page.getByText('Fewest advanced / expert holders first.').first(), n: 3 },
  ]));
  await shot(page, 'ins-05-workforce-intelligence', { element: 'main' });
  record(s, { name: 'ins-05-workforce-intelligence', persona: 'executive', path: '/admin/workforce-intelligence', caption: 'Workforce Intelligence: aggregate facts, no individual predictions' });
  await page.close();
}
if (run('b')) {
  // 7. Reports list (HR)
  let page = await open(s, 'hr', '/admin/reports');
  miss('07', await annotate(page, [
    { loc: page.getByRole('button', { name: 'New report' }).or(page.getByRole('link', { name: 'New report' })).first(), n: 1 },
    { loc: page.getByPlaceholder(/Search/i).first(), n: 2 },
    { loc: page.locator('.fi-ta-header-toolbar button').first(), n: 3 },
    { loc: page.locator('table tbody tr').filter({ hasText: 'Headcount by department' }).getByText('Run').first(), n: 4 },
    { loc: page.locator('table thead').getByText(/Shows as/i).first(), n: 5 },
  ]));
  await shot(page, 'ins-06-reports-list');
  record(s, { name: 'ins-06-reports-list', persona: 'hr', path: '/admin/reports', caption: 'Reports list with the six starter reports' });
  await page.close();
  // 8. Running a report (HR)
  page = await open(s, 'hr', '/admin/reports/1');
  await page.waitForTimeout(2500);
  miss('08', await annotate(page, [
    { loc: page.getByRole('button', { name: 'Export CSV' }).or(page.getByRole('link', { name: 'Export CSV' })).first(), n: 1 },
    { loc: page.locator('canvas').first(), n: 2 },
    { loc: page.getByText(/Grouped results/i).first(), n: 3 },
  ]));
  await shot(page, 'ins-07-report-run', { fullPage: true });
  record(s, { name: 'ins-07-report-run', persona: 'hr', path: '/admin/reports/1', caption: 'Running the starter report Headcount by department' });
  await page.close();
  // 9. Report builder form (HR) — filled with fictional values, never saved. A tall viewport keeps the
  // sticky "Review before creating" panel below the form instead of over it.
  page = await open(s, 'hr', '/admin/reports/create', { width: 1439, height: 2300 });
  await page.getByLabel('Name').first().fill('Headcount by location (demo)');
  await page.getByLabel('Description').first().fill('Illustration only: people per location.');
  await page.locator('select[id*="dataset"]').first().selectOption({ label: 'Employees (headcount, demographics, tenure, cost)' });
  await settle(page, 1500);
  for (const f of ['Location', 'Tenure (months)', 'Headcount (1 per row)']) {
    await page.locator('.fi-fo-checkbox-list').getByLabel(f, { exact: true }).check().catch(e => console.log('check fail', f, e.message.slice(0, 60)));
  }
  const filtersHead = page.locator('.fi-section-header-heading').filter({ hasText: /^\s*Filters\s*$/ }).first();
  await filtersHead.click().catch(() => {});
  const calcHead = page.locator('.fi-section-header-heading').filter({ hasText: /^\s*Calculated fields\s*$/ }).first();
  await calcHead.click().catch(() => {});
  await settle(page, 800);
  await page.getByRole('button', { name: /Add to calculated/i }).first().click().catch(e => console.log('add calc fail', e.message.slice(0, 80)));
  await settle(page, 1200);
  await page.locator('input[id*="definition.calculated"][id$="key"]').first().fill('tenure_years');
  await page.locator('input[id*="definition.calculated"][id$="label"]').first().fill('Tenure (years)');
  await page.locator('input[id*="definition.calculated"][id$="formula"]').first().fill('round(tenure_months / 12)');
  await page.locator('select[id*="visualization.type"]').first().selectOption({ label: 'Table' });
  await settle(page, 1000);
  await page.evaluate(() => window.scrollTo(0, 0));
  const top = await page.locator('main').boundingBox();
  const buttons = await page.getByRole('button', { name: 'Cancel' }).last().boundingBox();
  miss('09', await annotate(page, [
    { loc: page.getByLabel('Name').first(), n: 1 },
    { loc: page.locator('select[id*="dataset"]').first(), n: 2 },
    { loc: page.locator('.fi-fo-checkbox-list').first(), n: 3 },
    { loc: page.getByRole('button', { name: /Add to filters/i }).first(), n: 4 },
    { loc: page.locator('select[id*="group_by"]').first(), n: 5 },
    { loc: page.locator('input[id*="definition.calculated"][id$="formula"]').first(), n: 6 },
    { loc: page.locator('select[id*="visualization.type"]').first(), n: 7 },
    { loc: page.locator('input[id*="definition.limit"]').first(), n: 8 },
    { loc: page.getByRole('button', { name: 'Create report' }).first(), n: 9 },
  ]));
  await shot(page, 'ins-08-report-builder', { clip: { x: top.x, y: 90, width: top.width, height: buttons.y + buttons.height + 30 - 90 } });
  record(s, { name: 'ins-08-report-builder', persona: 'hr', path: '/admin/reports/create', caption: 'New report form filled with fictional values (not saved)' });
  await clearCallouts(page);
  await page.close();
}
if (run('c')) {
  // 12. Schedules tab and the Add schedule modal (admin; not saved)
  let page = await open(s, 'admin', '/admin/reports/1/edit');
  const tabs = page.getByRole('tab', { name: 'Schedules' }).first();
  await tabs.scrollIntoViewIfNeeded();
  await tabs.click().catch(() => {});
  await settle(page, 1500);
  const addBtn = page.getByRole('button', { name: 'Add schedule' }).first();
  await tabs.evaluate(e => e.scrollIntoView({ block: 'start' }));
  await page.mouse.wheel(0, -160); await page.waitForTimeout(600);
  miss('12', await annotate(page, [
    { loc: page.getByRole('tab', { name: 'Schedules' }).first(), n: 1 },
    { loc: page.getByRole('tab', { name: 'Runs' }).first(), n: 2 },
    { loc: page.getByRole('tab', { name: 'History' }).first(), n: 3 },
    { loc: addBtn, n: 4 },
  ]));
  await shot(page, 'ins-09-report-schedules-tab');
  record(s, { name: 'ins-09-report-schedules-tab', persona: 'admin', path: '/admin/reports/1/edit', caption: 'Schedules, Runs and History tabs on a report' });
  await clearCallouts(page);
  await page.close();
  // 14. Dashboard builder edit form (admin; not saved)
  page = await open(s, 'admin', '/admin/dashboards');
  const edit = page.locator('table tbody tr').filter({ hasText: 'HR overview' }).getByText('Edit').first();
  await edit.click();
  await settle(page, 2000);
  console.log('DASH EDIT URL', page.url());
  console.log((await page.locator('main').innerText()).replace(/\s+/g, ' ').slice(0, 1500));
  miss('14', await annotate(page, [
    { loc: page.getByLabel('Name').first(), n: 1 },
    { loc: page.locator('.fi-fo-field').filter({ hasText: 'Visible to roles' }).first(), n: 2 },
    { loc: page.getByText('Default dashboard').first(), n: 3 },
    { loc: page.locator('.fi-section-header-heading').filter({ hasText: /^\s*Widgets\s*$/ }).first(), n: 4 },
  ]));
  await shot(page, 'ins-10-dashboard-builder');
  record(s, { name: 'ins-10-dashboard-builder', persona: 'admin', path: '/admin/dashboards/1/edit', caption: 'Dashboard builder: editing the HR overview dashboard (not saved)' });
  await page.close();
}
if (run('d')) {
  // 15. Change history filtered to the employment module (admin)
  let page = await open(s, 'admin', '/admin/audit-events');
  await page.locator('.fi-ta-header-toolbar button').first().click();
  await page.waitForTimeout(800);
  let panel = page.locator('.fi-dropdown-panel:visible').first();
  await panel.locator('#tableFiltersForm\\.module\\.values').click();
  await page.waitForTimeout(500);
  await page.locator('[role=listbox]:visible [role=option]').filter({ hasText: /^employment$/ }).first().click();
  await page.waitForTimeout(500);
  await panel.getByText('Filters', { exact: true }).click(); await page.waitForTimeout(400);
  await panel.getByText('Apply filters').click();
  await settle(page, 1500);
  await page.locator('h1').first().click().catch(() => {});
  await page.getByPlaceholder('Search audit events').fill('Omar');
  await settle(page, 2000);
  const row = page.locator('table tbody tr').filter({ hasText: 'Moving abroad' }).first();
  console.log('rows15', (await page.locator('table tbody tr').allInnerTexts()).map(t => t.replace(/\s+/g, ' ')).slice(0, 6));
  miss('15', await annotate(page, [
    { loc: page.getByPlaceholder('Search audit events'), n: 1 },
    { loc: page.locator('.fi-ta-header-toolbar button').first(), n: 2 },
    { loc: page.locator('table thead th').filter({ hasText: /^\s*By\s*$/i }).first(), n: 3 },
    { loc: page.locator('table thead th').filter({ hasText: /^\s*Reason\s*$/i }).first(), n: 4 },
    { loc: row.getByText('View').first(), n: 5 },
  ]));
  await shot(page, 'ins-11-change-history-list');
  record(s, { name: 'ins-11-change-history-list', persona: 'admin', path: '/admin/audit-events', caption: 'Change history filtered to employment changes' });
  await page.close();
  // 16. One audit event with before / after, reason and integrity
  page = await open(s, 'admin', '/admin/audit-events/01M599M3W1A9V679FN4X4RJV1R');
  for (const h of ['Who and where', 'Integrity']) {
    await page.locator('.fi-section-header-heading').filter({ hasText: new RegExp('^\\s*' + h + '\\s*$') }).first().click().catch(e => console.log('sect', h, e.message.slice(0, 60)));
    await page.waitForTimeout(600);
  }
  await settle(page, 800);
  console.log((await page.locator('main').innerText()).replace(/\s+/g, ' ').slice(0, 1800));
  miss('16', await annotate(page, [
    { loc: page.locator('.fi-section').filter({ hasText: 'What happened' }).getByText('Reason', { exact: true }).first(), n: 1 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Field changes' }).first(), n: 2 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Who and where' }).locator('.fi-section-header').first(), n: 3 },
    { loc: page.getByText('Hash verified').first(), n: 4 },
  ]));
  await shot(page, 'ins-12-audit-event-detail', { element: 'main' });
  record(s, { name: 'ins-12-audit-event-detail', persona: 'admin', path: '/admin/audit-events/{id}', caption: 'One change: what happened, before and after, reason, who and where, integrity' });
  await page.close();
  // 17. Change intelligence: Explain (HR Manager, organisation-scoped)
  page = await open(s, 'admin', '/admin/change-intelligence?employee=21');
  await settle(page, 1000);
  const crow = page.locator('table tbody tr').filter({ hasText: 'Moving abroad' }).filter({ hasText: 'Update' }).first();
  console.log('CI rows', await page.locator('table tbody tr').count(), await crow.count());
  await page.locator('.fi-ta button[title="Filter"]').first().click().catch(e => console.log('ci filter toggle', e.message.slice(0, 60)));
  await settle(page, 1200);
  miss('17a', await annotate(page, [
    { loc: page.getByText('Read from the immutable audit trail.').first(), n: 1 },
    { loc: page.locator('#tableFiltersForm\\.scope\\.employee_id').locator('xpath=ancestor::div[contains(@class,"fi-sc")][1]').first(), n: 2 },
    { loc: crow.getByText('Explain').first(), n: 3 },
  ]));
  await shot(page, 'ins-13-change-intelligence', { element: 'main' });
  record(s, { name: 'ins-13-change-intelligence', persona: 'admin', path: '/admin/change-intelligence', caption: 'Change intelligence for one employee' });
  await clearCallouts(page);
  await page.close();
}
if (run('e')) {
  // 19. Assistant page (HR Manager) after asking one question
  let page = await open(s, 'hr', '/admin/assistant-page?assistant=hr');
  await page.getByPlaceholder(/Ask the HR Copilot/).fill('What is our attrition?');
  await page.getByRole('button', { name: 'Ask', exact: true }).click();
  await page.waitForTimeout(4000);
  await settle(page, 1000);
  const last = page.locator('.rounded-lg.bg-gray-50').last();
  console.log('ANSWER', (await last.innerText()).replace(/\s+/g, ' '));
  miss('19', await annotate(page, [
    { loc: page.locator('main .fi-btn').filter({ hasText: 'HR Copilot' }).first(), n: 1 },
    { loc: page.getByText('Answers come only from data you may already see.').first(), n: 2 },
    { loc: last.getByText(/^Sources:/).first(), n: 3 },
    { loc: last.locator('a').first(), n: 4 },
    { loc: last.locator('button').first(), n: 5 },
    { loc: page.getByPlaceholder(/Ask the HR Copilot/), n: 6 },
  ]));
  await shot(page, 'ins-14-assistant-page', { fullPage: true });
  record(s, { name: 'ins-14-assistant-page', persona: 'hr', path: '/admin/assistant-page', caption: 'Assistant page: HR Copilot answering an attrition question' });
  await page.close();
  // 20. Summarise on an Employee 360 (HR Manager)
  page = await open(s, 'hr', '/admin/employees/4');
  const intel = page.locator('.pos-intel').first();
  if (await intel.count()) {
    miss('21', await annotate(page, [
      { loc: intel.locator('.pos-intel-tag').first(), n: 1 },
      { loc: intel.locator('.pos-intel-source').first(), n: 2 },
      { loc: intel.locator('.pos-intel-item a, .pos-intel-item button').first(), n: 3 },
      { loc: intel.locator('.pos-intel-foot').first(), n: 4 },
    ]));
    await shot(page, 'ins-16-peopleos-intelligence', { element: intel, });
    record(s, { name: 'ins-16-peopleos-intelligence', persona: 'hr', path: '/admin/employees/4', caption: 'PeopleOS Intelligence card on an Employee 360' });
    await clearCallouts(page);
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.locator('button:has-text("Summarise")').first().click();
  await page.waitForTimeout(4500);
  await settle(page, 1000);
  const panel = page.locator('.pos-ai-panel');
  miss('20', await annotate(page, [
    { loc: page.locator('button:has-text("Summarise")').first(), n: 1 },
    { loc: panel.locator('.pos-chip[aria-selected="true"]').first(), n: 2 },
    { loc: panel.getByText('Key facts', { exact: true }).last(), n: 3 },
    { loc: panel.getByText('Sources', { exact: true }).last(), n: 4 },
    { loc: panel.getByText('Suggested actions', { exact: true }).last(), n: 5 },
    { loc: panel.locator('.pos-ai-foot').last(), n: 6 },
  ]));
  await shot(page, 'ins-15-employee360-summarise');
  record(s, { name: 'ins-15-employee360-summarise', persona: 'hr', path: '/admin/employees/4', caption: 'Summarise on an Employee 360 opens the assistant with a sourced summary' });
  await page.close();
  // 22. Ask PeopleOS for an employee (Priya)
  page = await open(s, 'employee', '/admin');
  await page.locator('.pos-rail-ai').first().click();
  await page.waitForTimeout(1200);
  const epanel = page.locator('.pos-ai-panel');
  await epanel.locator('.pos-ai-prompt').filter({ hasText: /leave/i }).first().click().catch(e => console.log('prompt', e.message.slice(0, 60)));
  await page.waitForTimeout(4500);
  await settle(page, 800);
  console.log('EMP PANEL', (await epanel.innerText()).replace(/\s+/g, ' ').slice(0, 1200));
  miss('22', await annotate(page, [
    { loc: page.locator('.pos-rail-ai').first(), n: 1 },
    { loc: epanel.locator('.pos-ai-response').last(), n: 2 },
    { loc: epanel.locator('.pos-ai-ask').first(), n: 3 },
    { loc: epanel.getByText('Assistive only.').first(), n: 4 },
  ]));
  await shot(page, 'ins-17-ask-peopleos-employee');
  record(s, { name: 'ins-17-ask-peopleos-employee', persona: 'employee', path: '/admin', caption: 'Ask PeopleOS panel for an employee' });
  await page.close();
  // 23. AI interactions log (admin) and one record
  page = await open(s, 'admin', '/admin/ai-interactions');
  const arow = page.locator('table tbody tr').filter({ hasText: 'What is our attrition?' }).first();
  console.log('AI rows', await page.locator('table tbody tr').count(), await arow.count());
  miss('23', await annotate(page, [
    { loc: page.getByPlaceholder(/Search/i).first(), n: 1 },
    { loc: page.locator('.fi-ta-header-toolbar button').first(), n: 2 },
    { loc: page.locator('table thead th').filter({ hasText: /Provider/i }).first(), n: 3 },
    { loc: page.locator('table thead th').filter({ hasText: /Data boundary/i }).first(), n: 4 },
    { loc: (await arow.count() ? arow : page.locator('table tbody tr').first()).getByText('Read').first(), n: 5 },
  ]));
  await shot(page, 'ins-18-ai-interactions');
  record(s, { name: 'ins-18-ai-interactions', persona: 'admin', path: '/admin/ai-interactions', caption: 'AI interactions log' });
  await clearCallouts(page);
  await page.close();
}
if (run('e2')) {
  // Retakes without asking new questions: the assistant page history and the AI log at a wider window.
  let page = await open(s, 'hr', '/admin/assistant-page?assistant=hr');
  const turns = page.locator('.rounded-lg.bg-gray-50');
  const first = turns.filter({ hasText: 'summary from the Employee 360' }).last();
  const lastT = turns.last();
  miss('19', await annotate(page, [
    { loc: page.locator('main .fi-btn').filter({ hasText: 'HR Copilot' }).first(), n: 1 },
    { loc: page.getByText('Answers come only from data you may already see.').first(), n: 2 },
    { loc: first.getByText(/^Sources:/).first(), n: 3 },
    { loc: first.locator('a').first(), n: 4 },
    { loc: lastT.locator('button').first(), n: 5 },
    { loc: page.getByPlaceholder(/Ask the HR Copilot/), n: 6 },
    { loc: page.locator('main form').locator('xpath=..').locator('.fi-btn').filter({ hasText: 'Whose probation ends this month?' }).first(), n: 7 },
  ]));
  await shot(page, 'ins-14-assistant-page', { element: 'main' });
  record(s, { name: 'ins-14-assistant-page', persona: 'hr', path: '/admin/assistant-page', caption: 'Assistant page with two HR Copilot answers' });
  await page.close();
  page = await open(s, 'admin', '/admin/ai-interactions', { width: 1900, height: 1000 });
  const arow = page.locator('table tbody tr').filter({ hasText: 'What is our attrition?' }).first();
  miss('23', await annotate(page, [
    { loc: page.getByPlaceholder(/Search/i).first(), n: 1 },
    { loc: page.locator('.fi-ta-header-toolbar button').first(), n: 2 },
    { loc: page.locator('table thead th').filter({ hasText: /Provider/i }).first(), n: 3 },
    { loc: page.locator('table thead th').filter({ hasText: /Data boundary/i }).first(), n: 4 },
    { loc: page.locator('table thead th').filter({ hasText: /Feedback/i }).first(), n: 5 },
    { loc: arow.getByText('Read').first(), n: 6 },
  ]));
  await shot(page, 'ins-18-ai-interactions');
  record(s, { name: 'ins-18-ai-interactions', persona: 'admin', path: '/admin/ai-interactions', caption: 'AI interactions log' });
  await page.close();
}
await finish(s);
