// Step-by-step captures (flows) for procedures that a single screen cannot show: signing in, menus opened, the
// Employee 360 section by section, wizards step by step. Each step: a genuine screenshot (masked) plus the boxes of the
// labelled elements for numbered callouts. Writes screenshots/flows/<flow>/<nn>-<step>.png and tools/.work/flows.json.
//   node capture/30-flows.mjs [flowName ...]
import { browser, signedIn, BASE, WORK, SHOTS, ADMIN, MASK, FIND } from './lib.mjs';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';

const outFile = join(WORK, 'flows.json');
const store = existsSync(outFile) ? JSON.parse(readFileSync(outFile, 'utf8')) : {};
const DPR = 1.5;
const want = process.argv.slice(2);

/** Screenshot the current state as flows/<flow>/<step> with callouts on the given labels. */
async function snap(page, id, meta, { fields = [], elements = [], fullPage = false, clip } = {}) {
    await page.waitForTimeout(500);
    await page.evaluate(MASK);
    const found = await page.evaluate(FIND, { fields, elements });
    const scroll = fullPage ? { x: 0, y: 0 } : await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY }));
    const boxes = found.map((f, i) => ({ n: i + 1, label: f.label, kind: f.kind, x: Math.round((f.x - scroll.x) * DPR), y: Math.round((f.y - scroll.y) * DPR), w: Math.round(f.w * DPR), h: Math.round(f.h * DPR) }))
        .filter((b) => fullPage || (b.y >= 0 && b.y + b.h <= 900 * DPR));
    const file = join(SHOTS, `flows/${id}.png`);
    mkdirSync(dirname(file), { recursive: true });
    await page.screenshot({ path: file, fullPage, clip });
    store[`flows/${id}`] = { ...meta, boxes, at: new Date().toISOString(), url: page.url().replace(BASE, '') };
    console.log(`  ✓ flows/${id} (${boxes.length}/${fields.length + elements.length} callouts)`);
}
/** Find a menu/overlay element by text (anywhere in the document, not only <main>). */
const FIND_ANY = ({ labels }) => {
    const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
    const vis = (el) => { const r = el.getBoundingClientRect(); return r.width > 2 && r.height > 2 && getComputedStyle(el).visibility !== 'hidden'; };
    return labels.map((label) => {
        const L = norm(label);
        const el = [...document.querySelectorAll('button, a, [role=menuitem], [role=option], li, span, div, label, input, h2, h3')]
            .filter((x) => vis(x) && (norm(x.innerText || x.value || x.placeholder) === L)).sort((a, b) => (a.innerText?.length ?? 0) - (b.innerText?.length ?? 0))[0];
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { label, kind: 'element', x: r.left, y: r.top, w: r.width, h: r.height };
    }).filter(Boolean);
};
async function snapAny(page, id, meta, labels) {
    await page.waitForTimeout(500);
    await page.evaluate(MASK);
    const found = await page.evaluate(FIND_ANY, { labels });
    const boxes = found.map((f, i) => ({ n: i + 1, label: f.label, kind: f.kind, x: Math.round(f.x * DPR), y: Math.round(f.y * DPR), w: Math.round(f.w * DPR), h: Math.round(f.h * DPR) }));
    const file = join(SHOTS, `flows/${id}.png`);
    mkdirSync(dirname(file), { recursive: true });
    await page.screenshot({ path: file });
    store[`flows/${id}`] = { ...meta, boxes, at: new Date().toISOString(), url: page.url().replace(BASE, '') };
    console.log(`  ✓ flows/${id} (${boxes.length}/${labels.length} callouts)`);
}

const FLOWS = {
    // ---- M01 / M26: signing in and out (signed-out browser)
    async signin(b) {
        const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: 'en-GB' });
        const page = await ctx.newPage();
        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await snapAny(page, 'signin/01-sign-in', { module: 'M01', screen: 'Sign in to PeopleOS', path: 'Sign-in page' }, ['Email address*', 'Password*', 'Forgot password?', 'Remember me', 'Sign in']);
        await page.click('text=Forgot password?');
        await page.waitForLoadState('networkidle');
        await snapAny(page, 'signin/02-forgot-password', { module: 'M01', screen: 'Reset your password', path: 'Sign-in › Forgot password?' }, ['Email address*', 'Send email', 'back to login']);
        await page.fill('input[type=email]', 'nobody@example.test');
        await page.click('button[type=submit]');
        await page.waitForTimeout(1500);
        await snapAny(page, 'signin/03-reset-sent', { module: 'M01', screen: 'Reset link requested', path: 'Sign-in › Forgot password? › Send email' }, []);
        await ctx.close();
    },
    // ---- M01: the shell, menus opened
    async shell(b, ctx) {
        const page = await ctx.newPage();
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        await snapAny(page, 'shell/01-home', { module: 'M01', screen: 'Home', path: 'Home' }, ['Home', 'My work', 'People', 'Admin', 'Search people, requests, actions…', 'Actions', 'New', 'Ask PeopleOS']);
        await page.keyboard.press('Control+k');
        await page.waitForTimeout(800);
        await page.keyboard.type('payroll', { delay: 40 });
        await page.waitForTimeout(1200);
        await snapAny(page, 'shell/02-command-search', { module: 'M01', screen: 'Search (Ctrl K)', path: 'Top bar › Search' }, []);
        await page.keyboard.press('Escape');
        for (const [n, label, id, screen] of [[3, 'Actions', 'shell/03-actions-panel', 'Actions panel'], [4, 'New', 'shell/04-new-menu', 'New menu']]) {
            await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
            await page.getByRole('button', { name: label, exact: true }).first().click().catch(() => page.locator(`text=${label}`).first().click());
            await page.waitForTimeout(1000);
            await snapAny(page, id, { module: 'M01', screen, path: `Top bar › ${label}` }, []);
            await page.keyboard.press('Escape');
        }
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        await page.locator('header [class*=notifications] button, button[aria-label*="otification"], [class*=database-notifications-trigger]').first().click().catch(() => {});
        await page.waitForTimeout(1000);
        await snapAny(page, 'shell/05-notifications', { module: 'M01', screen: 'Notifications', path: 'Top bar › bell' }, []);
        await page.keyboard.press('Escape');
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        await page.locator('[class*=user-menu] button, button[aria-label*="ser menu"], [class*=fi-user-avatar]').first().click().catch(() => {});
        await page.waitForTimeout(800);
        await snapAny(page, 'shell/06-profile-menu', { module: 'M01', screen: 'Profile menu', path: 'Top bar › your initials' }, ['Sign out']);
        await page.keyboard.press('Escape');
        await page.goto(BASE + '/admin/payroll-runs', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: /All in Pay/ }).first().click().catch(() => page.locator('text=/All in Pay/').last().click().catch(() => {}));
        await page.waitForTimeout(800);
        await snapAny(page, 'shell/07-area-menu', { module: 'M01', screen: 'Area bar: every module of the area', path: 'People › Pay › All in Pay' }, []);
        await page.close();
    },
    async profile(b, ctx) {
        const page = await ctx.newPage();
        await page.goto(BASE + '/admin/profile', { waitUntil: 'networkidle' });
        await snap(page, 'profile/01-edit-profile', { module: 'M26', screen: 'Your profile (password and sign-in security)', path: 'Profile menu › Profile' }, { fullPage: true, fields: ['Name', 'Email address', 'New password', 'Confirm new password', 'Current password'] });
        await page.close();
    },
    // ---- M07: the Employee 360, section by section (a well-populated record of the example organisation)
    async employee360(b, ctx) {
        const page = await ctx.newPage();
        const id = process.env.EMP360_ID ?? '3';
        await page.goto(BASE + `/admin/employees/${id}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1000);
        await snap(page, 'employee360/00-whole-page', { module: 'M07', screen: 'Employee 360 (whole page)', path: 'People › Directory › an employee' }, { fullPage: true });
        // The 360's views are tabs (Now, Journey, Work, Growth, Rewards, Documents, Records); Records has its own tabs.
        const tabs = ['Now', 'Journey', 'Work', 'Growth', 'Rewards', 'Documents', 'Records'];
        let k = 1;
        for (const t of tabs) {
            const tab = page.locator('main').getByRole('tab', { name: t, exact: true }).first();
            const link = page.locator('main a, main button').filter({ hasText: new RegExp(`^\\s*${t}\\s*$`) }).first();
            const el = (await tab.count()) ? tab : link;
            if (!(await el.count())) { console.log(`  - no tab ${t}`); continue; }
            await el.click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(900);
            await snap(page, `employee360/${String(k++).padStart(2, '0')}-${t.toLowerCase()}`, { module: 'M07', screen: `Employee 360: ${t}`, path: `People › Directory › an employee › ${t}` }, { fullPage: true, elements: tabs });
            if (t === 'Records') {
                const subs = await page.locator('main [role=tab]').allInnerTexts();
                const recTabs = [...new Set(subs.map((x) => x.replace(/\s+/g, ' ').trim()).filter((x) => x && !tabs.includes(x) && !/completed|not reached|current stage/i.test(x)))];
                for (const r of recTabs.slice(0, 16)) {
                    await page.locator('main [role=tab]').filter({ hasText: r }).first().click().catch(() => {});
                    await page.waitForLoadState('networkidle');
                    await page.waitForTimeout(900);
                    await snap(page, `employee360/${String(k++).padStart(2, '0')}-records-${r.toLowerCase().replace(/[^a-z0-9]+/g, '-').slice(0, 30)}`, { module: 'M07', screen: `Employee 360: Records › ${r}`, path: `People › Directory › an employee › Records › ${r}` }, { fullPage: true, elements: [r] });
                }
            }
        }
        await page.close();
    },
    // ---- M26: signing out (own signed-in context, so the shared session stays valid)
    async signout(b) {
        const ctx = await signedIn(b, ADMIN, { fresh: true });
        const page = await ctx.newPage();
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        await page.locator('[class*=user-menu] button, button[aria-label*="ser menu"], [class*=fi-user-avatar]').first().click().catch(() => {});
        await page.waitForTimeout(800);
        await snapAny(page, 'signout/01-profile-menu', { module: 'M26', screen: 'Profile menu: Sign out', path: 'Top bar › your initials › Sign out' }, ['Sign out']);
        await page.locator('text=Sign out').first().click();
        await page.waitForLoadState('networkidle');
        await snapAny(page, 'signout/02-signed-out', { module: 'M26', screen: 'Signed out', path: 'Sign-in page' }, ['Sign in']);
        await ctx.close();
    },
};

const b = await browser();
const ctx = await signedIn(b);
for (const [name, fn] of Object.entries(FLOWS)) {
    if (want.length && !want.includes(name)) continue;
    console.log(name);
    try { await fn(b, ctx); } catch (e) { console.log(`  ✗ ${name}: ${String(e.message).slice(0, 200)}`); }
    writeFileSync(outFile, JSON.stringify(store, null, 2));
}
await b.close();
export { FLOWS, snap, snapAny };
