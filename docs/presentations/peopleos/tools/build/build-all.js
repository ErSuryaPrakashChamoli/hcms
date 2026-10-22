// Builds the PeopleOS training decks: node build/build-all.js [01 02 ...]
// Each module in build/decks/ exports (lib) => Deck. Writes the .pptx files to docs/presentations/peopleos/,
// tools/usage.json (screenshots per deck, for the inventory) and tools/build-report.json (for the QA report).
'use strict';
const fs = require('fs');
const path = require('path');
const lib = require('./lib');

(async () => {
    const want = process.argv.slice(2);
    const dir = path.join(__dirname, 'decks');
    const mods = fs.readdirSync(dir).filter((f) => /^\d\d-.*\.js$/.test(f)).sort().filter((f) => !want.length || want.includes(f.slice(0, 2)));
    const usagePath = path.join(__dirname, '../usage.json');
    const reportPath = path.join(__dirname, '../build-report.json');
    const usage = fs.existsSync(usagePath) ? JSON.parse(fs.readFileSync(usagePath, 'utf8')) : {};
    const report = fs.existsSync(reportPath) ? JSON.parse(fs.readFileSync(reportPath, 'utf8')) : {};
    for (const f of mods) {
        lib.WARN.length = 0;
        const deck = await require(path.join(dir, f))(lib);
        const r = await deck.save();
        const key = String(deck.meta.num).padStart(2, '0');
        usage[key] = r.shots.sort();
        report[key] = { file: deck.meta.file, title: deck.meta.title, slides: r.slides, screenshots: r.shots.length, warnings: [...lib.WARN], built_at: new Date().toISOString() };
        console.log(`${deck.meta.file}: ${r.slides} slides, ${r.shots.length} screenshots${lib.WARN.length ? `, ${lib.WARN.length} warnings` : ''}`);
        for (const w of lib.WARN) console.log('   ! ' + w);
    }
    fs.writeFileSync(usagePath, JSON.stringify(usage, null, 2));
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
