// Deck 4 stage 9: payroll components, salary structure and statutory set-up in Northwind Retail.
// EXECUTED on the disposable training database (fictional registration numbers). Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, dump } from './lib.mjs';
import './d-accounts.mjs';

const s = await start('90-onboarding-k-pay');
const D = { deck: [4], prereq: 'Signed in as the tenant administrator (payroll.manage, compliance.manage)' };
const pg = await open(s, 'nwadmin', '/admin/salary-components');
const byId = (id) => modal(pg).locator(`[id="mountedActionSchema0.${id}"]`);
const has = async (text) => (await pg.locator('main').getByText(text, { exact: false }).count()) > 0;

const W1 = { ...D, workflow: 'Payroll components and salary structures' };
await shot(s, pg, 'client-onboarding/90-onb-73-salary-components', { ...W1, screen: 'People › Pay › Salary components', action: 'Review the starting components every new tenant receives',
    expected: 'Basic (40 % of monthly CTC), HRA (50 % of basic), Conveyance, Special allowance (balancing), Bonus …; each with a formula, order and flags; statutory lines are never configured here',
    callouts: [{ loc: pg.locator('tr', { hasText: 'BASIC' }), n: 1, label: 'Formula-based component' }, { loc: pg.getByRole('link', { name: 'New salary component' }), n: 2, label: 'New salary component' }] });
await pg.locator('tr', { hasText: 'BASIC' }).getByRole('link', { name: 'Edit' }).first().click();
await idle(pg, 2000);
await pg.setViewportSize({ width: 1440, height: 1300 });
await shot(s, pg, 'client-onboarding/90-onb-74-salary-component-edit', { ...W1, screen: 'Salary components › Basic (edit)', action: 'Open a component to review its formula',
    expected: 'Formula validated on save; changes need a reason and may wait in the Change Centre when approval is on', callouts: [] });
await pg.setViewportSize({ width: 1440, height: 900 });
await go(pg, '/admin/salary-structures');
await shot(s, pg, 'client-onboarding/90-onb-75-salary-structures', { ...W1, screen: 'People › Pay › Salary structures', action: 'Review the salary structure',
    expected: 'A Standard structure (v1) is seeded; new versions are drafted, submitted and approved', callouts: [{ loc: pg.locator('tr', { hasText: 'Standard structure' }), n: 1, label: 'Structure' }] });

// Statutory registration (fictional number) and establishment statutory profile.
const W2 = { ...D, workflow: 'Statutory configuration' };
await go(pg, '/admin/statutory-registrations');
if (!await pg.locator('tbody tr', { hasText: 'KNBNG' }).count()) {
    await pg.setViewportSize({ width: 1440, height: 1250 });
    await pg.getByRole('button', { name: 'New registration' }).click();
    await idle(pg, 1500);
    await byId('legal_entity_id').selectOption({ label: 'Northwind Retail Pvt Ltd' });
    await idle(pg, 800);
    await byId('registration_type').selectOption({ label: 'EPF establishment code' });
    await byId('registration_number').fill('KNBNG0000000000');
    await byId('registration_name').fill('Northwind Retail Pvt Ltd (training example)');
    await byId('jurisdiction').fill('EPFO Bengaluru (Koramangala)');
    await byId('state_code').selectOption({ label: 'Karnataka' });
    await byId('effective_from').fill('2026-04-01');
    await byId('reason').fill('Registration for go-live; number is fictional (training example)');
    await idle(pg, 800);
    // First without an establishment: the real rule for EPF codes.
    await modal(pg).getByRole('button', { name: 'Submit', exact: true }).last().click();
    await idle(pg, 2000);
    console.log('    first submit', (await toasts(pg)).join(' / '));
    await shot(s, pg, 'client-onboarding/90-onb-76a-registration-needs-establishment', { ...W2, deck: [4, 7], workflow: 'Statutory configuration: common error', screen: 'Statutory registrations › New registration (refused)',
        action: 'Submit an EPF establishment code at entity level (no establishment chosen)', expected: '"A EPF establishment code registration belongs to an establishment." Nothing is saved',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Rule message' }] });
    await closeToasts(pg);
    // The dialog closed: start again and choose the establishment.
    await pg.getByRole('button', { name: 'New registration' }).click();
    await idle(pg, 1500);
    await byId('legal_entity_id').selectOption({ label: 'Northwind Retail Pvt Ltd' });
    await idle(pg, 1200);
    const est = (await byId('establishment_id').locator('option').allInnerTexts()).map((t) => t.trim()).find((t) => /principal/.test(t));
    console.log('    establishment options now:', (await byId('establishment_id').locator('option').allInnerTexts()).map((t) => t.trim()).join(' | '));
    if (est) await byId('establishment_id').selectOption({ label: est });
    await idle(pg, 800);
    await byId('registration_type').selectOption({ label: 'EPF establishment code' });
    await byId('registration_number').fill('KNBNG0000000000');
    await byId('registration_name').fill('Northwind Retail Pvt Ltd (training example)');
    await byId('jurisdiction').fill('EPFO Bengaluru (Koramangala)');
    await byId('state_code').selectOption({ label: 'Karnataka' });
    await byId('effective_from').fill('2026-04-01');
    await byId('reason').fill('Registration for go-live; number is fictional (training example)');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-76-new-statutory-registration', { ...W2, screen: 'People › Pay › Statutory registrations › New registration',
        action: 'Legal entity, establishment, registration type, number, name, jurisdiction, state, effective date, reason',
        expected: 'The employer’s EPF / ESIC / PT / LWF / TAN registrations held as effective-dated records', element: '.fi-modal-window:visible >> nth=-1',
        note: 'The registration number is fictional.',
        callouts: [{ loc: byId('legal_entity_id'), n: 1, label: 'Legal entity' }, { loc: byId('establishment_id'), n: 2, label: 'Establishment' }, { loc: byId('registration_type'), n: 3, label: 'Registration type' }, { loc: byId('registration_number'), n: 4, label: 'Number' }] });
    await modal(pg).getByRole('button', { name: 'Submit', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    registration', (await toasts(pg)).join(' / '));
    await pg.setViewportSize({ width: 1440, height: 900 });
    await closeToasts(pg);
}
await go(pg, '/admin/statutory-registrations');
await shot(s, pg, 'client-onboarding/90-onb-77-statutory-registrations', { ...W2, screen: 'People › Pay › Statutory registrations', action: 'Back to the list', expected: 'EPF establishment code for the legal entity, with its state and effective date',
    callouts: [{ loc: pg.locator('tbody tr', { hasText: 'KNBNG' }), n: 1, label: 'Registration' }] });

await go(pg, '/admin/establishment-statutory-profiles');
if (!await pg.locator('tbody tr', { hasText: 'EPF' }).count()) {
    await pg.setViewportSize({ width: 1440, height: 1100 });
    await pg.getByRole('button', { name: 'New profile' }).click();
    await idle(pg, 1500);
    await byId('establishment_id').selectOption({ label: 'Northwind Retail — principal establishment' });
    await idle(pg, 600);
    await byId('statute').selectOption({ label: 'EPF' });
    await idle(pg, 1200);
    const regs = (await byId('statutory_registration_id').locator('option').allInnerTexts()).map((t) => t.trim()).filter((t) => t && t !== 'Select an option');
    console.log('    registrations offered:', regs.join(' | '));
    if (regs[0]) await byId('statutory_registration_id').selectOption({ label: regs[0] });
    await byId('effective_from').fill('2026-04-01');
    await byId('reason').fill('EPF applies to the principal establishment from go-live (training example)');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-78-new-establishment-statutory-profile', { ...W2, screen: 'People › Pay › Statutory profiles › New profile',
        action: 'Establishment, statute (EPF, ESI, PT, LWF, TDS), the registration that covers it, effective dates, reason', expected: 'Tells payroll which statutes apply where',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId('establishment_id'), n: 1, label: 'Establishment' }, { loc: byId('statute'), n: 2, label: 'Statute' }, { loc: byId('statutory_registration_id'), n: 3, label: 'Registration' }] });
    await modal(pg).getByRole('button', { name: 'Submit', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    profile', (await toasts(pg)).join(' / '));
    await pg.setViewportSize({ width: 1440, height: 900 });
    await closeToasts(pg);
}
await go(pg, '/admin/establishment-statutory-profiles');
await shot(s, pg, 'client-onboarding/90-onb-79-establishment-statutory-profiles', { ...W2, screen: 'People › Pay › Statutory profiles', action: 'Back to the list', expected: 'EPF profile for the principal establishment', callouts: [] });

await go(pg, '/admin/compliance-control-room');
await pg.setViewportSize({ width: 1440, height: 1300 });
await shot(s, pg, 'client-onboarding/90-onb-80-compliance-control-room', { ...W2, deck: [4, 3, 8], screen: 'People › Pay › Control room (compliance)', action: 'Open the Compliance control room',
    expected: 'Rules this tenant needs per establishment and whether each is verified; returns; production gate. Rule verification is a platform (Markedge) responsibility',
    status: 'AVAILABLE ONLY AFTER CONFIGURATION', note: 'Statutory rules are unverified (0 of 24 at the time of capture), so payroll cannot be finalised; see the release gate.', callouts: [] });
await pg.setViewportSize({ width: 1440, height: 900 });
await go(pg, '/admin/compliance-rules');
await shot(s, pg, 'client-onboarding/90-onb-81-compliance-rules', { ...W2, deck: [4, 5, 8], screen: 'People › Pay › Compliance rules (read only for the tenant)', action: 'Open Compliance rules',
    expected: 'The statutory rule versions shipped by the platform with their verification status', status: 'DISABLED BY SAFETY OR COMMERCIAL GATES',
    note: 'Verification needs two platform administrators with official evidence; until then payroll finalisation is blocked.', callouts: [] });
await finish(s);
