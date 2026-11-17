// Callout pass: for every screen, find the elements and form fields that the content explains (by their on-screen
// label), record their boxes in screenshot pixels, and re-take the screenshot in that same state (masked).
// Writes tools/.work/annotations.json { id: { boxes: [{ n, label, x, y, w, h }], at } }. Resumable.
//   node capture/20-annotate.mjs [--only <substring>] [--redo]
import { browser, signedIn, BASE, WORK, SHOTS, FIND } from './lib.mjs';
import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const args = process.argv.slice(2);
const only = args.includes('--only') ? args[args.indexOf('--only') + 1] : null;
const redo = args.includes('--redo');
const crawl = JSON.parse(readFileSync(join(WORK, 'crawl.json'), 'utf8'));
const contentDir = join(WORK, '../content');
const content = {};
for (const f of existsSync(contentDir) ? readdirSync(contentDir).filter((f) => f.endsWith('.json')) : []) Object.assign(content, JSON.parse(readFileSync(join(contentDir, f), 'utf8')));
const screenInfo = {};
for (const m of Object.values(content)) for (const [id, s] of Object.entries(m.screens ?? {})) screenInfo[id] = s;
const outFile = join(WORK, 'annotations.json');
const done = existsSync(outFile) && !redo ? JSON.parse(readFileSync(outFile, 'utf8')) : {};
const DPR = 1.5;

const labelsFor = (id) => {
    const info = screenInfo[id] ?? {};
    const ui = crawl[id]?.ui ?? {};
    const kind = crawl[id]?.kind;
    // On list pages the crawl's fields are the (closed) filter form: nothing to point at.
    const fields = kind === 'index' || kind === 'page' ? [] : (info.fields?.length ? info.fields.map((f) => f.label) : (ui.fields ?? []).map((f) => f.label));
    const els = (info.elements?.length ? info.elements.map((e) => e[0]) : [...(ui.tabs ?? []).slice(0, 3), ...(ui.headerActions ?? ui.toolbar ?? []).slice(0, 3), ...(ui.columns ?? []).slice(0, 2)]);
    return { fields: [...new Set(fields.filter(Boolean))], elements: [...new Set(els.filter(Boolean))] };
};
const todo = Object.values(crawl).filter((c) => c.ui && !c.ui.notAvailable && c.status === 200 && (!only || c.id.includes(only)) && (redo || !done[c.id]));
console.log(`${todo.length} screens to annotate (${Object.keys(screenInfo).length} with researched content)`);

// Same masking as the crawl (sensitive values never appear in a screenshot).
const MASK = () => {
    const rules = [
        [/\b[A-Z]{5}\d{4}[A-Z]\b/g, (m) => m.slice(0, 2) + 'XXXXXX' + m.slice(-2)],
        [/\b\d{4}[ -]?\d{4}[ -]?\d{4}\b/g, (m) => 'XXXX XXXX ' + m.replace(/\D/g, '').slice(-4)],
        [/\b\d{9,18}\b/g, (m) => 'X'.repeat(m.length - 4) + m.slice(-4)],
        [/(\+91[\s-]?)?\b[6-9]\d{4}[\s-]?\d{5}\b/g, () => '+91 XXXXX XXXXX'],
        [/[A-Za-z0-9._%+-]+@(?!demo\.local\b)(?!markedge)[A-Za-z0-9.-]+\.[a-z]{2,}/gi, () => 'personal.email@masked'],
    ];
    const fix = (s) => rules.reduce((t, [re, f]) => t.replace(re, f), s);
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) { const v = fix(n.nodeValue); if (v !== n.nodeValue) n.nodeValue = v; }
    for (const i of document.querySelectorAll('input:not([type=hidden]), textarea')) { if (i.value) { const v = fix(i.value); if (v !== i.value) i.value = v; } }
};

const b = await browser();
const ctx = await signedIn(b);
let n = 0;
const save = () => writeFileSync(outFile, JSON.stringify(done, null, 2));
const worker = async () => {
    while (todo.length) {
        const c = todo.shift();
        const page = await ctx.newPage();
        try {
            await page.goto(BASE + c.url, { waitUntil: 'networkidle', timeout: 60000 });
            await page.waitForTimeout(700);
            await page.evaluate(MASK);
            const found = await page.evaluate(FIND, labelsFor(c.id));
            const boxes = found.map((f, i) => ({ n: i + 1, label: f.label, kind: f.kind, x: Math.round(f.x * DPR), y: Math.round(f.y * DPR), w: Math.round(f.w * DPR), h: Math.round(f.h * DPR) }));
            await page.screenshot({ path: join(SHOTS, `${c.id}.png`), fullPage: true });
            done[c.id] = { boxes, at: new Date().toISOString(), wanted: labelsFor(c.id) };
        } catch (e) {
            done[c.id] = { boxes: [], error: String(e.message).slice(0, 200), at: new Date().toISOString() };
        } finally { await page.close(); }
        n += 1;
        if (n % 10 === 0) { save(); console.log(`${n} annotated (${todo.length} left)`); }
    }
};
await Promise.all([worker(), worker(), worker()]);
save();
await b.close();
const all = Object.values(done);
console.log(`annotated ${all.length}; boxes ${all.reduce((a, x) => a + x.boxes.length, 0)}; errors ${all.filter((x) => x.error).length}`);
