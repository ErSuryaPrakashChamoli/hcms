// Deck 4 stage 3: the customer's administrator sets up the organisation in the new, empty tenant (Northwind Retail).
// EXECUTED on the disposable training database. Re-runnable: records that already exist are not created again.
import { start, open, go, shot, finish, idle, settle, toasts, closeToasts, modal, setData, setForm, dismissWelcome } from './lib.mjs';
import './d-accounts.mjs';
import { chooseSearchable, fillForm } from './d-ui.mjs';

const s = await start('90-onboarding-c-org');
const P = 'nwadmin';
const D = { deck: [4], prereq: 'Signed in as the tenant administrator (Tenant Super Admin of Northwind Retail)' };
const has = async (page, text) => (await page.locator('main').getByText(text, { exact: false }).count()) > 0;
const tall = (page, h = 1300) => page.setViewportSize({ width: 1440, height: h });
const normal = (page) => page.setViewportSize({ width: 1440, height: 900 });
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);

const pg = await open(s, P, '/admin');
await shot(s, pg, 'client-onboarding/90-onb-12-empty-tenant-home', { ...D, workflow: 'First sign-in to the new tenant', screen: 'Home (new tenant, administration lens)',
    action: 'First sign-in as the customer administrator', expected: 'Open Admin Centre; Governance items (MFA not required, unscoped approvers); 1 active user, 14 roles',
    callouts: [{ loc: pg.getByRole('link', { name: 'Open Admin Centre' }), n: 1, label: 'Open Admin Centre' }, { loc: pg.getByText('GOVERNANCE', { exact: false }), n: 2, label: 'Governance' }, { loc: pg.getByText('Active users', { exact: false }), n: 3, label: 'At a glance' }] });
await dismissWelcome(pg);

await go(pg, '/admin/admin-centre');
await shot(s, pg, 'client-onboarding/90-onb-13-admin-centre', { ...D, workflow: 'Find the set-up screens', screen: 'Admin › All modules (Admin Centre)',
    action: 'Open Admin Centre', expected: 'Every module grouped by area, with a search box and the Change Centre',
    callouts: [{ loc: pg.getByText('Organisation and positions'), n: 1, label: 'Organisation and positions' }, { loc: pg.getByText('Access and security'), n: 2, label: 'Access and security' }, { loc: pg.getByRole('link', { name: /Change Centre/ }), n: 3, label: 'Change Centre' }] });
await go(pg, '/admin/configuration-finder');
await shot(s, pg, 'client-onboarding/90-onb-14-configuration-finder', { ...D, deck: [4, 3], workflow: 'Find the set-up screens', screen: 'Admin › Setup › What do you want to configure?',
    action: 'Open the configuration finder', expected: 'Set-up screens and individual settings grouped by topic (organisation, time and leave, pay, statutory, access …)',
    callouts: [{ loc: pg.getByText('Organisation structure'), n: 1, label: 'Organisation structure' }, { loc: pg.getByText('Statutory compliance'), n: 2, label: 'Statutory compliance' }, { loc: pg.getByText('Access and security'), n: 3, label: 'Access and security' }] });

// Company.
const W1 = { ...D, workflow: 'Company set-up' };
await go(pg, '/admin/companies');
if (!await has(pg, 'Northwind Retail')) {
    await shot(s, pg, 'client-onboarding/90-onb-15-companies-empty', { ...W1, screen: 'Organisation › Companies (empty)', action: 'Open Organisation › Companies in the new tenant', expected: 'No companies yet; New company',
        callouts: [{ loc: pg.getByRole('link', { name: 'New company' }), n: 1, label: 'New company' }] });
    await go(pg, '/admin/companies/create');
    await tall(pg, 1150);
    await fillForm(pg, { name: 'Northwind Retail', code: 'NWR', legal_name: 'Northwind Retail Pvt Ltd', country_code: 'IN' });
    await setForm(pg, 'effective_from', '2026-04-01');
    await shot(s, pg, 'client-onboarding/90-onb-16-new-company', { ...W1, screen: 'Companies › New company', action: 'Name, code, legal name, status, locale and the effective-from date',
        expected: 'Company record effective from 1 Apr 2026', callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.code"]'), n: 2, label: 'Code' },
            { loc: pg.locator('[id="form.legal_name"]'), n: 3, label: 'Legal name' }, { loc: pg.locator('[id="form.effective_from"]'), n: 4, label: 'Effective from' }, { loc: pg.getByRole('button', { name: 'Create company', exact: true }), n: 5, label: 'Create company' }] });
    await pg.getByRole('button', { name: 'Create company', exact: true }).click();
    await idle(pg, 2500);
    console.log('    company:', pg.url(), (await toasts(pg)).join(' / '));
    await normal(pg);
    await closeToasts(pg);
}
await go(pg, '/admin/companies');
await shot(s, pg, 'client-onboarding/90-onb-17-company-saved', { ...W1, screen: 'Organisation › Companies', action: 'Back to the list', expected: 'Northwind Retail (NWR) effective from 01 Apr 2026',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Northwind Retail' }), n: 1, label: 'Company' }] });

// Legal entity.
const W2 = { ...D, workflow: 'Legal entity set-up' };
await go(pg, '/admin/legal-entities');
if (!await has(pg, 'Northwind Retail Private Limited')) {
    await pg.getByRole('button', { name: 'New legal entity' }).click();
    await idle(pg, 1500);
    await byId(pg, 'company_id').selectOption({ label: 'Northwind Retail' });
    await byId(pg, 'code').fill('NWR-LE');
    await byId(pg, 'legal_name').fill('Northwind Retail Private Limited');
    await byId(pg, 'trade_name').fill('Northwind Retail');
    await byId(pg, 'legal_form').selectOption({ index: 1 });
    await byId(pg, 'country').fill('IN');
    await byId(pg, 'effective_from').fill('2026-04-01');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-18-new-legal-entity', { ...W2, screen: 'Organisation › Legal entities › New legal entity',
        action: 'Company, code, legal name, trade name, legal form, country, incorporation identifier, status and effective dates', expected: 'The statutory employer behind the company',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId(pg, 'company_id'), n: 1, label: 'Company' }, { loc: byId(pg, 'legal_name'), n: 2, label: 'Legal name' }, { loc: byId(pg, 'legal_form'), n: 3, label: 'Legal form' }, { loc: byId(pg, 'status'), n: 4, label: 'Status' }] });
    await modal(pg).getByRole('button', { name: 'Create', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    legal entity:', (await toasts(pg)).join(' / '));
    await closeToasts(pg);
}
await go(pg, '/admin/legal-entities');
await shot(s, pg, 'client-onboarding/90-onb-19-legal-entity-saved', { ...W2, screen: 'Organisation › Legal entities', action: 'Back to the list', expected: 'The legal entity with its company and status; row actions for verification',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Northwind Retail Private Limited' }), n: 1, label: 'Legal entity' }] });

// Establishment.
const W3 = { ...D, workflow: 'Establishment set-up' };
await go(pg, '/admin/establishments');
if (!await has(pg, 'Bengaluru head office')) {
    await tall(pg, 1300);
    await pg.getByRole('button', { name: 'New establishment' }).click();
    await idle(pg, 1500);
    await byId(pg, 'legal_entity_id').selectOption({ index: 1 });
    await byId(pg, 'code').fill('BLR-HO');
    await byId(pg, 'name').fill('Bengaluru head office');
    await byId(pg, 'establishment_type').selectOption({ index: 1 });
    await byId(pg, 'address').fill('12 Residency Road, Bengaluru (training example)');
    await byId(pg, 'country').fill('IN');
    await chooseSearchable(pg, modal(pg), 0, 'Karnataka', 'Karna');
    await byId(pg, 'district').fill('Bengaluru Urban');
    await byId(pg, 'postal_code').fill('560025');
    await byId(pg, 'effective_from').fill('2026-04-01');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-20-new-establishment', { ...W3, screen: 'Organisation › Establishments › New establishment',
        action: 'Legal entity, code, name, type, address, state, district, postal code, status and dates', expected: 'A registered place of work (state drives state-level statutory rules)',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId(pg, 'legal_entity_id'), n: 1, label: 'Legal entity' }, { loc: byId(pg, 'establishment_type'), n: 2, label: 'Type' }, { loc: byId(pg, 'district'), n: 3, label: 'District' }] });
    await modal(pg).getByRole('button', { name: 'Create', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    establishment:', (await toasts(pg)).join(' / '));
    await normal(pg);
    await closeToasts(pg);
}
await go(pg, '/admin/establishments');
await shot(s, pg, 'client-onboarding/90-onb-21-establishment-saved', { ...W3, screen: 'Organisation › Establishments', action: 'Back to the list', expected: 'Bengaluru head office under the legal entity',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Bengaluru head office' }), n: 1, label: 'Establishment' }] });

// Location.
const W4 = { ...D, workflow: 'Location set-up' };
await go(pg, '/admin/locations');
if (!await has(pg, 'Bengaluru HQ')) {
    await go(pg, '/admin/locations/create');
    await tall(pg, 1300);
    await chooseSearchable(pg, pg.locator('main'), 0, 'Northwind Retail', 'Northwind');
    await fillForm(pg, { name: 'Bengaluru HQ', code: 'BLR', address_line_1: '12 Residency Road', city: 'Bengaluru', postal_code: '560025', country_code: 'IN' });
    await pg.locator('[id="form.type"]').selectOption({ index: 1 }).catch(() => {});
    await pg.locator('[id="form.state_code"]').fill('KA').catch(() => {});
    await setForm(pg, 'effective_from', '2026-04-01');
    await shot(s, pg, 'client-onboarding/90-onb-22-new-location', { ...W4, screen: 'Organisation › Locations › New location', action: 'Company, name, code, type, address, state, postal code, country, time zone and dates',
        expected: 'A physical site for people, assets and attendance', callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.type"]'), n: 2, label: 'Type' }, { loc: pg.locator('[id="form.city"]'), n: 3, label: 'Address' }, { loc: pg.locator('[id="form.timezone"]'), n: 4, label: 'Time zone' }] });
    await pg.getByRole('button', { name: 'Create location', exact: true }).click();
    await idle(pg, 2500);
    console.log('    location:', pg.url(), (await toasts(pg)).join(' / '));
    await normal(pg);
    await closeToasts(pg);
}
await go(pg, '/admin/locations');
await shot(s, pg, 'client-onboarding/90-onb-23-location-saved', { ...W4, screen: 'Organisation › Locations', action: 'Back to the list', expected: 'Bengaluru HQ (BLR) for the company',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Bengaluru HQ' }), n: 1, label: 'Location' }] });

// Organisation units: business unit → department → team.
const W5 = { ...D, workflow: 'Organisation structure' };
const unit = async (path, name, code, parent, label, shotName, desc) => {
    await go(pg, path.replace('/create', ''));
    if (await has(pg, name)) return false;
    await go(pg, path);
    await tall(pg, 1100);
    await chooseSearchable(pg, pg.locator('main'), 0, 'Northwind Retail', 'Northwind');
    await fillForm(pg, { name, code, description: `${desc} (training example)` });
    await setForm(pg, 'effective_from', '2026-04-01');
    if (shotName) await shot(s, pg, shotName, { ...W5, screen: `${label} › New`, action: 'Company, name, code, description, status and effective date',
        expected: 'An effective-dated unit of the company; where it sits in the hierarchy is arranged in the Organisation Designer', callouts: [{ loc: pg.locator('main button', { hasText: 'Northwind Retail' }), n: 1, label: 'Company' }, { loc: pg.locator('[id="form.name"]'), n: 2, label: 'Name' }, { loc: pg.locator('[id="form.effective_from"]'), n: 3, label: 'Effective from' }] });
    await pg.locator('main button[type=submit]').first().click();
    await idle(pg, 2500);
    console.log('    unit', name, (await toasts(pg)).join(' / '));
    await normal(pg);
    await closeToasts(pg);
    return true;
};
await unit('/admin/business-units/create', 'Retail Operations', 'RETAIL', 'Northwind Retail', 'Business units', 'client-onboarding/90-onb-24-new-business-unit', 'Stores and supply chain');
await unit('/admin/departments/create', 'Store Operations', 'STOREOPS', 'Retail Operations', 'Departments', 'client-onboarding/90-onb-25-new-department', 'Runs the store network');
await unit('/admin/departments/create', 'People & Culture', 'PEOPLE', 'Northwind Retail', 'Departments', null, 'HR team');
await unit('/admin/teams/create', 'Store Excellence', 'STOREX', 'Store Operations', 'Teams', null, 'Store standards and audits');
await go(pg, '/admin/departments');
await shot(s, pg, 'client-onboarding/90-onb-26-departments', { ...W5, screen: 'Organisation › Departments', action: 'Open Departments', expected: 'Store Operations and People & Culture with their parents and dates',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Store Operations' }), n: 1, label: 'Department' }] });
await go(pg, '/admin/organisation-designer');
await shot(s, pg, 'client-onboarding/90-onb-27-organisation-designer', { ...W5, screen: 'Organisation › Organisation Designer', action: 'Open the Organisation Designer', expected: 'The hierarchy built so far: company → business unit → departments → team',
    callouts: [{ loc: pg.getByText('Retail Operations'), n: 1, label: 'Business unit' }] });
await go(pg, '/admin/organisation-map');
await shot(s, pg, 'client-onboarding/90-onb-28-org-map', { ...W5, screen: 'Organisation › Org map', action: 'Open the Org map', expected: 'Units and people (no people yet in a new tenant)', callouts: [] });

await finish(s);
