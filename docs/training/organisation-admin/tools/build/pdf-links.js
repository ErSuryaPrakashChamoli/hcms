// Reads every internal slide link of the built course (the transparent "Link · …" areas and any other slide jump)
// with its box in inches and its target slide, and writes tools/.work/pdf-links.json for build/pdf.py, which puts the
// same links into the PDF. node build/pdf-links.js
'use strict';
const fs = require('fs');
const path = require('path');
const JSZip = require(require.resolve('jszip', { paths: [require.resolve('pptxgenjs', { paths: [path.resolve(__dirname, '../../../../presentations/peopleos/tools/build')] })] }));

const EMU = 914400;
const DECK = path.resolve(__dirname, '../../PeopleOS-Organisation-Administrator-Complete-Training.pptx');

(async () => {
    const zip = await JSZip.loadAsync(fs.readFileSync(DECK));
    const pres = await zip.file('ppt/presentation.xml').async('string');
    const presRels = await zip.file('ppt/_rels/presentation.xml.rels').async('string');
    const relTarget = Object.fromEntries([...presRels.matchAll(/<Relationship [^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/g)].map((m) => [m[1], m[2]]));
    const order = [...pres.matchAll(/<p:sldId [^>]*r:id="(rId\d+)"/g)].map((m) => path.basename(relTarget[m[1]]));
    const numberOf = Object.fromEntries(order.map((f, i) => [f, i + 1]));
    const out = [];
    for (const [i, f] of order.entries()) {
        const xml = await zip.file(`ppt/slides/${f}`).async('string');
        const relsXml = (await zip.file(`ppt/slides/_rels/${f}.rels`)?.async('string')) ?? '';
        const rels = Object.fromEntries([...relsXml.matchAll(/<Relationship ([^>]*)\/>/g)].map((m) => [/Id="(rId\d+)"/.exec(m[1])[1], /Target="([^"]+)"/.exec(m[1])[1]]));
        for (const m of xml.matchAll(/<p:(sp|pic)>([\s\S]*?)<\/p:\1>/g)) {
            const link = /<p:cNvPr[^>]*>\s*<a:hlinkClick r:id="(rId\d+)"[^>]*action="ppaction:\/\/hlinksldjump"/.exec(m[2]);
            const off = /<a:off x="(-?\d+)" y="(-?\d+)"\/>\s*<a:ext cx="(\d+)" cy="(\d+)"\/>/.exec(m[2]);
            if (!link || !off || !rels[link[1]]) continue;
            const to = numberOf[path.basename(rels[link[1]])];
            if (!to) continue;
            out.push({ page: i + 1, to, x: +off[1] / EMU, y: +off[2] / EMU, w: +off[3] / EMU, h: +off[4] / EMU, name: (/name="([^"]*)"/.exec(m[2]) ?? [])[1] ?? '' });
        }
    }
    const file = path.resolve(__dirname, '../.work/pdf-links.json');
    fs.writeFileSync(file, JSON.stringify({ slides: order.length, links: out }));
    console.log(`${out.length} links on ${order.length} slides -> ${file}`);
})().catch((e) => { console.error(e); process.exit(1); });
