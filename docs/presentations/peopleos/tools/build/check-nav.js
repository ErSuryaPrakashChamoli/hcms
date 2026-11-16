// Navigation and layout checker for modular decks: node build/check-nav.js [13 ...]
//
// Re-opens the WRITTEN .pptx (not the generator's intentions) and checks it against tools/nav-maps/<deck>.json:
//   - every internal hyperlink has a relationship and points at a slide that exists
//   - every instructional slide (and every module divider) has Home → main contents, Previous → n−1, Next → n+1
//   - the main contents links each module card to that module's first slide
//   - each module contents slide links every submodule row to its first slide, plus checklist and summary
//   - summary slides link Next module, Module contents, Main contents and each related module correctly
//   - module chip and section label in the bar link to the module start and the module contents
//   - no run-level (underlined) hyperlinks; nothing but chrome inside the navigation bar or the footer band;
//     nothing off the slide; a footer and a slide-number field on every slide with chrome
// Writes tools/nav-check-<deck>.json and exits 1 when anything fails.
'use strict';
const fs = require('fs');
const path = require('path');
const JSZip = require(require.resolve('jszip', { paths: [require.resolve('pptxgenjs')] }));
const { CHROME: K } = require('./design');

const EMU = 914400;
const ROOT = path.resolve(__dirname, '../..');

async function readDeck(file) {
    const zip = await JSZip.loadAsync(fs.readFileSync(file));
    const pres = await zip.file('ppt/presentation.xml').async('string');
    const presRels = await zip.file('ppt/_rels/presentation.xml.rels').async('string');
    const relTarget = Object.fromEntries([...presRels.matchAll(/<Relationship [^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/g)].map((m) => [m[1], m[2]]));
    const order = [...pres.matchAll(/<p:sldId [^>]*r:id="(rId\d+)"/g)].map((m) => path.basename(relTarget[m[1]]));
    const numberOf = Object.fromEntries(order.map((f, i) => [f, i + 1]));
    const slides = [];
    for (const [i, f] of order.entries()) {
        const xml = await zip.file(`ppt/slides/${f}`).async('string');
        const relsXml = (await zip.file(`ppt/slides/_rels/${f}.rels`)?.async('string')) ?? '';
        const rels = Object.fromEntries([...relsXml.matchAll(/<Relationship ([^>]*)\/>/g)].map((m) => {
            const a = m[1], id = /Id="(rId\d+)"/.exec(a)[1], target = /Target="([^"]+)"/.exec(a)[1], type = /Type="([^"]+)"/.exec(a)[1];
            return [id, { target, type }];
        }));
        const shapes = [];
        for (const m of xml.matchAll(/<p:(sp|pic|graphicFrame|cxnSp)>([\s\S]*?)<\/p:\1>/g)) {
            const body = m[2];
            const name = (/<p:cNvPr [^>]*name="([^"]*)"/.exec(body) ?? [])[1] ?? '';
            const link = /<p:cNvPr[^>]*>\s*<a:hlinkClick r:id="(rId\d+)"[^>]*action="ppaction:\/\/hlinksldjump"/.exec(body);
            const off = /<a:off x="(-?\d+)" y="(-?\d+)"\/>\s*<a:ext cx="(\d+)" cy="(\d+)"\/>/.exec(body);
            const box = off ? { x: +off[1] / EMU, y: +off[2] / EMU, w: +off[3] / EMU, h: +off[4] / EMU } : null;
            let to = null, linkError = null;
            if (link) {
                const r = rels[link[1]];
                if (!r) linkError = `hyperlink ${link[1]} has no relationship`;
                else if (!/\/slide$/.test(r.type)) linkError = `hyperlink ${link[1]} is not a slide relationship`;
                else { to = numberOf[path.basename(r.target)] ?? null; if (!to) linkError = `hyperlink to missing ${r.target}`; }
            }
            const runLinks = (body.match(/<a:rPr[^>]*>(?:(?!<\/a:rPr>)[\s\S])*?<a:hlinkClick/g) ?? []).length;
            shapes.push({ kind: m[1], name, box, to, linkError, runLinks, sldNum: /type="sldNum"|type="slidenum"/.test(body) });
        }
        slides.push({ n: i + 1, file: f, shapes, hasSldNumField: /type="slidenum"/.test(xml) });
    }
    return { slides, N: order.length };
}

function check(deck, map) {
    const fails = [], stats = { links: 0, slides: deck.N };
    const fail = (n, msg) => fails.push(`slide ${n}: ${msg}`);
    if (deck.N !== map.slides) fails.push(`deck has ${deck.N} slides, nav map ${map.slides}: rebuild`);
    const link = (s, name) => s.shapes.filter((x) => x.name === `Link · ${name}`);
    const expect = (s, name, target, what) => {
        const l = link(s, name);
        if (!l.length) return fail(s.n, `missing link "${name}" (${what})`);
        for (const x of l) if (x.to !== target) fail(s.n, `link "${name}" goes to ${x.to}, expected ${target} (${what})`);
    };
    for (const s of deck.slides) {
        const p = map.plan[s.n - 1] ?? {};
        for (const x of s.shapes) {
            if (x.linkError) fail(s.n, `${x.name}: ${x.linkError}`);
            if (x.to) stats.links += 1;
            if (x.runLinks) fail(s.n, `${x.name}: ${x.runLinks} run-level hyperlink(s) (underlined text)`);
            if (x.box && (x.box.x < -0.01 || x.box.y < -0.01 || x.box.x + x.box.w > K.W + 0.01 || x.box.y + x.box.h > K.H + 0.01)) fail(s.n, `${x.name || x.kind} lies partly off the slide`);
        }
        if (p.kind === 'cover') continue;
        // Navigation on every slide with chrome (instructional slides and module dividers)
        expect(s, 'Home', map.toc, 'Home button');
        if (s.n > 1) expect(s, 'Previous', s.n - 1, 'Previous'); else if (link(s, 'Previous').length) fail(s.n, 'Previous on the first slide');
        if (s.n < deck.N) expect(s, 'Next', s.n + 1, 'Next'); else if (link(s, 'Next').length) fail(s.n, 'Next on the last slide');
        if (!s.shapes.some((x) => x.name === 'Footer · Module')) fail(s.n, 'no footer module identifier');
        if (!s.hasSldNumField) fail(s.n, 'no slide-number field');
        const mod = map.modules.find((m) => m.code === p.module);
        if (mod && p.kind !== 'divider') {
            expect(s, `Module ${mod.code} start`, mod.start, 'module chip');
            if (s.shapes.some((x) => x.name === 'Nav · Section')) expect(s, `Module ${mod.code} contents (section)`, mod.contents, 'section label');
        }
        // Chrome bands: only chrome inside the navigation bar and the footer
        if (p.kind !== 'divider') {
            for (const x of s.shapes) {
                if (!x.box || /^(Nav · |Link · (Home|Previous|Next|Module M\d+ (start|contents \(section\))|Course review start)$)/.test(x.name)) continue;
                if (x.box.y < K.nav.h - 0.005) fail(s.n, `"${x.name || x.kind}" enters the navigation bar (y ${x.box.y.toFixed(2)})`);
                if (!/^Footer · |^Slide Number/.test(x.name) && x.box.y + x.box.h > K.footer.y - 0.04) fail(s.n, `"${x.name || x.kind}" enters the footer band (bottom ${(x.box.y + x.box.h).toFixed(2)})`);
            }
        }
    }
    // Main contents: one card per module
    const toc = deck.slides[map.toc - 1];
    for (const m of map.modules) expect(toc, `Module ${m.code}`, m.start, `contents card ${m.code}`);
    if (map.howto) expect(toc, 'How to use this course', map.howto, 'contents button');
    if (map.review) expect(toc, 'Course review', map.review, 'contents button');
    // Modules: divider buttons, submodule contents, summary
    for (const m of map.modules) {
        const div = deck.slides[m.start - 1];
        expect(div, `${m.code} contents`, m.contents, 'divider button');
        expect(div, `${m.code} summary`, m.summary, 'divider button');
        const c = deck.slides[m.contents - 1];
        for (const x of m.subs) expect(c, `${m.code}.${x.id}`, x.start, `submodule row ${x.id}`);
        expect(c, `${m.code}.summary`, m.summary, 'summary row');
        if (m.checklist) expect(c, `${m.code}.checklist`, m.checklist, 'checklist row');
        const sm = deck.slides[m.summary - 1];
        if (m.next) expect(sm, 'Next module', m.next, 'next module button');
        expect(sm, 'Module contents', m.contents, 'summary button');
        expect(sm, 'Main contents', map.toc, 'summary button');
        for (const r of m.related) expect(sm, `Related ${r.code}`, r.start, `related module ${r.code}`);
        // anchors really are inside their module
        for (const x of m.subs) if (map.plan[x.start - 1]?.sub !== x.id) fails.push(`${m.code} ${x.id}: anchor slide ${x.start} is not in the submodule`);
    }
    return { fails, stats };
}

(async () => {
    const want = process.argv.slice(2);
    const dir = path.join(__dirname, '../nav-maps');
    const maps = fs.readdirSync(dir).filter((f) => f.endsWith('.json') && (!want.length || want.includes(f.slice(0, 2))));
    let bad = 0;
    for (const f of maps) {
        const map = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
        const file = path.join(process.env.PEOPLEOS_DECK_OUT || ROOT, map.file);
        const deck = await readDeck(file);
        const { fails, stats } = check(deck, map);
        const report = { file: map.file, checked_at: new Date().toISOString(), slides: stats.slides, internal_links: stats.links, failures: fails };
        fs.writeFileSync(path.join(__dirname, `../nav-check-${f.slice(0, 2)}.json`), JSON.stringify(report, null, 2));
        console.log(`${map.file}: ${stats.slides} slides, ${stats.links} internal links checked, ${fails.length} failure(s)`);
        for (const x of fails.slice(0, 60)) console.log('   ✗ ' + x);
        if (fails.length > 60) console.log(`   … ${fails.length - 60} more in nav-check-${f.slice(0, 2)}.json`);
        bad += fails.length;
    }
    process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
