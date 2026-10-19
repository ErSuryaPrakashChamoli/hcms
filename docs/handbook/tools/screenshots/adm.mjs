import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const only = process.argv.slice(2);
const want = (n) => only.length === 0 || only.includes(n);
const s = await start('adm');
const log = (...a) => console.log(...a);
async function flat(page) { await page.addStyleTag({ content: '.fi-topbar, .fi-topbar-ctn{position:static!important}' }).catch(() => {}); }
async function chk(page, items, name) { const m = await annotate(page, items); if (m.length) log('MISSING', name, m); return m; }

if (want('01')) {
  const page = await open(s, 'admin', '/admin/admin-centre');
  await page.locator('.fi-sidebar').getByText('Admin', { exact: true }).first().click().catch(() => {});
  await settle(page, 1000);
  await chk(page, [
    { loc: page.locator('.fi-sidebar').getByText('All modules', { exact: true }).first(), n: 1 },
    { loc: page.getByPlaceholder(/For example/).first(), n: 2 },
    { loc: page.getByText('Organisation and positions', { exact: true }).first(), n: 3 },
    { loc: page.getByText('Awaiting approval', { exact: false }).first(), n: 4 },
    { loc: page.getByText('Platform health', { exact: false }).first(), n: 5 },
  ], '01');
  await shot(page, 'adm-01-admin-centre');
  record(s, { name: 'adm-01-admin-centre', persona: 'admin', path: '/admin/admin-centre', caption: 'Admin Centre (Admin › All modules)' });
  await page.close();
}
if (want('02')) {
  const page = await open(s, 'admin', '/admin/configuration-finder?term=working%20hours');
  await settle(page, 1200);
  await chk(page, [
    { loc: page.locator('input[type=search]').first(), n: 1 },
    { loc: page.locator('main a', { hasText: 'Shifts' }).first(), n: 2 },
  ], '02');
  log('02 text:', (await page.locator('main').innerText()).slice(0, 800));
  await shot(page, 'adm-02-configuration-finder', { clip: { x: 360, y: 90, width: 1080, height: 560 } });
  record(s, { name: 'adm-02-configuration-finder', persona: 'admin', path: '/admin/configuration-finder?term=working%20hours', caption: 'What do you want to configure? with the words working hours' });
  await page.close();
}
if (want('03')) {
  const page = await open(s, 'admin', '/admin/configuration-changes');
  await chk(page, [
    { loc: page.getByRole('tab', { name: /Pending approval/ }).first(), n: 1 },
    { loc: page.getByRole('tab', { name: /History/ }).first(), n: 2 },
    { loc: page.locator('th').filter({ hasText: /Risk level/i }).first(), n: 3 },
    { loc: page.locator('th').filter({ hasText: /status/i }).first(), n: 4 },
  ], '03');
  await shot(page, 'adm-03-change-centre');
  record(s, { name: 'adm-03-change-centre', persona: 'admin', path: '/admin/configuration-changes', caption: 'Change Centre' });
  await page.close();
}
if (want('04')) {
  const page = await open(s, 'admin', '/admin/configuration-packs');
  await chk(page, [
    { loc: page.getByRole('button', { name: 'Apply a pack' }).first(), n: 1 },
    { loc: page.getByRole('button', { name: 'Export blueprint' }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Import blueprint' }).first(), n: 3 },
    { loc: page.getByText('IT Company', { exact: true }).first(), n: 4 },
  ], '04');
  await shot(page, 'adm-04-packs-blueprints');
  record(s, { name: 'adm-04-packs-blueprints', persona: 'admin', path: '/admin/configuration-packs', caption: 'Packs & Blueprints' });
  await page.close();
}
if (want('05')) {
  const page = await open(s, 'admin', '/admin/users');
  await chk(page, [
    { loc: page.getByRole('link', { name: 'New user' }).first(), n: 1 },
    { loc: page.getByPlaceholder('Search').first(), n: 2 },
    { loc: page.locator('.fi-ta-filters-trigger-action-ctn, button[title="Filter"], [aria-label="Filter"]').first(), n: 3 },
    { loc: page.locator('th').filter({ hasText: /Roles/i }).first(), n: 4 },
    { loc: page.locator('th').filter({ hasText: /Status/i }).first(), n: 5 },
    { loc: page.getByRole('link', { name: 'Edit' }).first(), n: 6 },
  ], '05');
  await shot(page, 'adm-05-users-list');
  record(s, { name: 'adm-05-users-list', persona: 'admin', path: '/admin/users', caption: 'Users list' });
  await page.close();
}
if (want('06')) {
  const page = await open(s, 'admin', '/admin/users/create', { height: 1700 });
  await page.getByLabel('Name').first().fill('Sana Kulkarni (example)');
  await page.getByLabel('Email address').first().fill('sana.kulkarni@example.test');
  // roles select (searchable multi-select)
  const rolesField = page.locator('.fi-fo-field').filter({ has: page.locator('label[for="form.roles"]') }).first();
  await rolesField.locator('.fi-select-input-btn').first().click().catch(e => log('roles click', e.message));
  await page.waitForTimeout(600);
  await page.getByRole('option', { name: 'HR Executive' }).first().click().catch(e => log('role option', e.message));
  await page.keyboard.press('Escape');
  const compField = page.locator('.fi-fo-field').filter({ has: page.locator('label[for="form.access_scope.company"]') }).first();
  await compField.locator('.fi-select-input-btn').first().click().catch(e => log('comp click', e.message));
  await page.waitForTimeout(600);
  await page.getByRole('option', { name: 'Demo Technologies' }).first().click().catch(e => log('comp option', e.message));
  await page.keyboard.press('Escape');
  await page.locator('h1').first().click().catch(() => {});
  await settle(page, 600);
  await chk(page, [
    { loc: page.getByLabel('Name').first(), n: 1 },
    { loc: page.getByLabel('Email address').first(), n: 2 },
    { loc: page.locator('.fi-section').filter({ hasText: /^\s*Roles/ }).first(), n: 3 },
    { loc: page.locator('.fi-section').filter({ hasText: /^\s*Access scope/ }).first(), n: 4 },
    { loc: page.getByRole('button', { name: /Create user/ }).first(), n: 5 },
  ], '06');
  await chk(page, [
    { loc: page.getByLabel('Name').first(), n: 1 },
    { loc: page.getByLabel('Email address').first(), n: 2 },
    { loc: page.locator('.fi-section').filter({ hasText: /^\s*Roles/ }).first(), n: 3 },
    { loc: page.locator('.fi-section').filter({ hasText: /^\s*Access scope/ }).first(), n: 4 },
    { loc: page.getByRole('button', { name: /^Create user$/ }).last(), n: 5 },
  ], '06b');
  const box = await page.locator('main').boundingBox();
  log('06 main box', JSON.stringify(box));
  await shot(page, 'adm-06-new-user-form', { clip: { x: box.x, y: 90, width: box.width, height: 900 } });
  record(s, { name: 'adm-06-new-user-form', persona: 'admin', path: '/admin/users/create', caption: 'New user form filled with fictional values (not saved)' });
  await page.close();
}

if (want('07')) {
  const page = await open(s, 'superadmin', '/admin/users');
  await page.getByPlaceholder('Search users').fill('HR Requester');
  await settle(page, 1500);
  const href = await page.locator('tr', { hasText: 'HR Requester' }).getByRole('link', { name: 'Edit' }).first().getAttribute('href');
  log('07 href', href);
  await page.goto(href); await settle(page, 1000);
  await page.getByRole('button', { name: 'Account' }).first().click();
  await settle(page, 800);
  log('07 text', (await page.locator('main').innerText()).slice(0, 1500));
  await chk(page, [
    { loc: page.getByRole('button', { name: 'Account' }).first(), n: 1 },
    { loc: page.getByText('Send password reset link').first(), n: 2 },
    { loc: page.getByText('Sign out everywhere').first(), n: 3 },
    { loc: page.locator('.fi-fo-field').filter({ has: page.locator('label[for="form.status"]') }).first(), n: 4 },
  ], '07');
  await shot(page, 'adm-07-edit-user-account-actions');
  record(s, { name: 'adm-07-edit-user-account-actions', persona: 'superadmin', path: href, caption: 'Editing a user, Account menu open (nothing clicked)' });
  await page.keyboard.press('Escape');
  // scroll down to show status/roles + reason
  await page.close();
}
if (want('08')) {
  const page = await open(s, 'superadmin', '/admin/roles');
  await page.getByPlaceholder(/Search/).first().fill('HR Executive');
  await settle(page, 1500);
  const href = await page.locator('tr', { hasText: 'hr-executive' }).getByRole('link', { name: 'Edit' }).first().getAttribute('href');
  log('08 href', href);
  await page.goto(href); await settle(page, 1200);
  log('08 text', (await page.locator('main').innerText()).slice(0, 1200));
  const perm = page.locator('.fi-section').filter({ hasText: /^\s*Permissions/ }).first();
  await perm.locator('input[type=search], input[placeholder*="search"]').first().fill('employee');
  await settle(page, 1000);
  await page.evaluate(() => window.scrollTo(0, 0));
  await settle(page, 400);
  await chk(page, [
    { loc: page.getByLabel('Name').first(), n: 1 },
    { loc: page.getByLabel('Slug').first(), n: 2 },
    { loc: perm.locator('input[type=search], input[placeholder*="Search"]').first(), n: 3 },
  ], '08');
  await shot(page, 'adm-08-role-permissions', { fullPage: false });
  record(s, { name: 'adm-08-role-permissions', persona: 'superadmin', path: href, caption: 'Editing the HR Executive role: permissions (not saved)' });
  await page.close();
}
if (want('09')) {
  const page = await open(s, 'admin', '/admin/tenant-settings');
  await chk(page, [
    { loc: page.getByRole('button', { name: 'New setting' }).first(), n: 1 },
    { loc: page.getByPlaceholder(/Search/).first(), n: 2 },
    { loc: page.locator('th').filter({ hasText: /key/i }).first(), n: 3 },
    { loc: page.locator('th').filter({ hasText: /value/i }).first(), n: 4 },
    { loc: page.getByRole('button', { name: 'Edit' }).first(), n: 5 },
  ], '09');
  await shot(page, 'adm-09-settings-list');
  record(s, { name: 'adm-09-settings-list', persona: 'admin', path: '/admin/tenant-settings', caption: 'Settings list' });
  await page.close();
}
if (want('10')) {
  const page = await open(s, 'admin', '/admin/tenant-settings');
  await page.getByPlaceholder(/Search/).first().fill('probation');
  await settle(page, 1500);
  await page.locator('tr', { hasText: 'employee.probation.default_months' }).getByRole('button', { name: 'Edit' }).first().click();
  await settle(page, 1200);
  const modal = page.locator('.fi-modal-window').first();
  await modal.locator('textarea').first().fill('Example only: probation for new hires changes to 3 months from April.');
  await page.locator('h2, .fi-modal-heading').first().click().catch(() => {});
  await chk(page, [
    { loc: modal.locator('input').nth(0), n: 1 },
    { loc: modal.locator('input').nth(1), n: 2 },
    { loc: modal.locator('textarea').first(), n: 3 },
    { loc: modal.getByRole('button', { name: /Save/ }).first(), n: 4 },
  ], '10');
  log('10 modal', (await modal.innerText()).slice(0, 600));
  await shot(page, 'adm-10-setting-edit', { element: '.fi-modal-window' });
  record(s, { name: 'adm-10-setting-edit', persona: 'admin', path: '/admin/tenant-settings', caption: 'Edit setting dialog with a fictional reason (cancelled, not saved)' });
  await modal.getByRole('button', { name: 'Cancel' }).first().click().catch(() => page.keyboard.press('Escape'));
  await page.close();
}
if (want('11')) {
  const page = await open(s, 'superadmin', '/admin/tenant-features');
  await chk(page, [
    { loc: page.locator('th').filter({ hasText: /feature/i }).first(), n: 1 },
    { loc: page.locator('th').filter({ hasText: /enabled/i }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Edit' }).first(), n: 3 },
  ], '11');
  await shot(page, 'adm-11-feature-flags');
  record(s, { name: 'adm-11-feature-flags', persona: 'superadmin', path: '/admin/tenant-features', caption: 'Feature flags (Tenant Super Admin view)' });
  await page.close();
}
if (want('12')) {
  const page = await open(s, 'admin', '/admin/security-policy-page');
  await flat(page);
  await chk(page, [
    { loc: page.getByLabel('IP allowlist').first(), n: 1 },
    { loc: page.getByText('Require authenticator-app MFA for every tenant login').first(), n: 2 },
    { loc: page.getByLabel('Sign out after idle minutes').first(), n: 3 },
    { loc: page.locator('.fi-section').filter({ hasText: /^\s*Passwords/ }).first(), n: 4 },
    { loc: page.locator('.fi-section').filter({ hasText: /^\s*Retention/ }).first(), n: 5 },
    { loc: page.locator('.fi-section').filter({ hasText: /^\s*Data warehouse feed/ }).first(), n: 6 },
    { loc: page.getByRole('button', { name: 'Save policy' }).first(), n: 7 },
  ], '12');
  await shot(page, 'adm-12-security-policy', { element: 'main' });
  record(s, { name: 'adm-12-security-policy', persona: 'admin', path: '/admin/security-policy-page', caption: 'Security & retention policy (not saved)' });
  await page.close();
}

async function tabArea(page, tabName, waitText) {
  const tab = page.getByRole('tab', { name: tabName }).first();
  await tab.scrollIntoViewIfNeeded();
  await tab.click().catch(() => {});
  await settle(page, 2000);
  if (waitText) await page.locator('main').getByText(waitText).first().waitFor({ timeout: 20000 }).catch(e => log('wait', waitText, e.message));
  await tab.evaluate(el => el.scrollIntoView({ block: 'start' }));
  await page.evaluate(() => window.scrollBy(0, -110));
  await settle(page, 800);
  return tab;
}
if (want('13')) {
  const page = await open(s, 'admin', '/admin/workflows/1/edit');
  await chk(page, [
    { loc: page.getByRole('button', { name: 'New draft' }).first(), n: 1 },
    { loc: page.getByRole('button', { name: 'Start a run' }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Webhook signing secret' }).first(), n: 3 },
    { loc: page.getByRole('button', { name: 'More' }).first(), n: 4 },
    { loc: page.locator('.fi-fo-field').filter({ has: page.locator('label[for="form.trigger_event"]') }).first(), n: 5 },
    { loc: page.locator('.fi-fo-field').filter({ has: page.locator('label[for="form.subject_type"]') }).first(), n: 6 },
  ], '13');
  await shot(page, 'adm-13-workflow-edit');
  record(s, { name: 'adm-13-workflow-edit', persona: 'admin', path: '/admin/workflows/1/edit', caption: 'Editing the Probation confirmation workflow (not saved)' });
  // versions tab
  const tab = await tabArea(page, 'Versions & builder', 'Manager recommendation');
  const tb = await tab.boundingBox();
  await chk(page, [
    { loc: tab, n: 1 },
    { loc: page.locator('th').filter({ hasText: /flow/i }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Check' }).first(), n: 3 },
  ], '13b');
  log('13b text', (await page.locator('main').innerText()).slice(-1500));
  const tb2 = await tab.boundingBox();
  await shot(page, 'adm-14-workflow-versions');
  record(s, { name: 'adm-14-workflow-versions', persona: 'admin', path: '/admin/workflows/1/edit', caption: 'Versions & builder tab of the Probation confirmation workflow' });
  await page.close();
}
if (want('16')) {
  const page = await open(s, 'admin', '/admin/policies/1/edit');
  const tab = await tabArea(page, 'Versions & settings', 'Published');
  await chk(page, [
    { loc: tab, n: 1 },
    { loc: page.getByRole('tab', { name: 'Assignment rules' }).first(), n: 2 },
    { loc: page.locator('th').filter({ hasText: /effective from/i }).first(), n: 3 },
    { loc: page.getByRole('button', { name: 'Settings' }).first(), n: 4 },
  ], '16');
  log('16 text', (await page.locator('main').innerText()).slice(-1200));
  const tb = await tab.boundingBox();
  await shot(page, 'adm-17-policy-versions');
  record(s, { name: 'adm-17-policy-versions', persona: 'admin', path: '/admin/policies/1/edit', caption: 'Standard leave policy: versions' });
  await clearCallouts(page);
  await page.getByRole('button', { name: 'Settings' }).first().click();
  await settle(page, 1500);
  const modal = page.locator('.fi-modal-window').first();
  log('16b modal', (await modal.innerText()).slice(0, 1500));
  /* settings view dropped: raw JSON, not customer friendly */
  record(s, { name: 'adm-17-policy-settings', persona: 'admin', path: '/admin/policies/1/edit', caption: 'Settings of the published version (read-only view)' });
  await page.keyboard.press('Escape');
  await settle(page, 500);
  const rules = await tabArea(page, 'Assignment rules');
  log('16c rules', (await page.locator('main').innerText()).slice(-800));
  await page.close();
}
if (want('19')) {
  const page = await open(s, 'superadmin', '/admin/data-export');
  await chk(page, [
    { loc: page.getByRole('button', { name: 'Request a full export' }).first(), n: 1 },
    { loc: page.getByText('Exports', { exact: false }).first(), n: 2 },
  ], '19');
  await shot(page, 'adm-18-data-export');
  record(s, { name: 'adm-18-data-export', persona: 'superadmin', path: '/admin/data-export', caption: 'Export your organisation\'s data' });
  await clearCallouts(page);
  await page.getByRole('button', { name: 'Request a full export' }).first().click();
  await settle(page, 1200);
  const modal = page.locator('.fi-modal-window').first();
  await modal.locator('textarea').first().fill('Example only: annual archive copy for our records.');
  await chk(page, [
    { loc: modal.locator('textarea').first(), n: 1 },
    { loc: modal.getByRole('button', { name: /Submit|Request|Confirm/ }).first(), n: 2 },
  ], '19b');
  log('19 modal', await modal.innerText());
  await shot(page, 'adm-19-data-export-request', { element: '.fi-modal-window' });
  record(s, { name: 'adm-19-data-export-request', persona: 'superadmin', path: '/admin/data-export', caption: 'Request a full export dialog with a fictional reason (cancelled)' });
  await modal.getByRole('button', { name: 'Cancel' }).first().click().catch(() => page.keyboard.press('Escape'));
  await page.close();
}
if (want('21')) {
  const page = await open(s, 'admin', '/admin/getting-started');
  await chk(page, [
    { loc: page.getByText('Where things stand', { exact: false }).first(), n: 1 },
    { loc: page.getByText(/Set-up steps/i).first(), n: 2 },
    { loc: page.getByText('Needs the', { exact: false }).first(), n: 3 },
  ], '21');
  await flat(page);
  await shot(page, 'adm-20-getting-started', { element: 'main' });
  record(s, { name: 'adm-20-getting-started', persona: 'admin', path: '/admin/getting-started', caption: 'Getting started (opened by its address; listed only for self-service tenants)' });
  await page.close();
}
if (want('22')) {
  const page = await open(s, 'admin', '/admin/api-keys');
  await page.getByRole('button', { name: 'Issue key' }).first().click();
  await settle(page, 1200);
  const modal = page.locator('.fi-modal-window').first();
  await modal.getByLabel('Name').first().fill('Recruitment system (example)');
  await modal.getByLabel('Create pre-employees from recruitment').check().catch(e => log('scope1', e.message));
  await modal.getByLabel('Read pre-employee status').check().catch(e => log('scope2', e.message));
  await chk(page, [
    { loc: modal.getByLabel('Name').first(), n: 1 },
    { loc: modal.getByText('Scopes', { exact: false }).first(), n: 2 },
  ], '22');
  log('22 modal', (await modal.innerText()).slice(0, 400), '...', (await modal.innerText()).slice(-300));
  await shot(page, 'adm-21-issue-api-key', { element: '.fi-modal-window' });
  record(s, { name: 'adm-21-issue-api-key', persona: 'admin', path: '/admin/api-keys', caption: 'Issue key dialog with fictional values (cancelled, no key issued)' });
  await modal.getByRole('button', { name: 'Cancel' }).first().click().catch(() => page.keyboard.press('Escape'));
  await page.close();
}

if (want('15')) {
  const page = await open(s, 'admin', '/admin/workflows/create');
  let created = null;
  try {
    await page.getByLabel('Name').first().fill('Example – equipment request (documentation)');
    await page.getByLabel('Name').first().blur();
    await settle(page, 800);
    await page.getByLabel('Runs on').first().selectOption({ label: 'Employee' }).catch(e => log('runs on', e.message));
    await settle(page, 500);
    await page.getByRole('button', { name: /^Create workflow$/ }).first().click();
    await page.waitForURL(/\/admin\/workflows\/\d+\/edit/, { timeout: 60000 });
    created = page.url();
    log('15 created', created);
    await settle(page, 1200);
    await tabArea(page, 'Versions & builder', 'Draft');
    await page.getByRole('button', { name: 'Open builder' }).first().click();
    await settle(page, 2000);
    const modal = page.locator('.fi-modal-window').first();
    log('15 modal start', (await modal.innerText()).slice(0, 800));
    await modal.getByRole('button', { name: /Add to nodes/i }).first().click();
    await settle(page, 1200);
    const items = modal.locator('.fi-fo-repeater-item');
    const n = await items.count();
    log('15 items', n);
    const node = items.nth(2);
    await node.getByLabel('Name').first().fill('Manager approval');
    await node.getByLabel('Name').first().blur();
    await settle(page, 800);
    await node.getByLabel('Type').first().selectOption({ label: 'Approval' }).catch(e => log('type', e.message));
    await settle(page, 1500);
    await node.getByLabel('Approver type').first().selectOption({ label: 'Line manager of the subject employee' }).catch(e => log('approver', e.message));
    await settle(page, 1000);
    await node.getByLabel('Task title').first().fill('Approve equipment for {{ employee.name }}').catch(e => log('title', e.message));
    await node.scrollIntoViewIfNeeded();
    await settle(page, 600);
    log('15 node text', (await node.innerText()).slice(0, 1200));
    await chk(page, [
      { loc: node.getByLabel('Type').first(), n: 1 },
      { loc: node.getByLabel('Mode').first(), n: 2 },
      { loc: node.getByLabel('Approver type').first(), n: 3 },
      { loc: node.getByLabel('SLA (hours)').first(), n: 4 },
      { loc: node.getByText('Escalation steps').first(), n: 5 },
    ], '15');
    await shot(page, 'adm-15-workflow-builder', { element: '.fi-modal-window' });
    record(s, { name: 'adm-15-workflow-builder', persona: 'admin', path: '(fictional draft workflow, deleted afterwards)', caption: 'Workflow builder: adding an Approval node (not saved)' });
    await clearCallouts(page);
    // edges part
    const edges = modal.getByText('Edges (who goes where)').first();
    await edges.scrollIntoViewIfNeeded();
    await settle(page, 500);
    await shot(page, 'adm-16-workflow-edges', { element: '.fi-modal-window' });
    await modal.getByRole('button', { name: 'Cancel' }).last().click().catch(() => page.keyboard.press('Escape'));
    await settle(page, 800);
  } catch (e) { log('15 error', e.message); }
  finally {
    if (created) {
      await page.goto(created); await settle(page, 1200);
      await page.getByRole('button', { name: 'More' }).first().click();
      await settle(page, 500);
      await page.getByRole('menuitem', { name: 'Delete' }).first().click().catch(async () => { await page.getByText('Delete', { exact: true }).first().click(); });
      await settle(page, 800);
      await page.locator('.fi-modal-window').getByRole('button', { name: 'Delete' }).first().click();
      await settle(page, 1500);
      log('15 after delete', page.url());
    }
  }
  await page.close();
}

if (want('23')) {
  const page = await open(s, 'admin', '/admin/sso-connections/create', { height: 1500 });
  await page.getByLabel('Name').first().fill('Demo Group Entra ID (example)');
  await page.getByLabel('Slug').first().fill('demo-entra-example');
  await page.getByLabel('Provider').first().selectOption({ label: 'Microsoft Entra ID' }).catch(e => log('provider', e.message));
  await settle(page, 1500);
  await page.getByLabel('Client id').first().fill('00000000-0000-0000-0000-000000000000').catch(e => log('client id', e.message));
  await page.locator('h1').first().click().catch(() => {});
  await settle(page, 800);
  log('23 text', (await page.locator('main').innerText()).slice(0, 2500));
  await chk(page, [
    { loc: page.getByLabel('Provider').first(), n: 1 },
    { loc: page.getByLabel('Authorization endpoint').first(), n: 2 },
    { loc: page.locator('.fi-fo-field').filter({ has: page.locator('label[for="form.allowed_domains"]') }).first(), n: 3 },
    { loc: page.getByText('Create users on first login').first(), n: 4 },
    { loc: page.locator('.fi-fo-field').filter({ has: page.locator('label[for="form.default_role_id"]') }).first(), n: 5 },
    { loc: page.getByText('Show SSO as the primary login for this tenant').first(), n: 6 },
  ], '23');
  const box = await page.locator('main').boundingBox();
  await shot(page, 'adm-22-sso-connection-form', { clip: { x: box.x, y: 90, width: box.width, height: Math.min(1380, box.height - 30) } });
  record(s, { name: 'adm-22-sso-connection-form', persona: 'admin', path: '/admin/sso-connections/create', caption: 'New SSO connection with the Microsoft Entra ID preset and fictional values (not saved)' });
  await page.close();
}
await finish(s);
