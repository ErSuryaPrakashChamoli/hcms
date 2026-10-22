// Re-captures the "filled form" screenshots of Deck 4 / Deck 6 whose first capture ran in a session that stopped before it
// could record its manifest. Each form is opened and filled with the SAME fictional values as the original step, captured,
// then CANCELLED (the records were already created by the original run). Northwind Retail / operator only.
import { start, open, go, shot, finish, idle, modal, setForm, cancelModal } from './lib.mjs';
import './d-accounts.mjs';
import { chooseSearchable, fillForm } from './d-ui.mjs';

const s = await start('90-onboarding-z-forms');
const D = { deck: [4], prereq: 'Signed in as the tenant administrator (Tenant Super Admin of Northwind Retail)',
    note: 'Form re-opened and filled with the values used when the record was created, then cancelled (the record already exists).' };
const pg = await open(s, 'nwadmin', '/admin/companies/create');
const byId = (id) => modal(pg).locator(`[id="mountedActionSchema0.${id}"]`);
const tall = (h) => pg.setViewportSize({ width: 1440, height: h });

// Company.
await tall(1150);
await fillForm(pg, { name: 'Northwind Retail', code: 'NWR', legal_name: 'Northwind Retail Pvt Ltd', country_code: 'IN' });
await setForm(pg, 'effective_from', '2026-04-01');
await shot(s, pg, 'client-onboarding/90-onb-16-new-company', { ...D, workflow: 'Company set-up', screen: 'Companies › New company', action: 'Name, code, legal name, status, locale and the effective-from date',
    expected: 'Company record effective from 1 Apr 2026', callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.code"]'), n: 2, label: 'Code' },
        { loc: pg.locator('[id="form.legal_name"]'), n: 3, label: 'Legal name' }, { loc: pg.locator('[id="form.effective_from"]'), n: 4, label: 'Effective from' }, { loc: pg.locator('main button[type=submit]'), n: 5, label: 'Create company' }] });

// Legal entity.
await go(pg, '/admin/legal-entities');
await tall(1250);
await pg.getByRole('button', { name: 'New legal entity' }).click();
await idle(pg, 1500);
await byId('company_id').selectOption({ label: 'Northwind Retail' });
await byId('code').fill('NWR-LE');
await byId('legal_name').fill('Northwind Retail Private Limited');
await byId('trade_name').fill('Northwind Retail');
await byId('legal_form').selectOption({ index: 1 });
await byId('country').fill('IN');
await byId('effective_from').fill('2026-04-01');
await idle(pg, 800);
await shot(s, pg, 'client-onboarding/90-onb-18-new-legal-entity', { ...D, workflow: 'Legal entity set-up', screen: 'Organisation › Legal entities › New legal entity',
    action: 'Company, code, legal name, trade name, legal form, country, incorporation identifier, status and effective dates', expected: 'The statutory employer behind the company; a review of the values appears before Create',
    element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId('company_id'), n: 1, label: 'Company' }, { loc: byId('legal_name'), n: 2, label: 'Legal name' }, { loc: byId('legal_form'), n: 3, label: 'Legal form' }, { loc: byId('status'), n: 4, label: 'Status' }] });
await cancelModal(pg);

// Establishment.
await go(pg, '/admin/establishments');
await tall(1300);
await pg.getByRole('button', { name: 'New establishment' }).click();
await idle(pg, 1500);
const le = (await byId('legal_entity_id').locator('option').allInnerTexts()).map((t) => t.trim()).find((t) => /Private Limited/.test(t));
await byId('legal_entity_id').selectOption({ label: le });
await byId('code').fill('BLR-HO');
await byId('name').fill('Bengaluru head office');
await byId('establishment_type').selectOption({ index: 1 });
await byId('address').fill('12 Residency Road, Bengaluru (training example)');
await byId('country').fill('IN');
await chooseSearchable(pg, modal(pg), 0, 'Karnataka', 'Karna');
await byId('district').fill('Bengaluru Urban');
await byId('postal_code').fill('560025');
await byId('effective_from').fill('2026-04-01');
await idle(pg, 800);
await shot(s, pg, 'client-onboarding/90-onb-20-new-establishment', { ...D, workflow: 'Establishment set-up', screen: 'Organisation › Establishments › New establishment',
    action: 'Legal entity, code, name, type, address, state, district, postal code, status and dates', expected: 'A registered place of work; the state drives state-level statutory rules (professional tax, labour welfare fund)',
    element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId('legal_entity_id'), n: 1, label: 'Legal entity' }, { loc: byId('establishment_type'), n: 2, label: 'Type' }, { loc: byId('district'), n: 3, label: 'District' }] });
await cancelModal(pg);

// Location.
await go(pg, '/admin/locations/create');
await tall(1300);
await chooseSearchable(pg, pg.locator('main'), 0, 'Northwind Retail', 'Northwind');
await fillForm(pg, { name: 'Bengaluru HQ', code: 'BLR', address_line_1: '12 Residency Road', city: 'Bengaluru', postal_code: '560025', country_code: 'IN' });
await pg.locator('[id="form.type"]').selectOption({ index: 1 }).catch(() => {});
await pg.locator('[id="form.state_code"]').fill('KA').catch(() => {});
await setForm(pg, 'effective_from', '2026-04-01');
await shot(s, pg, 'client-onboarding/90-onb-22-new-location', { ...D, workflow: 'Location set-up', screen: 'Organisation › Locations › New location', action: 'Company, name, code, type, address, state, postal code, country, time zone and dates',
    expected: 'A physical site for people, assets and attendance', note: D.note + ' Locations cannot be linked to an establishment on this screen (F10).',
    callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.type"]'), n: 2, label: 'Type' }, { loc: pg.locator('[id="form.city"]'), n: 3, label: 'Address' }, { loc: pg.locator('[id="form.timezone"]'), n: 4, label: 'Time zone' }] });

// Business unit.
await go(pg, '/admin/business-units/create');
await tall(1100);
await chooseSearchable(pg, pg.locator('main'), 0, 'Northwind Retail', 'Northwind');
await fillForm(pg, { name: 'Retail Operations', code: 'RETAIL', description: 'Stores and supply chain (training example)' });
await setForm(pg, 'effective_from', '2026-04-01');
await shot(s, pg, 'client-onboarding/90-onb-24-new-business-unit', { ...D, workflow: 'Organisation structure', screen: 'Business units › New', action: 'Company, name, code, description, status and effective date',
    expected: 'An effective-dated unit of the company; where it sits in the hierarchy is arranged in the Organisation Designer',
    callouts: [{ loc: pg.locator('main button', { hasText: 'Northwind Retail' }), n: 1, label: 'Company' }, { loc: pg.locator('[id="form.name"]'), n: 2, label: 'Name' }, { loc: pg.locator('[id="form.effective_from"]'), n: 3, label: 'Effective from' }] });

// Shift, work schedule, holiday calendar.
const T = { ...D, prereq: 'Signed in as the tenant administrator (attendance.manage)' };
await go(pg, '/admin/shifts/create');
await tall(1500);
await fillForm(pg, { name: 'General shift', code: 'GEN', start_time: '09:30', end_time: '18:30', full_day_minutes: '480', half_day_minutes: '240', break_minutes: '60', grace_in_minutes: '15', grace_out_minutes: '10', min_overtime_minutes: '60' });
await setForm(pg, 'effective_from', '2026-04-01');
await shot(s, pg, 'client-onboarding/90-onb-61-new-shift', { ...T, workflow: 'Attendance configuration: shifts and schedules', screen: 'Time & leave › Shifts › New shift', action: 'Name, code, type, start and end, full/half-day minutes, breaks, grace and overtime thresholds, effective date',
    expected: 'Attendance status, late marks and overtime are computed from these values', note: D.note + ' Late-mark actions in the attendance policy are not applied (F40).',
    callouts: [{ loc: pg.locator('[id="form.start_time"]'), n: 1, label: 'Start / end' }, { loc: pg.locator('[id="form.full_day_minutes"]'), n: 2, label: 'Full / half day' }, { loc: pg.locator('[id="form.grace_in_minutes"]'), n: 3, label: 'Grace' }, { loc: pg.locator('[id="form.min_overtime_minutes"]'), n: 4, label: 'Overtime' }] });
await go(pg, '/admin/work-schedules/create');
await tall(1200);
await fillForm(pg, { name: 'Bengaluru five-day week', code: 'BLR5' });
await setForm(pg, 'effective_from', '2026-04-01');
for (const day of ['mon', 'tue', 'wed', 'thu', 'fri']) {
    const sel = pg.locator(`select[id$=".${day}"]`).first();
    const label = (await sel.locator('option').allInnerTexts()).map((o) => o.trim()).find((o) => /General/.test(o));
    if (label) await sel.selectOption({ label });
}
for (const day of ['sat', 'sun']) {
    const sel = pg.locator(`select[id$=".${day}"]`).first();
    const off = (await sel.locator('option').allInnerTexts()).map((o) => o.trim()).find((o) => /off|rest|weekly/i.test(o));
    if (off) await sel.selectOption({ label: off });
}
await idle(pg, 800);
await shot(s, pg, 'client-onboarding/90-onb-62-new-work-schedule', { ...T, workflow: 'Attendance configuration: shifts and schedules', screen: 'Time & leave › Work schedules › New work schedule', action: 'A weekly pattern: the general shift Monday to Friday, weekly off at the weekend',
    expected: 'Schedules can rotate over several weeks (Add to weeks); rules on the saved schedule decide who it applies to', callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('select[id$=".mon"]'), n: 2, label: 'Monday' }, { loc: pg.locator('select[id$=".sat"]'), n: 3, label: 'Saturday' }, { loc: pg.getByRole('button', { name: 'Add to weeks' }), n: 4, label: 'Add a week (rotation)' }] });
await go(pg, '/admin/work-schedules');
await pg.locator('tr', { hasText: 'Bengaluru five-day week' }).getByRole('link').first().click();
await idle(pg, 2000);
await tall(1400);
await idle(pg, 1200);
await shot(s, pg, 'client-onboarding/90-onb-63-work-schedule-saved', { ...T, workflow: 'Attendance configuration: shifts and schedules', screen: 'Work schedule (edit page)', action: 'Open the saved schedule',
    expected: 'Weekly pattern, "Applies to" rules (who follows the schedule) and History', note: '', callouts: [] });
await go(pg, '/admin/holiday-calendars/create');
await tall(1000);
await fillForm(pg, { name: 'Karnataka holidays 2026', code: 'KA-2026', year: '2026' });
await shot(s, pg, 'client-onboarding/90-onb-64-new-holiday-calendar', { ...T, workflow: 'Attendance configuration: holiday calendar', screen: 'Time & leave › Holiday calendars › New holiday calendar', action: 'Name, code, year, status',
    expected: 'Holidays and the "Applies to" rules are added on the saved calendar', callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.year"]'), n: 2, label: 'Year' }] });

// Operator: the internal tenant's creation form (Deck 6).
const op = await open(s, 'operator', '/admin/tenants/create');
await op.setViewportSize({ width: 1440, height: 1400 });
await op.locator('[id="form.name"]').fill('Markedge Technologies (internal workforce)');
await idle(op, 1500);
await op.locator('[id="form.admin_name"]').fill('Sana Qureshi');
await op.locator('[id="form.admin_email"]').fill('sana.qureshi@markedge-internal.example');
await op.locator('[id="form.audit_reason"]').fill('Markedge’s own employee records move to PeopleOS as an internal tenant (training example)');
await idle(op, 800);
await op.evaluate(() => window.scrollTo(0, 0));
await shot(s, op, 'internal-operations/96-int-01-provision-internal-tenant', { deck: [6, 4], workflow: 'Provision Markedge’s internal tenant (operator)', screen: 'Tenants › New tenant',
    action: 'The operator provisions the internal tenant exactly like a customer tenant, with Markedge HR’s administrator as first administrator (slug suggested from the name)',
    expected: 'A separate tenant: Markedge employee data never sits in the operator console', prereq: 'Platform operator with MFA', note: D.note,
    callouts: [{ loc: op.locator('[id="form.name"]'), n: 1, label: 'Internal tenant name' }, { loc: op.locator('[id="form.admin_email"]'), n: 2, label: 'Markedge HR administrator' }, { loc: op.locator('[id="form.audit_reason"]'), n: 3, label: 'Reason for change' }] });
await finish(s);
