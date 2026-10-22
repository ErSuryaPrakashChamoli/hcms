// Writes SCREENSHOT-INVENTORY.md from the capture manifests (tools/manifests) and the deck usage record
// written by build-all.js (tools/usage.json). Run after the decks are built: node build/inventory.js
'use strict';
const fs = require('fs');
const path = require('path');
const { manifest, ROOT } = require('./lib');

const usagePath = path.join(__dirname, '../usage.json');
const usage = fs.existsSync(usagePath) ? JSON.parse(fs.readFileSync(usagePath, 'utf8')) : {};
const usedIn = {};
for (const [deck, shots] of Object.entries(usage)) for (const s of shots) (usedIn[s] ??= []).push(deck);

const all = Object.values(manifest()).filter((e) => fs.existsSync(path.join(ROOT, e.file)));
const missingFiles = Object.values(manifest()).filter((e) => !fs.existsSync(path.join(ROOT, e.file)));
const onDisk = [];
for (const dir of fs.readdirSync(path.join(ROOT, 'screenshots'))) {
    const p = path.join(ROOT, 'screenshots', dir);
    if (!fs.statSync(p).isDirectory()) continue;
    for (const f of fs.readdirSync(p).filter((f) => f.endsWith('.png'))) onDisk.push(`${dir}/${f.replace(/\.png$/, '')}`);
}
const unlisted = onDisk.filter((n) => !manifest()[n]);
const esc = (t) => String(t ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
const dirs = [...new Set(all.map((e) => e.name.split('/')[0]))].sort();
const byStatus = all.reduce((m, e) => ((m[e.status] = (m[e.status] ?? 0) + 1), m), {});

let md = `# PeopleOS training library: screenshot inventory

**Generated:** ${new Date().toISOString().slice(0, 10)} by \`tools/build/inventory.js\` from the capture manifests (\`tools/manifests/*.json\`).
**For:** reviewers and maintainers of the training decks. Every row is one genuine screenshot of the running application.

## Environment (applies to every row)

| Item | Value |
|---|---|
| Application | Markedge PeopleOS, branch \`feature/oct_20_people_os_phase_1\`, commit \`72c96a73\` (no application file changed) |
| Instance | Local development servers \`http://127.0.0.1:8098\`–\`8101\`, all serving one disposable database |
| Database | \`hcm_training_showcase\`: migrated, then seeded with \`UxShowcaseSeeder\` (fictional people only). Workflows were executed on it to produce the screens. No real tenant, person or customer data |
| Browser | Chromium (Playwright 1.62) at 1440 × 900 CSS pixels, device scale 1.5 (images 2160 px wide), locale en-GB, time zone Asia/Kolkata |
| Annotations | None in the image files. Numbered callouts in the decks are PowerPoint shapes drawn from the element boxes recorded at capture time |
| Redaction | Only where noted: the operator's authenticator QR code and key were covered before capture |
| Embedding | Decks embed a JPEG copy (max 1920 px wide), sometimes cropped. The PNG listed here is the full original |

## Summary

- Screenshots: **${all.length}** (${dirs.map((d) => `${d} ${all.filter((e) => e.name.startsWith(d + '/')).length}`).join(', ')}).
- Used in at least one deck: **${all.filter((e) => usedIn[e.name]).length}**; not used in any deck: ${all.filter((e) => !usedIn[e.name]).length} (kept as evidence).
- Status of the screen shown: ${Object.entries(byStatus).map(([k, v]) => `${k}: ${v}`).join('; ')}.
${missingFiles.length ? `- Manifest entries without a file (removed screenshots): ${missingFiles.length}.\n` : ''}${unlisted.length ? `- Files without a manifest entry: ${unlisted.join(', ')}.\n` : ''}
Columns: **Deck(s)** = decks that embed the screenshot (from the build); planned decks from the capture manifest are in brackets when they differ.
**Status** uses the seven labels of the accuracy register. **Callouts** = number of element boxes recorded for the deck annotations.

`;

for (const d of dirs) {
    const rows = all.filter((e) => e.name.startsWith(d + '/')).sort((a, b) => a.name.localeCompare(b.name));
    md += `## ${d}\n\n| File | Deck(s) | User (role) | Workflow | Screen | Action demonstrated | Expected / observed result | Status | Prerequisite | Callouts | Captured |\n|---|---|---|---|---|---|---|---|---|---|---|\n`;
    for (const e of rows) {
        const used = (usedIn[e.name] ?? []).sort().join(', ');
        const planned = (e.deck ?? []).join(', ');
        const decks = used ? (used === planned ? used : `${used} [${planned}]`) : `— [${planned}]`;
        md += `| \`${e.name.split('/')[1]}.png\` | ${decks} | ${esc(e.user)} (${esc(e.role)}) | ${esc(e.workflow)} | ${esc(e.screen)} \`${esc(e.url)}\` | ${esc(e.action)} | ${esc(e.expected)}${e.note ? ` _Note: ${esc(e.note)}_` : ''} | ${esc(e.status)} | ${esc(e.prereq) || '—'} | ${e.callouts?.length ?? 0} | ${e.captured_at.slice(0, 16).replace('T', ' ')} UTC |\n`;
    }
    md += '\n';
}
fs.writeFileSync(path.join(ROOT, 'SCREENSHOT-INVENTORY.md'), md);
console.log(`SCREENSHOT-INVENTORY.md: ${all.length} screenshots, ${missingFiles.length} manifest entries without file, ${unlisted.length} files without entry`);
