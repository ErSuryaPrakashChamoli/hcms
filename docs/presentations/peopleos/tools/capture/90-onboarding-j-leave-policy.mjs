// Deck 4 stage 8b: set the entitlements on the leave policy's draft version and publish it (Northwind Retail).
// EXECUTED on the disposable training database. Re-runnable (skips when v1 is already published).
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, dump } from './lib.mjs';
import './d-accounts.mjs';

const s = await start('90-onboarding-j-leave-policy');
const D = { deck: [4], workflow: 'Leave configuration: leave policy entitlements and publishing', prereq: 'Leave policy created (draft v1); policy.manage' };
const pg = await open(s, 'nwadmin', '/admin/policies');
await pg.locator('tr', { hasText: 'Northwind leave policy' }).getByRole('link').first().click();
await idle(pg, 1500);
await pg.setViewportSize({ width: 1440, height: 1600 });
await idle(pg, 2000);

if (await pg.getByRole('button', { name: 'Edit draft' }).count()) {
    await pg.getByRole('button', { name: 'Edit draft' }).click();
    await idle(pg, 2000);
    const m = () => modal(pg);
    const rows = [
        { type: 'Earned leave (EL)', days: '18', accrual: 'Monthly', proration: 'Remaining months', eligible: 'Immediately', cf: '30', encash: '10', notice: '7' },
        { type: 'Casual leave (CL)', days: '7', accrual: 'Annual (credited at year start)', proration: 'Remaining months', eligible: 'Immediately', cf: '0', encash: '0', notice: '1' },
        { type: 'Sick leave (SL)', days: '10', accrual: 'Annual (credited at year start)', proration: 'Remaining months', eligible: 'Immediately', cf: '0', encash: '0', notice: '0' },
    ];
    for (const [i, r] of rows.entries()) {
        await m().getByRole('button', { name: /Add to entitlements/ }).click();
        await idle(pg, 1200);
        const pick = async (suffix) => m().locator(`[id$=".${suffix}"]`).nth(i);
        await (await pick('leave_type_code')).selectOption({ label: r.type });
        await (await pick('days')).fill(r.days);
        await (await pick('accrual_frequency')).selectOption({ label: r.accrual });
        await (await pick('proration')).selectOption({ label: r.proration });
        await (await pick('eligible_after')).selectOption({ label: r.eligible });
        await (await pick('carry_forward_limit')).fill(r.cf);
        await (await pick('max_encash_days')).fill(r.encash);
        await (await pick('min_notice_days')).fill(r.notice);
        await idle(pg, 600);
    }
    await m().locator('[id="mountedActionSchema0.change_note"]').fill('Initial entitlements: EL 18 (monthly), CL 7, SL 10');
    await m().locator('[id="mountedActionSchema0.audit_reason"]').fill('Go-live leave entitlements agreed with HR (training example)');
    await idle(pg, 800);
    // The dialog is long: capture the first entitlement in full.
    await m().evaluate((e) => e.scrollTo?.(0, 0));
    await shot(s, pg, 'client-onboarding/90-onb-70-leave-entitlements', { ...D, screen: 'Policy › Versions & settings › Edit draft',
        action: 'For each leave type: days per year, accrual, pro-ration, eligibility, carry-forward, encashment, notice and limits',
        expected: 'The draft version holds the rules; nothing applies until it is published',
        callouts: [{ loc: m().locator('[id$=".leave_type_code"]').first(), n: 1, label: 'Leave type' }, { loc: m().locator('[id$=".days"]').first(), n: 2, label: 'Days per year' }, { loc: m().locator('[id$=".accrual_frequency"]').first(), n: 3, label: 'Accrual' }, { loc: m().locator('[id$=".carry_forward_limit"]').first(), n: 4, label: 'Carry-forward' }] });
    await m().getByRole('button', { name: 'Save changes', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    draft saved', (await toasts(pg)).join(' / '));
    await closeToasts(pg);
}

if (await pg.getByRole('button', { name: 'Publish draft' }).count()) {
    await pg.getByRole('button', { name: 'Publish draft' }).click();
    await idle(pg, 1500);
    await dump(pg, '.fi-modal-window:visible >> nth=-1', 30);
    const ta = modal(pg).locator('textarea');
    if (await ta.count()) await ta.first().fill('Publish go-live leave entitlements (training example)');
    await shot(s, pg, 'client-onboarding/90-onb-71-publish-policy', { ...D, screen: 'Policy › Publish draft', action: 'Publish the draft version', expected: 'The version becomes active from its effective date; earlier versions are kept',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
    await modal(pg).getByRole('button', { name: /Publish|Confirm|Submit/ }).last().click();
    await idle(pg, 2500);
    console.log('    published', (await toasts(pg)).join(' / '));
    await shot(s, pg, 'client-onboarding/90-onb-72-policy-published', { ...D, screen: 'Policy (after publishing)', action: 'Publish', expected: 'v1 published; Assignment rules decide who the policy applies to',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }, { loc: pg.getByRole('button', { name: 'Assignment rules' }), n: 2, label: 'Assignment rules' }] });
    await closeToasts(pg);
}
await finish(s);
