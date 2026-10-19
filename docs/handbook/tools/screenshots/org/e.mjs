import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
import { modal, pick, sel, fill, date, openAction, toast } from './wf-helpers.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
const step = process.argv[2];

if (step === 'scenario') {
  const page = await open(s, 'admin', '/admin/workforce-scenarios');
  await openAction(page, 'New scenario');
  await fill(page, 'code', 'EX-GROWTH');
  await fill(page, 'name', 'Example growth scenario (handbook)');
  await page.locator('[id="mountedActionSchema0.description"]').fill('Illustrative scenario for the customer handbook.');
  await fill(page, 'assumptions.attrition_rate_percent', 8);
  await fill(page, 'assumptions.growth_rate_percent', 12);
  await page.locator('[id="mountedActionSchema0.assumptions.notes"]').fill('Example only: hiring continues at the current pace.');
  const md = modal(page);
  const m = await annotate(page, [
    { sel: '[id="mountedActionSchema0.code"]', n: 1 },
    { sel: '[id="mountedActionSchema0.assumptions.attrition_rate_percent"]', n: 2 },
    { sel: '[id="mountedActionSchema0.assumptions.growth_rate_percent"]', n: 3 },
  ]);
  log('scenario', m);
  await shot(page, 'org-wf-scenario-form', { element: md });
  await clearCallouts(page);
  await md.getByRole('button', { name: 'Submit' }).click();
  console.log('TOAST', await toast(page));
  await settle(page, 800);
  console.log((await page.locator('main').innerText()).slice(0, 800));
}

if (step === 'plan') {
  const page = await open(s, 'admin', '/admin/workforce-plans');
  await openAction(page, 'New plan');
  await fill(page, 'code', 'EX-FY27');
  await fill(page, 'name', 'Example FY2027-28 plan (handbook)');
  await sel(page, 'company_id', 'Demo Technologies');
  await sel(page, 'workforce_scenario_id', 'Example growth scenario (handbook)');
  await sel(page, 'period_type', 'Annual');
  console.log('start', await date(page, 'period_start', '2027-04-01'));
  console.log('end', await date(page, 'period_end', '2028-03-31'));
  await page.locator('[id="mountedActionSchema0.notes"]').fill('Illustrative plan for the customer handbook.');
  const md = modal(page);
  await shot(page, 'org-wf-new-plan-form', { element: md });
  await md.getByRole('button', { name: 'Submit' }).click();
  console.log('TOAST', await toast(page));
  await settle(page, 800);
  console.log((await page.locator('main').innerText()).slice(0, 900));
}
await finish(s);
