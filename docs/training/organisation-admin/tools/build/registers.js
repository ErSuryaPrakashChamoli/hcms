// Writes the five registers of the training package (docs/training/organisation-admin/0N-*.md) from the evidence:
// discovery (what the application grants each role), crawl (what each screen shows), annotations (callouts),
// content (researched explanations) and the built deck (nav-map). node build/registers.js
'use strict';
const fs = require('fs');
const path = require('path');
const { GROUPS, MODULES } = require('../course');
const TOOLS = path.resolve(__dirname, '..');
const PKG = path.resolve(TOOLS, '..');
const W = path.join(TOOLS, '.work');
const j = (f, d = null) => (fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : d);
const disc = j(path.join(W, 'discovery.json'));
const crawl = j(path.join(W, 'crawl.json'), {});
const assignment = j(path.join(W, 'assignment.json'), {});
const notes = j(path.join(W, 'annotations.json'), {});
const flows = j(path.join(W, 'flows.json'), {});
const nav = j(path.join(TOOLS, 'nav-map', 'OA.json'));
const navCheck = j(path.join(TOOLS, 'nav-map', 'nav-check-OA.json'));
const warnings = j(path.join(W, 'build-warnings.json'), []);
const content = {};
for (const f of fs.existsSync(path.join(TOOLS, 'content')) ? fs.readdirSync(path.join(TOOLS, 'content')).filter((f) => f.endsWith('.json')) : []) Object.assign(content, j(path.join(TOOLS, 'content', f)));
const esc = (s) => String(s ?? '').replace(/\|/g, '\\|').replace(/\s+/g, ' ').trim();
const list = (a, n = 12) => esc((a ?? []).filter(Boolean).slice(0, n).join(', ') + ((a ?? []).length > n ? ` (+${a.length - n})` : ''));
const today = new Date().toISOString().slice(0, 10);
const head = (title, purpose) => `# ${title}\n\n**Generated:** ${today} by \`tools/build/registers.js\`.\n**For:** the people who maintain and review the PeopleOS Organisation Administrator training. ${purpose}\n\n`;
const statusOf = (c) => (!c ? 'Not captured' : c.error ? 'Error while opening' : c.ui?.notAvailable ? 'Restricted' : c.status === 200 ? 'Working' : `HTTP ${c.status}`);

// ---------------------------------------------------------------- 01 inventory
let inv = head('PeopleOS Organisation Administrator: complete application inventory',
    'Every screen the organisation administrator (role Tenant Super Admin of the example organisation) can open, as the application itself grants it and as it renders.');
inv += `Source of truth: the application's own navigation and access checks for the signed-in administrator (\`tools/discover.php\`) and the rendered interface of every screen (\`tools/capture/10-crawl.mjs\`). Screens that exist only for the service provider's platform operators are not shown to this role and are not listed.\n\n`;
const totalScreens = Object.values(assignment).reduce((a, m) => a + m.screens.length, 0);
inv += `- Rail sections: ${disc.sections.length}; modules in the navigation: ${disc.catalogue.length}; screens inventoried (lists, pages, new / view / edit forms): ${totalScreens}.\n- Resources the role can open: ${disc.resources.filter((r) => r.access).length} of ${disc.resources.length}; custom pages: ${disc.pages.filter((p) => p.access).length} of ${disc.pages.length}.\n\n`;
for (const m of MODULES) {
    const a = assignment[m.code];
    if (!a || !a.screens.length) continue;
    const c = content[m.code] ?? {};
    inv += `## ${m.code} ${m.name}\n\n| Screen | Navigation path | Kind | Purpose | Tabs | Sections | Fields | Actions | Filters | Columns | Permissions | Dependencies | Output | Status |\n|---|---|---|---|---|---|---|---|---|---|---|---|---|---|\n`;
    for (const s of a.screens) {
        const cr = crawl[s.id];
        const ui = cr?.ui ?? {};
        const info = c.screens?.[s.id] ?? {};
        const actions = [...(ui.headerActions ?? []), ...(ui.toolbar ?? []), ...(ui.rowActions ?? []).map((x) => `${x} (row)`)];
        const fields = (ui.fields ?? []).map((f) => f.label + (f.required ? ' *' : ''));
        inv += `| ${esc(ui.h1 || s.label)} | ${esc(s.path)} | ${s.kind} | ${esc(info.purpose ?? ui.subheading ?? '')} | ${list(ui.tabs)} | ${list(ui.sections)} | ${list(fields, 20)} | ${list(actions)} | ${list(ui.filters)} | ${list(ui.columns)} | ${esc(info.permission ?? '')} | ${esc(info.depends ?? '')} | ${esc(info.after ?? '')} | ${statusOf(cr)} |\n`;
    }
    inv += '\n';
}
fs.writeFileSync(path.join(PKG, '01-complete-application-inventory.md'), inv);

// ---------------------------------------------------------------- 02 navigation and permission matrix
const roles = [['admin@demo.local', 'Tenant Super Admin (organisation administrator)', 'discovery.json'], ['kavya.menon@demo.local', 'Tenant HR Admin', 'discovery-kavya_menon_demo_local.json'], ['neha.kapoor@demo.local', 'HR Manager', 'discovery-neha_kapoor_demo_local.json'],
    ['arjun.bose@demo.local', 'Payroll Admin', 'discovery-arjun_bose_demo_local.json'], ['amit.verma@demo.local', 'Manager', 'discovery-amit_verma_demo_local.json'], ['priya.nair@demo.local', 'Employee', 'discovery-priya_nair_demo_local.json']]
    .map(([u, r, f]) => ({ user: u, role: r, d: j(path.join(W, f)) })).filter((x) => x.d);
let nm = head('PeopleOS Organisation Administrator: navigation and permission matrix', 'The navigation the organisation administrator sees, and which other roles of the example organisation can open the same modules.');
nm += '## 1. Navigation map (organisation administrator)\n\n';
for (const s of disc.sections) {
    nm += `### ${s.label}${s.landing ? ` (opens ${s.landing})` : ''}\n\n`;
    for (const a of s.areas) nm += `- **${a.label}**: ${a.modules.map((m) => `${m.label} \`${m.url}\``).join(' · ')}\n`;
    nm += '\n';
}
nm += `## 2. Who can open each module\n\nYes = the application lets that role open the module (its own access check, evaluated for a real account of the example organisation). The Tenant HR Admin, HR Manager, Payroll Admin, Manager and Employee accounts are also employees, so they additionally see self-service pages (not listed).\n\n| Module (course) | Navigation module | ${roles.map((r) => r.role).join(' | ')} |\n|---|---|${roles.map(() => '---').join('|')}|\n`;
const opens = roles.map((r) => new Set(r.d.catalogue.map((m) => m.class)));
for (const m of MODULES) for (const s of (assignment[m.code]?.screens ?? []).filter((s) => s.kind === 'index' || s.kind === 'page')) {
    nm += `| ${m.code} | ${esc(s.path)} | ${opens.map((o) => (o.has(s.class) || s.class.endsWith('\\Home') ? 'Yes' : '—')).join(' | ')} |\n`;
}
nm += `\n## 3. Role templates\n\nRoles and their permission keys are defined in \`config/peopleos.php\` (\`roles\`). The organisation administrator holds **Tenant Super Admin** (all permissions of the organisation, ${disc.permissions?.length ?? '?'} keys). Platform roles of the service provider are not organisation roles and are not listed.\n`;
fs.writeFileSync(path.join(PKG, '02-navigation-and-permission-matrix.md'), nm);

// ---------------------------------------------------------------- 03 coverage and gap register
let cg = head('PeopleOS Organisation Administrator: module coverage and gap register', 'How much of each module the training covers, and what could not be covered or confirmed.');
cg += '| Module | Screens in the application | Screens with a screenshot | Screens explained | Callouts placed | Procedures | Calculations | Warnings | Troubleshooting rows | Status |\n|---|---:|---:|---:|---:|---:|---:|---:|---:|---|\n';
const gaps = [];
for (const m of MODULES) {
    const a = assignment[m.code]?.screens ?? [];
    const c = content[m.code] ?? {};
    const shot = a.filter((s) => crawl[s.id]?.shot && !crawl[s.id]?.ui?.notAvailable).length;
    const explained = a.filter((s) => c.screens?.[s.id]?.purpose).length;
    const boxes = a.reduce((x, s) => x + (notes[s.id]?.boxes?.length ?? 0), 0);
    const status = !a.length ? (c.intro ? 'Process module (no screens of its own)' : 'Not started') : explained === a.length && shot === a.length ? 'Covered' : explained || shot ? `Partly covered (${explained}/${a.length} explained)` : 'Not covered';
    cg += `| ${m.code} ${m.name} | ${a.length} | ${shot} | ${explained} | ${boxes} | ${(c.procedures ?? []).length} | ${(c.calculations ?? []).length} | ${(c.warnings ?? []).length} | ${(c.troubleshooting ?? []).length} | ${status} |\n`;
    for (const g of c.gaps ?? []) gaps.push([m.code, g]);
    for (const s of a) if (!crawl[s.id]?.shot) gaps.push([m.code, `No screenshot: ${s.path} (${s.kind})`]);
    for (const s of a) if (crawl[s.id]?.ui?.notAvailable || (crawl[s.id]?.status ?? 200) >= 400) gaps.push([m.code, `Screen did not open for the administrator: ${s.path} (${s.kind}, HTTP ${crawl[s.id]?.status})`]);
}
cg += `\n## Gaps, limitations and unverified items\n\n${gaps.length ? gaps.map(([m, g]) => `- **${m}** ${esc(g)}`).join('\n') : 'None recorded.'}\n`;
fs.writeFileSync(path.join(PKG, '03-module-coverage-and-gap-register.md'), cg);

// ---------------------------------------------------------------- 04 screenshot register
let sr = head('PeopleOS Organisation Administrator: screenshot register', 'Every screenshot in the package: what it shows, where it was taken, and its callouts.');
sr += 'All screenshots are genuine captures of PeopleOS, signed in as the organisation administrator of the fictional example organisation (Demo Group) on a disposable training database. Personal identifiers (PAN, Aadhaar/UAN, bank account numbers, mobile numbers, personal e-mail addresses) are masked in the page before capture. No screen is edited or generated.\n\n| File | Module | Screen | Navigation path | Kind | Size (px) | Callouts | Captured |\n|---|---|---|---|---|---|---:|---|\n';
const where = {};
for (const [code, a] of Object.entries(assignment)) for (const s of a.screens) where[s.id] = { code, s };
for (const [id, c] of Object.entries(crawl).sort()) {
    if (!c.shot) continue;
    const w = where[id];
    sr += `| \`screenshots/${id}.png\` | ${w?.code ?? ''} | ${esc(c.ui?.h1 || c.label)} | ${esc(w?.s.path ?? '')} | ${c.kind} | ${c.ui?.height ? `2160 × ${Math.round(c.ui.height * 1.5)}` : ''} | ${notes[id]?.boxes?.length ?? 0} | ${(notes[id]?.at ?? '').slice(0, 10) || today} |\n`;
}
for (const [id, f] of Object.entries(flows)) sr += `| \`screenshots/${id}.png\` | ${f.module ?? ''} | ${esc(f.screen)} | ${esc(f.path ?? '')} | flow step | | ${f.boxes?.length ?? 0} | ${(f.at ?? '').slice(0, 10)} |\n`;
fs.writeFileSync(path.join(PKG, '04-screenshot-register.md'), sr);

// ---------------------------------------------------------------- 05 validation report
const shots = Object.values(crawl).filter((c) => c.shot).length + Object.keys(flows).length;
let vr = head('PeopleOS Organisation Administrator: training validation report', 'What was checked, how, and what remains open.');
vr += `## Deliverable\n\n- \`PeopleOS-Organisation-Administrator-Complete-Training.pptx\`: ${nav?.slides ?? '?'} slides, ${MODULES.length} modules in ${GROUPS.length} learning groups.\n- Internal links: ${navCheck ? `${navCheck.internal_links} checked, ${navCheck.failures.length} failures (\`tools/nav-map/nav-check-OA.json\`)` : 'not checked yet'}.\n- Build warnings (text fit, layout): ${warnings.length}.\n\n## Coverage\n\n| Area | Pages/features identified | Pages/features documented | Screenshots | Status |\n|---|---:|---:|---:|---|\n`;
for (const m of MODULES) {
    const a = assignment[m.code]?.screens ?? [];
    const c = content[m.code] ?? {};
    const documented = a.filter((s) => c.screens?.[s.id]?.purpose).length;
    const sh = a.filter((s) => crawl[s.id]?.shot).length;
    vr += `| ${m.code} ${m.name} | ${a.length} | ${documented} | ${sh} | ${!a.length ? (c.intro ? 'Process guidance' : 'Open') : documented === a.length ? 'Documented' : 'Partly documented'} |\n`;
}
const forms = Object.values(crawl).filter((c) => c.kind === 'create' || c.kind === 'edit').length;
const actions = Object.values(crawl).reduce((a, c) => a + (c.ui?.headerActions?.length ?? 0) + (c.ui?.toolbar?.length ?? 0) + (c.ui?.rowActions?.length ?? 0), 0);
vr += `\n## Totals\n\n- Rail sections: ${disc.sections.length}. Navigation modules: ${disc.catalogue.length}. Screens inventoried: ${totalScreens}. Forms (new/edit): ${forms}.\n- Actions seen on screens (header, toolbar, row): ${actions}. Workflows/procedures documented: ${Object.values(content).reduce((a, c) => a + (c.procedures ?? []).length, 0)}.\n- Screenshots: ${shots}. Slides: ${nav?.slides ?? '?'}.\n- Screens that did not open for the administrator: ${Object.values(crawl).filter((c) => c.ui?.notAvailable || (c.status ?? 200) >= 400 || c.error).map((c) => c.id).join(', ') || 'none'}.\n\n`;
vr += `## Method\n\n1. Discovery: the application's own navigation and access checks for the organisation administrator (and five other roles for comparison).\n2. Capture: every screen opened as the administrator on a disposable training database with the fictional Demo Group; sensitive values masked; interface structure recorded.\n3. Research: each module's purpose, fields, actions, rules and calculations taken from the source code and the training data, never invented (see \`tools/AGENT-BRIEF.md\`).\n4. Callouts: element boxes measured on the live screen and drawn as editable PowerPoint shapes.\n5. Build and checks: every internal link re-read from the written file; slides rendered and reviewed.\n\n## Known limitations\n\n${gaps.length ? `See \`03-module-coverage-and-gap-register.md\` (${gaps.length} items).` : 'None recorded.'}\n`;
fs.writeFileSync(path.join(PKG, '05-training-validation-report.md'), vr);
console.log(`registers written: inventory ${totalScreens} screens, matrix ${roles.length} roles, gaps ${gaps.length}, screenshots ${shots}`);
