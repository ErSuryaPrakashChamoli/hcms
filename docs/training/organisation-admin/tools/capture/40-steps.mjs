// Data-driven step captures: tools/capture/steps/*.json hold flows; each flow is a list of actions ending in snaps.
//   { "flow": "m13-run-payroll", "module": "M13", "as": "admin@demo.local", "base": "http://127.0.0.1:8091", "steps": [
//       { "goto": "/admin/run-payroll?company=1&month=2026-11&step=month" },
//       { "click": "Recalculate" }, { "clickRow": { "row": "EMP00003", "action": "Breakdown" } },
//       { "fill": { "First name": "Asha" } }, { "choose": { "Company": "Demo Technologies" } },
//       { "press": "Control+k" }, { "type": "leave" }, { "wait": 800 }, { "scrollTo": "Step tables" },
//       { "snap": { "id": "01-pay-month", "screen": "Run payroll: Pay month", "path": "People › Pay › Run payroll", "labels": ["Next"], "full": false } }
//   ] }
// Screens are saved to screenshots/flows/<flow>/<id>.png and recorded in tools/.work/flows.json.
//   node capture/40-steps.mjs [flow-name-substring ...]
import { browser, signedIn, BASE, WORK, SHOTS, ADMIN, MASK } from './lib.mjs';
import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';

const want = process.argv.slice(2);
const outFile = join(WORK, 'flows.json');
const store = existsSync(outFile) ? JSON.parse(readFileSync(outFile, 'utf8')) : {};
const dir = join(WORK, '../capture', process.env.STEPS_DIR ?? 'steps');
const flows = readdirSync(dir).filter((f) => f.endsWith('.json')).sort().flatMap((f) => JSON.parse(readFileSync(join(dir, f), 'utf8')))
    .filter((f) => !want.length || want.some((w) => f.flow.includes(w)));
const DPR = 1.5;

const FIND_ANY = ({ labels }) => {
    const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').replace(/[*✱]\s*$/, '').trim().toLowerCase();
    const vis = (el) => { const r = el.getBoundingClientRect(); return r.width > 2 && r.height > 2 && getComputedStyle(el).visibility !== 'hidden'; };
    const top = (el) => !!el.closest('[role=dialog], [class*=modal], [class*=slide-over], [class*=dropdown-panel]');
    return labels.map((label) => {
        const L = norm(label);
        const pool = [...document.querySelectorAll('button, a, [role=tab], [role=menuitem], [role=option], th, label, [class*=field-label-content], legend, h2, h3, h4, span, p, div, dt, td, li')]
            .filter((x) => vis(x) && norm(x.innerText || x.value) === L);
        // Prefer elements in an open dialog / panel, then the smallest match.
        const el = pool.sort((a, b) => (top(b) - top(a)) || ((a.innerText?.length ?? 0) - (b.innerText?.length ?? 0)))[0];
        if (!el) return null;
        const wrap = el.matches('label, [class*=field-label-content], legend') ? (el.closest('[class*=field-wrp], [class*=fi-fo-field]') ?? el) : el;
        const r = wrap.getBoundingClientRect();
        return { label, x: r.left, y: r.top, w: r.width, h: r.height };
    }).filter(Boolean);
};

async function clickText(page, text, { within } = {}) {
    const root = within ? page.locator(within) : page;
    const tries = [
        () => root.getByRole('button', { name: text, exact: true }),
        () => root.getByRole('link', { name: text, exact: true }),
        () => root.getByRole('tab', { name: text, exact: true }),
        () => root.getByRole('menuitem', { name: text, exact: true }),
        () => root.getByRole('button', { name: text }),
        () => root.getByText(text, { exact: true }),
    ];
    for (const t of tries) {
        const l = t().filter({ visible: true }).first();
        if (await l.count()) { await l.click(); return true; }
    }
    throw new Error(`nothing to click: "${text}"`);
}

async function run(b, f) {
    const ctx = await signedIn(b, f.as ?? ADMIN);
    const page = await ctx.newPage();
    if (f.viewport) await page.setViewportSize(f.viewport);
    const base = f.base ?? BASE;
    try {
        for (const st of f.steps) {
            if (st.goto) { await page.goto(base + st.goto, { waitUntil: 'networkidle', timeout: 90000 }); await page.waitForTimeout(800); }
            else if (st.click) { await clickText(page, st.click, st); await page.waitForLoadState('networkidle'); await page.waitForTimeout(st.after ?? 900); }
            else if (st.clickAny) {
                let ok = false;
                for (const t of st.clickAny) { try { await clickText(page, t, st); ok = true; break; } catch {} }
                if (!ok) throw new Error(`none of ${st.clickAny.join(' / ')} to click`);
                await page.waitForLoadState('networkidle'); await page.waitForTimeout(st.after ?? 1500);
            }
            else if (st.clickAll) {
                for (let i = 0; i < (st.max ?? 12); i++) {
                    const l = page.getByRole('button', { name: st.clickAll, exact: true }).filter({ visible: true }).first();
                    if (!(await l.count())) break;
                    await l.click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(st.after ?? 1500);
                }
            }
            else if (st.clickSel) { await page.locator(st.clickSel).filter({ visible: true }).first().click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(st.after ?? 900); }
            else if (st.clickRow) {
                const row = page.locator('tbody tr').filter({ hasText: st.clickRow.row }).first();
                const act = row.getByRole('button', { name: st.clickRow.action }).or(row.getByRole('link', { name: st.clickRow.action })).first();
                if (await act.count()) await act.click();
                else {   // action hidden in the row's "more" menu
                    await row.locator('[class*=ta-actions] button').last().click();
                    await page.waitForTimeout(500);
                    await clickText(page, st.clickRow.action);
                }
                await page.waitForLoadState('networkidle'); await page.waitForTimeout(st.after ?? 1000);
            } else if (st.fill) { for (const [label, value] of Object.entries(st.fill)) await page.getByLabel(label, { exact: false }).filter({ visible: true }).first().fill(String(value)); }
            else if (st.choose) {
                for (const [label, value] of Object.entries(st.choose)) {
                    const field = page.getByLabel(label, { exact: false }).filter({ visible: true }).first();
                    const tag = await field.evaluate((e) => e.tagName.toLowerCase()).catch(() => '');
                    if (tag === 'select') await field.selectOption({ label: value });
                    else { await field.click(); await page.keyboard.type(String(value), { delay: 30 }); await page.waitForTimeout(900); await page.getByRole('option', { name: String(value) }).first().click().catch(() => page.keyboard.press('Enter')); }
                    await page.waitForTimeout(600);
                }
            } else if (st.press) { await page.keyboard.press(st.press); await page.waitForTimeout(st.after ?? 700); }
            else if (st.type) { await page.keyboard.type(st.type, { delay: 40 }); await page.waitForTimeout(st.after ?? 900); }
            else if (st.wait) { await page.waitForTimeout(st.wait); }
            else if (st.scrollTo) { await page.getByText(st.scrollTo, { exact: false }).first().scrollIntoViewIfNeeded(); await page.evaluate(() => window.scrollBy(0, -110)); await page.waitForTimeout(600); }
            else if (st.snap) {
                const sn = st.snap;
                await page.waitForTimeout(400);
                await page.evaluate(MASK);
                const found = await page.evaluate(FIND_ANY, { labels: sn.labels ?? [] });
                const sc = await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY }));
                const vh = (f.viewport?.height ?? 900);
                // Element positions are viewport-relative; a full-page image starts at the top of the page.
                const boxes = found.map((x, i) => ({ n: i + 1, label: x.label, kind: 'element', x: Math.round((x.x + (sn.full ? sc.x : 0)) * DPR), y: Math.round((x.y + (sn.full ? sc.y : 0)) * DPR), w: Math.round(x.w * DPR), h: Math.round(x.h * DPR) }))
                    .filter((x) => sn.full || (x.y >= 0 && x.y + x.h <= vh * DPR));
                const id = `flows/${f.flow}/${sn.id}`;
                const file = join(SHOTS, `${id}.png`);
                mkdirSync(dirname(file), { recursive: true });
                await page.screenshot({ path: file, fullPage: !!sn.full });
                store[id] = { module: f.module, screen: sn.screen, path: sn.path, step: sn.step ?? null, boxes, at: new Date().toISOString(), url: page.url().replace(base, ''), as: f.as ?? ADMIN };
                console.log(`  ✓ ${id} (${boxes.length}/${(sn.labels ?? []).length} callouts)`);
            }
        }
    } catch (e) {
        console.log(`  ✗ ${f.flow}: ${String(e.message).split('\n')[0].slice(0, 220)}`);
        const file = join(WORK, `fail-${f.flow}.png`);
        await page.screenshot({ path: file }).catch(() => {});
    } finally { await page.close(); }
}

const b = await browser();
for (const f of flows) { console.log(f.flow); await run(b, f); writeFileSync(outFile, JSON.stringify(store, null, 2)); }
await b.close();
