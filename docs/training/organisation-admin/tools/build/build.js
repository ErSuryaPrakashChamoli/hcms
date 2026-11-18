// Builds PeopleOS-Organisation-Administrator-Complete-Training.pptx from:
//   tools/course.js                 groups, modules, colours, screen → module rules
//   tools/.work/assignment.json     screens per module (assign.js)
//   tools/.work/crawl.json          the interface as the organisation administrator sees it (capture/10-crawl.mjs)
//   tools/.work/annotations.json    element boxes for numbered callouts (capture/20-annotate.mjs), optional
//   tools/content/*.json            researched content per module (see AGENT-BRIEF.md), plus content/course.json
//   screenshots/<id>.png            genuine screens (masked), the flows in screenshots/flows/
// Uses the navigable design system of docs/presentations/peopleos/tools/build (design.js, modular.js, lib.js) and
// validates every internal link afterwards (check-nav.js). node build/build.js [--only M13,M14]
'use strict';
const fs = require('fs');
const path = require('path');
const P = path.resolve(__dirname, '../../../../presentations/peopleos/tools/build');
const lib = require(path.join(P, 'lib'));
const { ModularDeck, tw } = require(path.join(P, 'modular'));
const D = require(path.join(P, 'design'));
const { GROUPS, MODULES } = require('../course');
const sharp = require(require.resolve('sharp', { paths: [P] }));

const TOOLS = path.resolve(__dirname, '..');
const PKG = path.resolve(TOOLS, '..');
const WORK = path.join(TOOLS, '.work');
const SHOTS = path.join(PKG, 'screenshots');
const { BRAND, CHROME: K } = D;
const { W, MX } = K;
const readJson = (f, d = null) => (fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : d);
process.env.PEOPLEOS_DECK_OUT = path.resolve(__dirname, '../..');   // the deck is written to docs/training/organisation-admin/
const args = process.argv.slice(2);
const only = args.includes('--only') ? args[args.indexOf('--only') + 1].split(',') : null;

// ---------------------------------------------------------------- inputs
const crawl = readJson(path.join(WORK, 'crawl.json'), {});
const assignment = readJson(path.join(WORK, 'assignment.json'), {});
const notes = readJson(path.join(WORK, 'annotations.json'), {});
const flows = readJson(path.join(WORK, 'flows.json'), {});
const placement = readJson(path.join(TOOLS, 'flows-placement.json'), { flows: [], warnings_first: [] });
const content = {};
for (const f of fs.existsSync(path.join(TOOLS, 'content')) ? fs.readdirSync(path.join(TOOLS, 'content')).filter((f) => f.endsWith('.json')).sort() : []) {
    Object.assign(content, readJson(path.join(TOOLS, 'content', f)));
}

// Screenshot entries for lib.image(): the callouts are the annotated element boxes (image pixels).
const entries = {};
for (const [id, c] of Object.entries(crawl)) {
    // Screens that did not open for the administrator (refused, errors) are not shown; the gap register lists them.
    if (!c.shot || c.ui?.notAvailable || (c.status ?? 200) >= 400 || !fs.existsSync(path.join(SHOTS, `${id}.png`))) continue;
    entries[id] = { name: id, file: `screenshots/${id}.png`, screen: c.ui?.h1 || c.label, user: 'Demo Admin', role: 'organisation administrator',
        status: 'VERIFIED IN RUNNING APPLICATION', callouts: notes[id]?.boxes ?? [], captured_at: notes[id]?.at ?? new Date().toISOString(), expected: c.ui?.subheading ?? '' };
}
for (const [id, f] of Object.entries(flows)) {
    if (fs.existsSync(path.join(SHOTS, `${id}.png`))) entries[id] = { name: id, file: `screenshots/${id}.png`, screen: f.screen, user: 'Demo Admin', role: 'organisation administrator', status: 'VERIFIED IN RUNNING APPLICATION', callouts: f.boxes ?? [], captured_at: f.at, expected: '' };
}
lib.useScreens(SHOTS, entries, path.join(WORK, 'derived'), { maxW: 1600, quality: 84 });

const clean = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
const short = (s, n) => { s = clean(s); return s.length > n ? s.slice(0, n - 1).replace(/\s+\S*$/, '') + '…' : s; };
/** A title that fits: drop a trailing bracketed remark or ': …' clause before ever cutting words. */
const fitTitle = (t, n) => {
    t = clean(t);
    if (t.length <= n) return t;
    const noParen = t.replace(/\s*\([^)]*\)\s*$/, '');
    const noClause = t.replace(/\s*[:—]\s+[^:—]*$/, '');
    for (const c of [noParen, noClause, noClause.replace(/\s*\([^)]*\)\s*$/, '')]) if (c.length >= 18 && c.length <= n) return c;
    return short(t, n);
};

// ---------------------------------------------------------------- course model (content + fallbacks from the crawl)
function moduleModel(m) {
    const c = content[m.code] ?? {};
    const screens = (assignment[m.code]?.screens ?? []).filter((s) => entries[s.id]);
    const ids = new Set(screens.map((s) => s.id));
    let subs = (c.subs ?? []).map((x) => ({ ...x, screens: (x.screens ?? []).filter((id) => ids.has(id)) }));
    const placed = new Set(subs.flatMap((x) => x.screens));
    const rest = screens.filter((s) => !placed.has(s.id));
    if (!subs.length && rest.length) {
        // No researched content yet: one submodule per application area.
        const areas = [...new Set(rest.map((s) => s.area))];
        subs = areas.map((a, i) => ({ id: `${m.num}.${i + 1}`, name: a, desc: `The ${a} screens.`, screens: rest.filter((s) => s.area === a).map((s) => s.id) }));
    } else if (rest.length) {
        subs.push({ id: `${m.num}.${subs.length + 1}`, name: 'More screens', desc: 'Other screens of this module.', screens: rest.map((s) => s.id) });
    }
    if ((c.troubleshooting ?? []).length) subs.push({ id: `${m.num}.${subs.length + 1}`, name: 'Troubleshooting', desc: 'Problems you may meet in this module, and what to do.', screens: [], troubleshooting: true });
    if (!subs.length) subs.push({ id: `${m.num}.1`, name: m.name, desc: c.intro ? short(c.intro, 100) : m.name, screens: [] });
    const related = [...new Set((c.depends_on ?? []).map((d) => (/\b(M\d\d)\b/.exec(d) ?? [])[1]).filter((x) => x && x !== m.code && MODULES.some((y) => y.code === x)))].slice(0, 4);
    return {
        ...m, c, subs, screensById: Object.fromEntries(screens.map((s) => [s.id, s])), related,
        desc: m.desc ?? short(c.intro ?? m.name, 56),
        intro: short(c.intro ?? `${m.name} in PeopleOS.`, 320),
        who: short(c.who ?? 'The organisation administrator.', 230),
        where: short(c.where ?? '', 230) || 'See the module contents.',
        objectives: (c.objectives ?? [`Find your way around ${m.name.toLowerCase()}`]).slice(0, 3).map((o) => short(o, 140)),
    };
}

// ---------------------------------------------------------------- the deck class: extra layouts for application screens
class CourseDeck extends ModularDeck {
    constructor(meta, course) { super(meta, course); this.fig = {}; }

    figure(code) { this.fig[code] = (this.fig[code] ?? 0) + 1; return `${Number(code.slice(1))}.${this.fig[code]}`; }

    breadcrumb(s, crumbs, y, t) {
        let x = MX;
        const parts = crumbs.split(' › ').map(clean).filter(Boolean);
        parts.forEach((c, i) => {
            const last = i === parts.length - 1, w = tw(c, 10.5, last) + 0.26;
            s.addText(c, { x, y, w, h: 0.3, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.06, fill: { color: last ? t.hex : BRAND.surfaceMuted }, line: { color: last ? t.hex : BRAND.border, width: 0.75 }, color: last ? 'FFFFFF' : BRAND.textSecondary, fontSize: 10.5, bold: last, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: `Breadcrumb ${i + 1}` });
            x += w + (last ? 0 : 0.2);
            if (!last) s.addText('›', { x: x - 0.19, y, w: 0.18, h: 0.3, fontSize: 12, color: BRAND.textMuted, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
        });
        return x;
    }

    /** One application screen: breadcrumb, the real screen with numbered callouts, and what is on it. */
    screen({ id, title, crumbs, info, items, crop, maxAspect, part, figure }) {
        this.queue.push(async () => {
            const e = lib.entry(id), t = this.tones;
            const s = this.slide('content', this.screenNotes(title, crumbs, info, items));
            this.head(s, title, '');
            this.breadcrumb(s, crumbs, 1.5, t);
            const box = { x: MX, y: 1.95, w: 7.75, h: 4.55 };
            const wanted = items.filter((x) => x.n != null).map((x) => String(x.n));
            const renumber = Object.fromEntries(items.filter((x) => x.n != null).map((x, i) => [String(x.n), x.badge]));
            const im = await this.image(s, id, box, { crop, callouts: wanted, renumber, valign: 'top', maxAspect: maxAspect ?? 0.62 });
            s.addText(`Figure ${figure}  ·  ${title}${part ? ` (${part})` : ''}  ·  real screen, example organisation`, { x: MX, y: Math.min(6.62, im.y + im.h + 0.06), w: 7.75, h: 0.24, fontSize: 9, color: BRAND.textMuted, margin: 0, isTextBox: true, objectName: 'Figure caption' });
            // Right panel
            const px = 8.6, pw = W - MX - px;
            let y = 1.95;
            if (info.purpose) {
                const h = (lib.estLines(info.purpose, pw, 12.5) * 12.5 * 1.22) / 72 + 0.08;
                s.addText(info.purpose, { x: px, y, w: pw, h, fontSize: 12.5, color: BRAND.text, valign: 'top', margin: 0, isTextBox: true, objectName: 'Purpose' });
                y += h + 0.12;
            }
            if (items.length) {
                s.addText(info.fields ? 'FIELDS' : 'ON THIS SCREEN', { x: px, y, w: pw, h: 0.24, fontSize: 10, bold: true, color: t.hex, charSpacing: 1.5, margin: 0, isTextBox: true });
                y += 0.3;
                let fsz = 11.5;
                const room = 6.45 - y - (info.footer ? 0.42 : 0);
                const hOf = (x, f) => Math.max(0.3, (lib.estLines(`${x.name}: ${x.text}`, pw - 0.42, f) * f * 1.2) / 72 + 0.06);
                while (fsz > 10 && items.reduce((a, x) => a + hOf(x, fsz), 0) > room) fsz -= 0.5;
                for (const x of items) {
                    const h = hOf(x, fsz);
                    if (im.drawn.includes(String(x.badge))) this.badge(s, x.badge, px, y, 0.27);
                    else s.addShape(this.pres.shapes.OVAL, { x: px + 0.09, y: y + 0.09, w: 0.1, h: 0.1, fill: { color: BRAND.textMuted }, line: { color: BRAND.textMuted, width: 0 } });
                    s.addText([{ text: x.name, options: { bold: true, color: BRAND.text } }, ...(x.rule ? [{ text: `  ${x.rule}`, options: { color: x.rule === 'Required' ? BRAND.danger : BRAND.textMuted, fontSize: fsz - 1.5, bold: true } }] : []), { text: x.text ? ` — ${x.text}` : '', options: { color: BRAND.textSecondary } }],
                        { x: px + 0.38, y: y - 0.02, w: pw - 0.38, h, fontSize: fsz, valign: 'top', margin: 0, isTextBox: true });
                    y += h + 0.04;
                }
                if (y > 6.5 - (info.footer ? 0.42 : 0)) lib.WARN.push(`${this.meta.num}#${this.n}: screen items overflow (${title})`);
            }
            if (info.limit) {
                const h = Math.max(0.5, (lib.estLines('NOTE ' + info.limit, pw - 0.24, 10.5) * 10.5 * 1.2) / 72 + 0.16);
                const ly = Math.min(Math.max(y + 0.05, 1.95), 6.5 - (info.footer ? 0.42 : 0) - h);
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: px, y: ly, w: pw, h, rectRadius: 0.06, fill: { color: BRAND.warningSoft }, line: { color: BRAND.warningSoft, width: 0 }, objectName: 'Limitation' });
                s.addText([{ text: 'NOTE  ', options: { bold: true, color: BRAND.warning, fontSize: 9.5, charSpacing: 1 } }, { text: info.limit, options: { color: BRAND.text, fontSize: 10.5 } }], { x: px + 0.12, y: ly + 0.07, w: pw - 0.24, h: h - 0.14, valign: 'top', margin: 0, isTextBox: true });
            }
            if (info.footer) s.addText(info.footer, { x: px, y: 6.52, w: pw, h: 0.42, fontSize: 9.5, color: BRAND.textMuted, valign: 'bottom', margin: 0, isTextBox: true, objectName: 'Permissions' });
        });
    }

    screenNotes(title, crumbs, info, items) {
        return [`SCREEN: ${title}. WHERE: ${crumbs}.`, info.purpose ? `SAY: ${info.purpose}` : '', info.when ? `WHEN: ${info.when}` : '',
            items.length ? `POINT OUT:\n${items.map((x, i) => `${x.badge ?? '•'} ${x.name}${x.rule ? ` (${x.rule})` : ''}: ${x.text}`).join('\n')}` : '',
            info.after ? `AFTERWARDS: ${info.after}` : '', info.depends ? `FIRST: ${info.depends}` : '', info.limit ? `NOTE: ${info.limit}` : '',
            'Real screen of PeopleOS, signed in as the organisation administrator of the fictional example organisation (Demo Group). Personal identifiers are masked.'].filter(Boolean).join('\n');
    }
}

// ---------------------------------------------------------------- right-panel pagination
const PW = W - MX - 8.6;
const hOfItem = (x) => Math.max(0.3, (lib.estLines(`${x.name}${x.rule ? '  ' + x.rule : ''}: ${x.text}`, PW - 0.5, 11) * 11 * 1.22) / 72 + 0.08) + 0.04;
/** Split screen items into pages that fit beside the screenshot (the first page also carries the purpose and note). */
function pages(items, base) {
    const purposeH = base.purpose ? (lib.estLines(base.purpose, PW - 0.1, 12.5) * 12.5 * 1.25) / 72 + 0.25 : 0;
    const noteH = base.limit ? Math.max(0.5, (lib.estLines('NOTE ' + base.limit, PW - 0.24, 10.5) * 10.5 * 1.2) / 72 + 0.16) + 0.05 : 0;
    const footH = base.footer ? 0.42 : 0;
    const out = [[]];
    let room = 6.45 - 1.95 - purposeH - noteH - footH - 0.3;
    let used = 0;
    for (const x of items) {
        const h = hOfItem(x);
        if (used + h > room && out[out.length - 1].length) { out.push([]); used = 0; room = 6.45 - 1.95 - footH - 0.3; }
        out[out.length - 1].push(x); used += h;
    }
    return out;
}
/** Number the boxed items of one page 1..k (their callouts on the screenshot carry the same numbers). */
const numbered = (list) => { let k = 0; return list.map((x) => ({ ...x, badge: x.n != null ? ++k : null })); };

// ---------------------------------------------------------------- screen slides for one screen id
async function screenSlides(d, mod, id) {
    const sc = mod.screensById[id];
    const c = crawl[id];
    const info = mod.c.screens?.[id] ?? {};
    const ui = c?.ui ?? {};
    const title = fitTitle(clean(info.title ?? ui.h1 ?? sc.label) || sc.label, 70);
    const kindLabel = { create: 'New record', edit: 'Edit a record', view: 'A record' }[sc.kind];
    const crumbs = sc.path + (kindLabel ? ` › ${kindLabel}` : '');
    const box = notes[id]?.boxes ?? [];
    const boxOf = (label) => box.find((b) => clean(b.label).toLowerCase() === clean(label).toLowerCase());
    const footer = [info.permission ? `Access: ${short(info.permission, 120)}` : '', info.depends ? `First: ${short(info.depends, 110)}` : ''].filter(Boolean).join('   ·   ');
    const base = { purpose: short(info.purpose ?? ui.subheading ?? '', 230), when: info.when, after: info.after, depends: info.depends, limit: info.limits ? short(info.limits, 200) : null, footer };

    const isForm = (sc.kind === 'create' || sc.kind === 'edit') && (info.fields?.length || ui.fields?.length);
    if (isForm) {
        const fields = (info.fields?.length ? info.fields : ui.fields.map((f) => ({ label: f.label, meaning: '', rule: f.required ? 'Required' : null })))
            .map((f) => ({ name: clean(f.label), text: short(f.meaning, 120), rule: f.rule && f.rule !== 'null' ? f.rule : null, b: boxOf(f.label) }));
        // Split a tall form into slices of the screenshot; each slice lists the fields inside it.
        const meta = await sharp(path.join(SHOTS, `${id}.png`)).metadata();
        const sliceH = Math.round(meta.width * 0.6), top = 0;
        const withBox = fields.filter((f) => f.b);
        const slices = [];
        if (withBox.length) {
            const maxY = Math.max(...withBox.map((f) => f.b.y + f.b.h));
            for (let y0 = top; y0 < maxY; y0 += sliceH - 60) {
                const inSlice = withBox.filter((f) => f.b.y >= y0 && f.b.y + f.b.h <= y0 + sliceH && !slices.some((sl) => sl.fields.includes(f)));
                if (inSlice.length) slices.push({ crop: { x: 0, y: y0, w: meta.width, h: Math.min(sliceH, meta.height - y0) }, fields: inSlice });
            }
        }
        const unboxed = fields.filter((f) => !f.b);
        if (!slices.length) slices.push({ crop: null, fields: [] });
        // Fields without a box go with the last slice (or get their own list).
        const groups = slices.map((sl) => ({ ...sl, list: sl.fields }));
        if (unboxed.length) groups[groups.length - 1].list = [...groups[groups.length - 1].list, ...unboxed];
        const all = [];
        for (const g of groups) {
            const list = g.list.map((f) => ({ name: f.name, text: f.text, rule: f.rule, n: f.b ? f.b.n : null }));
            for (const pg of pages(list, all.length ? { ...base, purpose: '', limit: null } : base)) all.push({ crop: g.crop, list: pg });
        }
        const fig = d.figure(mod.code);
        all.forEach((pg, pi) => {
            d.screen({ id, title, crumbs, info: { ...base, fields: true, purpose: pi === 0 ? base.purpose : '', limit: pi === 0 ? base.limit : null }, items: numbered(pg.list), crop: pg.crop, maxAspect: 0.62, part: all.length > 1 ? `${pi + 1} of ${all.length}` : null, figure: fig });
        });
        return;
    }
    // Index, page and view screens: the elements the researcher explained, matched to boxes where found.
    const els = (info.elements?.length ? info.elements : [
        ...(ui.tabs ?? []).slice(0, 3).map((x) => [x, 'Tab']), ...(ui.headerActions ?? ui.toolbar ?? []).slice(0, 3).map((x) => [x, 'Action']),
        ...(ui.columns ?? []).slice(0, 2).map((x) => [x, 'Column']),
    ]).slice(0, 8);
    const items = els.map(([name, text]) => { const b = boxOf(name); return { name: clean(name), text: short(text, 120), n: b ? b.n : null }; });
    const pp = pages(items, base);
    const fig = d.figure(mod.code);
    pp.forEach((list, pi) => d.screen({ id, title, crumbs, info: pi === 0 ? base : { ...base, purpose: '', limit: null }, items: numbered(list), part: pp.length > 1 ? `${pi + 1} of ${pp.length}` : null, figure: fig }));
}

// ---------------------------------------------------------------- build
(async () => {
    const models = MODULES.map(moduleModel);
    const course = {
        groups: GROUPS,
        modules: models.map((m) => ({ code: m.code, num: m.num, group: m.group, color: m.color, icon: m.icon, name: m.name, desc: m.desc, intro: m.intro, who: m.who, where: m.where, objectives: m.objectives, subs: m.subs.map((x) => ({ id: x.id, name: x.name, desc: short(x.desc, 100) })), related: m.related })),
    };
    const meta = {
        num: 'OA', file: 'PeopleOS-Organisation-Administrator-Complete-Training.pptx', short: 'Organisation administrator training',
        title: 'Organisation Administrator Training',
        subtitle: 'Everything an organisation administrator needs to run PeopleOS for their company: from the first sign-in to payroll, reports and signing out.',
        audience: 'The organisation administrator of a company that uses PeopleOS, and the HR and payroll leads they work with',
        navMapDir: path.join(TOOLS, 'nav-map'),
        dedupeMedia: true,
    };
    const d = new CourseDeck(meta, course);
    d.cover({ shot: 'home/home', kicker: 'PEOPLEOS HCM  ·  ORGANISATION ADMINISTRATOR TRAINING', date: 'Every screen in this course is a real PeopleOS screen of a fictional example organisation (Demo Group). Personal identifiers are masked.' });
    d.toc({ title: 'Contents', groups: ['A', 'B', 'C', 'D', 'E'], key: 'toc', more: { label: 'More modules', key: 'toc2' } });
    d.toc({ title: 'Contents (continued)', groups: ['F', 'G', 'H', 'I'], key: 'toc2', back: { label: 'First page', key: 'toc' } });
    for (const m of models) {
        if (only && !only.includes(m.code)) { /* still a full module shell so links resolve */ }
        d.mod(m.code);
        const c = m.c;
        for (const [si, sub] of m.subs.entries()) {
            d.sub(sub.id);
            if (m.code === 'M01' && si === 0) d.howto();
            const did = { slides: 0 };
            for (const p of (c.procedures ?? []).filter((p) => p.sub === sub.id)) {
                const steps = (p.steps ?? []).map(([h, b]) => [short(h, 70), short(b, 190)]);
                const per = steps.length <= 7 ? 7 : 6;
                for (let i = 0; i < steps.length; i += per) {
                    d.procedure({ title: fitTitle(p.title, steps.length > per ? 66 : 76) + (steps.length > per ? ` (${i / per + 1} of ${Math.ceil(steps.length / per)})` : ''), steps: steps.slice(i, i + per),
                        side: i === 0 ? [['Before you start', short(p.before ?? 'Nothing else is needed.', 170)], ['Result', short(p.result ?? '', 190)]].filter((x) => x[1]) : null });
                    did.slides++;
                }
            }
            if (!only || only.includes(m.code)) for (const id of sub.screens) { await screenSlides(d, m, id); did.slides++; }
            if (m.code === 'M01' && sub.id === '1.4' && (c.navigation_map ?? []).length) {
                d.table({ title: 'Navigation map: everything in the rail', columns: ['Section', 'Areas (pages)', 'Opens', 'What you find'], colW: [1.7, 4.6, 2.2, 3.63],
                    rows: c.navigation_map.map((r) => [short(r[0], 30), short(r[1], 150), short(r[2], 40), short(r[3], 110)]), rowsPerSlide: 7, fontSize: 10.5 });
                did.slides++;
            }
            if (m.code === 'M22' && sub.id === '22.1' && (c.report_catalogue ?? []).length) {
                d.table({ title: 'Report catalogue', intro: `All ${c.report_catalogue.length} reports of the Report library, by area. Every report exports to CSV.`,
                    columns: ['Area', 'Report', 'What it shows', 'Filters'], colW: [1.6, 2.7, 5.73, 2.1],
                    rows: c.report_catalogue.map((r) => [short(r[0], 26), short(r[1], 48), short(r[2], 150), short(r[3], 50)]), rowsPerSlide: 7, fontSize: 10 });
                did.slides++;
            }
            for (const w of placement.warnings_first.filter((x) => x.module === m.code && x.sub === sub.id)) { d.warning({ title: fitTitle(w.title, 76), level: w.level ?? 'important', headline: short(w.headline, 150), body: w.body ? short(w.body, 300) : null, points: (w.points ?? []).map((p) => short(p, 200)).slice(0, 4), todo: w.todo ? short(w.todo, 220) : null }); did.slides++; }
            if (!only || only.includes(m.code)) for (const f of placement.flows.filter((x) => x.module === m.code && x.sub === sub.id && entries[x.key])) {
                const fl = flows[f.key];
                const items = (fl.boxes ?? []).map((b, i) => ({ name: clean(b.label).replace(/[*✱]$/, ''), text: '', n: b.n, badge: i + 1 }));
                d.screen({ id: f.key, title: fitTitle(fl.screen, 70), crumbs: fl.path ?? m.name, info: { purpose: short(f.caption, 230) }, items, maxAspect: 0.62, figure: d.figure(m.code) });
                did.slides++;
            }
            for (const k of (c.calculations ?? []).filter((x) => x.sub === sub.id)) { d.calculation({ title: fitTitle(k.title, 76), intro: short(k.intro, 250), lines: k.lines, total: k.total, explain: (k.explain ?? []).slice(0, 4).map(([h, b]) => [h, short(b, 190)]), source: short(k.source, 160) }); did.slides++; }
            for (const w of (c.warnings ?? []).filter((x) => x.sub === sub.id)) { d.warning({ title: fitTitle(w.title, 76), level: w.level ?? 'warning', headline: short(w.headline, 150), body: w.body ? short(w.body, 260) : null, points: (w.points ?? []).map((p) => short(p, 200)).slice(0, 4), todo: w.todo ? short(w.todo, 220) : null }); did.slides++; }
            if (c.troubleshoot_by_sub?.[sub.id]) { d.troubleshoot({ title: sub.name, rows: c.troubleshoot_by_sub[sub.id].map((r) => [short(r[0], 150), short(r[1], 160), short(r[2], 200), r[3] ?? '']) }); did.slides++; }
            if (sub.troubleshooting) { d.troubleshoot({ title: 'Problems and fixes', rows: c.troubleshooting.map((r) => [short(r[0], 150), short(r[1], 160), short(r[2], 200), r[3] ?? '']) }); did.slides++; }
            if (!did.slides) d.cards({ title: sub.name, cards: [{ head: sub.name, body: short(sub.desc, 200), tone: 'info' }] });
        }
        if ((c.checklist ?? []).length) d.checklist({ items: c.checklist.slice(0, 12).map(([h, x]) => [short(h, 70), short(x ?? '', 90)]) });
        const sp = (c.summary ?? [`Use ${m.name.toLowerCase()} from the menus shown in this module.`]).map((p) => short(p, 140));
        d.summary({ points: sp.join('').length > 620 ? sp.slice(0, 5) : sp.slice(0, 6), related: m.related });
    }
    const r = await d.save();
    console.log(`${meta.file}: ${r.slides} slides, ${r.shots.length} screenshots${lib.WARN.length ? `, ${lib.WARN.length} warnings` : ''}`);
    for (const w of lib.WARN.slice(0, 40)) console.log('   ! ' + w);
    fs.writeFileSync(path.join(WORK, 'build-warnings.json'), JSON.stringify(lib.WARN, null, 2));
    const res = require('child_process').spawnSync(process.execPath, [path.join(P, 'check-nav.js'), 'OA'], { stdio: 'inherit', env: { ...process.env, NAV_MAP_DIR: meta.navMapDir, PEOPLEOS_DECK_OUT: PKG } });
    process.exit(res.status ?? 1);
})().catch((e) => { console.error(e); process.exit(1); });
