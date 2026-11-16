// Crawl every screen the organisation administrator can open (from tools/.work/discovery.json), as that administrator,
// on the training database. For each screen: a genuine screenshot (sensitive values masked) and the interface as
// rendered: heading, breadcrumbs, tabs, header actions, table columns, filters, first-row actions, form fields,
// sections and wizard steps. Results: tools/.work/crawl.json (resumable). Screenshots: screenshots/<section>/<slug>.png
//   node capture/10-crawl.mjs [--only <substring>] [--redo]
import { browser, signedIn, BASE, WORK, SHOTS } from './lib.mjs';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';

const args = process.argv.slice(2);
const only = args.includes('--only') ? args[args.indexOf('--only') + 1] : null;
const redo = args.includes('--redo');
const disc = JSON.parse(readFileSync(join(WORK, 'discovery.json'), 'utf8'));
const outFile = join(WORK, 'crawl.json');
const done = existsSync(outFile) && !redo ? JSON.parse(readFileSync(outFile, 'utf8')) : {};
const slug = (s) => String(s).toLowerCase().replace(/&/g, 'and').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);

// ---- targets: every module in the rail (index), then create / view / edit of each accessible resource
const targets = [];
const seen = new Set();
const add = (t) => { if (!t.url || seen.has(t.url)) return; seen.add(t.url); targets.push(t); };
for (const s of disc.sections) {
    if (s.key === 'home') add({ id: 'home/home', section: 'home', area: 'Home', label: 'Home', url: s.landing, kind: 'page', cls: 'Home' });
    for (const a of s.areas) for (const m of a.modules) add({ id: `${s.key}/${slug(m.label)}`, section: s.key, area: a.label, label: m.label, url: m.url, kind: m.kind === 'resource' ? 'index' : 'page', cls: m.class });
}
const byClass = Object.fromEntries(targets.map((t) => [t.cls, t]));
for (const r of disc.resources.filter((r) => r.access)) {
    const parent = byClass[r.class];
    const base = parent ? parent.id : `other/${slug(r.label)}`;
    const meta = { section: parent?.section ?? 'other', area: parent?.area ?? r.group, label: r.label, cls: r.class };
    if (!parent && r.pages.index?.url) add({ ...meta, id: base, url: r.pages.index.url, kind: 'index' });
    if (r.pages.create?.url && r.can_create !== false) add({ ...meta, id: `${base}--create`, url: r.pages.create.url, kind: 'create' });
    if (r.pages.view?.sample) add({ ...meta, id: `${base}--view`, url: r.pages.view.sample, kind: 'view' });
    if (r.pages.edit?.sample) add({ ...meta, id: `${base}--edit`, url: r.pages.edit.sample, kind: 'edit' });
}
const todo = targets.filter((t) => (!only || t.id.includes(only) || t.url.includes(only)) && (redo || !done[t.id]));
console.log(`${targets.length} screens in scope, ${todo.length} to capture`);

// ---- in-page helpers
const MASK = () => {
    const rules = [
        [/\b[A-Z]{5}\d{4}[A-Z]\b/g, (m) => m.slice(0, 2) + 'XXXXXX' + m.slice(-2)],                         // PAN
        [/\b\d{4}[ -]?\d{4}[ -]?\d{4}\b/g, (m) => 'XXXX XXXX ' + m.replace(/\D/g, '').slice(-4)],           // Aadhaar / UAN
        [/\b\d{9,18}\b/g, (m) => 'X'.repeat(m.length - 4) + m.slice(-4)],                                   // account numbers
        [/(\+91[\s-]?)?\b[6-9]\d{4}[\s-]?\d{5}\b/g, () => '+91 XXXXX XXXXX'],                               // mobile numbers
        [/[A-Za-z0-9._%+-]+@(?!demo\.local\b)(?!markedge)[A-Za-z0-9.-]+\.[a-z]{2,}/gi, () => 'personal.email@masked'],
    ];
    const fix = (s) => rules.reduce((t, [re, f]) => t.replace(re, f), s);
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) { const v = fix(n.nodeValue); if (v !== n.nodeValue) n.nodeValue = v; }
    for (const i of document.querySelectorAll('input:not([type=hidden]), textarea')) { if (i.value) { const v = fix(i.value); if (v !== i.value) i.value = v; } }
};
const EXTRACT = () => {
    const vis = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    const txt = (el) => (el?.innerText ?? el?.textContent ?? '').replace(/\s+/g, ' ').trim();
    const main = document.querySelector('main') || document.body;
    const q = (sel, root = main) => [...root.querySelectorAll(sel)].filter(vis);
    const uniq = (a) => [...new Set(a.map((x) => (typeof x === 'string' ? x.trim() : x)).filter(Boolean))];
    const fieldLabels = q('[class*=field-label-content], label[class*=field-label]').map((l) => {
        const wrap = l.closest('[class*=field-wrp], [class*=fo-field]') ?? l.parentElement;
        return { label: txt(l).replace(/\*$/, '').trim(), required: !!wrap?.querySelector('sup, [class*=required]') || /\*\s*$/.test(txt(l)) };
    }).filter((f) => f.label);
    return {
        title: document.title.replace(/ - PeopleOS$/, ''),
        h1: txt(document.querySelector('h1')),
        subheading: txt(document.querySelector('[class*=header-subheading]')),
        breadcrumbs: uniq(q('[class*=breadcrumbs-item-label]', document).map(txt)),
        tabs: uniq(q('[role=tab], [class*=tabs-item-label]').map(txt)).slice(0, 40),
        headerActions: uniq(q('[class*=header-actions] button, [class*=header-actions] a, [class*=fi-header] [class*=fi-ac] button, [class*=fi-header] [class*=fi-ac] a').map(txt)).slice(0, 30),
        toolbar: uniq(q('[class*=ta-header-toolbar] button, [class*=ta-header-toolbar] a, [class*=ta-header] [class*=fi-ac] button').map(txt)).slice(0, 30),
        columns: uniq(q('thead th').map(txt)).slice(0, 40),
        rows: q('tbody tr').length,
        pagination: txt(main.querySelector('[class*=pagination-overview]')),
        empty: txt(main.querySelector('[class*=empty-state-heading]')),
        search: !!main.querySelector('[class*=search-field] input, input[type=search]'),
        rowActions: uniq(q('tbody tr:first-child [class*=ta-actions] a, tbody tr:first-child [class*=ta-actions] button').map((e) => txt(e) || e.getAttribute('aria-label') || e.title)).slice(0, 20),
        fields: fieldLabels.slice(0, 120),
        sections: uniq(q('[class*=section-header-heading]').map(txt)).slice(0, 40),
        steps: uniq(q('[class*=wizard-header-step-label], [class*=step-label]').map(txt)).slice(0, 20),
        headings: uniq(q('h2, h3').map(txt)).slice(0, 50),
        buttons: uniq(q('button, a[class*=btn]').map(txt)).filter((t) => t.length < 60).slice(0, 100),
        notAvailable: /not available to you|Server Error|Not Found|Forbidden/i.test(document.title + ' ' + txt(document.querySelector('h1'))),
        height: document.documentElement.scrollHeight,
    };
};

async function filtersOf(page) {
    const trigger = page.locator('main [class*=filters-trigger] button, main button[title*="ilter"], main [class*=ta-filters] button').first();
    if (!(await trigger.count())) return [];
    try {
        await trigger.click({ timeout: 3000 });
        await page.waitForTimeout(500);
        const labels = await page.locator('[class*=dropdown-panel]:visible [class*=field-label-content], [class*=ta-filters-dropdown]:visible label').allInnerTexts();
        await page.keyboard.press('Escape');
        return [...new Set(labels.map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean))];
    } catch { return []; }
}

async function capture(ctx, t) {
    const page = await ctx.newPage();
    const started = Date.now();
    let status = null;
    try {
        const resp = await page.goto(BASE + t.url, { waitUntil: 'networkidle', timeout: 60000 });
        status = resp?.status() ?? null;
        await page.waitForTimeout(700);
        const filters = await filtersOf(page);
        await page.evaluate(MASK);
        const ui = await page.evaluate(EXTRACT);
        const file = join(SHOTS, `${t.id}.png`);
        mkdirSync(dirname(file), { recursive: true });
        await page.screenshot({ path: file, fullPage: true });
        return { ...t, status, finalUrl: page.url().replace(BASE, ''), ui: { ...ui, filters }, shot: `screenshots/${t.id}.png`, ms: Date.now() - started };
    } catch (e) {
        return { ...t, status, error: String(e.message).slice(0, 300), ms: Date.now() - started };
    } finally { await page.close(); }
}

const b = await browser();
const ctx = await signedIn(b);
let n = 0;
const save = () => writeFileSync(outFile, JSON.stringify(done, null, 2));
const worker = async () => {
    while (todo.length) {
        const t = todo.shift();
        const r = await capture(ctx, t);
        done[t.id] = r; n += 1;
        if (n % 10 === 0) { save(); console.log(`${n} done (${todo.length} left) last ${t.id} ${r.status ?? r.error?.slice(0, 40)}`); }
    }
};
await Promise.all([worker(), worker(), worker()]);
save();
await b.close();
const all = Object.values(done);
console.log(`captured ${all.length}: ok ${all.filter((r) => r.status === 200 && !r.ui?.notAvailable).length}, not available ${all.filter((r) => r.ui?.notAvailable).length}, errors ${all.filter((r) => r.error || (r.status && r.status >= 400)).length}`);
