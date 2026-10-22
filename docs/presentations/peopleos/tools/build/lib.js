// PeopleOS training decks: shared design system and slide builders (pptxgenjs).
//
// Every screenshot slide uses a GENUINE capture from screenshots/ (see tools/capture). Callouts are drawn here as
// editable PowerPoint shapes from the element boxes recorded at capture time (tools/manifests/*.json), so the original
// screenshot files stay unannotated. Diagrams are drawn with shapes and always carry a "Conceptual diagram" label.
'use strict';
const fs = require('fs');
const path = require('path');
const pptxgen = require('pptxgenjs');
const sharp = require('sharp');
const JSZip = require(require.resolve('jszip', { paths: [require.resolve('pptxgenjs')] }));

const ROOT = path.resolve(__dirname, '../..');                 // docs/presentations/peopleos
const SHOTS = path.join(ROOT, 'screenshots');
const DERIVED = path.join(__dirname, '../.work/derived');
const MANIFESTS = path.join(__dirname, '../manifests');

// ---------------------------------------------------------------- design tokens
const THEME = {
    name: 'PeopleOS Training',
    headFontFace: 'Cambria',
    bodyFontFace: 'Calibri',
    colors: {
        dk1: '1F2433', lt1: 'FFFFFF', dk2: '141A30', lt2: 'F4F5F8',
        accent1: '574BC4', accent2: 'C9A45C', accent3: '1E7F4F', accent4: 'B3261E', accent5: '2F6FB3', accent6: 'D97706',
        hlink: '574BC4', folHlink: '3E3594',
    },
};
const HEX = {
    navy: '141A30', ink: '1F2433', muted: '5B6275', faint: '8A90A2', line: 'D9DCE3', soft: 'F4F5F8', soft2: 'ECEAFB',
    primary: '574BC4', primaryDark: '3E3594', gold: 'C9A45C', goldLight: 'E6C98F', callout: 'D97706', white: 'FFFFFF',
    green: '1E7F4F', red: 'B3261E', blue: '2F6FB3', grey: '6B7280',
};
const STATUS = {
    'VERIFIED IN RUNNING APPLICATION': { fill: 'E3F3EA', text: HEX.green, short: 'Verified in the running application', tiny: 'Verified' },
    'IMPLEMENTED BUT NOT VERIFIED END-TO-END': { fill: 'ECEAFB', text: HEX.primaryDark, short: 'Implemented · not verified end to end', tiny: 'Not verified end to end' },
    'AVAILABLE ONLY AFTER CONFIGURATION': { fill: 'E4EEF8', text: HEX.blue, short: 'Available only after configuration', tiny: 'After configuration' },
    'DISABLED BY SAFETY OR COMMERCIAL GATES': { fill: 'FDF0DD', text: '8A5A00', short: 'Disabled by safety or commercial gates', tiny: 'Disabled (gate)' },
    INCOMPLETE: { fill: 'FBE6E4', text: HEX.red, short: 'Incomplete · known defect or gap', tiny: 'Incomplete' },
    'NOT IMPLEMENTED': { fill: 'EEEFF2', text: HEX.grey, short: 'Not implemented', tiny: 'Not implemented' },
    'UNABLE TO VERIFY': { fill: 'EEEFF2', text: HEX.grey, short: 'Unable to verify', tiny: 'Unable to verify' },
};
const W = 13.333, H = 7.5, MX = 0.6;
const RELEASE_LINE = 'Release gate: NO-GO (10 of 14 gates blocked, re-verified 13 Oct 2026). Not yet generally available.';

// ---------------------------------------------------------------- manifest + images
let MANIFEST = null;
function manifest() {
    if (MANIFEST) return MANIFEST;
    MANIFEST = {};
    for (const f of fs.readdirSync(MANIFESTS).filter((f) => f.endsWith('.json'))) {
        for (const e of JSON.parse(fs.readFileSync(path.join(MANIFESTS, f), 'utf8'))) MANIFEST[e.name] = { ...e, area: f.replace('.json', '') };
    }
    return MANIFEST;
}
function entry(name) {
    const e = manifest()[name];
    if (!e) throw new Error(`No manifest entry for screenshot "${name}"`);
    if (!fs.existsSync(path.join(SHOTS, `${name}.png`))) throw new Error(`Screenshot file missing: ${name}.png`);
    return e;
}

function has(name) { return !!manifest()[name] && fs.existsSync(path.join(SHOTS, `${name}.png`)); }
/** Find screenshots whose name matches a pattern (RegExp), sorted. */
function find(re) { return Object.keys(manifest()).filter((n) => re.test(n) && has(n)).sort(); }

/** Derived JPEG for embedding (original PNG untouched). crop = {x,y,w,h} in image px. Returns {file, w, h}. */
/** Height of a tall page capture once trailing empty background is trimmed (sidebar ignored). */
async function contentHeight(src, meta) {
    if (meta.height <= 1400) return meta.height;
    const { data, info } = await sharp(src).removeAlpha().raw().toBuffer({ resolveWithObject: true });
    const W = info.width, ch = info.channels;
    const px = (x, y) => { const i = (y * W + x) * ch; return [data[i], data[i + 1], data[i + 2]]; };
    const bg = px(W - 4, info.height - 4);
    const x0 = Math.min(560, Math.floor(W * 0.27)), step = 3;
    for (let y = info.height - 1; y > 1350; y--) {
        for (let x = x0; x < W - 8; x += step) {
            const c = px(x, y);
            if (Math.abs(c[0] - bg[0]) + Math.abs(c[1] - bg[1]) + Math.abs(c[2] - bg[2]) > 24) return Math.min(info.height, y + 40);
        }
    }
    return 1350;
}

/** x where the dark navigation sidebar ends (0 if none found). */
async function sidebarEdge(src) {
    const { data, info } = await sharp(src).removeAlpha().raw().toBuffer({ resolveWithObject: true });
    const y = Math.min(600, Math.floor(info.height / 2));
    const dark = (x) => { const i = (y * info.width + x) * 3; return data[i] + data[i + 1] + data[i + 2] < 180; };
    if (!dark(10) || !dark(60)) return 0;
    for (let x = 60; x < 800; x++) if (!dark(x) && !dark(Math.min(x + 4, info.width - 1))) return x + 1;
    return 0;
}

/** Bounding box of everything that differs from the page background (corner pixel), padded; null if empty. */
async function contentBox(src) {
    const { data, info } = await sharp(src).removeAlpha().raw().toBuffer({ resolveWithObject: true });
    const W = info.width, H = info.height, px = (x, y) => (y * W + x) * 3;
    const b = px(W - 3, H - 3);
    let x0 = W, y0 = H, x1 = 0, y1 = 0;
    for (let y = 0; y < H; y += 3) for (let x = 0; x < W; x += 3) {
        const i = px(x, y);
        if (Math.abs(data[i] - data[b]) + Math.abs(data[i + 1] - data[b + 1]) + Math.abs(data[i + 2] - data[b + 2]) > 30) { if (x < x0) x0 = x; if (y < y0) y0 = y; if (x > x1) x1 = x; if (y > y1) y1 = y; }
    }
    if (x1 <= x0 || y1 <= y0) return null;
    const pad = 60;
    x0 = Math.max(0, x0 - pad); y0 = Math.max(0, y0 - pad); x1 = Math.min(W - 1, x1 + pad); y1 = Math.min(H - 1, y1 + pad);
    return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
}

async function derive(name, crop, maxAspect = 0.95) {
    const src = path.join(SHOTS, `${name}.png`);
    if (!crop) {
        const m0 = await sharp(src).metadata();
        const ch = await contentHeight(src, m0);
        if (ch < m0.height - 20) crop = { x: 0, y: 0, w: m0.width, h: ch };
        // Tall full-page captures: drop the navigation sidebar (355 CSS px) so the form is larger on the slide.
        const hh = crop?.h ?? m0.height;
        if (m0.width === 2160 && hh / m0.width > 0.72) {
            const edge = await sidebarEdge(src);
            if (edge) crop = { x: edge, y: 0, w: m0.width - edge, h: hh };
        }
        // Pages without the navigation sidebar (sign-in, MFA, invitation, reset): crop to the card and any toast.
        if (!crop && m0.width === 2160 && !(await sidebarEdge(src))) {
            const bb = await contentBox(src);
            if (bb && bb.w * bb.h < 0.5 * m0.width * m0.height) { crop = bb; maxAspect = 9; }   // keep the whole card
        }
        // Very tall pages: show the top of the page at a readable size (the full original stays in screenshots/).
        if (crop && crop.h / crop.w > maxAspect) crop = { ...crop, h: Math.round(crop.w * maxAspect) };
        else if (!crop && m0.height / m0.width > maxAspect) crop = { x: 0, y: 0, w: m0.width, h: Math.round(m0.width * maxAspect) };
    }
    const key = crop ? `${name.replace(/\//g, '__')}__${crop.x}_${crop.y}_${crop.w}_${crop.h}` : name.replace(/\//g, '__');
    const out = path.join(DERIVED, `${key}.jpg`);
    fs.mkdirSync(DERIVED, { recursive: true });
    const meta = await sharp(src).metadata();
    let w = meta.width, h = meta.height;
    if (crop) { w = Math.min(crop.w, meta.width - crop.x); h = Math.min(crop.h, meta.height - crop.y); }
    if (!fs.existsSync(out) || fs.statSync(out).mtimeMs < fs.statSync(src).mtimeMs) {
        let img = sharp(src);
        if (crop) img = img.extract({ left: crop.x, top: crop.y, width: w, height: h });
        const maxW = 1920;
        if (w > maxW) img = img.resize({ width: maxW });
        await img.jpeg({ quality: 86, chromaSubsampling: '4:4:4', mozjpeg: true }).toFile(out);
    }
    return { file: out, w, h, crop };
}

// ---------------------------------------------------------------- text fit estimate (Calibri ≈ 0.5 em per char)
const WARN = [];
function estLines(text, wIn, pt) {
    const cpl = Math.max(8, Math.floor((wIn * 72) / (pt * 0.54)));
    return String(text).split('\n').reduce((n, para) => n + Math.max(1, Math.ceil(para.length / cpl)), 0);
}
function checkFit(where, text, wIn, hIn, pt, spacing = 1.2) {
    const need = (estLines(text, wIn - 0.1, pt) * pt * spacing) / 72 + 0.08;
    if (need > hIn + 0.02) WARN.push(`${where}: text may overflow (${need.toFixed(2)}in needed, ${hIn.toFixed(2)}in box): "${String(text).slice(0, 60)}…"`);
}

// ---------------------------------------------------------------- deck
class Deck {
    constructor(meta) {
        this.meta = meta;                   // { num, file, title, subtitle, short, audience, cover: shotName }
        this.pres = new pptxgen();
        this.pres.layout = 'LAYOUT_WIDE';
        this.pres.author = 'Markedge PeopleOS training';
        this.pres.company = 'Markedge Technologies';
        this.pres.title = `PeopleOS · ${meta.title}`;
        this.pres.subject = meta.subtitle ?? meta.title;
        this.pres.theme = { headFontFace: THEME.headFontFace, bodyFontFace: THEME.bodyFontFace };
        this.C = this.pres.SchemeColor;
        this.n = 0;
        this.section_ = null;
        this.sectionIndex = 0;
        this.used = new Set();              // screenshots used in this deck
        this.queue = [];                    // async slide builders, run in order on save()
        this.defineLayouts();
    }

    defineLayouts() {
        const p = this.pres, C = this.C, short = this.meta.short;
        const mark = (ink, gold, tail) => [
            { text: { text: 'People', options: { x: MX, y: 7.02, w: 0.5, h: 0.28, fontSize: 10, bold: true, color: ink, fontFace: THEME.headFontFace, margin: 0, valign: 'middle', isTextBox: true } } },
            { text: { text: 'OS', options: { x: MX + 0.475, y: 7.02, w: 0.3, h: 0.28, fontSize: 10, bold: true, color: gold, fontFace: THEME.headFontFace, margin: 0, valign: 'middle', isTextBox: true } } },
            { text: { text: `Markedge PeopleOS training  ·  ${short}`, options: { x: MX + 0.9, y: 7.02, w: 7.5, h: 0.28, fontSize: 9, color: tail, margin: 0, valign: 'middle', isTextBox: true } } },
        ];
        const footer = mark(C.text2, C.accent2, HEX.muted);
        p.defineSlideMaster({
            title: 'PEOPLEOS_CONTENT', background: { color: HEX.white },
            objects: [
                ...footer,
                { placeholder: { options: { name: 'eyebrow', type: 'body', x: MX, y: 0.32, w: 9.5, h: 0.3, fontSize: 11, bold: true, color: C.accent1, charSpacing: 1.5, margin: 0, valign: 'middle', align: 'left' }, text: '' } },
                { placeholder: { options: { name: 'title', type: 'title', x: MX, y: 0.6, w: W - 2 * MX, h: 0.78, fontSize: 28, bold: true, color: C.text2, fontFace: THEME.headFontFace, margin: 0, valign: 'middle', align: 'left' }, text: '' } },
            ],
            slideNumber: { x: W - MX - 0.6, y: 7.02, w: 0.6, h: 0.28, fontSize: 9, color: HEX.muted, align: 'right' },
        });
        p.defineSlideMaster({
            title: 'PEOPLEOS_DARK', background: { color: HEX.navy },
            objects: [
                ...mark('FFFFFF', HEX.goldLight, 'AEB3C7'),
                { placeholder: { options: { name: 'title', type: 'title', x: MX, y: 2.55, w: 7.7, h: 1.5, fontSize: 40, bold: true, color: 'FFFFFF', fontFace: THEME.headFontFace, margin: 0, valign: 'top', align: 'left' }, text: '' } },
            ],
            slideNumber: { x: W - MX - 0.6, y: 7.02, w: 0.6, h: 0.28, fontSize: 9, color: 'AEB3C7', align: 'right' },
        });
    }

    // ------------------------------------------------------------ helpers
    slide(kind = 'content', notes = '') {
        this.n += 1;
        const s = this.pres.addSlide({ masterName: kind === 'dark' ? 'PEOPLEOS_DARK' : 'PEOPLEOS_CONTENT', sectionTitle: this.section_ ?? 'Introduction' });
        if (notes) s.addNotes(notes);
        return s;
    }
    head(s, title, eyebrow) {
        s.addText((eyebrow ?? this.section_ ?? '').toUpperCase(), { placeholder: 'eyebrow' });
        s.addText(title, { placeholder: 'title' });
        if (title.length > 62) WARN.push(`${this.meta.num}#${this.n}: long title (${title.length} chars): ${title}`);
    }
    text(s, text, o) {
        s.addText(text, { isTextBox: true, margin: 0, fontFace: THEME.bodyFontFace, color: this.C.text1, valign: 'top', ...o });
        if (typeof text === 'string' && o.w && o.h && o.fontSize) checkFit(`${this.meta.num}#${this.n}`, text, o.w, o.h, o.fontSize);
    }
    pill(s, label, x, y, w = 3.4) {
        const st = STATUS[label] ?? STATUS['UNABLE TO VERIFY'];
        s.addText(st.short, { x, y, w, h: 0.32, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.16, fill: { color: st.fill }, color: st.text, fontSize: 10.5, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Status' });
    }
    badge(s, n, x, y, d = 0.32, color = HEX.callout) {
        s.addText(String(n), { x, y, w: d, h: d, shape: this.pres.shapes.OVAL, fill: { color }, line: { color: 'FFFFFF', width: 1.5 }, color: 'FFFFFF', bold: true, fontSize: d > 0.3 ? 13 : 11, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: `Callout ${n}` });
    }

    /** Place a screenshot inside box (contain), draw callouts. opts: crop, callouts (array of numbers to show | false), frame */
    async image(s, name, box, opts = {}) {
        const e = entry(name);
        this.used.add(name);
        const d = await derive(name, opts.crop, opts.maxAspect);
        const r = Math.min(box.w / d.w, box.h / d.h);
        const w = d.w * r, h = d.h * r;
        const x = box.x + (opts.align === 'left' ? 0 : (box.w - w) / 2), y = box.y + (opts.valign === 'middle' ? (box.h - h) / 2 : 0);
        s.addImage({ path: d.file, x, y, w, h, altText: `Screenshot: ${e.screen} (${e.user}, fictional data)`, objectName: `Screenshot ${name}` });
        s.addShape(this.pres.shapes.RECTANGLE, { x, y, w, h, line: { color: HEX.line, width: 0.75 }, fill: { type: 'none' }, objectName: 'Screenshot frame' });
        const show = opts.callouts === false ? [] : (e.callouts ?? []).filter((c) => !opts.callouts || opts.callouts.map(String).includes(String(c.n)));
        const ox = d.crop?.x ?? 0, oy = d.crop?.y ?? 0;
        const renumber = opts.renumber ?? {};
        const boxes = [];
        for (const c of show) {
            const cx = c.x - ox, cy = c.y - oy;
            if (cx < -6 || cy < -6 || cx + c.w > d.w + 6 || cy + c.h > d.h + 6) continue;   // only callouts fully inside the picture
            const bx = x + Math.max(0, cx) * r, by = y + Math.max(0, cy) * r;
            const bw = Math.min(c.w, d.w - Math.max(0, cx)) * r, bh = Math.min(c.h, d.h - Math.max(0, cy)) * r;
            boxes.push({ c, bx, by, bw: Math.max(bw, 0.12), bh: Math.max(bh, 0.1) });
        }
        const dd = 0.28, placed = [];
        const slot = { x: Math.min(box.x, x), y: Math.min(box.y, y), w: Math.max(box.w, w), h: Math.max(box.h, h) };
        const hits = (px, py, own) => [...boxes.filter((o) => o !== own).map((o) => ({ x: o.bx, y: o.by, w: o.bw, h: o.bh })), ...placed]
            .some((o) => px < o.x + o.w && px + dd > o.x && py < o.y + o.h && py + dd > o.y);
        const within = (px, py) => px >= slot.x - 0.02 && py >= slot.y && px + dd <= slot.x + slot.w + 0.02 && py + dd <= slot.y + slot.h;
        for (const o of boxes) {
            s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: o.bx, y: o.by, w: o.bw, h: o.bh, rectRadius: 0.05, line: { color: HEX.callout, width: 2 }, fill: { type: 'none' }, objectName: `Callout box ${o.c.n}` });
        }
        const drawn = boxes.map((o) => String(renumber[o.c.n] ?? o.c.n));
        if (opts.badges === false) return { x, y, w, h, entry: e, cropped: !!d.crop, drawn };
        for (const o of boxes) {
            const cy = o.by + (o.bh - dd) / 2;
            const left = [o.bx - dd - 0.06, cy], leftMargin = [x - dd - 0.1, cy], right = [o.bx + o.bw + 0.06, cy], rightMargin = [x + w + 0.1, cy];
            const aboveL = [o.bx - dd * 0.5, o.by - dd - 0.04], aboveR = [o.bx + o.bw - dd * 0.5, o.by - dd - 0.04], corner = [o.bx + 0.04, o.by + 0.04];
            // Small targets (buttons, chips): above first, so neighbouring controls on the same row stay visible.
            // Wide targets (fields, rows): beside them, so the label above stays readable.
            const small = o.bw < 1.6 && o.bw < 4 * o.bh;
            const below = [o.bx + (o.bw - dd) / 2, o.by + o.bh + 0.05], belowL = [o.bx - dd * 0.5, o.by + o.bh + 0.04];
            const named = { left, leftMargin, right, rightMargin, above: aboveL, aboveR, below, belowL, corner };
            const pref = opts.place?.[String(o.c.n)];
            const rightInside = [o.bx + o.bw - dd - 0.3, cy];
            let cands = small ? [aboveL, aboveR, left, leftMargin, below, rightMargin, right, corner] : [left, leftMargin, rightInside, right, rightMargin, aboveL, aboveR, below, corner];
            // A badge far from its target needs a long connector that would cross other content: skip those.
            const near = ([px]) => (px + dd < o.bx ? o.bx - (px + dd) : px > o.bx + o.bw ? px - (o.bx + o.bw) : 0) < 0.9;
            cands = cands.filter(near);
            if (pref && named[pref]) cands = [named[pref], ...cands];
            const pos = cands.find(([px, py]) => within(px, py) && !hits(px, py, o)) ?? cands.find(([px, py]) => within(px, py)) ?? [o.bx, o.by];
            placed.push({ x: pos[0], y: pos[1], w: dd, h: dd });
            const outside = pos[0] + dd <= x || pos[0] >= x + w;
            const gapL = pos[0] + dd < o.bx, gapR = pos[0] > o.bx + o.bw;
            if (outside && (gapL || gapR)) {
                const x1 = gapL ? pos[0] + dd : o.bx + o.bw, x2 = gapL ? o.bx : pos[0], yy = pos[1] + dd / 2;
                if (x2 - x1 > 0.04) s.addShape(this.pres.shapes.LINE, { x: x1, y: yy, w: x2 - x1, h: 0, line: { color: HEX.callout, width: 1.25 }, objectName: `Callout connector ${o.c.n}` });
            }
            this.badge(s, renumber[o.c.n] ?? o.c.n, pos[0], pos[1], dd);
        }
        return { x, y, w, h, entry: e, cropped: !!d.crop, drawn };
    }

    /** Small status pill with the short label (for compare columns). */
    pillSmall(s, label, x, y, w = 2.3) {
        const st = STATUS[label] ?? STATUS['UNABLE TO VERIFY'];
        s.addText(st.tiny, { x, y, w, h: 0.28, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.14, fill: { color: st.fill }, color: st.text, fontSize: 9.5, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Status' });
    }
    sourceLine(e, cropped) { return `Real screen${cropped ? ' (cropped)' : ''} · ${e.user} (${e.role}) · ${e.screen}`; }

    // ------------------------------------------------------------ slide types
    cover({ shot, notes }) {
        const m = this.meta;
        this.queue.push(async () => {
            this.section_ = 'Introduction';
            this.pres.addSection({ title: 'Introduction' });
            const s = this.slide('dark', notes);
            s.addText('MARKEDGE PEOPLEOS  ·  TRAINING LIBRARY', { x: MX, y: 0.7, w: 7, h: 0.3, fontSize: 12, bold: true, color: HEX.goldLight, charSpacing: 2, margin: 0, isTextBox: true });
            s.addText(`Deck ${String(m.num).padStart(2, '0')} of 12`, { x: MX, y: 1.05, w: 4, h: 0.3, fontSize: 12, color: 'AEB3C7', margin: 0, isTextBox: true });
            s.addText(m.title, { placeholder: 'title' });
            this.text(s, m.subtitle, { x: MX, y: 4.15, w: 7.3, h: 1.0, fontSize: 18, color: 'D5D8E6' });
            this.text(s, `For: ${m.audience}\nBuilt from the running application (local, disposable database, fictional people) · 21 October 2026`, { x: MX, y: 5.3, w: 7.4, h: 1.05, fontSize: 12, color: 'AEB3C7' });
            if (shot) {
                const box = { x: 8.55, y: 1.25, w: 4.2, h: 5.2 };
                await this.image(s, shot, box, { callouts: false, valign: 'middle' });
                s.addText('Real screen, fictional data', { x: 8.55, y: 6.5, w: 4.2, h: 0.28, fontSize: 10, color: 'AEB3C7', align: 'center', margin: 0, isTextBox: true });
            }
        });
    }

    section(num, title, blurb, notes) {
        this.queue.push(async () => {
            this.section_ = title;
            this.sectionIndex += 1;
            this.pres.addSection({ title });
            const s = this.slide('dark', notes ?? `TRANSITION: Section ${num}, ${title}.${blurb ? ' ' + blurb : ''}\nSAY: Tell learners what they will be able to do by the end of this section, then move on.\nASK: "What questions do you have before we start this section?"`);
            s.addText(String(num).padStart(2, '0'), { x: MX, y: 1.4, w: 2.2, h: 1.0, fontSize: 54, bold: true, color: HEX.goldLight, fontFace: THEME.headFontFace, margin: 0, isTextBox: true });
            s.addText(title, { placeholder: 'title' });
            if (blurb) this.text(s, blurb, { x: MX, y: 4.2, w: 8.6, h: 1.4, fontSize: 18, color: 'D5D8E6' });
        });
    }

    /** Bulleted list + optional right-hand visual (screenshot or callout box). */
    bullets({ title, eyebrow, items, shot, crop, side, notes, cols = 1 }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: ${title}. ${items.map((i) => (Array.isArray(i) ? i.join(': ') : i)).join(' ')}`);
            this.head(s, title, eyebrow);
            const wText = shot || side ? 6.2 : W - 2 * MX;
            const arr = items.map((it, i) => {
                const [b, rest] = Array.isArray(it) ? it : [null, it];
                const runs = b ? [{ text: b + '  ', options: { bold: true, color: this.C.text2 } }, { text: rest }] : [{ text: rest }];
                return runs.map((r, j) => ({ text: r.text, options: { ...(r.options ?? {}), bullet: j === 0 ? { indent: 18 } : undefined, breakLine: j === runs.length - 1 && i < items.length - 1, paraSpaceAfter: 8 } }));
            }).flat();
            s.addText(arr, { x: MX, y: 1.6, w: wText, h: 5.2, fontSize: 16, color: this.C.text1, valign: 'top', margin: 0, isTextBox: true });
            checkFit(`${this.meta.num}#${this.n}`, items.map((i) => (Array.isArray(i) ? i.join('  ') : i)).join('\n'), wText - 0.3, 5.2, 16, 1.45);
            if (shot) await this.image(s, shot, { x: 7.1, y: 1.6, w: 5.63, h: 5.2 }, { crop, valign: 'top' });
            if (side) this.sideBox(s, side, { x: 7.1, y: 1.6, w: 5.63, h: 5.2 });
        });
    }

    sideBox(s, side, b) {
        s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: b.x, y: b.y, w: b.w, h: b.h, rectRadius: 0.12, fill: { color: HEX.soft }, line: { color: HEX.soft } });
        this.text(s, side.title, { x: b.x + 0.3, y: b.y + 0.25, w: b.w - 0.6, h: 0.45, fontSize: 18, bold: true, color: this.C.text2, fontFace: THEME.headFontFace });
        this.text(s, side.body, { x: b.x + 0.3, y: b.y + 0.8, w: b.w - 0.6, h: b.h - 1.0, fontSize: 14, color: this.C.text1 });
    }

    /** Learning objectives / agenda: numbered cards. */
    numbered({ title, eyebrow, items, notes, intro }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: ${title}:\n${items.map((it, i) => `${i + 1}. ${Array.isArray(it) ? it.filter(Boolean).join(': ') : it}`).join('\n')}\nASK: "Which of these matters most to you?" Return to the answer in the summary.`);
            this.head(s, title, eyebrow);
            let y0 = 1.6;
            if (intro) { this.text(s, intro, { x: MX, y: 1.55, w: W - 2 * MX, h: 0.5, fontSize: 15, color: HEX.muted }); y0 = 2.15; }
            const cols = items.length > 4 ? 2 : 1;
            const rows = Math.ceil(items.length / cols);
            const gap = 0.18;
            const cw = (W - 2 * MX - (cols - 1) * 0.3) / cols;
            const ch = Math.min(1.05, (6.85 - y0 - (rows - 1) * gap) / rows);
            items.forEach((it, i) => {
                const col = Math.floor(i / rows), row = i % rows;
                const x = MX + col * (cw + 0.3), y = y0 + row * (ch + gap);
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x, y, w: cw, h: ch, rectRadius: 0.1, fill: { color: HEX.soft }, line: { color: HEX.soft } });
                this.badge(s, i + 1, x + 0.2, y + (ch - 0.42) / 2, 0.42, HEX.primary);
                const [head, body] = Array.isArray(it) ? it : [it, ''];
                const runs = [{ text: head, options: { bold: true, color: this.C.text2, breakLine: !!body } }];
                if (body) runs.push({ text: body, options: { color: HEX.muted, fontSize: 13 } });
                s.addText(runs, { x: x + 0.8, y: y + 0.08, w: cw - 1.0, h: ch - 0.16, fontSize: 15, valign: 'middle', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n}`, `${head}\n${body}`, cw - 1.0, ch - 0.16, 14);
            });
        });
    }

    /** Workflow purpose slide (teaching pattern A): goal, who, where, before you start, and the sequence. */
    purpose({ title, eyebrow, goal, who, where, before, flow, notes, status }) {
        this.queue.push(async () => {
            const auto = `SAY: ${goal}\nWHO: ${who}\nWHERE: ${where}\nBEFORE YOU START: ${before}\nSEQUENCE: ${(flow ?? []).map((f) => (Array.isArray(f) ? f.join(' ') : f)).join(' → ')}`;
            const s = this.slide('content', notes ? `${auto}\n${notes}` : auto);
            this.head(s, title, eyebrow ?? 'Purpose');
            this.text(s, goal, { x: MX, y: 1.6, w: 7.6, h: 1.25, fontSize: 20, color: this.C.text2, fontFace: THEME.headFontFace });
            const cards = [['Who', who], ['Where', where], ['Before you start', before]].filter((c) => c[1]);
            const cw = (W - 2 * MX - 0.4) / 3;
            cards.forEach(([h, b], i) => {
                const x = MX + i * (cw + 0.2);
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x, y: 3.05, w: cw, h: 1.75, rectRadius: 0.1, fill: { color: HEX.soft }, line: { color: HEX.soft } });
                this.text(s, h.toUpperCase(), { x: x + 0.25, y: 3.2, w: cw - 0.5, h: 0.3, fontSize: 11, bold: true, color: this.C.accent1, charSpacing: 1 });
                this.text(s, b, { x: x + 0.25, y: 3.55, w: cw - 0.5, h: 1.15, fontSize: 14 });
            });
            if (status) this.pill(s, status, W - MX - 3.6, 1.65, 3.6);
            if (flow) { this.flowRow(s, flow, 5.15); s.addText('Conceptual diagram: sequence summary', { x: MX, y: 6.47, w: 4, h: 0.25, fontSize: 10, italic: true, color: HEX.faint, margin: 0, isTextBox: true }); }
        });
    }

    flowRow(s, steps, y, conceptual = false) {
        const n = steps.length, gap = 0.32;
        const bw = (W - 2 * MX - (n - 1) * gap) / n;
        steps.forEach((st, i) => {
            const [label, sub] = Array.isArray(st) ? st : [st, ''];
            const x = MX + i * (bw + gap);
            s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x, y, w: bw, h: 1.25, rectRadius: 0.1, fill: { color: i === 0 ? HEX.primary : HEX.white }, line: { color: HEX.primary, width: 1.25 } });
            s.addText([{ text: label, options: { bold: true, fontSize: 13, color: i === 0 ? 'FFFFFF' : this.C.text2, breakLine: !!sub } }, ...(sub ? [{ text: sub, options: { fontSize: 11, color: i === 0 ? 'E4E2F7' : HEX.muted } }] : [])],
                { x: x + 0.1, y: y + 0.08, w: bw - 0.2, h: 1.09, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
            checkFit(`${this.meta.num}#${this.n}`, `${label}\n${sub}`, bw - 0.2, 1.09, 12);
            if (i < n - 1) s.addShape(this.pres.shapes.RIGHT_ARROW, { x: x + bw + 0.05, y: y + 0.5, w: gap - 0.1, h: 0.25, fill: { color: HEX.gold }, line: { color: HEX.gold } });
        });
        if (conceptual) s.addText('Conceptual diagram, not a screenshot', { x: MX, y: y + 1.32, w: 5, h: 0.25, fontSize: 10, italic: true, color: HEX.faint, margin: 0, isTextBox: true });
    }

    /**
     * Screenshot step slide (teaching patterns B–E): screenshot left with callouts, numbered steps right.
     * steps: [string] (numbered to match callouts) ; result: string ; crop: {x,y,w,h} ; callouts: [n] | false
     */
    shot({ title, eyebrow, img, steps = [], result, notes, crop, callouts, renumber, status, tip, source, maxAspect, place }) {
        this.queue.push(async () => {
            const e = entry(img);
            const st = status ?? e.status ?? 'VERIFIED IN RUNNING APPLICATION';
            const s = this.slide('content', notes ?? this.autoNotes(e, steps, result));
            this.head(s, title, eyebrow);
            if (!steps.length && callouts !== false && !renumber) {
                const dv = await derive(img, crop, maxAspect);
                const ox = dv.crop?.x ?? 0, oy = dv.crop?.y ?? 0;
                const visible = (c) => c.x - ox >= -4 && c.y - oy >= -4 && c.x - ox + c.w <= dv.w + 4 && c.y - oy + c.h <= dv.h + 4;
                const cs = (e.callouts ?? []).filter((c) => c.label && visible(c) && (!callouts || callouts.map(String).includes(String(c.n)))).sort((a, b) => Number(a.n) - Number(b.n));
                renumber = Object.fromEntries(cs.map((c, i) => [String(c.n), i + 1]));
                steps = cs.map((c, i) => [i + 1, /[.?!]$/.test(c.label) ? c.label : c.label + '.']);
            }
            let stepNums = steps.map((t, i) => String(Array.isArray(t) ? t[0] : i + 1));
            // Which markers will really be drawn (inside the cropped picture)?
            const dv2 = await derive(img, crop, maxAspect);
            const ox2 = dv2.crop?.x ?? 0, oy2 = dv2.crop?.y ?? 0;
            const inside = (c) => c.x - ox2 >= -6 && c.y - oy2 >= -6 && c.x - ox2 + c.w <= dv2.w + 6 && c.y - oy2 + c.h <= dv2.h + 6;
            const rn = (n) => String((renumber ?? {})[n] ?? n);
            const wanted = (e.callouts ?? []).filter((c) => inside(c)).map((c) => String(c.n)).filter((n) => (!callouts || callouts.map(String).includes(n)) && stepNums.includes(rn(n)));
            // Display numbers run 1..k over the steps that have a marker; the others get a dot.
            const marked = new Set(wanted.map(rn));
            let k = 0; const seq = {};
            stepNums.forEach((sn) => { if (marked.has(sn) && !(sn in seq)) seq[sn] = ++k; });
            renumber = Object.fromEntries(wanted.map((n) => [n, seq[rn(n)]]));
            steps = steps.map((t, i) => { const sn = stepNums[i]; const txt = Array.isArray(t) ? t[1] : t; return [sn in seq ? seq[sn] : '·', txt]; });
            stepNums = steps.map((t) => String(t[0]));
            const im = await this.image(s, img, { x: MX, y: 1.55, w: 8.55, h: 5.3 }, { crop, callouts: callouts === false ? false : wanted, renumber, valign: 'top', maxAspect, place });
            const px = 9.4, pw = W - MX - px;
            this.pill(s, st, px, 1.55, pw);
            const txt = (t) => (Array.isArray(t) ? t[1] : t);
            const resH = result ? Math.max(0.6, (estLines('RESULT  ' + result, pw - 0.4, 12.5) * 12.5 * 1.22) / 72 + 0.26) : 0;
            let fs = 14;
            const heights = () => steps.map((t) => Math.max(0.4, (estLines(txt(t), pw - 0.55, fs) * fs * 1.22) / 72 + 0.12));
            let hs = heights();
            const room = 6.25 - 2.05 - (result ? resH + 0.15 : 0);
            if (hs.reduce((a, b) => a + b, 0) > room) { fs = 13; hs = heights(); }
            if (hs.reduce((a, b) => a + b, 0) > room) { fs = 12; hs = heights(); }
            if (hs.reduce((a, b) => a + b, 0) > room) WARN.push(`${this.meta.num}#${this.n}: steps do not fit (${title})`);
            let y = 2.05;
            steps.forEach((t, i) => {
                const num = Array.isArray(t) ? t[0] : i + 1;
                if (im.drawn.includes(String(num))) this.badge(s, num, px, y + 0.02, 0.32);
                else s.addShape(this.pres.shapes.OVAL, { x: px + 0.1, y: y + 0.1, w: 0.12, h: 0.12, fill: { color: HEX.faint }, line: { color: HEX.faint }, objectName: 'Bullet' });
                s.addText(txt(t), { x: px + 0.45, y, w: pw - 0.45, h: hs[i] - 0.04, fontSize: fs, color: this.C.text1, valign: 'top', margin: 0, isTextBox: true });
                y += hs[i];
            });
            if (result) {
                const boxY = Math.max(y + 0.1, 6.25 - resH - (steps.length ? 0 : 0));
                const by = steps.length ? Math.min(y + 0.1, 6.25 - resH) : 2.05;
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: px, y: by, w: pw, h: resH, rectRadius: 0.08, fill: { color: HEX.soft }, line: { color: HEX.soft } });
                s.addText([{ text: tip ? 'TIP  ' : 'RESULT  ', options: { bold: true, color: this.C.accent1, fontSize: 10.5, charSpacing: 1 } }, { text: result, options: { fontSize: 12.5, color: this.C.text1 } }],
                    { x: px + 0.15, y: by + 0.1, w: pw - 0.3, h: resH - 0.2, valign: 'top', margin: 0, isTextBox: true });
            }
            this.text(s, source ?? this.sourceLine(e, im.cropped), { x: px, y: 6.28, w: pw, h: 0.68, fontSize: 9, color: HEX.faint });
        });
    }

    /** Large screenshot with a caption strip below (for overview screens). */
    wide({ title, eyebrow, img, caption, notes, crop, callouts, status, legend, place }) {
        this.queue.push(async () => {
            const e = entry(img);
            const s = this.slide('content', notes ?? this.autoNotes(e, [], caption));
            this.head(s, title, eyebrow);
            const box = { x: MX, y: 1.5, w: legend ? 8.9 : W - 2 * MX, h: 4.8 };
            const im = await this.image(s, img, box, { crop, callouts: legend ? legend.map(([n]) => String(n)) : false, valign: 'top', align: legend ? 'left' : 'center', maxAspect: 0.64, place });
            if (legend) {
                const lx = MX + 9.15, lw = W - MX - lx;
                this.pill(s, status ?? e.status, lx, 1.5, lw);
                let y = 2.0;
                for (const [n, t] of legend) { this.badge(s, n, lx, y, 0.3); this.text(s, t, { x: lx + 0.42, y: y - 0.02, w: lw - 0.42, h: 0.62, fontSize: 13 }); y += 0.66; }
            }
            this.text(s, caption ?? '', { x: MX, y: 6.3, w: legend ? 8.9 : W - 2 * MX, h: 0.48, fontSize: 12.5, color: this.C.text1 });
            s.addText(this.sourceLine(e, im.cropped), { x: MX, y: 6.78, w: W - 2 * MX, h: 0.2, fontSize: 9, color: HEX.faint, margin: 0, isTextBox: true });
            if (!legend) this.pill(s, status ?? e.status, W - MX - 3.4, 0.3, 3.4);
        });
    }

    /** Two screenshots side by side (allowed vs restricted, before vs after). */
    compare({ title, eyebrow, left, right, notes }) {
        this.queue.push(async () => {
            const who = (x) => { const e = manifest()[x.img]; return e ? `${e.user} (${e.role}), ${e.screen}` : x.img; };
            const auto = `SAY: ${title}.\nLEFT, ${left.label}: ${left.caption}\nRIGHT, ${right.label}: ${right.caption}\nOBSERVE: point out what differs between the two screens.\nEVIDENCE: left = ${left.img} [${who(left)}]; right = ${right.img} [${who(right)}]. Real screens, fictional data (SCREENSHOT-INVENTORY.md).`;
            const s = this.slide('content', notes ? `${notes}\n${auto}` : auto);
            this.head(s, title, eyebrow);
            const cw = (W - 2 * MX - 0.4) / 2;
            for (const [i, side] of [left, right].entries()) {
                const x = MX + i * (cw + 0.4);
                const se = entry(side.img);
                s.addText(side.label, { x, y: 1.5, w: cw - 2.45, h: 0.36, fontSize: 13, bold: true, color: side.tone === 'bad' ? HEX.red : side.tone === 'good' ? HEX.green : this.C.text2, margin: 0, isTextBox: true });
                this.pillSmall(s, side.status ?? se.status, x + cw - 2.3, 1.52, 2.3);
                const im = await this.image(s, side.img, { x, y: 1.92, w: cw, h: 3.75 }, { crop: side.crop, callouts: false, badges: false, valign: 'top', maxAspect: side.maxAspect ?? 0.66 });
                this.text(s, side.caption, { x, y: 5.78, w: cw, h: 0.8, fontSize: 13 });
                s.addText(this.sourceLine(se, im.cropped), { x, y: 6.6, w: cw, h: 0.3, fontSize: 9, color: HEX.faint, margin: 0, valign: 'top', isTextBox: true });
            }
        });
    }

    /** Grid of cards: [{head, body, tone?}] — common mistakes, permissions, controls. */
    cards({ title, eyebrow, cards, notes, cols, intro }) {
        this.queue.push(async () => {
            const auto = `SAY: ${title}.${intro ? ' ' + intro : ''}\n${cards.map((c) => `- ${c.head}: ${c.body}`).join('\n')}\nASK: "Which of these points needs more explanation for your team?"`;
            const s = this.slide('content', notes ? `${notes}\n${auto}` : auto);
            this.head(s, title, eyebrow);
            let y0 = 1.6;
            if (intro) { this.text(s, intro, { x: MX, y: 1.5, w: W - 2 * MX, h: 0.62, fontSize: 15, color: HEX.muted }); y0 = 2.22; }
            const n = cards.length, c = cols ?? (n <= 3 ? n : n === 4 ? 2 : 3), rows = Math.ceil(n / c);
            const gap = 0.25, cw = (W - 2 * MX - (c - 1) * gap) / c, ch = (6.85 - y0 - (rows - 1) * gap) / rows;
            cards.forEach((cd, i) => {
                const x = MX + (i % c) * (cw + gap), y = y0 + Math.floor(i / c) * (ch + gap);
                const tone = { bad: 'FBE6E4', warn: 'FDF0DD', good: 'E3F3EA', info: HEX.soft2 }[cd.tone] ?? HEX.soft;
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x, y, w: cw, h: ch, rectRadius: 0.1, fill: { color: tone }, line: { color: tone } });
                const headLines = estLines(cd.head, cw - 0.6, 16);
                const hh = headLines > 1 ? 0.62 : 0.4;
                this.text(s, cd.head, { x: x + 0.25, y: y + 0.16, w: cw - 0.5, h: hh, fontSize: headLines > 1 ? 15 : 16, bold: true, color: this.C.text2 });
                const by = y + 0.2 + hh + 0.08, bh = y + ch - by - 0.12;
                const need = (estLines(cd.body, cw - 0.6, 14) * 14 * 1.2) / 72 + 0.08;
                const need16 = (estLines(cd.body, cw - 0.6, 16) * 16 * 1.2) / 72 + 0.08;
                this.text(s, cd.body, { x: x + 0.25, y: by, w: cw - 0.5, h: bh, fontSize: need > bh ? 12.5 : need16 < bh * 0.5 ? 16 : 14 });
            });
        });
    }

    /** Table; rows are arrays of strings; status cells (exact labels) are coloured. Splits automatically. */
    table({ title, eyebrow, columns, rows, colW, notes, fontSize = 13, rowsPerSlide = 8, intro }) {
        const pages = [];
        for (let i = 0; i < rows.length; i += rowsPerSlide) pages.push(rows.slice(i, i + rowsPerSlide));
        pages.forEach((page, pi) => this.queue.push(async () => {
            const auto = `SAY: ${title}${pages.length > 1 ? ` (part ${pi + 1} of ${pages.length})` : ''}. ${intro ?? ''}\n${page.map((r) => '- ' + r.map((c) => (STATUS[c] ? STATUS[c].short : c)).join(' | ')).join('\n')}`;
            const s = this.slide('content', notes ? `${notes}\n${auto}` : auto);
            this.head(s, pages.length > 1 ? `${title} (${pi + 1} of ${pages.length})` : title, eyebrow);
            let y = 1.55;
            if (intro && pi === 0) { this.text(s, intro, { x: MX, y: 1.48, w: W - 2 * MX, h: 0.62, fontSize: 14, color: HEX.muted }); y = 2.15; }
            const tw = W - 2 * MX;
            const widths = colW ?? columns.map(() => tw / columns.length);
            const head = columns.map((c) => ({ text: c, options: { bold: true, color: 'FFFFFF', fill: { color: HEX.navy }, fontSize: fontSize - 0.5 } }));
            const body = page.map((r, ri) => r.map((cell) => {
                const st = STATUS[cell];
                const gate = typeof cell === 'string' && /^(PASS|BLOCKED|FAIL)\b/.test(cell) ? (cell.includes('BLOCKED') && cell.includes('PASS') ? ['8A5A00', 'FDF0DD'] : cell.startsWith('PASS') ? [HEX.green, 'E3F3EA'] : [HEX.red, 'FBE6E4']) : null;
                if (gate) return { text: cell, options: { bold: true, color: gate[0], fill: { color: gate[1] }, fontSize: fontSize - 1 } };
                const ref = { 'Executed: matched expected': [HEX.green, 'E3F3EA'], 'Executed: known defect': [HEX.red, 'FBE6E4'], 'Executed in part': ['8A5A00', 'FDF0DD'], 'Not executed': [HEX.grey, 'EEEFF2'] }[cell];
                if (ref) return { text: cell, options: { bold: true, color: ref[0], fill: { color: ref[1] }, fontSize: fontSize - 1 } };
                return st ? { text: st.tiny, options: { bold: true, color: st.text, fill: { color: st.fill }, fontSize: fontSize - 1 } }
                    : { text: String(cell ?? ''), options: { fill: { color: ri % 2 ? HEX.soft : HEX.white } } };
            }));
            s.addTable([head, ...body], { x: MX, y, w: tw, colW: widths, fontSize, color: HEX.ink, border: { type: 'solid', pt: 0.5, color: HEX.line }, valign: 'middle', margin: [4, 6, 4, 6], rowH: 0.42, autoPage: false });
        }));
    }

    /** Conceptual diagram: layered boxes or a flow. Always labelled as conceptual. */
    diagram({ title, eyebrow, layers, flow, notes, caption }) {
        this.queue.push(async () => {
            const auto = `SAY: ${title}. ${caption ?? ''}\n${flow ? 'SEQUENCE: ' + flow.map((f) => (Array.isArray(f) ? f.join(' (') + ')' : f)).join(' → ') : ''}${layers ? 'LAYERS: ' + layers.map((l) => `${l.name}: ${(l.parts ?? []).join(', ')}`).join('; ') : ''}\nLABEL: conceptual diagram, not a screenshot.`;
            const s = this.slide('content', notes ? `${notes}\n${auto}` : auto);
            this.head(s, title, eyebrow);
            if (flow) this.flowRow(s, flow, 2.4, true);
            if (layers) {
                const lh = Math.min(0.95, (5.0 - (layers.length - 1) * 0.12) / layers.length);
                layers.forEach((L, i) => {
                    const y = 1.6 + i * (lh + 0.12);
                    s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: MX, y, w: 2.9, h: lh, rectRadius: 0.08, fill: { color: i === 0 ? HEX.primary : HEX.navy }, line: { color: HEX.navy } });
                    this.text(s, L.name, { x: MX + 0.15, y: y + 0.05, w: 2.6, h: lh - 0.1, fontSize: 14, bold: true, color: 'FFFFFF', valign: 'middle' });
                    const parts = L.parts ?? [];
                    const pw = (W - 2 * MX - 3.1 - (parts.length - 1) * 0.1) / Math.max(parts.length, 1);
                    parts.forEach((p, j) => {
                        const x = MX + 3.1 + j * (pw + 0.1);
                        s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x, y, w: pw, h: lh, rectRadius: 0.08, fill: { color: HEX.soft }, line: { color: HEX.line, width: 0.75 } });
                        this.text(s, p, { x: x + 0.08, y: y + 0.04, w: pw - 0.16, h: lh - 0.08, fontSize: parts.length > 4 ? 11 : 12, align: 'center', valign: 'middle' });
                    });
                });
                s.addText('Conceptual diagram, not a screenshot', { x: MX, y: 6.62, w: 5, h: 0.25, fontSize: 10, italic: true, color: HEX.faint, margin: 0, isTextBox: true });
            }
            if (caption) this.text(s, caption, { x: MX, y: flow ? 4.3 : 6.0, w: W - 2 * MX, h: flow ? 2.4 : 0.6, fontSize: 15 });
        });
    }

    /** Feature-status legend (shared across decks). */
    statusLegend(notes) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? 'Explain the seven status labels. Every screenshot slide carries one of them in its top-right corner. Stress that "Verified in the running application" means we ran it on a disposable demonstration database, not that the feature is released.');
            this.head(s, 'How to read this deck', 'Before you start');
            const explain = {
                'VERIFIED IN RUNNING APPLICATION': 'We ran it on the training database and captured each step.',
                'IMPLEMENTED BUT NOT VERIFIED END-TO-END': 'The screen exists; the last steps need data or a second party we could not provide.',
                'AVAILABLE ONLY AFTER CONFIGURATION': 'Works once an administrator or Markedge has set it up.',
                'DISABLED BY SAFETY OR COMMERCIAL GATES': 'Switched off on purpose until owner decisions and approvals arrive.',
                INCOMPLETE: 'Partly working: a known defect or gap is stated on the slide.',
                'NOT IMPLEMENTED': 'Not in the product today.',
                'UNABLE TO VERIFY': 'Could not be checked in this environment.',
            };
            let y = 1.6;
            for (const [k, v] of Object.entries(explain)) {
                this.pill(s, k, MX, y, 4.1);
                this.text(s, v, { x: MX + 4.35, y: y - 0.02, w: 3.45, h: 0.66, fontSize: 12 });
                y += 0.68;
            }
            const bx = 8.55, bw = W - MX - bx;
            s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: bx, y: 1.6, w: bw, h: 4.6, rectRadius: 0.12, fill: { color: HEX.soft }, line: { color: HEX.soft } });
            this.badge(s, 1, bx + 0.3, 1.85, 0.36);
            this.text(s, 'Numbered markers on a screenshot match the numbered steps beside it.', { x: bx + 0.8, y: 1.83, w: bw - 1.0, h: 0.75, fontSize: 13 });
            this.text(s, 'Every screenshot is a real capture of PeopleOS. The people and companies in it are fictional.', { x: bx + 0.3, y: 2.75, w: bw - 0.6, h: 0.9, fontSize: 13 });
            this.text(s, 'Diagrams are marked "Conceptual diagram". They explain ideas; they are not screens.', { x: bx + 0.3, y: 3.7, w: bw - 0.6, h: 0.9, fontSize: 13 });
            this.text(s, RELEASE_LINE, { x: bx + 0.3, y: 4.65, w: bw - 0.6, h: 1.4, fontSize: 13, bold: true, color: HEX.red });
        });
    }

    /** Implementation stage card (Deck 4): every field the playbook needs, with a mode pill. */
    stageCard(st, { eyebrow = 'Implementation stage', notes } = {}) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `RESPONSIBLE: ${st.owner}.\nSAY: ${st.configure}.\nVERIFY: ${st.verify}. EVIDENCE TO KEEP: ${st.evidence}.\nWATCH FOR: ${st.errors}.`);
            this.head(s, `Stage ${st.id} · ${st.name}`, eyebrow);
            const MODE = { 'Configuration in PeopleOS': ['E4EEF8', HEX.blue], 'Operator action in PeopleOS': [HEX.soft2, HEX.primaryDark], 'Automated by PeopleOS': ['E3F3EA', HEX.green], 'Manual (outside PeopleOS)': ['EEEFF2', HEX.grey], 'Blocked (defect or gate)': ['FBE6E4', HEX.red] };
            const [mf, mt] = MODE[st.mode] ?? ['EEEFF2', HEX.grey];
            s.addText(st.mode, { x: W - MX - 3.4, y: 0.32, w: 3.4, h: 0.32, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.16, fill: { color: mf }, color: mt, fontSize: 11, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
            const cells = [['Responsible', st.owner], ['Required inputs', st.inputs], ['Where in PeopleOS', st.nav], ['What to configure', st.configure], ['Expected outcome', st.outcome], ['How to verify', st.verify], ['Evidence to retain', st.evidence], ['Common errors and limits', st.errors], ['Dependencies and blockers', st.deps], ['Acceptance criteria', st.accept]];
            const cols = 2, gx = 0.2, gy = 0.12, cw = (W - 2 * MX - gx) / cols, ch = (6.85 - 1.55 - 4 * gy) / 5;
            cells.forEach(([h, b], i) => {
                const x = MX + (i % cols) * (cw + gx), y = 1.55 + Math.floor(i / cols) * (ch + gy);
                const tone = h.startsWith('Common errors') ? 'FDF0DD' : h.startsWith('Dependencies') && /Blocked|gate|defect|NO-GO|O-\d/.test(b) ? 'FBE6E4' : HEX.soft;
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x, y, w: cw, h: ch, rectRadius: 0.06, fill: { color: tone }, line: { color: tone } });
                s.addText([{ text: h.toUpperCase(), options: { bold: true, fontSize: 9.5, color: this.C.accent1, charSpacing: 1, breakLine: true } }, { text: b, options: { fontSize: b.length > 120 ? 11 : 12, color: this.C.text1 } }],
                    { x: x + 0.15, y: y + 0.06, w: cw - 0.3, h: ch - 0.12, valign: 'top', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n}`, h + '\n' + b, cw - 0.3, ch - 0.12, b.length > 120 ? 11 : 12);
            });
        });
    }

    /** Phase map: groups of chips (e.g. implementation phases with their stages). */
    phaseMap({ title, eyebrow, phases, notes, caption }) {
        this.queue.push(async () => {
            const auto = `SAY: ${title}. ${caption ?? ''}\n${phases.map((p) => `${p.name}: ${p.items.map((i) => (Array.isArray(i) ? i[0] : i)).join(', ')}`).join('\n')}`;
            const s = this.slide('content', notes ? `${notes}\n${auto}` : auto);
            this.head(s, title, eyebrow);
            const n = phases.length, gap = 0.15, cw = (W - 2 * MX - (n - 1) * gap) / n;
            phases.forEach((ph, i) => {
                const x = MX + i * (cw + gap);
                s.addText(ph.name, { x, y: 1.6, w: cw, h: 0.62, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.08, fill: { color: i % 2 ? HEX.navy : HEX.primary }, color: 'FFFFFF', bold: true, fontSize: 12.5, align: 'center', valign: 'middle', margin: 2, isTextBox: true });
                let y = 2.35;
                const ih = Math.min(0.56, (6.3 - 2.35) / Math.max(...phases.map((q) => q.items.length), 1) - 0.08);
                for (const it of ph.items) {
                    const [label, tone] = Array.isArray(it) ? it : [it, null];
                    const fill = { bad: 'FBE6E4', manual: 'D9DBE1', op: HEX.soft2, auto: 'E3F3EA' }[tone] ?? 'E4EEF8';
                    s.addText(label, { x, y, w: cw, h: ih, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.06, fill: { color: fill }, color: HEX.ink, fontSize: 10.5, align: 'center', valign: 'middle', margin: 2, isTextBox: true });
                    y += ih + 0.08;
                }
            });
            if (caption) this.text(s, caption, { x: MX, y: 6.36, w: W - 2 * MX - 3.2, h: 0.58, fontSize: 11.5, color: HEX.muted });
            s.addText('Conceptual diagram, not a screenshot', { x: W - MX - 3.0, y: 6.42, w: 3.0, h: 0.25, fontSize: 10, italic: true, color: HEX.faint, align: 'right', margin: 0, isTextBox: true });
        });
    }

    exercise({ title, tasks, notes, eyebrow = 'Practice' }) {
        this.queue.push(async () => {
            const s = this.slide('content', `RUN: give learners about 10 minutes in a practice tenant (never a live one). Walk around and help.\nSUCCESS: each learner can show the result on screen.\n${notes ?? ''}`);
            this.head(s, title, eyebrow);
            const n = tasks.length, gap = 0.18, ch = Math.min(1.0, (5.2 - (n - 1) * gap) / n);
            tasks.forEach((t, i) => {
                const y = 1.6 + i * (ch + gap);
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: MX, y, w: W - 2 * MX, h: ch, rectRadius: 0.1, fill: { color: HEX.soft2 }, line: { color: HEX.soft2 } });
                this.badge(s, i + 1, MX + 0.2, y + (ch - 0.4) / 2, 0.4, HEX.primary);
                this.text(s, t, { x: MX + 0.8, y: y + 0.08, w: W - 2 * MX - 1.0, h: ch - 0.16, fontSize: 15, valign: 'middle' });
            });
            s.addText('Answer key: on the next slide, and in the speaker notes.', { x: MX, y: 6.6, w: 9, h: 0.28, fontSize: 11, italic: true, color: HEX.muted, margin: 0, isTextBox: true });
        });
    }

    closing({ title = 'Summary', points, next, notes }) {
        this.queue.push(async () => {
            this.section_ = 'Summary';
            this.pres.addSection({ title: 'Summary' });
            const auto = `SAY: Recap the key points:\n${points.map((p) => '- ' + p).join('\n')}\n${next ? 'POINT TO: ' + next.join('; ') : ''}\nASK: "What will you do first in PeopleOS?" Collect questions you cannot answer and send them to the PeopleOS team.`;
            const s = this.slide('dark', notes ? `${notes}\n${auto}` : auto);
            s.addText(title, { x: MX, y: 0.6, w: 8, h: 0.8, fontSize: 36, bold: true, color: 'FFFFFF', fontFace: THEME.headFontFace, margin: 0, isTextBox: true });
            const arr = points.map((p, i) => ({ text: p, options: { bullet: { indent: 18 }, breakLine: i < points.length - 1, paraSpaceAfter: 8 } }));
            s.addText(arr, { x: MX, y: 1.6, w: 7.4, h: 4.6, fontSize: 16, color: 'E4E6F0', valign: 'top', margin: 0, isTextBox: true });
            checkFit(`${this.meta.num}#${this.n}`, points.join('\n'), 7.1, 4.6, 16, 1.45);
            if (next) {
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: 8.4, y: 1.6, w: W - MX - 8.4, h: 4.6, rectRadius: 0.12, fill: { color: '1E2542' }, line: { color: '2A3255' } });
                s.addText('NEXT STEPS & HELP', { x: 8.65, y: 1.8, w: 3.9, h: 0.3, fontSize: 11, bold: true, color: HEX.goldLight, charSpacing: 1.5, margin: 0, isTextBox: true });
                const arr2 = next.map((p, i) => ({ text: p, options: { bullet: { indent: 16 }, breakLine: i < next.length - 1, paraSpaceAfter: 6 } }));
                s.addText(arr2, { x: 8.65, y: 2.2, w: W - MX - 8.9, h: 3.8, fontSize: 13.5, color: 'D5D8E6', valign: 'top', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n}`, next.join('\n'), W - MX - 9.2, 3.8, 13.5, 1.4);
            }
            s.addText(RELEASE_LINE, { x: MX, y: 6.4, w: W - 2 * MX, h: 0.35, fontSize: 11, color: HEX.goldLight, margin: 0, isTextBox: true });
        });
    }

    autoNotes(e, steps, result) {
        return [
            `SAY: ${result || e.expected}`,
            `ON SCREEN: ${e.action || e.workflow}${/[.!?]$/.test(e.action || '') ? '' : '.'}`,
            steps.length ? `WALK THROUGH: ${steps.map((t, i) => `(${Array.isArray(t) ? t[0] : i + 1}) ${Array.isArray(t) ? t[1] : t}`).join(' ')}` : '',
            `LEARNERS SHOULD SEE: ${e.expected}`,
            `SCREEN: ${e.screen}. Signed in as ${e.user} (${e.role}). ${e.prereq ? 'Prerequisite: ' + e.prereq + '.' : ''}`,
            `EVIDENCE: screenshot ${e.file}, captured ${e.captured_at.slice(0, 10)} on the disposable training database (fictional people).`,
        ].filter(Boolean).join('\n');
    }

    async save() {
        for (const fn of this.queue) await fn();
        const out = path.join(ROOT, this.meta.file);
        await this.pres.writeFile({ fileName: out });
        await applyTheme(out);
        return { file: out, slides: this.n, shots: [...this.used] };
    }
}

async function applyTheme(file) {
    const zip = await JSZip.loadAsync(fs.readFileSync(file));
    const slot = (k) => `<a:${k}><a:srgbClr val="${THEME.colors[k]}"/></a:${k}>`;
    const scheme = `<a:clrScheme name="PeopleOS">${['dk1', 'lt1', 'dk2', 'lt2', 'accent1', 'accent2', 'accent3', 'accent4', 'accent5', 'accent6', 'hlink', 'folHlink'].map(slot).join('')}</a:clrScheme>`;
    for (const name of Object.keys(zip.files).filter((n) => /^ppt\/theme\/theme\d+\.xml$/.test(n))) {
        let xml = await zip.file(name).async('string');
        xml = xml.replace(/<a:clrScheme[\s\S]*?<\/a:clrScheme>/, scheme).replace(/(<a:theme[^>]*\sname=")[^"]*(")/, '$1PeopleOS Training$2');
        zip.file(name, xml);
    }
    fs.writeFileSync(file, await zip.generateAsync({ type: 'nodebuffer', compression: 'DEFLATE' }));
}

module.exports = { Deck, HEX, STATUS, THEME, WARN, entry, manifest, has, find, RELEASE_LINE, ROOT };
