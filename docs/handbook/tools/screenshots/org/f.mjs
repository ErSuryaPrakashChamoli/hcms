import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
import { modal, pick, sel, fill, date, openAction, toast } from './wf-helpers.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
const step = process.argv[2];

if (step === 'line') {
  const page = await open(s, 'admin', '/admin/workforce-plans');
  const row = page.locator('main .fi-ta-row').first();
  const trigger = row.locator('.fi-dropdown-trigger button, button[aria-haspopup]').last();
  await trigger.click();
  await settle(page, 600);
  const panel = page.locator('.fi-dropdown-panel:visible').last();
  console.log('MENU', await panel.innerText());
  const m = await annotate(page, [{ loc: panel, n: 1 }, { loc: row.locator('button:has-text("Forecast")'), n: 2 }, { loc: row.locator('button:has-text("Lines")'), n: 3 }]);
  log('plan-menu', m);
  await shot(page, 'org-wf-plan-actions-menu');
  await clearCallouts(page);
  await panel.getByText('Add line', { exact: true }).click();
  await page.locator('.fi-modal-window').first().waitFor({ state: 'visible' });
  await settle(page, 700);
  console.log('LINE MODAL', await modal(page).innerText());
  await sel(page, 'movement_type', 'New position');
  await pick(page, 'organisation_node_id', 'Department: Engineering');
  await pick(page, 'designation_id', 'Software Engineer');
  await fill(page, 'headcount', 2);
  await fill(page, 'fte', 2);
  console.log('eff', await date(page, 'effective_date', '2027-07-01'));
  await page.locator('[id="mountedActionSchema0.notes"]').fill('Two engineers for the data platform (example).');
  await settle(page, 400);
  await shot(page, 'org-wf-add-line-form', { element: modal(page) });
  await modal(page).getByRole('button', { name: 'Submit' }).click();
  console.log('TOAST', (await toast(page)).slice(0, 1));
  await settle(page, 800);
  await row.locator('button:has-text("Forecast")').click();
  await page.locator('.fi-modal-window').first().waitFor({ state: 'visible' });
  await settle(page, 900);
  console.log('FORECAST', await modal(page).innerText());
  await shot(page, 'org-wf-forecast', { element: modal(page) });
  await page.keyboard.press('Escape');
}

if (step === 'position') {
  const page = await open(s, 'admin', '/admin/positions');
  await openAction(page, 'New position');
  await fill(page, 'code', 'EX-DATA-01');
  await fill(page, 'title', 'Data Engineer (example)');
  await pick(page, 'designation_id', 'Software Engineer');
  await pick(page, 'job_family_id', 'Engineering');
  await sel(page, 'company_id', 'Demo Technologies');
  await pick(page, 'organisation_node_id', 'Department: Engineering');
  await pick(page, 'location_id', 'Bangalore');
  await sel(page, 'employment_type_id', 'Full-time');
  await sel(page, 'worker_type', 'Employee');
  await sel(page, 'grade_id', 'G3');
  await sel(page, 'occupancy_mode', 'Multiple occupants');
  await fill(page, 'headcount', 2);
  await fill(page, 'standard_hours', 40);
  await page.locator('[id="mountedActionSchema0.reason"]').fill('Example position for the customer handbook.');
  await settle(page, 500);
  const md = modal(page);
  const m = await annotate(page, [
    { sel: '[id="mountedActionSchema0.code"]', n: 1 },
    { sel: '[id="mountedActionSchema0.effective_from"]', n: 2 },
    { sel: '[id="mountedActionSchema0.designation_id"]', n: 3 },
    { sel: '[id="mountedActionSchema0.organisation_node_id"]', n: 4 },
    { sel: '[id="mountedActionSchema0.occupancy_mode"]', n: 5 },
    { sel: '[id="mountedActionSchema0.headcount"]', n: 6 },
  ]);
  log('pos-form', m);
  await shot(page, 'org-wf-new-position-form', { element: md });
  await clearCallouts(page);
  await md.getByRole('button', { name: 'Submit' }).click();
  await page.waitForTimeout(2500);
  const notes = await page.locator('.fi-no-notification').allInnerTexts();
  console.log('NOTES', notes.map(t => t.replace(/\s+/g, ' ').trim()).filter(t => t && !t.includes('announcement') && !t.includes('Configuration change')));
  if (await md.isVisible()) { console.log('MODAL STILL OPEN'); await shot(page, '_org-debug'); console.log((await md.innerText()).slice(0, 3000)); }
  await settle(page, 800);
  console.log((await page.locator('main').innerText()).slice(0, 600));
}
await finish(s);
