// Writes QA-REPORT.md from the build report, the python-pptx integrity check, the validator results, the
// screenshot manifests and the feature matrix. Run after build-all.js, check.py and the validator.
'use strict';
const fs = require('fs');
const path = require('path');
const { manifest, ROOT } = require('./lib');

const T = path.join(__dirname, '..');
const build = JSON.parse(fs.readFileSync(path.join(T, 'build-report.json'), 'utf8'));
const check = JSON.parse(fs.readFileSync(path.join(T, 'check-report.json'), 'utf8'));
const usage = JSON.parse(fs.readFileSync(path.join(T, 'usage.json'), 'utf8'));
const valid = fs.existsSync(path.join(T, 'validate-report.json')) ? JSON.parse(fs.readFileSync(path.join(T, 'validate-report.json'), 'utf8')) : {};
const m = manifest();
const all = Object.values(m);
const matrix = fs.readFileSync(path.join(ROOT, 'FEATURE-TO-PRESENTATION-MATRIX.md'), 'utf8');
const mrows = matrix.split('\n').filter((l) => /^\| [A-Z]+[0-9][0-9A-Za-z–]* \|/.test(l)).map((l) => l.split('|').map((c) => c.trim()));

let md = `# PeopleOS training library: QA report

**Date:** ${new Date().toISOString().slice(0, 10)} · **Application:** commit \`72c96a73\` (no application file changed) ·
**For:** the reviewers who accept the training library.

## 1. Summary

| Item | Result |
|---|---|
| Presentations | ${Object.keys(build).length} editable PPTX files (pptxgenjs; text, shapes, tables and callouts are native PowerPoint objects) |
| Slides | ${Object.values(check).reduce((a, c) => a + c.slides, 0)} in total |
| Genuine screenshots captured | ${all.length} (all from the running application on the disposable training database) |
| Screenshots used in at least one deck | ${new Set(Object.values(usage).flat()).size} |
| Workflows in the feature matrix | ${mrows.length}; every one except C16 (implementation process outside the application) has screenshot evidence in a deck |
| Speaker notes | every slide of every deck |
| Validator (pptx skill \`validate.py\`) | ${Object.values(valid).length ? Object.values(valid).every((v) => v === 'PASSED') ? 'PASSED for all decks' : 'see table' : 'see table'} |
| Distorted pictures | 0 (aspect ratio of every placed picture equals its image) |
| Empty slides | 0 |
| Secrets in screenshots or documents | none found (operator QR code and key covered before capture; no token, link, password or real personal data) |

## 2. Per presentation

| Deck | Slides | Screenshots used | Screenshot placements | Notes | Conceptual-diagram labels | Size | Validator |
|---|---|---|---|---|---|---|---|
`;
for (const [k, b] of Object.entries(build).sort()) {
    const c = check[b.file] ?? {};
    md += `| ${b.file} | ${c.slides} | ${usage[k]?.length ?? 0} | ${c.screenshot_placements} | ${c.slides_with_notes}/${c.slides} | ${c.conceptual_diagram_slides} | ${c.size_mb} MB | ${valid[b.file] ?? 'not run'} |\n`;
}

const byStatus = {};
for (const r of mrows) (byStatus[r[3]] ??= []).push(`${r[1]} ${r[2]}`);
md += `
## 3. Workflow coverage

Major workflows documented (feature matrix rows): **${mrows.length}**. Status after capture:

| Status | Count | Workflows |
|---|---|---|
${Object.entries(byStatus).map(([s, l]) => `| ${s} | ${l.length} | ${l.join('; ')} |`).join('\n')}

Workflows without screenshot evidence: **C16** (UAT, go-live, hypercare and handover are process stages outside the
application; Deck 04 and Deck 11 cover them with stage cards, checklists and the supporting workbooks).

Workflows executed end to end on the training database (each state shown happened): leave request → manager approval
and rejection → employee result; attendance check-in, correction, approval, manual punch and day processing; hire with
duplicate check; transfer and manager change; onboarding task; document upload and second-person verification; leave
balance adjustment; letters with approval; asset assignment; HR request handling; exit with clearance, full & final
settlement and alumni portal; four-person compensation change; payroll recalculation and refused validation; report
run, export and audit; tenant provisioning, activation and configuration from empty (Northwind Retail); a second
internal tenant (Markedge); tenant suspension and reactivation; legal hold; password reset; employee self-service
(HR request, goal check-in, self review, course start, acknowledgements); manager one-on-one and feedback.

## 4. Rendering and file-integrity checks

1. **Exist and open:** all 12 files open with python-pptx (\`tools/build/check.py\`) and with LibreOffice.
2. **Real content:** every deck has a cover, learning objectives, status slides, sections, screenshot slides, exercises,
   an answer key and a summary; no empty slide.
3. **Screenshots embedded:** ${Object.values(check).reduce((a, c) => a + c.screenshot_placements, 0)} placements; JPEG copies (max 1920 px) of the PNG originals, cropped where
   noted ("Real screen (cropped)"); originals kept in \`screenshots/\`.
4. **Validator:** the pptx skill's \`office/validate.py\` (schema, relationships, content types, slide checks).
5. **Rendering:** every deck converted to PDF with LibreOffice and rasterised (\`tools/.work/render.sh\`); every slide
   inspected in two review passes (section 5).
6. **Editability:** titles use layout placeholders; text, callout badges and boxes, tables and diagrams are native
   shapes; screenshots are pictures; theme colours are written into the theme (PeopleOS palette).
7. **Notes:** every slide has speaker notes (what to say, what learners should see, evidence, what to avoid).
8. **Fictional data:** all people, companies and tenants are invented; e-mail domains are .local, .example or .test.

## 5. Visual review passes

**Pass 1** (five independent reviewers, every slide of the build at that time, before later consolidation to the
final ${Object.values(check).reduce((a, c) => a + c.slides, 0)} slides): findings included steps without markers and markers without
steps, compare slides without status pill or source line, captions describing parts of a page outside the crop,
step text overprinting on some slides, small guest-page cards, a slide captioned as success that showed a development
error page, two screenshots taken before the screen refreshed, a too-broad claim about small-group suppression, and
several badges covering labels. All High findings and the systematic Medium findings were fixed in the generator
(markers only for referenced steps, generated steps from recorded labels, outline-free comparisons with per-side pills
and source lines, content cropping of guest pages, conservative text fitting, connector-length limits) or in the deck
content; two screens were re-captured (\`tools/capture/98-recapture.mjs\`).

**Pass 2** (three reviewers, all decks re-rendered): confirmed the pass-1 fixes and reported remaining items (mostly
Medium: individual badge positions, captions naming rows outside a crop, two toasts too small). These were fixed and
the affected decks rebuilt; see section 6 for what remains.

## 6. Known limitations of the decks

- LibreOffice renders Calibri and Cambria with metric-compatible substitutes; PowerPoint should match, but check the
  densest slides (stage cards, tables) once in PowerPoint before a live session.
- Comparison slides show two screenshots at half width: overall layout is readable; small text inside them is
  described in the caption rather than read from the image.
- Callout badges are placed automatically; on a few dense screens a badge may still sit close to a label.
- Some screenshots intentionally show development-mode error pages (labelled as defects). In production the user sees a
  generic server-error dialog instead.
- The Give feedback error toast (F17) shows local database connection details from the disposable environment; no
  secret is shown.
- Screens were captured at 1440 × 900 in Chromium. Safari, Android Chrome and real devices were not used.

## 7. Unverified, incomplete, disabled or unimplemented features shown as such

See section 3 and \`FEATURE-TO-PRESENTATION-MATRIX.md\`. Highlights: payroll finalisation, payslips and statutory returns
(disabled until 24 statutory rules are verified; 0 verified); employee and punch imports (F25); Apply on behalf (F38);
report builder (F44); executive dataset permissions (F45); Give feedback (F17); notification rule creation (F18);
public signup, billing, payments and tenant deletion (disabled by gates); data export generation, integration round
trips and statutory rule verification (not exercised); gratuity, SAML, native mobile app, SMS/WhatsApp delivery (not
implemented). New findings during capture are in the accuracy register (D14–D30).

## 7a. Remaining blockers (product, not the training library)

The PeopleOS release gate is **NO-GO** (10 of 14 gates blocked; \`docs/saas/SaaS-Final-Release-Gate.md\`): credential
remediation, operator MFA in the target, independent security review, statutory verification and gratuity, backup and
DR, hosting and on-call, staging validation, owner decisions, deployment rehearsal. Every deck states this.
`;
fs.writeFileSync(path.join(ROOT, 'QA-REPORT.md'), md);
console.log('QA-REPORT.md written');
