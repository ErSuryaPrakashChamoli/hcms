// Exit workflow (Decks 1, 3, 6, 11): employee resigns → HR exit case → clearance → exit interview → full & final
// settlement → complete exit → alumni. EXECUTED on the disposable training database (Rahul Sharma, fictional).
// Groups: resign case clearance interview settlement complete alumni (default: all, in order; re-runnable per group).
import { start, open, go, shot, finish, idle, settle, modal, setData, toasts, dump } from './lib.mjs';

const groups = process.argv.slice(2);
const want = (g) => groups.length === 0 || groups.includes(g);
const s = await start('70-exit');
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);
async function closeToasts(page) {
    for (const b of await page.locator('.fi-no-notification:visible .fi-no-notification-close-btn, .fi-no-notification:visible button.fi-icon-btn').all()) await b.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(400);
}
let f26 = false;
async function clearError(page, D) {
    await page.waitForTimeout(1500);
    if (!await page.locator('#livewire-error').count()) return;
    await page.evaluate(() => { const f = document.querySelector('#livewire-error iframe'); f?.contentWindow?.scrollTo(0, 0); });
    const msg = await page.frameLocator('#livewire-error iframe').locator('h1').first().innerText().catch(() => '');
    console.log('    error dialog:', msg.slice(0, 120));
    if (!f26 && /LazyLoading/.test(msg)) {
        f26 = true;
        await shot(s, page, 'support/70-exit-err-01-history-f26', { ...D, deck: [7], workflow: 'Exit case page: History section error (F26, non-production)', screen: 'Exit cases › case page', status: 'INCOMPLETE',
            action: 'Open an exit case', expected: 'LazyLoadingViolationException over the page (History section, strict mode outside production). Closing it leaves the page usable.', note: 'Known finding F26.' });
    }
    await page.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } });
    await page.waitForTimeout(400);
}
/** Run a header or row action by name on the current page; fill any textarea; confirm. Returns the first toast. */
async function act(page, name, D, fill = {}) {
    await closeToasts(page);
    let btn = page.getByRole('button', { name, exact: true });
    if (!await btn.count()) {
        const more = page.getByRole('button', { name: 'More', exact: true });
        if (await more.count()) { await more.first().click(); await idle(page, 700); btn = page.locator('.fi-dropdown-panel:visible').getByText(name, { exact: true }); }
    }
    if (!await btn.count()) { console.log('    no action', name); return null; }
    await btn.first().click(); await idle(page, 1500);
    if (await page.locator('.fi-modal-window:visible').count()) {
        const m = modal(page);
        for (const [id, v] of Object.entries(fill)) {
            const el = m.locator(`[id="mountedActionSchema0.${id}"]`);
            if (!await el.count()) continue;
            const tag = await el.evaluate((e) => e.tagName);
            if (tag === 'SELECT') await el.selectOption(typeof v === 'number' ? { index: v } : { label: v }).catch(() => {});
            else if (v?.date) await setData(page, id, v.date);
            else await el.fill(String(v));
        }
        for (const ta of await m.locator('textarea').all()) if (!(await ta.inputValue())) await ta.fill('Training example');
        await idle(page, 600);
        if (D.shot) await shot(s, page, D.shot, { ...D, element: '.fi-modal-window:visible >> nth=-1' });
        await m.getByRole('button', { name: /Confirm|Submit|Save|Start|Complete|Approve|Calculate/ }).last().click();
    }
    await idle(page, 2500);
    await clearError(page, D);
    const t = (await toasts(page)).map((x) => x.replace(/\s+/g, ' ').trim()).filter(Boolean);
    console.log(`    ${name} →`, t.slice(0, 1));
    return t[0] ?? '';
}

const W = { deck: [3, 11], workflow: 'Exit: resignation to alumni', prereq: 'Employee: own exit (Resign). HR: exit.manage / exit.clear / exit.settle (Tenant HR Admin, HR Manager)' };

if (want('resign')) {
    const e = await open(s, 'employee2', '/admin/exit-cases');
    await e.getByRole('button', { name: 'Resign' }).click(); await idle(e, 1500);
    await byId(e, 'reason').fill('Moving to another city for family reasons (training example)');
    await setData(e, 'requested_last_day', '2026-12-18');
    await shot(s, e, 'employee/70-exit-01-resign', { ...W, deck: [1, 3, 11], workflow: 'Resign (employee)', screen: 'My exit › Resign', action: 'The employee resigns: reason and requested last working day',
        expected: 'Blank last day = end of the notice period', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(e, 'reason'), n: 1, label: 'Reason' }, { loc: byId(e, 'requested_last_day'), n: 2, label: 'Requested last day' }, { loc: modal(e).getByRole('button', { name: 'Confirm' }), n: 3, label: 'Confirm' }] });
    await closeToasts(e);
    await modal(e).getByRole('button', { name: 'Confirm' }).click(); await idle(e, 2500);
    console.log('    resign toast', (await toasts(e)).slice(0, 1));
    await go(e, '/admin/exit-cases');
    await shot(s, e, 'employee/70-exit-02-my-exit', { ...W, deck: [1, 3], workflow: 'Resign (employee)', screen: 'My exit', action: 'The employee checks My exit', expected: 'The exit case with its status and last working day',
        callouts: [{ loc: e.locator('tbody tr').first(), n: 1, label: 'My exit case' }] });
    await e.close();
}

const rowBtn = (row, name) => row.locator('button, a').filter({ hasText: new RegExp(`^\\s*${name}\\s*$`) }).first();
async function openCase(persona = 'hr') {
    if (process.env.EXIT_CASE) { const h = await open(s, persona, `/admin/exit-cases/${process.env.EXIT_CASE}`); await clearError(h, W); return h; }
    const h = await open(s, persona, '/admin/exit-cases');
    const row = h.locator('tbody tr', { hasText: 'Rahul Sharma' }).first();
    await row.getByRole('link').last().click(); await idle(h, 2500);
    await clearError(h, W);
    return h;
}

if (want('case')) {
    const h = await open(s, 'hr', '/admin/exit-cases');
    await shot(s, h, 'hr-admin/70-exit-03-exit-cases', { ...W, screen: 'People › Lifecycle › Exit cases', action: 'HR opens Exit cases', expected: 'The resignation appears as a new exit case',
        callouts: [{ loc: h.locator('tbody tr', { hasText: 'Rahul Sharma' }), n: 1, label: 'New exit case' }, { loc: h.getByRole('button', { name: 'Initiate exit' }), n: 2, label: 'Initiate exit (HR-led)' }] });
    await h.close();
    const c = await openCase();
    console.log('    case url', c.url());
    await dump(c, 'main', 60);
    await shot(s, c, 'hr-admin/70-exit-04-case', { ...W, screen: 'Exit case (HR view)', action: 'Open the case', expected: 'Status, dates, notice; actions to start clearance, record the interview, generate letters and complete the exit' });
    await c.close();
}

if (want('clearance')) {
    const c = await openCase();
    const t = process.env.SKIP_START ? '' : await act(c, 'Start clearance', W);
    if (!process.env.SKIP_START) await shot(s, c, 'hr-admin/70-exit-05-clearance-started', { ...W, screen: 'Exit case › Clearance', action: 'Start clearance', expected: `Clearance stages are created from the exit checklist (${t || 'confirmation'})`,
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Started' }, { loc: c.getByRole('tab', { name: 'Clearance' }), n: 2, label: 'Clearance' }] });
    const tab = c.getByRole('tab', { name: 'Clearance' });
    await clearError(c, W);
    await tab.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(c, 1200);
    const rows = c.locator('.fi-ta tbody tr');
    console.log('    stages', (await rows.allInnerTexts()).map((x) => x.replace(/\s+/g, ' ')).join(' || '));
    await shot(s, c, 'hr-admin/70-exit-06-clearance-stages', { ...W, screen: 'Exit case › Clearance (stages)', action: 'Review the clearance stages', expected: 'One row per stage (manager, IT, finance, assets, HR …) with owner and status; Clear / Block / Not applicable',
        callouts: [{ loc: rows.first(), n: 1, label: 'Stage' }, { loc: rowBtn(rows.first(), 'Clear'), n: 2, label: 'Clear' }, { loc: rowBtn(rows.first(), 'Block'), n: 3, label: 'Block' }] });
    await c.close();
    // The line manager clears the manager stage from the same case.
    const mg = await openCase('manager');
    const mrow = mg.locator('.fi-ta tbody tr', { hasText: 'Manager clearance' }).first();
    await mrow.evaluate((x) => { x.scrollIntoView({ block: 'center' }); }); await idle(mg, 800);
    await closeToasts(mg);
    await rowBtn(mrow, 'Clear').click(); await idle(mg, 1200);
    if (await mg.locator('.fi-modal-window:visible').count()) {
        for (const ta of await modal(mg).locator('textarea').all()) await ta.fill('Handover done to the Platform team (training example)');
        await shot(s, mg, 'manager/70-exit-07-manager-clears-stage', { ...W, deck: [2, 3], workflow: 'Exit clearance: manager stage', screen: 'Exit case › Clearance › Clear (manager)',
            action: 'The line manager clears the Manager clearance stage', expected: 'Clear dialog with a note', element: '.fi-modal-window:visible >> nth=-1' });
        await modal(mg).getByRole('button', { name: /Confirm|Submit|Clear/ }).last().click();
    }
    await idle(mg, 2000); await clearError(mg, W);
    console.log('    manager clear →', (await toasts(mg)).slice(0, 1));
    await mg.close();
    const c2 = await openCase();
    // HR clears every remaining stage.
    for (let i = 0; i < 12; i++) {
        const btn = rowBtn(c2.locator('.fi-ta tbody tr'), 'Clear');
        if (!await btn.count()) break;
        await closeToasts(c2);
        await btn.click(); await idle(c2, 1200);
        if (await c2.locator('.fi-modal-window:visible').count()) {
            const m = modal(c2);
            for (const ta of await m.locator('textarea').all()) await ta.fill('Cleared (training example)');
            await m.getByRole('button', { name: /Confirm|Submit|Clear/ }).last().click();
        }
        await idle(c2, 1800); await clearError(c2, W);
        console.log('    clear →', (await toasts(c2)).slice(0, 1).map((x) => x.replace(/\s+/g, ' ')));
    }
    await c2.getByRole('tab', { name: 'Clearance' }).evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(c2, 1200);
    await shot(s, c2, 'hr-admin/70-exit-08-clearance-done', { ...W, screen: 'Exit case › Clearance (all stages cleared)', action: 'Clear each stage', expected: 'Every stage cleared, with who and when',
        callouts: [{ loc: c2.locator('.fi-ta tbody tr').first(), n: 1, label: 'Cleared stage' }] });
    await c2.close();
}

if (want('assetblock')) {
    const c = await openCase();
    const row = c.locator('.fi-ta tbody tr', { hasText: 'Asset clearance' }).first();
    await row.evaluate((x) => { x.scrollIntoView({ block: 'center' }); }); await idle(c, 800);
    await closeToasts(c);
    await rowBtn(row, 'Clear').click(); await idle(c, 1200);
    if (await c.locator('.fi-modal-window:visible').count()) { for (const ta of await modal(c).locator('textarea').all()) await ta.fill('Training example'); await modal(c).getByRole('button', { name: /Confirm|Submit|Clear/ }).last().click(); }
    await idle(c, 2000);
    console.log('    asset stage →', (await toasts(c)).slice(0, 1));
    await shot(s, c, 'hr-admin/70-exit-08a-asset-stage-blocked', { ...W, deck: [3, 7, 11], workflow: 'Exit clearance: assets still in custody', screen: 'Exit case › Clearance › Asset clearance › Clear',
        action: 'Clear the Asset clearance stage while the leaver still holds a laptop', expected: 'Refused: "Not allowed — Assets still in custody: LT-0001. Take them back, or record a recoverable amount."',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Refused' }, { loc: row, n: 2, label: 'Asset clearance' }] });
    await c.close();
    const a = await open(s, 'admin', '/admin/assets');
    await a.locator('tbody tr', { hasText: 'LT-0001' }).getByRole('link', { name: 'View' }).click(); await idle(a, 2000); await clearError(a, W);
    await closeToasts(a);
    const tb = a.getByRole('button', { name: 'Take back' });
    await tb.click(); await idle(a, 1500);
    const m = modal(a);
    for (const sel of await m.locator('select').all()) { const n = await sel.locator('option').count(); if (n > 1) await sel.selectOption({ index: 1 }).catch(() => {}); }
    for (const ta of await m.locator('textarea').all()) await ta.fill('Returned at exit, good condition (training example)');
    await idle(a, 600);
    await shot(s, a, 'hr-admin/70-exit-08b-take-back-asset', { ...W, workflow: 'Exit clearance: return company assets', screen: 'Asset register › LT-0001 › Take back',
        action: 'Take the laptop back from the leaver: date, condition, note', expected: 'Custody ends; the asset returns to stock', element: '.fi-modal-window:visible >> nth=-1' });
    await m.getByRole('button', { name: /Confirm|Submit|Take back/ }).last().click(); await idle(a, 2000); await clearError(a, W);
    console.log('    take back →', (await toasts(a)).slice(0, 1));
    await a.close();
    const c2 = await openCase();
    for (const stage of ['Asset clearance', 'HR clearance']) {
        const r = c2.locator('.fi-ta tbody tr', { hasText: stage }).first();
        await closeToasts(c2);
        await rowBtn(r, 'Clear').click(); await idle(c2, 1200);
        if (await c2.locator('.fi-modal-window:visible').count()) { for (const ta of await modal(c2).locator('textarea').all()) await ta.fill('Cleared (training example)'); await modal(c2).getByRole('button', { name: /Confirm|Submit|Clear/ }).last().click(); }
        await idle(c2, 2000); await clearError(c2, W);
        console.log(`    ${stage} →`, (await toasts(c2)).slice(0, 1));
    }
    await c2.getByRole('tab', { name: 'Clearance' }).evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(c2, 1200);
    await shot(s, c2, 'hr-admin/70-exit-08-clearance-done', { ...W, screen: 'Exit case › Clearance (all stages cleared)', action: 'Clear each stage (manager, IT, finance, assets, HR)',
        expected: 'Every stage cleared, with who and when', callouts: [{ loc: c2.locator('.fi-ta tbody tr').first(), n: 1, label: 'Cleared stages' }] });
    await c2.close();
}

if (want('interview')) {
    const c = await openCase();
    await closeToasts(c);
    await c.getByRole('button', { name: 'Record exit interview' }).click(); await idle(c, 1500);
    const m = modal(c);
    const ids = await m.locator('[id^="mountedActionSchema0."]').evaluateAll((els) => els.map((e) => e.tagName + ' ' + e.id + ' ' + (e.type || '')));
    console.log('    interview fields', ids.join(', '));
    for (const sel of await m.locator('select').all()) { const n = await sel.locator('option').count(); if (n > 2) await sel.selectOption({ index: Math.min(4, n - 1) }).catch(() => {}); }
    const names = await m.locator('input[type=radio]').evaluateAll((els) => [...new Set(els.map((e) => e.name || e.id.replace(/-\d+$/, '')))]);
    for (const n of names) { const r = m.locator(`input[type=radio][id$="-4"][id^="${n.startsWith('mountedActionSchema0') ? n : ''}"]`).first(); }
    for (const id of await m.locator('input[type=radio]').evaluateAll((els) => els.filter((e) => /-4$/.test(e.id) || e.value === '4').map((e) => e.id))) await m.locator(`[id="${id}"]`).check({ force: true }).catch(() => {});
    for (const ta of await m.locator('textarea').all()) await ta.fill('Good team and manager; relocating for family reasons (training example)');
    for (const tg of await m.locator('button[role=switch]').all()) await tg.click().catch(() => {});
    await idle(c, 800);
    await shot(s, c, 'hr-admin/70-exit-09-exit-interview', { ...W, screen: 'Exit case › Record exit interview', action: 'Record the exit interview: reason, ratings 1–5, would recommend / rejoin, comments',
        expected: 'Answers feed Exit insights (averages use employee answers only)', element: '.fi-modal-window:visible >> nth=-1' });
    await m.getByRole('button', { name: /Confirm|Submit|Save/ }).last().click(); await idle(c, 2500); await clearError(c, W);
    console.log('    interview →', (await toasts(c)).slice(0, 1));
    await c.close();
}

async function ffTab(c) {
    const t = c.getByRole('tab', { name: 'Full & final' });
    await t.click(); await idle(c, 2500); await clearError(c, W);
    await t.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(c, 800);
    return t;
}
if (want('settlement')) {
    const SW = { ...W, deck: [3, 11], workflow: 'Full & final settlement', prereq: 'exit.settle (Tenant HR Admin, HR Manager)' };
    const c = await openCase();
    let t = await ffTab(c);
    if (!process.env.ONLY_PAID) {
    await shot(s, c, 'hr-admin/70-exit-10-ff-tab', { ...SW, screen: 'Exit case › Full & final', action: 'Open Full & final', expected: 'Settlement lines with Calculate, Add line and Breakdown',
        callouts: [{ loc: t, n: 1, label: 'Full & final' }, { loc: c.getByRole('button', { name: 'Calculate' }), n: 2, label: 'Calculate' }, { loc: c.getByRole('button', { name: 'Add line' }), n: 3, label: 'Add line' }] });
    await closeToasts(c);
    await c.getByRole('button', { name: 'Calculate' }).click(); await idle(c, 1500);
    if (await c.locator('.fi-modal-window:visible').count()) await modal(c).getByRole('button', { name: /Confirm|Submit|Calculate/ }).last().click();
    await idle(c, 3000); await clearError(c, SW);
    const tc = await toasts(c);
    console.log('    calculate →', tc.slice(0, 1));
    t = await ffTab(c);
    console.log('    ff rows', (await c.locator('.fi-ta').last().locator('tbody tr').allInnerTexts()).map((x) => x.replace(/\s+/g, ' ')).slice(0, 12).join(' || '));
    await shot(s, c, 'hr-admin/70-exit-11-ff-calculated', { ...SW, screen: 'Exit case › Full & final (calculated)', action: 'Calculate',
        expected: `Settlement lines (salary for days worked, leave encashment, recoveries, statutory deductions) and the net amount. ${tc[0] ? 'Message: ' + tc[0].replace(/\s+/g, ' ') : ''}`,
        note: 'Gratuity is not calculated (not implemented); statutory deductions use payroll rules that are not yet verified.',
        callouts: [{ loc: c.locator('.fi-ta').last().locator('tbody tr').first(), n: 1, label: 'Settlement line' }] });
    await closeToasts(c);
    await c.getByRole('button', { name: 'Breakdown' }).click(); await idle(c, 2000);
    if (await c.locator('.fi-modal-window:visible').count()) {
        await shot(s, c, 'hr-admin/70-exit-12-ff-breakdown', { ...SW, screen: 'Full & final › Breakdown', action: 'Open Breakdown', expected: 'How each line was computed (the Basis column shows raw technical values)',
            element: '.fi-modal-window:visible >> nth=-1' });
        await c.keyboard.press('Escape'); await idle(c, 800);
    }
    }
    const btns = (await c.locator('main button').allInnerTexts()).map((x) => x.trim()).filter(Boolean);
    console.log('    buttons now', btns.join(' | '));
    for (const name of process.env.ONLY_PAID ? ['Mark paid'] : ['Approve', 'Mark paid']) {
        const b = c.getByRole('button', { name, exact: true });
        if (!await b.count()) { console.log('    no', name); continue; }
        await closeToasts(c);
        await b.first().click(); await idle(c, 1500);
        if (await c.locator('.fi-modal-window:visible').count()) {
            for (const ta of await modal(c).locator('textarea').all()) await ta.fill('Training example');
            const ref = modal(c).locator('[id="mountedActionSchema0.reference"]');
            if (await ref.count()) { await ref.fill('NEFT-TRAINING-0001'); await shot(s, c, 'hr-admin/70-exit-13b-ff-mark-paid-form', { ...SW, screen: 'Full & final › Mark paid', action: 'Record the payment reference', expected: 'Mark paid dialog', element: '.fi-modal-window:visible >> nth=-1' }); }
            await modal(c).getByRole('button', { name: /Confirm|Submit|Approve|Mark/ }).last().click();
        }
        await idle(c, 2500); await clearError(c, SW);
        const tt = await toasts(c);
        console.log(`    ${name} →`, tt.slice(0, 1));
        await ffTab(c);
        await shot(s, c, `hr-admin/70-exit-13-ff-${name === 'Approve' ? 'approve' : 'paid'}`, { ...SW, screen: `Full & final (after ${name})`, action: name,
            expected: tt[0] ? tt[0].replace(/\s+/g, ' ') : `${name} recorded`, callouts: [{ sel: '.fi-no-notification', n: 1, label: name }] });
    }
    await c.close();
}

if (want('complete')) {
    const c = await openCase();
    await closeToasts(c);
    await c.getByRole('button', { name: 'Complete exit' }).click(); await idle(c, 1500);
    if (await c.locator('.fi-modal-window:visible').count()) {
        for (const ta of await modal(c).locator('textarea').all()) await ta.fill('All stages cleared and settlement approved (training example)');
        await shot(s, c, 'hr-admin/70-exit-14-complete-exit', { ...W, screen: 'Exit case › Complete exit', action: 'Complete the exit', expected: 'Confirmation; the employee becomes Exited on the last working day', element: '.fi-modal-window:visible >> nth=-1' });
        await modal(c).getByRole('button', { name: /Confirm|Submit|Complete/ }).last().click();
    }
    await idle(c, 2500); await clearError(c, W);
    const tt = await toasts(c);
    console.log('    complete →', tt.slice(0, 1));
    await shot(s, c, 'hr-admin/70-exit-15-completed', { ...W, screen: 'Exit case (after Complete exit)', action: 'Complete exit', expected: tt[0] ? tt[0].replace(/\s+/g, ' ') : 'Case completed',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
    const btns = (await c.locator('main button').allInnerTexts()).map((x) => x.trim()).filter(Boolean);
    console.log('    buttons now', btns.join(' | '));
    await c.close();
}

if (want('alumni')) {
    const c = await openCase();
    await closeToasts(c);
    await c.getByRole('button', { name: 'Create alumni profile' }).click(); await idle(c, 1500);
    if (await c.locator('.fi-modal-window:visible').count()) {
        const m = modal(c);
        console.log('    alumni fields', (await m.locator('[id^="mountedActionSchema0."]').evaluateAll((els) => els.map((e) => e.tagName + ' ' + e.id))).join(', '));
        for (const tg of await m.locator('button[role=switch]').all()) { if ((await tg.getAttribute('aria-checked')) !== 'true') await tg.click().catch(() => {}); }
        const em = m.locator('input[type=email]'); if (await em.count()) await em.first().fill('rahul.sharma@example.test');
        await idle(c, 600);
        await shot(s, c, 'hr-admin/70-exit-16-create-alumni', { ...W, screen: 'Exit case › Create alumni profile', action: 'Create the alumni profile (personal e-mail, portal access, consent)',
            expected: 'The leaver keeps a lifetime record as an alumnus', element: '.fi-modal-window:visible >> nth=-1' });
        await m.getByRole('button', { name: /Confirm|Submit|Create/ }).last().click();
    }
    await idle(c, 2500); await clearError(c, W);
    console.log('    alumni →', (await toasts(c)).slice(0, 1));
    await c.close();
    const a = await open(s, 'hr', '/admin/alumni-profiles');
    await shot(s, a, 'hr-admin/70-exit-17-alumni-list', { ...W, screen: 'People › Lifecycle › Alumni', action: 'Open Alumni', expected: 'The new alumni profile (with the earlier seeded alumnus)',
        callouts: [{ loc: a.locator('tbody tr', { hasText: 'Rahul Sharma' }), n: 1, label: 'New alumnus' }] });
    await a.close();
    const e = await open(s, 'hr', '/admin/employees/3');
    await shot(s, e, 'hr-admin/70-exit-18-employee-exited', { ...W, deck: [3, 9, 11], screen: 'Employee 360 (after the exit)', action: 'Open the leaver’s Employee 360',
        expected: 'Status Exited / Alumni; the one lifetime record keeps the whole journey', callouts: [{ loc: e.getByText('One lifetime record'), n: 1, label: 'Lifetime record' }] });
    await e.close();
}

if (want('portal')) {
    const AW = { ...W, deck: [1, 3, 6], workflow: 'Alumni portal', prereq: 'Alumni role with portal enabled on the alumni profile' };
    const p = await open(s, 'employee2', '/admin/alumni-portal');
    await shot(s, p, 'employee/70-alumni-01-portal', { ...AW, screen: 'Alumni portal (former employee)', action: 'The former employee signs in after the exit and opens the Alumni portal',
        expected: 'Welcome back; My requests, My documents, Payslips; Request a document or reference',
        callouts: [{ loc: p.getByRole('button', { name: 'Request a document or reference' }), n: 1, label: 'Request a document' }, { loc: p.getByText('MY DOCUMENTS', { exact: false }), n: 2, label: 'My documents' }] });
    await closeToasts(p);
    await p.getByRole('button', { name: 'Request a document or reference' }).click(); await idle(p, 1500);
    const m = modal(p);
    console.log('    portal fields', (await m.locator('[id^="mountedActionSchema0."]').evaluateAll((els) => els.map((e) => e.tagName + ' ' + e.id))).join(', '));
    for (const sel of await m.locator('select').all()) { const opts = await sel.locator('option').allInnerTexts(); const i = opts.findIndex((o) => /experience|relieving/i.test(o)); await sel.selectOption({ index: i > 0 ? i : 1 }).catch(() => {}); }
    for (const ta of await m.locator('textarea').all()) await ta.fill('Needed for a new employer’s background check (training example)');
    await idle(p, 600);
    await shot(s, p, 'employee/70-alumni-02-request-document', { ...AW, screen: 'Alumni portal › Request a document or reference', action: 'Choose the document and give a purpose',
        expected: 'The request goes to HR, who verify identity before issuing', element: '.fi-modal-window:visible >> nth=-1' });
    await m.getByRole('button', { name: /Submit|Confirm|Request/ }).last().click(); await idle(p, 2500); await clearError(p, AW);
    console.log('    alumni request →', (await toasts(p)).slice(0, 1));
    await shot(s, p, 'employee/70-alumni-03-requested', { ...AW, screen: 'Alumni portal (after the request)', action: 'Submit', expected: 'The request appears under My requests',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Requested' }, { loc: p.getByText('MY REQUESTS', { exact: false }), n: 2, label: 'My requests' }] });
    await p.close();
    const h = await open(s, 'hr', '/admin/alumni-profiles');
    await h.locator('tbody tr', { hasText: 'Rahul Sharma' }).getByRole('link', { name: 'View' }).first().click().catch(async () => { await h.locator('tbody tr', { hasText: 'Rahul Sharma' }).first().click(); });
    await idle(h, 2500); await clearError(h, AW);
    const rt = h.getByRole('tab', { name: 'Requests' });
    if (await rt.count()) { await rt.click(); await idle(h, 2000); await rt.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(h, 800); }
    await shot(s, h, 'hr-admin/70-alumni-04-hr-requests', { ...AW, deck: [3, 6], workflow: 'Alumni requests (HR)', screen: 'Alumni › Rahul Sharma › Requests', action: 'HR opens the alumni profile’s Requests',
        expected: 'The request with Verify identity, Approve & generate, Mark delivered, Reject',
        callouts: [{ loc: h.locator('.fi-ta tbody tr').first(), n: 1, label: 'Alumni request' }] });
    await h.close();
}

if (want('ffretake')) {
    const SW = { ...W, deck: [3, 11], workflow: 'Full & final settlement', prereq: 'exit.settle (Tenant HR Admin, HR Manager)' };
    const c = await openCase();
    await c.setViewportSize({ width: 1440, height: 1200 });
    const t = c.getByRole('tab', { name: 'Full & final' });
    await t.click(); await idle(c, 2500); await clearError(c, SW);
    for (let i = 0; i < 4; i++) { await c.mouse.wheel(0, 400); await idle(c, 700); }
    await t.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -120); }); await idle(c, 1000);
    const tbl = c.locator('.fi-ta').last();
    console.log('    ff', (await tbl.locator('tbody tr').allInnerTexts()).map((x) => x.replace(/\s+/g, ' ')).join(' || '));
    await shot(s, c, 'hr-admin/70-exit-11-ff-settlement', { ...SW, screen: 'Exit case › Full & final',
        action: 'Calculate, Approve and Mark paid (executed in this order)', expected: 'Settlement: total earnings 43,400.06, deductions 5,146.00, net 38,254.06; status Paid with reference; Breakdown per line',
        note: 'Gratuity is not calculated (not implemented). Statutory deductions use payroll rules that are not yet verified. The same HR person calculated and approved (no separation of duties on F&F approval).',
        callouts: [{ loc: tbl.locator('tbody tr').first(), n: 1, label: 'Settlement' }, { loc: c.getByRole('button', { name: 'Breakdown' }), n: 2, label: 'Breakdown' }] });
    await c.getByRole('button', { name: 'Breakdown' }).click(); await idle(c, 2000);
    await c.mouse.move(720, 450);
    for (let i = 0; i < 12; i++) { await c.mouse.wheel(0, -1500); await c.waitForTimeout(150); }
    await c.evaluate(() => { document.querySelectorAll('*').forEach((e) => { if (e.scrollTop > 0) e.scrollTop = 0; }); });
    await idle(c, 600);
    await shot(s, c, 'hr-admin/70-exit-12-ff-breakdown', { ...SW, screen: 'Full & final › Breakdown', action: 'Open Breakdown',
        expected: 'Each line with type, name, amount and its basis (the Basis column shows raw technical values)' });
    await c.close();
}

await finish(s);
