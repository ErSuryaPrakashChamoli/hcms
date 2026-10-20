import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const only = (process.argv[2] ?? 'all').split(',');
const want = (k) => only.includes('all') || only.includes(k);
const s = await start('svc');
const miss = (name, m) => { if (m.length) console.log('MISSING in', name, m); };
const modal = (p) => p.locator('.fi-modal-window:visible').last();

if (want('emp')) {
  // 1. My HR overview
  let p = await open(s, 'employee', '/admin/my-hr');
  miss('01', await annotate(p, [
    { loc: p.locator('nav.pos-sectnav'), n: 1 },
    { loc: p.getByRole('button', { name: 'Ask HR' }).first(), n: 2 },
    { loc: p.getByRole('button', { name: 'Give feedback' }).first(), n: 3 },
    { loc: p.getByRole('link', { name: 'Your record' }).first(), n: 4 },
    { loc: p.locator('h2.pos-sec-title').filter({ hasText: 'Start a request' }).first(), n: 5 },
    { loc: p.locator('h2.pos-sec-title').filter({ hasText: 'My requests' }).first(), n: 6 },
    { loc: p.locator('h2.pos-sec-title').filter({ hasText: 'Needs you' }).first(), n: 7 },
  ]));
  await shot(p, 'svc-01-my-hr-overview');
  record(s, { name: 'svc-01-my-hr-overview', persona: 'employee', path: '/admin/my-hr', caption: 'My HR front door for Priya Nair' });
  await clearCallouts(p);

  // 2. Ask HR modal
  await p.getByRole('button', { name: 'Ask HR' }).first().click();
  await modal(p).waitFor();
  await p.waitForTimeout(600);
  const m = modal(p);
  await m.locator('select').first().selectOption({ label: 'Letters & certificates' }).catch(async () => {
    // Filament select may be a custom component
    await m.getByLabel('What is it about?').click(); await p.getByRole('option', { name: 'Letters & certificates' }).click();
  });
  await m.getByLabel('Subject').fill('Address proof letter for a new bank account');
  await m.getByLabel('Description').fill('Please issue a letter confirming my employment and current address. My bank needs it to open a salary account.');
  await p.waitForTimeout(400);
  miss('02', await annotate(p, [
    { loc: m.getByText('What is it about?').first(), n: 1 },
    { loc: m.getByText('Subject', { exact: false }).first(), n: 2 },
    { loc: m.getByText('Description').first(), n: 3 },
    { loc: m.getByText('Priority').first(), n: 4 },
    { loc: m.getByRole('button', { name: 'Submit' }).first(), n: 5 },
  ]));
  await shot(p, 'svc-02-ask-hr-form', { element: m });
  record(s, { name: 'svc-02-ask-hr-form', persona: 'employee', path: '/admin/my-hr (Ask HR)', caption: 'Ask HR form, filled with example text and then cancelled' });
  await clearCallouts(p);
  await p.keyboard.press('Escape');
  await p.close();

  // 3. Policies tab
  p = await open(s, 'employee', '/admin/my-hr?tab=policies');
  miss('03', await annotate(p, [
    { loc: p.getByRole('tab', { name: 'Policies' }), n: 1 },
    { loc: p.getByRole('link', { name: 'Leave policy' }).first(), n: 2 },
    { loc: p.getByRole('button', { name: 'Acknowledge' }).first(), n: 3 },
  ]));
  await shot(p, 'svc-03-my-hr-policies', { clip: { x: 262, y: 70, width: 1172, height: 470 } });
  record(s, { name: 'svc-03-my-hr-policies', persona: 'employee', path: '/admin/my-hr?tab=policies', caption: 'Policies tab with acknowledgement buttons' });
  await p.close();

  // 4. Preferences tab
  p = await open(s, 'employee', '/admin/my-hr?tab=preferences');
  miss('05', await annotate(p, [
    { loc: p.getByRole('tab', { name: 'Preferences' }), n: 1 },
    { loc: p.locator('table tbody tr').first(), n: 2 },
    { loc: p.getByText('Always delivered (mandatory)').first(), n: 3 },
  ]));
  await shot(p, 'svc-05-my-hr-preferences', { clip: { x: 262, y: 70, width: 1172, height: 560 } });
  record(s, { name: 'svc-05-my-hr-preferences', persona: 'employee', path: '/admin/my-hr?tab=preferences', caption: 'Communication preferences' });
  await p.close();

  // 5. Announcements feed
  p = await open(s, 'employee', '/admin/announcements-feed');
  miss('04', await annotate(p, [
    { loc: p.locator('.fi-sidebar').getByText('Communication', { exact: true }).first(), n: 1 },
    { loc: p.getByText('pinned · unread').first(), n: 2 },
    { loc: p.getByRole('button', { name: 'Mark as read' }).first(), n: 3 },
    { loc: p.getByRole('button', { name: 'I acknowledge' }).first(), n: 4 },
  ]));
  await shot(p, 'svc-04-announcements-feed');
  record(s, { name: 'svc-04-announcements-feed', persona: 'employee', path: '/admin/announcements-feed', caption: 'Announcements feed for Priya Nair' });
  await p.close();

  // 6. Give feedback modal
  p = await open(s, 'employee', '/admin/my-hr?tab=feedback');
  await p.getByRole('button', { name: 'Give feedback' }).first().click();
  await modal(p).waitFor(); await p.waitForTimeout(600);
  let f = modal(p);
  await f.getByLabel('Anonymous').check().catch(e => console.log('anon radio', e.message));
  await f.locator('select').first().selectOption({ label: 'Idea / suggestion' }).catch(e => console.log('cat', e.message));
  await f.getByLabel('Your feedback').fill('A quiet room for focused work on the second floor would help the whole team.');
  miss('06', await annotate(p, [
    { loc: f.getByText('How do you want to send it?').first(), n: 1 },
    { loc: f.getByText('Category').first(), n: 2 },
    { loc: f.getByText('Your feedback').first(), n: 3 },
  ]));
  await shot(p, 'svc-06-give-feedback-form', { element: f });
  record(s, { name: 'svc-06-give-feedback-form', persona: 'employee', path: '/admin/my-hr (Give feedback)', caption: 'Give feedback form, filled and then cancelled' });
  await clearCallouts(p);
  await p.keyboard.press('Escape');
  await p.close();

  // 7. Raise a grievance modal
  p = await open(s, 'employee', '/admin/grievances');
  await p.getByRole('button', { name: 'Raise a grievance' }).first().click();
  await modal(p).waitFor(); await p.waitForTimeout(600);
  let g = modal(p);
  await g.locator('select').first().selectOption({ label: 'Workplace conduct' }).catch(e => console.log('gcat', e.message));
  await p.waitForTimeout(1200);
  await g.getByLabel('Subject').fill('Repeated interruptions in team meetings');
  await g.getByLabel('Details').fill('Example text for this guide. Describe what happened, when, where, who was involved and any witnesses.');
  miss('07', await annotate(p, [
    { loc: g.locator('.fi-fo-field-label-content').filter({ hasText: 'Category' }).first(), n: 1 },
    { loc: g.getByText('Raise anonymously').first(), n: 2 },
    { loc: g.locator('.fi-fo-field-label-content').filter({ hasText: 'Subject' }).first(), n: 3 },
    { loc: g.locator('.fi-fo-field-label-content').filter({ hasText: 'Details' }).first(), n: 4 },
    { loc: g.locator('.fi-fo-field-label-content').filter({ hasText: 'Severity' }).first(), n: 5 },
  ]));
  await shot(p, 'svc-07-raise-grievance-form', { element: g });
  record(s, { name: 'svc-07-raise-grievance-form', persona: 'employee', path: '/admin/grievances (Raise a grievance)', caption: 'Raise a grievance form, filled and then cancelled' });
  await clearCallouts(p);
  await p.keyboard.press('Escape');
  await p.close();

  // 8. Article reader view
  p = await open(s, 'employee', '/admin/articles');
  const href = await p.getByRole('link', { name: 'Read' }).first().getAttribute('href').catch(() => null);
  console.log('article href', href);
  await p.close();
}

if (want('art')) {
  // Article reader view (Rahul, so Priya's read state is untouched)
  const p = await open(s, 'employee2', '/admin/articles/3');
  miss('16', await annotate(p, [
    { loc: p.getByRole('button', { name: 'I have read and understood' }).first(), n: 1 },
    { loc: p.getByText(/Version 1 · published/).first(), n: 2 },
  ]));
  await shot(p, 'svc-16-article-reader');
  record(s, { name: 'svc-16-article-reader', persona: 'employee2', path: '/admin/articles/3', caption: 'A published policy article as an employee sees it' });
  await p.close();
}

if (want('hr')) {
  // 8. HR queue
  let p = await open(s, 'hr', '/admin/tickets');
  miss('08', await annotate(p, [
    { loc: p.getByRole('button', { name: 'Ask HR' }).first(), n: 1 },
    { loc: p.locator('.fi-ta-filters-trigger-action-ctn, button[title="Filter"], .fi-ta-filters-dropdown button').first(), n: 2 },
    { loc: p.getByText('Active filters').first(), n: 3, pad: 6 },
    { loc: p.getByRole('columnheader', { name: /SLA due/i }).first(), n: 4 },
    { loc: p.getByRole('link', { name: 'Open' }).first(), n: 5 },
  ]));
  await shot(p, 'svc-08-hr-queue');
  record(s, { name: 'svc-08-hr-queue', persona: 'hr', path: '/admin/tickets', caption: 'HR queue for Neha Kapoor' });
  await clearCallouts(p);
  // filters panel open
  await p.locator('.fi-ta-filters-trigger-action-ctn button, button[title="Filter"]').first().click().catch(e => console.log('filter btn', e.message));
  await p.waitForTimeout(900);
  const panel = p.locator('.fi-dropdown-panel:visible').first();
  if (await panel.count()) { await shot(p, 'svc-08b-hr-queue-filters', { element: panel }); record(s, { name: 'svc-08b-hr-queue-filters', persona: 'hr', path: '/admin/tickets', caption: 'HR queue filters' }); }
  await p.close();

  // 9. Ticket detail
  p = await open(s, 'hr', '/admin/tickets/1');
  miss('09', await annotate(p, [
    { loc: p.getByRole('button', { name: 'Acknowledge' }).first(), n: 1 },
    { loc: p.getByRole('button', { name: 'Take it' }).first(), n: 2 },
    { loc: p.getByRole('button', { name: 'Reply' }).first(), n: 3 },
    { loc: p.getByRole('button', { name: 'More' }).first(), n: 4 },
    { loc: p.getByText('Assignment and SLA', { exact: true }).first(), n: 5 },
    { loc: p.getByText(/service hours left|Breached by/).first(), n: 6 },
  ]));
  await shot(p, 'svc-09-ticket-detail', { fullPage: true });
  record(s, { name: 'svc-09-ticket-detail', persona: 'hr', path: '/admin/tickets/1', caption: 'Case detail of TKT-2026-00001 for an HR agent' });
  await clearCallouts(p);
  await p.getByRole('button', { name: 'More' }).first().click();
  await p.waitForTimeout(800);
  const dd = p.locator('.fi-dropdown-panel:visible').first();
  console.log('MORE MENU:', (await dd.innerText().catch(() => '-')).replace(/\n/g, ' | '));
  await shot(p, 'svc-10-ticket-more-actions', { clip: { x: 900, y: 60, width: 540, height: 420 } });
  record(s, { name: 'svc-10-ticket-more-actions', persona: 'hr', path: '/admin/tickets/1', caption: 'More actions on a submitted case' });
  await p.keyboard.press('Escape');
  // Conversation / Status history tabs (scroll)
  const tabs = p.locator('.fi-tabs-item').filter({ hasText: 'Status history' }).first();
  if (await tabs.count()) { await tabs.click(); await p.waitForTimeout(1200); }
  const rm = p.locator('.fi-resource-relation-manager').first();
  await rm.scrollIntoViewIfNeeded().catch(() => {});
  await shot(p, 'svc-11-ticket-status-history', { element: p.locator('.fi-resource-relation-manager').first() }).catch(e => console.log('rm shot', e.message));
  record(s, { name: 'svc-11-ticket-status-history', persona: 'hr', path: '/admin/tickets/1', caption: 'Status history tab of a case' });
  await p.close();

}
if (want('cat')) {
  let p;
  // 12. Service catalogue list
  p = await open(s, 'hr', '/admin/service-definitions');
  miss('12', await annotate(p, [
    { loc: p.getByRole('link', { name: 'New service' }).first(), n: 1 },
    { loc: p.getByRole('columnheader', { name: /Version in force/i }).first(), n: 2 },
    { loc: p.getByText('Not available').first(), n: 3 },
    { loc: p.getByRole('link', { name: 'Open' }).first(), n: 4 },
  ]));
  await shot(p, 'svc-12-service-catalogue');
  record(s, { name: 'svc-12-service-catalogue', persona: 'hr', path: '/admin/service-definitions', caption: 'Service catalogue with the 13 starter services (all still drafts)' });
  const bankHref = await p.getByRole('row').filter({ hasText: 'Bank account change' }).getByRole('link', { name: 'Open' }).first().getAttribute('href');
  console.log('bank href', bankHref);
  await p.close();

  // 13. Service versions + Edit draft modal
  p = await open(s, 'hr', new URL(bankHref).pathname);
  const vt = p.locator('.fi-resource-relation-manager').first();
  await vt.scrollIntoViewIfNeeded();
  await p.waitForTimeout(500);
  miss('13', await annotate(p, [
    { loc: vt.getByRole('button', { name: 'New version' }).first(), n: 1 },
    { loc: vt.getByText('Draft', { exact: true }).first(), n: 2 },
    { loc: vt.getByText('Bank account change (Employment)').first(), n: 3 },
    { loc: vt.getByRole('button', { name: 'Details' }).first(), n: 4 },
    { loc: vt.locator('td .fi-ta-actions .fi-dropdown button').first(), n: 5 },
  ]));
  await shot(p, 'svc-13-service-versions', { element: vt });
  record(s, { name: 'svc-13-service-versions', persona: 'hr', path: new URL(bankHref).pathname, caption: 'Versions of the Bank account change service' });
  await clearCallouts(p);
  await vt.locator('td .fi-ta-actions .fi-dropdown button').first().click();
  await p.waitForTimeout(800);
  await p.getByRole('button', { name: 'Edit draft' }).first().click();
  await modal(p).waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(1200);
  const em = modal(p);
  const box = await em.boundingBox();
  const clip = { x: box.x, y: Math.max(0, box.y), width: box.width, height: Math.min(900 - Math.max(0, box.y), box.height) };
  miss('14', await annotate(p, [
    { loc: em.getByText('Hands off to (domain action)').first(), n: 1 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Confidentiality' }).first(), n: 2 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Available to' }).first(), n: 3 },
    { loc: em.getByText('The employee concerned can see the request').first(), n: 4 },
  ]));
  await shot(p, 'svc-14-service-draft-form', { clip });
  record(s, { name: 'svc-14-service-draft-form', persona: 'hr', path: new URL(bankHref).pathname, caption: 'Edit draft form of a service version, top part (not saved)' });
  await clearCallouts(p);
  // scroll the modal to the approval section
  await em.getByText('Approval, SLA and assignment').first().scrollIntoViewIfNeeded();
  await p.waitForTimeout(600);
  const box2 = await em.boundingBox();
  miss('14b', await annotate(p, [
    { loc: em.getByText('Needs approval (existing workflow engine)').first(), n: 5 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'SLA policy' }).first(), n: 6 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Team (role)' }).first(), n: 7 },
    { loc: em.getByText('Field classification', { exact: false }).first(), n: 8 },
  ]));
  await shot(p, 'svc-14b-service-draft-approval', { clip: { x: box2.x, y: Math.max(0, box2.y), width: box2.width, height: Math.min(900 - Math.max(0, box2.y), box2.height) } });
  record(s, { name: 'svc-14b-service-draft-approval', persona: 'hr', path: new URL(bankHref).pathname, caption: 'Edit draft form, approval and SLA part (not saved)' });
  await p.keyboard.press('Escape');
  await p.close();
}

if (want('comm')) {
  let p;
  if (want('comm-all')) {
  // Grievance categories (hr)
  p = await open(s, 'hr', '/admin/grievance-categories');
  miss('15', await annotate(p, [
    { loc: p.getByRole('button', { name: 'New grievance category' }).or(p.getByRole('link', { name: 'New grievance category' })).first(), n: 1 },
    { loc: p.getByRole('columnheader', { name: /Confidential/i }).first(), n: 2 },
    { loc: p.getByRole('columnheader', { name: /Anonymous/i }).first(), n: 3 },
    { loc: p.getByText('Not set — assign manually').first(), n: 4 },
    { loc: p.getByRole('columnheader', { name: /^SLA$/i }).first(), n: 5 },
  ]));
  await shot(p, 'svc-15-grievance-categories');
  record(s, { name: 'svc-15-grievance-categories', persona: 'hr', path: '/admin/grievance-categories', caption: 'Grievance categories with confidentiality, anonymity and handlers' });
  await p.close();

  // Articles, writer view (hr)
  p = await open(s, 'hr', '/admin/articles');
  miss('17', await annotate(p, [
    { loc: p.getByRole('link', { name: 'New article' }).first(), n: 1 },
    { loc: p.getByRole('columnheader', { name: /Published/i }).first(), n: 2 },
    { loc: p.getByRole('columnheader', { name: /Read \/ ack/i }).first(), n: 3 },
    { loc: p.getByRole('button', { name: 'Start a revision' }).first(), n: 4 },
  ]));
  await shot(p, 'svc-17-articles-writer');
  record(s, { name: 'svc-17-articles-writer', persona: 'hr', path: '/admin/articles', caption: 'Knowledge articles as an author sees them' });
  await p.close();

  // Announcements management (hr)
  p = await open(s, 'hr', '/admin/announcements');
  const row = p.getByRole('row').filter({ hasText: 'Welcome to MY PEOPLEOS' }).first();
  miss('18', await annotate(p, [
    { loc: p.getByRole('link', { name: 'Compose' }).or(p.getByRole('button', { name: 'Compose' })).first(), n: 1 },
    { loc: p.getByRole('columnheader', { name: /Audience/i }).first(), n: 2 },
    { loc: p.getByRole('columnheader', { name: /Sent \/ failed/i }).first(), n: 3 },
    { loc: row.locator('td .fi-ta-actions .fi-dropdown button').first(), n: 4 },
  ]));
  await shot(p, 'svc-18-announcements-admin');
  record(s, { name: 'svc-18-announcements-admin', persona: 'hr', path: '/admin/announcements', caption: 'Announcements management list' });
  await clearCallouts(p);
  await row.locator('td .fi-ta-actions .fi-dropdown button').first().click().catch(e => console.log('ann menu', e.message));
  await p.waitForTimeout(700);
  console.log('ANN MENU:', (await p.locator('.fi-dropdown-panel:visible').first().innerText().catch(() => '-')).replace(/\n/g, ' | '));
  await p.close();
  }

  // Compose form (hr), filled then cancelled
  p = await open(s, 'hr', '/admin/announcements/create', { width: 1441, height: 1500 });
  await p.getByLabel('Title').fill('Office closed for the regional holiday');
  await p.locator('select').first().selectOption({ label: 'Circular' }).catch(e => console.log('type', e.message));
  const ed = p.locator('.fi-fo-markdown-editor').first();
  await ed.locator('.CodeMirror').first().click().catch(e => console.log('ed click', e.message));
  await p.keyboard.type('All Demo Technologies offices are closed on Friday. For anything urgent, use Ask HR in My HR.');
  await p.getByText('Employees must acknowledge').first().click().catch(e => console.log('ack toggle', e.message));
  await p.evaluate(() => window.scrollTo(0, 0));
  await p.waitForTimeout(700);
  miss('19', await annotate(p, [
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Title' }).first(), n: 1 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Type' }).first(), n: 2 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Body' }).first(), n: 3 },
    { loc: p.getByText('Employees must acknowledge').first(), n: 4 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Saved audience' }).first(), n: 5 },
  ]));
  await shot(p, 'svc-19-compose-announcement');
  record(s, { name: 'svc-19-compose-announcement', persona: 'hr', path: '/admin/announcements/create', caption: 'New announcement form, filled with example text (not created)' });
  await p.close();

  // SLA policy edit form (hr)
  p = await open(s, 'hr', '/admin/service-sla-policies');
  const slaHref = await p.getByRole('link', { name: 'Edit' }).first().getAttribute('href');
  await p.close();
  p = await open(s, 'hr', new URL(slaHref).pathname);
  miss('26', await annotate(p, [
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Calendar' }).first(), n: 1 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Warn when' }).first(), n: 2 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Escalate to (role)' }).first(), n: 3 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'SLA paused while' }).first(), n: 4 },
  ]));
  await shot(p, 'svc-26-sla-policy', { element: 'main' });
  record(s, { name: 'svc-26-sla-policy', persona: 'hr', path: new URL(slaHref).pathname, caption: 'The starter SLA policy (viewed, not changed)' });
  console.log('SLA PAGE:', (await p.locator('main').innerText()).slice(0, 1500).replace(/\n/g, ' | '));
  await p.close();
}

if (want('adm')) {
  let p = await open(s, 'admin', '/admin/notification-rules');
  miss('23', await annotate(p, [
    { loc: p.getByRole('columnheader', { name: /Event/i }).first(), n: 1 },
    { loc: p.getByRole('columnheader', { name: /Audience/i }).first(), n: 2 },
    { loc: p.getByRole('columnheader', { name: /Channels/i }).first(), n: 3 },
    { loc: p.getByRole('columnheader', { name: /Template/i }).first(), n: 4 },
    { loc: p.getByRole('button', { name: 'Edit' }).first(), n: 5 },
  ]));
  await shot(p, 'svc-23-notification-rules');
  record(s, { name: 'svc-23-notification-rules', persona: 'admin', path: '/admin/notification-rules', caption: 'Notification rules' });
  await clearCallouts(p);
  console.log('RULES HEAD:', (await p.locator('main').innerText()).slice(0, 400).replace(/\n/g, ' | '));
  await p.getByRole('button', { name: 'Edit' }).first().click();
  await modal(p).waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(1200);
  const m = modal(p);
  console.log('RULE EDIT:', (await m.innerText()).slice(0, 1500).replace(/\n/g, ' | '));
  const box = await m.boundingBox();
  miss('24', await annotate(p, [
    { loc: m.locator('.fi-fo-field-label-content').filter({ hasText: 'Event' }).first(), n: 1 },
    { loc: m.locator('.fi-fo-field-label-content').filter({ hasText: 'Template' }).first(), n: 2 },
    { loc: m.getByText('Audience', { exact: true }).first(), n: 3 },
    { loc: m.locator('.fi-fo-field-label-content').filter({ hasText: 'Channels' }).first(), n: 4 },
    { loc: m.getByText('Only when', { exact: true }).first(), n: 5 },
  ]));
  await shot(p, 'svc-24-notification-rule-edit', { clip: { x: box.x, y: Math.max(0, box.y), width: box.width, height: Math.min(900 - Math.max(0, box.y), box.height) } });
  record(s, { name: 'svc-24-notification-rule-edit', persona: 'admin', path: '/admin/notification-rules', caption: 'A notification rule opened for editing (not saved)' });
  await p.keyboard.press('Escape');
  await p.close();

  p = await open(s, 'admin', '/admin/notification-templates');
  console.log('TPL HEAD:', (await p.locator('main').innerText()).slice(0, 400).replace(/\n/g, ' | '));
  await p.getByRole('button', { name: 'Edit' }).first().click();
  await modal(p).waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(1000);
  const tm = modal(p);
  const tb = await tm.boundingBox();
  await shot(p, 'svc-24b-notification-template-edit', { clip: { x: tb.x, y: Math.max(0, tb.y), width: tb.width, height: Math.min(900 - Math.max(0, tb.y), tb.height) } });
  record(s, { name: 'svc-24b-notification-template-edit', persona: 'admin', path: '/admin/notification-templates', caption: 'A notification template opened for editing (not saved)' });
  await p.keyboard.press('Escape');
  await p.close();

  p = await open(s, 'admin', '/admin/notification-deliveries');
  miss('25', await annotate(p, [
    { loc: p.getByRole('columnheader', { name: /Channel/i }).first(), n: 1 },
    { loc: p.getByRole('columnheader', { name: /Event/i }).first(), n: 2 },
    { loc: p.getByRole('columnheader', { name: /Status/i }).first(), n: 3 },
    { loc: p.getByRole('button', { name: 'View' }).first().or(p.getByRole('link', { name: 'View' }).first()).first(), n: 4 },
  ]));
  await shot(p, 'svc-25-delivery-log');
  record(s, { name: 'svc-25-delivery-log', persona: 'admin', path: '/admin/notification-deliveries', caption: 'Delivery log' });
  await p.close();
}

if (want('survey')) {
  // New survey form filled, then created as a DRAFT with obviously fictional data (never submitted or opened)
  let p = await open(s, 'hr', '/admin/surveys/create');
  await p.getByLabel('Code').fill('GUIDE-PULSE');
  await p.getByLabel('Name').fill('Sample pulse survey (guide example)');
  const sels = p.locator('main select');
  console.log('selects', await sels.count());
  await p.getByLabel('Survey type').selectOption({ label: 'Pulse' }).catch(e => console.log('stype', e.message));
  await p.getByLabel('Category').selectOption({ label: 'Wellbeing' }).catch(e => console.log('scat', e.message));
  await p.getByLabel('Description').fill('Example used in the customer guide. Not sent to anyone.').catch(() => {});
  await p.waitForTimeout(500);
  miss('20a', await annotate(p, [
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Code' }).first(), n: 1 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Survey type' }).first(), n: 2 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Anonymity mode' }).first(), n: 3 },
    { loc: p.locator('.fi-fo-field-label-content').filter({ hasText: 'Closes at' }).first(), n: 4 },
  ]));
  await shot(p, 'svc-20-new-survey');
  record(s, { name: 'svc-20-new-survey', persona: 'hr', path: '/admin/surveys/create', caption: 'New survey form with example values' });
  await clearCallouts(p);
  await p.getByRole('button', { name: 'Create survey' }).first().click();
  await p.waitForURL(/\/surveys\/\d+\/edit/, { timeout: 60000 }).catch(e => console.log('create wait', e.message));
  await settle(p, 1200);
  console.log('SURVEY URL', p.url());
  const vt = p.locator('.fi-resource-relation-manager').first();
  await vt.scrollIntoViewIfNeeded();
  miss('21', await annotate(p, [
    { loc: vt.getByRole('button', { name: 'New version' }).first(), n: 1 },
    { loc: vt.getByText('Draft', { exact: true }).first(), n: 2 },
    { loc: vt.getByRole('columnheader', { name: /Breakdown/i }).first(), n: 3 },
    { loc: vt.getByRole('columnheader', { name: /Eligible/i }).first(), n: 4 },
    { loc: vt.locator('td .fi-ta-actions .fi-dropdown button').first(), n: 5 },
  ]));
  await shot(p, 'svc-21-survey-versions', { element: vt });
  record(s, { name: 'svc-21-survey-versions', persona: 'hr', path: new URL(p.url()).pathname, caption: 'Versions of a draft example survey' });
  await clearCallouts(p);
  await vt.locator('td .fi-ta-actions .fi-dropdown button').first().click();
  await p.waitForTimeout(800);
  console.log('SURVEY MENU:', (await p.locator('.fi-dropdown-panel:visible').first().innerText().catch(() => '-')).replace(/\n/g, ' | '));
  await p.getByRole('button', { name: 'Edit settings' }).first().click();
  await modal(p).waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(1200);
  const em = modal(p);
  console.log('SETTINGS:', (await em.innerText()).slice(0, 2000).replace(/\n/g, ' | '));
  const box = await em.boundingBox();
  miss('22', await annotate(p, [
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Anonymity mode' }).first(), n: 1 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Response rule' }).first(), n: 2 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Results breakdown' }).first(), n: 3 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Results visible to' }).first(), n: 4 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Closes at' }).first(), n: 5 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Saved audience' }).first(), n: 6 },
  ]));
  await shot(p, 'svc-22-survey-settings', { clip: { x: box.x, y: Math.max(0, box.y), width: box.width, height: Math.min(900 - Math.max(0, box.y), box.height) } });
  record(s, { name: 'svc-22-survey-settings', persona: 'hr', path: new URL(p.url()).pathname, caption: 'Edit settings of a draft survey version (not saved)' });
  await p.keyboard.press('Escape');
  await p.close();
}

if (want('fb')) {
  // One fictional ANONYMOUS feedback item from Rahul (employee2)
  let p = await open(s, 'employee2', '/admin/my-hr?tab=feedback');
  await p.getByRole('button', { name: 'Give feedback' }).first().click();
  await modal(p).waitFor(); await p.waitForTimeout(600);
  const f = modal(p);
  await f.getByLabel('Anonymous').check();
  await f.locator('select').first().selectOption({ label: 'Idea / suggestion' });
  await f.getByLabel('Your feedback').fill('Guide example: a short weekly digest of HR announcements would be easier to follow than separate messages.');
  await f.getByRole('button', { name: 'Submit' }).click();
  await p.waitForTimeout(2000);
  console.log('FB toast:', await p.locator('.fi-no-notification').first().innerText().catch(() => '-'));
  await p.close();
  p = await open(s, 'hr', '/admin/employee-feedback');
  const row = p.getByRole('row').filter({ hasText: 'Guide example' }).first();
  miss('27', await annotate(p, [
    { loc: p.getByRole('columnheader', { name: /Mode/i }).first(), n: 1 },
    { loc: row.getByText('— (not identified)').first(), n: 2 },
    { loc: row.getByRole('button', { name: 'Read' }).first(), n: 3 },
    { loc: row.locator('td .fi-ta-actions .fi-dropdown button').first(), n: 4 },
  ]));
  await shot(p, 'svc-27-feedback-inbox');
  record(s, { name: 'svc-27-feedback-inbox', persona: 'hr', path: '/admin/employee-feedback', caption: 'Feedback inbox with one anonymous example item' });
  await clearCallouts(p);
  await row.locator('td .fi-ta-actions .fi-dropdown button').first().click();
  await p.waitForTimeout(800);
  console.log('FB MENU:', (await p.locator('.fi-dropdown-panel:visible').first().innerText().catch(() => '-')).replace(/\n/g, ' | '));
  await p.close();
}

if (want('survey2')) {
  const p = await open(s, 'hr', '/admin/surveys/1/edit');
  const vt = p.locator('.fi-resource-relation-manager').first();
  await vt.scrollIntoViewIfNeeded();
  await vt.locator('td .fi-ta-actions .fi-dropdown button').first().click();
  await p.waitForTimeout(800);
  await p.getByRole('button', { name: 'Edit settings' }).first().click();
  await modal(p).waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(1200);
  const em = modal(p);
  const box = await em.boundingBox();
  miss('22', await annotate(p, [
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Anonymity mode' }).first(), n: 1 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Response rule' }).first(), n: 2 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Results breakdown' }).first(), n: 3 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Results visible to' }).first(), n: 4 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Closes at' }).first(), n: 5 },
    { loc: em.locator('.fi-fo-field-label-content').filter({ hasText: 'Saved audience' }).first(), n: 6 },
  ]));
  await shot(p, 'svc-22-survey-settings', { clip: { x: box.x, y: Math.max(0, box.y), width: box.width, height: Math.min(900 - Math.max(0, box.y), box.height) } });
  record(s, { name: 'svc-22-survey-settings', persona: 'hr', path: '/admin/surveys/1/edit', caption: 'Edit settings of a draft survey version (not saved)' });
  await clearCallouts(p);
  await p.keyboard.press('Escape');
  await p.waitForTimeout(800);
  // Questions modal with one example question typed in (not saved)
  await vt.locator('td .fi-ta-actions .fi-dropdown button').first().click();
  await p.waitForTimeout(800);
  await p.getByRole('button', { name: 'Questions' }).first().click();
  await modal(p).waitFor({ timeout: 10000 }).catch(() => {});
  await p.waitForTimeout(1000);
  const qm = modal(p);
  await qm.getByRole('button', { name: /Add to questions|Add/ }).first().click().catch(e => console.log('add q', e.message));
  await p.waitForTimeout(1000);
  await qm.getByLabel('Key').first().fill('workload').catch(e => console.log('key', e.message));
  await qm.locator('select').first().selectOption({ label: 'Agreement scale (5 points)' }).catch(e => console.log('qtype', e.message));
  await qm.getByLabel('Prompt').first().fill('My workload is manageable.').catch(e => console.log('prompt', e.message));
  await p.waitForTimeout(800);
  console.log('QUESTIONS:', (await qm.innerText()).slice(0, 1200).replace(/\n/g, ' | '));
  const qb = await qm.boundingBox();
  await shot(p, 'svc-22b-survey-questions', { clip: { x: qb.x, y: Math.max(0, qb.y), width: qb.width, height: Math.min(900 - Math.max(0, qb.y), qb.height) } });
  record(s, { name: 'svc-22b-survey-questions', persona: 'hr', path: '/admin/surveys/1/edit', caption: 'Questions of a draft survey version with one example question (not saved)' });
  await p.keyboard.press('Escape');
  await p.close();
}

await finish(s);
