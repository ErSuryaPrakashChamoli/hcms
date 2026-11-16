// Writes tools/.work/assignment.json: for each course module, its screens (crawl ids, labels, URLs, source classes).
// Screen ids match capture/10-crawl.mjs. Run after discovery (and again after the crawl to pick up capture status).
'use strict';
const fs = require('fs');
const path = require('path');
const { MODULES, moduleOf, base } = require('./course');
const W = path.join(__dirname, '.work');
const disc = JSON.parse(fs.readFileSync(path.join(W, 'discovery.json'), 'utf8'));
const crawl = fs.existsSync(path.join(W, 'crawl.json')) ? JSON.parse(fs.readFileSync(path.join(W, 'crawl.json'), 'utf8')) : {};
const slug = (s) => String(s).toLowerCase().replace(/&/g, 'and').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
const out = Object.fromEntries(MODULES.map((m) => [m.code, { code: m.code, name: m.name, screens: [] }]));
const seen = new Set();
const resources = Object.fromEntries(disc.resources.map((r) => [r.class, r]));
const push = (code, s) => { if (!code || seen.has(s.url)) return; seen.add(s.url); out[code].screens.push({ ...s, captured: !!crawl[s.id]?.ui, status: crawl[s.id]?.status ?? null }); };
for (const sec of disc.sections) {
    if (sec.key === 'home') push('M01', { id: 'home/home', label: 'Home', url: sec.landing, kind: 'page', class: 'App\\Filament\\Pages\\Home', area: 'Home', path: 'Home' });
    for (const a of sec.areas) for (const m of a.modules) {
        const code = moduleOf(sec.key, m);
        const id = `${sec.key}/${slug(m.label)}`;
        const crumb = sec.key === 'work' || a.label === m.label ? `${sec.label} › ${m.label}` : `${sec.label} › ${a.label} › ${m.label}`;
        push(code, { id, label: m.label, url: m.url, kind: m.kind === 'resource' ? 'index' : 'page', class: m.class, area: a.label, group: m.group, path: crumb });
        const r = resources[m.class];
        if (r?.access) {
            if (r.pages.create?.url && r.can_create !== false) push(code, { id: `${id}--create`, label: `${m.label}: new`, url: r.pages.create.url, kind: 'create', class: m.class, area: a.label, path: crumb });
            if (r.pages.view?.sample) push(code, { id: `${id}--view`, label: `${m.label}: view a record`, url: r.pages.view.sample, kind: 'view', class: m.class, area: a.label, path: crumb });
            if (r.pages.edit?.sample) push(code, { id: `${id}--edit`, label: `${m.label}: edit a record`, url: r.pages.edit.sample, kind: 'edit', class: m.class, area: a.label, path: crumb });
        }
    }
}
fs.writeFileSync(path.join(W, 'assignment.json'), JSON.stringify(out, null, 2));
for (const m of Object.values(out)) console.log(`${m.code} ${m.name}: ${m.screens.length} screens (${m.screens.filter((s) => s.kind === 'index' || s.kind === 'page').length} modules), captured ${m.screens.filter((s) => s.captured).length}`);
const unassigned = disc.sections.flatMap((s) => s.areas.flatMap((a) => a.modules.filter((m) => !moduleOf(s.key, m)).map((m) => `${s.key}/${m.group}/${base(m.class)}`)));
console.log('unassigned:', unassigned.length ? unassigned.join(', ') : 'none');
