// Builds the PeopleOS Customer Handbook from docs/customer-guide/*.md:
//   docs/assets/diagrams/*.svg            Mermaid flowcharts rendered to SVG (also inlined in the handbook)
//   docs/customer-guide/08-workflow-diagrams.md   generated index of every flowchart (with its Mermaid source)
//   docs/handbook/PeopleOS-Customer-Handbook.html  full HTML handbook (images from ../assets/screenshots)
//   docs/handbook/PeopleOS-Customer-Handbook.pdf   A4 PDF (optimised images, numbered TOC, bookmarks, page footers)
//   <scratch>/artifact/handbook.html + img/        variant for publishing as a shareable page
// Usage (from docs/handbook/tools): npm ci && node build.mjs [--no-pdf] [--tagged]   — see ../README.md
import { marked } from 'marked';
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync, statSync, copyFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { basename, dirname, join } from 'node:path';

const HERE = dirname(new URL(import.meta.url).pathname);
const REPO = join(HERE, '../../..');
const { chromium } = createRequire(join(REPO, 'package.json'))('playwright'); // the application's pinned Playwright
const GUIDE = `${REPO}/docs/customer-guide`;
const SHOTS = `${REPO}/docs/assets/screenshots`;
const DIAGRAMS = `${REPO}/docs/assets/diagrams`;
const OUTDIR = `${REPO}/docs/handbook`;
const WORK = join(HERE, '.work'); // gitignored scratch output: optimised images, print HTML, pass-1 PDF, page variant
const SCRATCH = WORK;
const OPT = `${WORK}/opt`;
const ART = `${WORK}/artifact`;
const MERMAID_JS = join(HERE, 'node_modules/mermaid/dist/mermaid.min.js');
const EDITION = { date: '19 October 2026', build: '1844ce6' };
const NO_PDF = process.argv.includes('--no-pdf');
// --tagged also writes an accessibility-tagged PDF (screen-reader structure; about 40 % larger).
const TAGGED = process.argv.includes('--tagged');
for (const d of [DIAGRAMS, OUTDIR, WORK, OPT, ART, `${ART}/img`]) mkdirSync(d, { recursive: true });

// ---------------------------------------------------------------- chapter list
const MAIN = [
    ['01-product-overview.md', '1'], ['02-buyer-guide.md', '2'], ['03-getting-started.md', '3'], ['04-dashboard-guide.md', '4'],
    ['05-module-guides.md', '5'], ['06-role-specific-guides.md', '6'], ['07-administrator-guide.md', '7'], ['08-workflow-diagrams.md', '8'],
    ['09-troubleshooting.md', '9'], ['10-faq.md', '10'], ['11-quick-reference.md', '11'], ['12-glossary.md', '12'],
];
const modules = existsSync(`${GUIDE}/modules`) ? readdirSync(`${GUIDE}/modules`).filter((f) => /^\d\d-.*\.md$/.test(f)).sort() : [];
const chapters = [];
for (const [file, num] of MAIN) {
    if (file === '08-workflow-diagrams.md') { chapters.push({ file, num, path: `${GUIDE}/${file}`, generated: true }); continue; }
    if (existsSync(`${GUIDE}/${file}`)) chapters.push({ file, num, path: `${GUIDE}/${file}` });
    if (file === '05-module-guides.md') {
        modules.forEach((m, i) => chapters.push({ file: m, num: `5.${i + 1}`, path: `${GUIDE}/modules/${m}`, module: true }));
    }
}
chapters.forEach((c) => { c.id = 'ch' + c.num.replace('.', '-'); c.src = readFileSafe(c.path); });
function readFileSafe(p) { return existsSync(p) ? readFileSync(p, 'utf8') : ''; }
const byBase = Object.fromEntries(chapters.map((c) => [c.file, c]));

// ---------------------------------------------------------------- flowcharts: collect + generate chapter 8
const diagrams = []; // {key, chapter, code, title, intro, file}
for (const c of chapters) {
    if (c.generated || !c.src) continue;
    let n = 0;
    c.src = c.src.replace(/```mermaid\n([\s\S]*?)```/g, (m, code, offset, whole) => {
        n += 1;
        const before = whole.slice(Math.max(0, offset - 1200), offset);
        const paras = before.split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
        const lead = paras[paras.length - 1] ?? '';
        const t = lead.match(/^\*\*(Flowchart|Diagram)\s*[—–-]\s*([^*]+?)\.?\*\*\s*([\s\S]*)$/);
        const key = `${c.file.replace(/\.md$/, '')}-${n}`;
        const d = { key, chapter: c, code: code.trim(), kind: t ? t[1] : 'Flowchart', title: t ? t[2].trim() : `${c.file} diagram ${n}`, intro: t ? t[3].replace(/\n/g, ' ').trim() : '' };
        diagrams.push(d);
        return `<div class="mmd" data-key="${key}"></div>`;
    });
}
const flowcharts = diagrams.filter((d) => d.kind === 'Flowchart');
// The repository copy of chapter 8 carries every flowchart with its source; the handbook shows an index.
{
    const lines = ['# 8. Workflow diagrams', '',
        '> **In this chapter:** every flowchart in this handbook in one place, with the roles involved and where the workflow',
        '> is explained. **Who should read it:** anyone who wants the big picture of how work flows through PeopleOS.', '',
        '> **Note.** This file is generated from the flowcharts in the other chapters (the source of truth). The diagrams',
        '> follow the business rules implemented in the application; each chapter explains the steps in detail.', '',
        `PeopleOS documentation contains **${flowcharts.length} flowcharts**.`, '',
        '| # | Flowchart | Chapter |', '|---|---|---|'];
    flowcharts.forEach((d, i) => lines.push(`| ${i + 1} | ${d.title} | [${chapterTitle(d.chapter)}](${relFromGuide(d.chapter)}) |`));
    lines.push('');
    flowcharts.forEach((d, i) => {
        lines.push(`## 8.${i + 1} ${d.title}`, '', `*From:* [${chapterTitle(d.chapter)}](${relFromGuide(d.chapter)})`, '');
        if (d.intro) lines.push(d.intro, '');
        lines.push(`![${d.title}](../assets/diagrams/${d.key}.svg)`, '', '<details><summary>Mermaid source</summary>', '', '```mermaid', d.code, '```', '', '</details>', '');
    });
    writeFileSync(`${GUIDE}/08-workflow-diagrams.md`, lines.join('\n'));
    const ch8 = chapters.find((c) => c.generated);
    // Handbook version: index with links to the place each flowchart appears.
    const idx = ['# 8. Workflow diagrams', '',
        '> **In this chapter:** a map of every flowchart in this handbook, the roles involved and where to find it.', '',
        `This handbook contains **${flowcharts.length} flowcharts**. Each one is shown in the chapter that explains the workflow; the`,
        'list below takes you there. The diagrams follow the business rules implemented in the application.', '',
        '| # | Flowchart | Roles and scope | Where |', '|---|---|---|---|'];
    flowcharts.forEach((d, i) => {
        const roles = (d.intro.match(/\*Roles?:\*\s*([^*]+?)(?:\.\s|\s\*|$)/) ?? [])[1] ?? '';
        idx.push(`| ${i + 1} | [${d.title}](#fig-${d.key}) | ${roles.replace(/\|/g, '/')} | ${d.chapter.num}. ${chapterTitle(d.chapter)} |`);
    });
    ch8.src = idx.join('\n');
}
function chapterTitle(c) { return ((c.src.match(/^#\s+(.+)$/m) ?? [])[1] ?? c.file).replace(/^\d+(\.\d+)*\.?\s+/, '').trim(); }
function relFromGuide(c) { return c.module ? `modules/${c.file}` : c.file; }

// ---------------------------------------------------------------- render Mermaid to SVG
const browser = await chromium.launch();
{
    const page = await browser.newPage();
    await page.setContent('<!doctype html><html><body><div id="x"></div></body></html>');
    await page.addScriptTag({ path: MERMAID_JS });
    await page.evaluate(() => window.mermaid.initialize({
        startOnLoad: false, securityLevel: 'strict', theme: 'base',
        fontFamily: '"Instrument Sans", "Segoe UI", system-ui, sans-serif',
        themeVariables: { primaryColor: '#efedfb', primaryBorderColor: '#574bc4', primaryTextColor: '#15171d', lineColor: '#646a77',
            secondaryColor: '#f6efe1', tertiaryColor: '#f5f6f8', fontSize: '16px', edgeLabelBackground: '#ffffff' },
        flowchart: { htmlLabels: true, curve: 'basis', nodeSpacing: 30, rankSpacing: 30, padding: 10, wrappingWidth: 200, diagramPadding: 6 },
    }));
    const failures = [];
    for (const d of diagrams) {
        const res = await page.evaluate(async ({ id, code }) => {
            try { const { svg } = await window.mermaid.render(id, code); return { svg }; } catch (e) { return { error: String(e.message ?? e) }; }
        }, { id: 'mmd-' + d.key.replace(/[^a-z0-9]/gi, '-'), code: d.code });
        if (res.error) { failures.push(`${d.key}: ${res.error.split('\n')[0]}`); d.svg = `<p class="mmd-error">Diagram could not be rendered.</p>`; continue; }
        d.svg = res.svg;
        writeFileSync(`${DIAGRAMS}/${d.key}.svg`, res.svg);
    }
    await page.close();
    if (failures.length) { console.log('MERMAID FAILURES:\n' + failures.join('\n')); }
    console.log(`diagrams: ${diagrams.length} rendered (${flowcharts.length} flowcharts), failures: ${failures.length}`);
}

// ---------------------------------------------------------------- markdown → html
const slugify = (s) => s.toLowerCase().replace(/<[^>]+>/g, '').replace(/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/gu, '').replace(/ /g, '-');
const plain = (md) => md.replace(/!\[[^\]]*\]\([^)]*\)/g, '').replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/[*_`]/g, '').trim();
const toc = []; // {level, id, text, chapter}
const imagesUsed = new Set();
let current = null; let slugCounts = {};
const renderer = {
    heading({ tokens, depth, text }) {
        const inner = this.parser.parseInline(tokens);
        const base = slugify(plain(text));
        const n = slugCounts[base] ?? 0; slugCounts[base] = n + 1;
        const id = `${current.id}-${n ? `${base}-${n}` : base}`;
        if (depth === 1) {
            toc.push({ level: 1, id: current.id, text: plain(text), chapter: current });
            const label = current.module ? `Chapter ${current.num}` : `Chapter ${current.num}`;
            return `<header class="chapter-head" id="${current.id}"><p class="eyebrow">${label}</p><h1 id="${id}">${inner.replace(/^\d+(\.\d+)*\.?\s+/, '')}</h1></header>\n`;
        }
        if (depth === 2) toc.push({ level: 2, id, text: plain(text), chapter: current });
        return `<h${depth} id="${id}">${inner}</h${depth}>\n`;
    },
    link({ href, title, tokens }) {
        const text = this.parser.parseInline(tokens);
        if (/^https?:/.test(href)) return `<a href="${href}" target="_blank" rel="noopener">${text}</a>`;
        if (href.startsWith('#fig-')) return `<a href="${href}">${text}</a>`;
        if (href.startsWith('#')) return `<a href="#${current.id}-${href.slice(1)}">${text}</a>`;
        const [file, anchor] = href.split('#');
        const target = byBase[basename(file)];
        if (target && /\.md$/.test(file)) return `<a href="#${anchor ? `${target.id}-${anchor}` : target.id}">${text}</a>`;
        return `<span class="xref">${text}</span>`;
    },
    image({ href, text }) {
        const file = basename(href);
        if (href.includes('/diagrams/')) return '';
        imagesUsed.add(file);
        return `<img src="@@IMG:${file}@@" alt="${text.replace(/"/g, '&quot;')}" loading="lazy">`;
    },
};
marked.use({ gfm: true, renderer });
for (const c of chapters) {
    if (!c.src) continue;
    current = c; slugCounts = {};
    let html = marked.parse(c.src);
    // figures: image paragraph followed by an italic "Figure" caption
    html = html.replace(/<p>(<img [^>]+>)<\/p>\s*<p><em>(Figure[\s\S]*?)<\/em><\/p>/g, '<figure>$1<figcaption>$2</figcaption></figure>');
    html = html.replace(/<p>(<img [^>]+>)\s*<em>(Figure[\s\S]*?)<\/em><\/p>/g, '<figure>$1<figcaption>$2</figcaption></figure>');
    html = html.replace(/<p>(<img [^>]+>)<\/p>/g, '<figure>$1</figure>');
    // callouts
    html = html.replace(/<blockquote>\s*<p><strong>([^<]+)<\/strong>/g, (m, label) => {
        const l = label.toLowerCase();
        const kind = l.startsWith('important') || l.startsWith('keep your account') ? 'warn' : l.startsWith('tip') ? 'tip' : l.startsWith('in this chapter') ? 'intro' : 'note';
        return `<blockquote class="callout callout-${kind}"><p><strong>${label}</strong>`;
    });
    // status badges
    const badge = (t) => {
        const s = t.toLowerCase();
        const k = s.startsWith('available with') ? 'warn' : s.startsWith('available') || s.startsWith('yes') && !s.includes('limitation') ? 'ok' : s.startsWith('set up by markedge') || s.includes('provider') ? 'prov' : s.startsWith('not available') || s === 'no' ? 'no' : s.startsWith('yes, with') ? 'warn' : 'neutral';
        return k;
    };
    html = html.replace(/<p><strong>Status:<\/strong>\s*([\s\S]*?)<\/p>/g, (m, t) => `<p class="status"><span class="status-label">Status</span> <span class="badge badge-${badge(t.replace(/<[^>]+>/g, ''))}">${t}</span></p>`);
    html = html.replace(/<td>(Available(?: with limitations)?[^<]{0,80}|Not available[^<]{0,60}|Yes, with limitations|Set up by Markedge[^<]{0,40})<\/td>/g, (m, t) => `<td><span class="badge badge-${badge(t)}">${t}</span></td>`);
    html = html.replace(/(<strong>)?(T|t)o be confirmed with the SaaS provider\.?(<\/strong>)?/g, (m) => `<span class="tbc">${m.replace(/<\/?strong>/g, '')}</span>`);
    // tables scroll on small screens
    html = html.replace(/<table>/g, '<div class="table-wrap"><table>').replace(/<\/table>/g, '</table></div>');
    // diagrams
    html = html.replace(/<div class="mmd" data-key="([^"]+)"><\/div>/g, (m, key) => {
        const d = diagrams.find((x) => x.key === key);
        return `<figure class="diagram" id="fig-${key}"><div class="diagram-paper">${d?.svg ?? ''}</div></figure>`;
    });
    c.html = html;
}

// guard: raw HTML-like text in a chapter (for example "Position <code> created") would swallow the rest of the handbook
for (const c of chapters) {
    if (!c.html) continue;
    for (const tag of ['code', 'pre', 'em', 'strong', 'table', 'blockquote', 'ol', 'ul']) {
        const open = (c.html.match(new RegExp(`<${tag}[\\s>]`, 'g')) ?? []).length, close = (c.html.match(new RegExp(`</${tag}>`, 'g')) ?? []).length;
        if (open !== close) console.log(`UNBALANCED <${tag}> in ${c.file}: ${open} opened, ${close} closed`);
    }
}

// figure numbering per chapter (handbook only)
for (const c of chapters) {
    if (!c.html) continue;
    let k = 0;
    c.html = c.html.replace(/<figcaption>Figure\s+[\d.]+\s*[—–-]\s*/g, () => `<figcaption><span class="fig-no">Figure ${c.num}.${++k}</span> `);
}

// ---------------------------------------------------------------- page assembly
let brandN = 0;
const BRAND_SVG = () => { const g = `brand-grad-${++brandN}`; return `<svg class="mark" viewBox="0 0 32 32" aria-hidden="true"><defs><linearGradient id="${g}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6d5fe0"/><stop offset="1" stop-color="#463ba6"/></linearGradient></defs><rect width="32" height="32" rx="9" fill="url(#${g})"/><circle cx="16" cy="12" r="4.2" fill="#ffffff"/><path d="M8.5 24.5c1.1-4.1 4-6.4 7.5-6.4s6.4 2.3 7.5 6.4" fill="none" stroke="#e2c48c" stroke-width="2.4" stroke-linecap="round"/></svg>`; };
const shotCount = imagesUsed.size;
function tocHtml(withPages) {
    const items = toc.filter((t) => t.level === 1 || (t.level === 2 && !t.chapter.module && !['ch10', 'ch11', 'ch12', 'ch8'].includes(t.chapter.id)));
    return `<ol class="toc">${items.map((t) => {
        const label = t.level === 1 ? `<span class="toc-num">${t.chapter.num}</span>${esc(t.text.replace(/^\d+(\.\d+)*\.?\s+/, ''))}` : esc(t.text);
        return `<li class="toc-l${t.level}${t.chapter.module && t.level === 1 ? ' toc-mod' : ''}"><a href="#${t.id}"><span class="toc-text">${label}</span>${withPages ? `<span class="toc-page" data-for="${t.id}">${withPages[t.id] ?? ''}</span>` : ''}</a></li>`;
    }).join('')}</ol>`;
}
function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function sideNav() {
    return `<nav class="sidenav" aria-label="Chapters"><a class="brand" href="#top">${BRAND_SVG()}<span>People<b>OS</b></span></a><ol>${toc.filter((t) => t.level === 1).map((t) => `<li${t.chapter.module ? ' class="mod"' : ''}><a href="#${t.id}"><span>${t.chapter.num}</span>${esc(t.text.replace(/^\d+(\.\d+)*\.?\s+/, ''))}</a></li>`).join('')}</ol></nav>`;
}
const css = readFileSync(new URL('./handbook.css', import.meta.url), 'utf8');
function body(pages) {
    return `
<div class="layout" id="top">
${sideNav()}
<main class="doc">
<section class="cover">
  <div class="cover-brand">${BRAND_SVG()}<span class="word">People<b>OS</b></span></div>
  <p class="cover-kicker">Markedge PeopleOS</p>
  <h1 class="cover-title">Customer Handbook</h1>
  <p class="cover-sub">Product overview, buyer's guide and user manual for the employee operating system — from first hello to lifelong connection.</p>
  <dl class="cover-meta">
    <div><dt>Edition</dt><dd>${EDITION.date}</dd></div>
    <div><dt>Application build</dt><dd>${EDITION.build}</dd></div>
    <div><dt>Contents</dt><dd>${chapters.filter((c) => c.html).length} chapters · ${flowcharts.length} flowcharts · ${shotCount} screenshots</dd></div>
  </dl>
  <p class="cover-note">Every screenshot is a genuine capture of the PeopleOS application in a demonstration environment with fictional people and companies. Commercial terms are not part of this handbook; they are marked "To be confirmed with the SaaS provider".</p>
</section>
<section class="front">
  <h2 class="front-title" id="contents">Contents</h2>
  ${tocHtml(pages)}
</section>
<section class="front how">
  <h2 class="front-title" id="how-to-use">How to use this handbook</h2>
  <div class="how-grid">
    <div><h3>Evaluating PeopleOS?</h3><p>Read chapter 1 (five-minute overview and fit check) and chapter 2 (buyer's guide, checklists and questions for the demonstration).</p></div>
    <div><h3>New user?</h3><p>Start with chapter 3: a 30-minute quick start. Chapter 11 is a one-page quick reference.</p></div>
    <div><h3>Manager or HR?</h3><p>Find your role in chapter 6, then the module chapters 5.1–5.9 for step-by-step tasks.</p></div>
    <div><h3>Administrator?</h3><p>Chapter 7 is your set-up manual; chapter 5.9 covers users, roles, settings, security and integrations.</p></div>
  </div>
  <p class="legend"><span class="badge badge-ok">Available</span> ready to use · <span class="badge badge-warn">Available with limitations</span> usable, with limits explained · <span class="badge badge-prov">Set up by Markedge</span> done by the provider · <span class="badge badge-no">Not available</span> not in the product today</p>
</section>
${chapters.filter((c) => c.html).map((c) => `<section class="chapter${c.module ? ' module' : ''}" data-chapter="${c.num}">\n${c.html}\n</section>`).join('\n')}
<footer class="colophon"><p>Markedge PeopleOS Customer Handbook · Edition ${EDITION.date} · build ${EDITION.build}. Generated from the Markdown sources in <code>docs/customer-guide</code>.</p></footer>
</main>
</div>`;
}
const head = (extra = '') => `<title>PeopleOS Customer Handbook</title>
<meta name="claude:viewport-fit-sides" content="cover">
<meta name="description" content="Product overview, buyer's guide and user manual for Markedge PeopleOS.">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap">
<style>${css}${extra}</style>`;
const dims = JSON.parse(execFileSync('python3', ['-c', `
import sys, json
from PIL import Image
out = {}
for f in sys.argv[2:]:
    try:
        out[f] = Image.open(sys.argv[1] + '/' + f).size
    except Exception:
        pass
print(json.dumps(out))
`, SHOTS, ...imagesUsed]).toString());
const tall = new Set(Object.entries(dims).filter(([, [w, h]]) => h / w > 1.25).map(([f]) => f));
const withImages = (html, fn) => html.replace(/<img src="@@IMG:([^@]+)@@"/g, (m, f) => `<img${tall.has(f) ? ' class="tall"' : ''} src="@@IMG:${f}@@"`).replace(/@@IMG:([^@]+)@@/g, (m, f) => fn(f));
const missing = [...imagesUsed].filter((f) => !existsSync(`${SHOTS}/${f}`));
if (missing.length) console.log('MISSING IMAGES:', missing.join(', '));

// repository HTML (full document)
const repoHtml = `<!doctype html>\n<html lang="en-GB">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n${head()}\n</head>\n<body>\n${withImages(body(null), (f) => `../assets/screenshots/${f}`)}\n</body>\n</html>\n`;
writeFileSync(`${OUTDIR}/PeopleOS-Customer-Handbook.html`, repoHtml);

// optimised images (PDF + artifact)
const toOptimise = [...imagesUsed].filter((f) => existsSync(`${SHOTS}/${f}`) && (!existsSync(`${OPT}/${f.replace(/\.png$/, '.jpg')}`) || statSync(`${OPT}/${f}`).mtimeMs < statSync(`${SHOTS}/${f}`).mtimeMs));
if (toOptimise.length) {
    execFileSync('python3', ['-c', `
import sys
from PIL import Image
src, out = sys.argv[1], sys.argv[2]
for f in sys.argv[3:]:
    im = Image.open(src + '/' + f).convert('RGB')
    if im.size[0] > 1600:
        im = im.resize((1600, round(im.size[1] * 1600 / im.size[0])), Image.LANCZOS)
    im.quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(out + '/' + f, optimize=True)
    pdf = im if im.size[0] <= 1280 else im.resize((1280, round(im.size[1] * 1280 / im.size[0])), Image.LANCZOS)
    pdf.save(out + '/' + f[:-4] + '.jpg', quality=74, optimize=True, progressive=False, subsampling=2)
`, SHOTS, OPT, ...toOptimise]);
}
for (const f of imagesUsed) if (existsSync(`${OPT}/${f}`)) copyFileSync(`${OPT}/${f}`, `${ART}/img/${f}`);
writeFileSync(`${ART}/handbook.html`, `${head()}\n${withImages(body(null), (f) => `img/${f}`)}\n`);
console.log(`chapters: ${chapters.filter((c) => c.html).length}, images used: ${imagesUsed.size}, missing: ${missing.length}`);

// ---------------------------------------------------------------- PDF (two passes: page numbers into the contents)
if (!NO_PDF) {
    const printDoc = (pages) => `<!doctype html><html lang="en-GB"><head><meta charset="utf-8">${head()}</head><body class="print">${withImages(body(pages), (f) => `file://${OPT}/${f.replace(/\.png$/, '.jpg')}`).replace(/ loading="lazy"/g, '')}</body></html>`;
    const tmp = `${WORK}/print.html`;
    const pdfOpts = (path, tagged = false) => ({
        path, format: 'A4', printBackground: true, outline: true, tagged, preferCSSPageSize: true,
        displayHeaderFooter: true, headerTemplate: '<span></span>',
        footerTemplate: `<div style="width:100%;font:8px 'Instrument Sans',Helvetica,Arial,sans-serif;color:#646a77;padding:0 16mm;display:flex;justify-content:space-between"><span>Markedge PeopleOS · Customer Handbook · ${EDITION.date}</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`,
        margin: { top: '16mm', bottom: '16mm', left: '0', right: '0' },
    });
    const page = await browser.newPage();
    writeFileSync(tmp, printDoc({}));
    await page.goto(`file://${tmp}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    const pass1 = `${WORK}/pass1.pdf`;
    await page.pdf(pdfOpts(pass1));
    const pages = await pageNumbers(pass1, toc.map((t) => t.id));
    writeFileSync(tmp, printDoc(pages));
    await page.goto(`file://${tmp}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.pdf(pdfOpts(`${OUTDIR}/PeopleOS-Customer-Handbook.pdf`));
    if (TAGGED) await page.pdf(pdfOpts(`${OUTDIR}/PeopleOS-Customer-Handbook-accessible.pdf`, true));
    const shown = (tocHtml(pages).match(/data-for="[^"]+"><\/span>/g) ?? []).length;
    const found = Object.keys(pages).length;
    console.log(`contents entries shown without a page number: ${shown}`);
    console.log(`pdf: written; toc page numbers found for ${found}/${toc.length} entries; size ${(statSync(`${OUTDIR}/PeopleOS-Customer-Handbook.pdf`).size / 1e6).toFixed(1)} MB`);
}
await browser.close();

async function pageNumbers(pdfPath, ids) {
    const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
    const doc = await pdfjs.getDocument({ data: new Uint8Array(readFileSync(pdfPath)), verbosity: 0 }).promise;
    const out = {};
    const dests = await doc.getDestinations();
    for (const id of ids) {
        const d = dests[id];
        if (!d) continue;
        try { out[id] = (await doc.getPageIndex(d[0])) + 1; } catch {}
    }
    out.__total = doc.numPages;
    return out;
}
