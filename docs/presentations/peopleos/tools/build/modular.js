// ModularDeck: a navigable training deck built on the shared Deck (lib.js).
//
// Every instructional slide carries the same chrome: an ink navigation bar (PeopleOS mark, Home / Contents button,
// the current module highlighted in its colour, the current submodule, module progress, Previous / Next) and a footer
// (course, module identifier, submodule progress, slide number). Module colours, brand values and geometry come from
// build/design.js.
//
// Links use STABLE SLIDE KEYS, never hard-coded numbers: 'cover', 'toc', 'howto', 'review', 'M05' (module start),
// 'M05.contents', 'M05.5.2' (first slide of submodule 5.2), 'M05.checklist', 'M05.summary'. Every builder in lib.js
// enqueues exactly one slide, so the key of each queued slide is known before rendering and every link is resolved
// to a slide number at build time. Insert or move slides, rebuild, and the links follow; an unknown key fails the build.
// build/check-nav.js then re-reads the written .pptx and verifies every link against tools/nav-maps/<deck>.json.
//
// Clickable areas are transparent rectangles laid over the visual (objectName "Link · …"), so the whole button or card
// is a target in slideshow mode and no text gets a run-level hyperlink (which PowerPoint underlines and recolours).
'use strict';
const fs = require('fs');
const path = require('path');
const lib = require('./lib');
const { Deck, HEX, STATUS, WARN, estLines, checkFit } = lib;
const D = require('./design');
const { BRAND, CHROME: K, FONTS } = D;
const { W, MX } = K;

/** Rough width of a single line of Calibri text, inches. */
const tw = (text, pt, bold = false) => (String(text).length * pt * (bold ? 0.53 : 0.49)) / 72;
const nn = (n) => String(n).padStart(2, '0');

class ModularDeck extends Deck {
    /**
     * @param meta   { num, file, title, subtitle, short, audience, footer }
     * @param course { groups: [{id, name, desc}], modules: [{code, num, name, desc, intro, group, color, icon, who, where,
     *                objectives: [], subs: [{id, name, desc}], related: [codes]}] }
     */
    constructor(meta, course) {
        super(meta);
        this.course = course;
        this.mods = new Map(course.modules.map((m) => [m.code, { ...m, color: D.tones(D.PALETTE[m.color].hex) }]));
        this.order = course.modules.map((m) => m.code);
        D.assertContrast([...this.mods.values()]);
        this.brandTones = D.tones(BRAND.iris);
        this.plan = [];                                   // one entry per queued slide: { key, kind, module, sub, section }
        this.ctx = { module: null, sub: null, section: 'Course' };
        this.next = {};
        this.icons = new Map();
        const push = Array.prototype.push;
        this.queue.push = (fn) => {
            const key = this.next.key ?? null, alias = this.next.alias ?? null;
            for (const k of [key, alias]) if (k && this.has(k)) throw new Error(`Duplicate slide key "${k}"`);
            this.plan.push({ key, alias, kind: this.next.kind ?? 'content', ...this.ctx });
            this.next = {};
            return push.call(this.queue, fn);
        };
    }

    defineLayouts() { /* defined in save(), once the PeopleOS mark is rendered */ }

    // ------------------------------------------------------------ plan helpers
    modOf(code) { return code ? this.mods.get(code) ?? null : null; }
    subOf(m, id) { return m && id ? m.subs.find((x) => x.id === id) ?? null : null; }
    here() { return this.plan[this.n - 1]; }
    go(key) {
        const i = this.plan.findIndex((p) => p.key === key || p.alias === key);
        if (i < 0) throw new Error(`Link to unknown slide key "${key}"`);
        return i + 1;
    }
    has(key) { return this.plan.some((p) => p.key === key || p.alias === key); }
    /** Slides belonging to a module, or to a submodule of it. */
    members(code, sub) { return this.plan.map((p, i) => ({ ...p, n: i + 1 })).filter((p) => p.module === code && (sub === undefined || p.sub === sub)); }
    nextModule(code) { const i = this.order.indexOf(code); return i >= 0 && i < this.order.length - 1 ? this.order[i + 1] : null; }

    async ico(name, color, px = 192) {
        const k = `${name}/${color}/${px}`;
        if (!this.icons.has(k)) this.icons.set(k, await D.icon(name, color, px));
        return this.icons.get(k);
    }
    /** Transparent clickable area over a visual. target = slide key or slide number. */
    hot(s, { x, y, w, h }, target, tooltip, name) {
        const slide = typeof target === 'number' ? target : this.go(target);
        s.addShape(this.pres.shapes.RECTANGLE, { x, y, w, h, fill: { color: 'FFFFFF', transparency: 100 }, line: { color: 'FFFFFF', transparency: 100, width: 0 }, hyperlink: { slide, tooltip }, objectName: `Link · ${name}` });
    }

    // ------------------------------------------------------------ layouts (slide frames)
    defineModularLayouts() {
        const p = this.pres, A = this.assets;
        const course = `PeopleOS HCM  ·  ${this.meta.title}`;
        // The wordmark itself is drawn per slide (navRow): layout text cannot hold two coloured runs.
        const mark = (y, h) => [{ image: { x: 0.32, y: y + (h - 0.3) / 2, w: 0.3, h: 0.3, data: A.logo } }];
        p.defineSlideMaster({
            title: 'OA_CONTENT', background: { color: 'FFFFFF' },
            objects: [
                { rect: { x: 0, y: 0, w: W, h: K.nav.h, fill: { color: BRAND.ink }, line: { color: BRAND.ink, width: 0 } } },
                ...mark(K.nav.btnY, K.nav.btnH),
                { line: { x: 1.8, y: 0.14, w: 0, h: 0.22, line: { color: BRAND.inkLine, width: 1 } } },
                { text: { text: course, options: { x: MX, y: K.footer.y, w: 5.6, h: K.footer.h, fontSize: 9, color: BRAND.textMuted, margin: 0, valign: 'middle', fontFace: FONTS.body } } },
                { placeholder: { options: { name: 'title', type: 'title', x: MX, y: K.titleY, w: W - 2 * MX, h: K.titleH, fontSize: K.titleSize, bold: true, color: this.C.text2, fontFace: FONTS.head, margin: 0, valign: 'middle', align: 'left' }, text: '' } },
            ],
        });
        p.defineSlideMaster({ title: 'OA_DIVIDER', background: { color: BRAND.ink }, objects: [...mark(K.nav.btnY, K.nav.btnH)] });
        p.defineSlideMaster({ title: 'OA_COVER', background: { color: BRAND.ink }, objects: [] });
    }

    // ------------------------------------------------------------ slide + chrome
    slide(_kind = 'content', notes = '') {
        this.n += 1;
        const p = this.here();
        const m = this.modOf(p.module);
        const t = m ? m.color : this.brandTones;
        this.accent = t.hex; this.accentSoft = t.soft; this.tones = t;
        const section = m ? `${m.code}  ${m.name}` : p.section;
        if (section !== this.lastSection) { this.pres.addSection({ title: section }); this.lastSection = section; }
        const master = { cover: 'OA_COVER', divider: 'OA_DIVIDER' }[p.kind] ?? 'OA_CONTENT';
        const s = this.pres.addSlide({ masterName: master, sectionTitle: section });
        if (notes) s.addNotes(notes);
        if (p.kind === 'divider') { s.background = { color: t.hex }; this.navRow(s, p, m, t, true); this.footer(s, p, m, t, true); }
        else if (p.kind !== 'cover') { this.navRow(s, p, m, t, false); this.footer(s, p, m, t, false); }
        return s;
    }

    /** Navigation row. onColour = the simplified version on a module divider (no module chip, no progress bar). */
    navRow(s, p, m, t, onColour) {
        const A = this.assets, sh = this.pres.shapes, y = K.nav.btnY, h = K.nav.btnH;
        const btnFill = onColour ? { color: 'FFFFFF', transparency: 84 } : { color: BRAND.inkRaised };
        const btnLine = onColour ? { color: 'FFFFFF', transparency: 45, width: 0.75 } : { color: BRAND.inkLine, width: 0.75 };
        s.addText([{ text: 'People', options: { color: 'FFFFFF' } }, { text: 'OS', options: { color: BRAND.goldText } }], { x: 0.7, y, w: 1.05, h, fontSize: 13, bold: true, fontFace: FONTS.head, valign: 'middle', margin: 0, isTextBox: true, objectName: 'Nav · Wordmark' });
        // Home / Contents
        const isHome = p.key === 'toc';
        const hb = { x: 1.95, y, w: 1.3, h };
        s.addShape(sh.ROUNDED_RECTANGLE, { ...hb, rectRadius: 0.08, fill: isHome ? { color: BRAND.iris } : btnFill, line: isHome ? { color: BRAND.iris, width: 0.75 } : btnLine, objectName: 'Nav · Home button' });
        s.addImage({ data: A.home, x: hb.x + 0.12, y: y + 0.065, w: 0.19, h: 0.19, altText: 'Home', objectName: 'Nav · Home icon' });
        s.addText('Contents', { x: hb.x + 0.38, y, w: 0.88, h, fontSize: 10.5, bold: true, color: 'FFFFFF', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Nav · Home label' });
        this.hot(s, hb, 'toc', 'Home: main contents', 'Home');
        let x = hb.x + hb.w + 0.15;
        if (!onColour) {
            // Current module, highlighted in its colour (links to the module's first slide)
            const label = m ? `${nn(m.num)}  ${m.name}` : (p.key === 'toc' ? 'All modules' : p.section);
            const cw = Math.min(3.3, tw(label, 10.5, true) + 0.36);
            const cb = { x, y, w: cw, h };
            s.addText(label, { ...cb, shape: sh.ROUNDED_RECTANGLE, rectRadius: 0.08, fill: { color: t.hex }, line: { color: t.onInk, width: 1 }, color: 'FFFFFF', fontSize: 10.5, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Nav · Module' });
            if (m) this.hot(s, cb, m.code, `Start of module ${m.num}: ${m.name}`, `Module ${m.code} start`);
            else if (p.key !== 'toc' && this.has('review') && p.section === 'Course review') this.hot(s, cb, 'review', 'Start of the course review', 'Course review start');
            x += cw + 0.16;
            // Current section (links to the module contents)
            const sub = this.subOf(m, p.sub);
            const secText = sub ? `${sub.id}  ${sub.name}` : ({ contents: 'Module contents', summary: 'Module summary', checklist: 'Administrator checklist' }[p.kind] ?? (p.navLabel || ''));
            const sw = 9.5 - x;
            if (secText && sw > 0.6) {
                s.addText(secText, { x, y, w: sw, h, fontSize: 10.5, color: BRAND.onInkMuted, valign: 'middle', margin: 0, isTextBox: true, objectName: 'Nav · Section' });
                if (m) this.hot(s, { x, y, w: Math.min(sw, tw(secText, 10.5) + 0.1), h }, `${m.code}.contents`, 'Module contents', `Module ${m.code} contents (section)`);
            }
            // Progress: within the module, or through the course outside modules
            const list = m ? this.members(m.code) : this.plan.map((q, i) => ({ ...q, n: i + 1 }));
            const idx = list.findIndex((q) => q.n === this.n) + 1, total = list.length;
            s.addText(`${m ? '' : 'Slide '}${idx} of ${total}`, { x: 9.58, y, w: 1.02, h, fontSize: 9.5, color: BRAND.onInkFaint, align: 'right', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Nav · Progress text' });
            const tx = 10.7, tw_ = 1.0, ty = 0.215;
            s.addShape(sh.ROUNDED_RECTANGLE, { x: tx, y: ty, w: tw_, h: 0.07, rectRadius: 0.035, fill: { color: BRAND.inkRaised }, line: { color: BRAND.inkLine, width: 0.5 }, objectName: 'Nav · Progress track' });
            s.addShape(sh.ROUNDED_RECTANGLE, { x: tx, y: ty, w: Math.max(0.07, (tw_ * idx) / total), h: 0.07, rectRadius: 0.035, fill: { color: t.onInk }, line: { color: t.onInk, width: 0 }, objectName: 'Nav · Progress fill' });
        }
        // Previous / Next
        const N = this.plan.length;
        for (const [label, target, icon, bx] of [['Previous', this.n - 1, 'prev', W - MX - 0.8], ['Next', this.n + 1, 'next', W - MX - 0.36]]) {
            const b = { x: bx, y, w: 0.36, h };
            const live = target >= 1 && target <= N;
            s.addShape(sh.ROUNDED_RECTANGLE, { ...b, rectRadius: 0.08, fill: btnFill, line: btnLine, objectName: `Nav · ${label} button` });
            s.addImage({ data: live ? A[icon] : A[`${icon}Off`], x: bx + 0.08, y: y + 0.06, w: 0.2, h: 0.2, altText: label, objectName: `Nav · ${label} icon` });
            if (live) this.hot(s, b, target, `${label} slide`, label);
        }
    }

    footer(s, p, m, t, onColour) {
        const subs = m?.subs ?? [];
        const si = p.sub ? subs.findIndex((x) => x.id === p.sub) + 1 : 0;
        const runs = [{ text: m ? `${m.code} · ${m.name}` : p.section, options: { bold: true, color: onColour ? 'FFFFFF' : t.hex } }];
        if (si) runs.push({ text: `   Submodule ${si} of ${subs.length}`, options: { color: onColour ? 'FFFFFF' : BRAND.textMuted } });
        if (onColour) s.addText(`PeopleOS HCM  ·  ${this.meta.title}`, { x: MX, y: K.footer.y, w: 5.6, h: K.footer.h, fontSize: 9, color: 'FFFFFF', margin: 0, valign: 'middle', isTextBox: true, objectName: 'Footer · Course' });
        s.addText(runs, { x: 6.3, y: K.footer.y, w: 5.75, h: K.footer.h, fontSize: 9, align: 'right', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Footer · Module' });
        s.slideNumber = { x: W - MX - 0.5, y: K.footer.y, w: 0.5, h: K.footer.h, fontSize: 10, bold: true, color: onColour ? 'FFFFFF' : t.hex, align: 'right', fontFace: FONTS.body, margin: 0 };
    }

    head(s, title, eyebrow) {
        const p = this.here();
        const sub = this.subOf(this.modOf(p.module), p.sub);
        const label = sub ? `${sub.id}  ·  ${sub.name}` : (eyebrow ?? (p.module ? '' : p.section));
        s.addText(label.toUpperCase(), { x: MX, y: K.eyebrowY, w: 8.6, h: K.eyebrowH, fontSize: 11, bold: true, color: this.accent, charSpacing: 1.5, margin: 0, valign: 'middle', isTextBox: true, objectName: 'Eyebrow' });
        s.addText(title, { placeholder: 'title' });
        if (title.length > 62) WARN.push(`${this.meta.num}#${this.n}: long title (${title.length} chars): ${title}`);
    }

    topPill(s, label) {
        const st = STATUS[label] ?? STATUS['UNABLE TO VERIFY'];
        s.addText(st.short, { x: W - MX - 3.4, y: K.pillY, w: 3.4, h: K.pillH, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.13, fill: { color: st.fill }, color: st.text, fontSize: 10, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Status' });
    }

    /** Button: filled or outline; draws the visual and its click area. */
    async button(s, b, label, target, tooltip, { fill, color = 'FFFFFF', line, icon, iconColor, size = 12, name } = {}) {
        s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { ...b, rectRadius: Math.min(0.1, b.h / 2), fill: fill ? { color: fill } : { color: 'FFFFFF' }, line: { color: line ?? fill ?? BRAND.border, width: 1 }, objectName: `Button · ${name ?? label}` });
        let tx = b.x + 0.18;
        if (icon) { s.addImage({ data: await this.ico(icon, iconColor ?? color), x: b.x + 0.16, y: b.y + (b.h - 0.22) / 2, w: 0.22, h: 0.22, altText: '', objectName: `Button icon · ${name ?? label}` }); tx += 0.32; }
        s.addText(label, { x: tx, y: b.y, w: b.x + b.w - tx - 0.12, h: b.h, fontSize: size, bold: true, color, valign: 'middle', margin: 0, isTextBox: true, objectName: `Button label · ${name ?? label}` });
        this.hot(s, b, target, tooltip, name ?? label);
    }

    // ------------------------------------------------------------ structure
    cover({ shot, notes, kicker, date }) {
        this.ctx = { module: null, sub: null, section: 'Course' };
        this.next = { key: 'cover', kind: 'cover' };
        this.queue.push(async () => {
            const m = this.meta, A = this.assets;
            const s = this.slide('cover', notes);
            s.addImage({ data: A.logo, x: MX, y: 0.62, w: 0.56, h: 0.56, altText: 'PeopleOS logo', objectName: 'Logo' });
            s.addText([{ text: 'People', options: { color: 'FFFFFF' } }, { text: 'OS', options: { color: BRAND.goldText } }], { x: MX + 0.72, y: 0.62, w: 3, h: 0.56, fontSize: 24, bold: true, fontFace: FONTS.head, valign: 'middle', margin: 0, isTextBox: true, objectName: 'Wordmark' });
            s.addText(kicker, { x: MX, y: 1.7, w: 7.6, h: 0.3, fontSize: 12, bold: true, color: BRAND.goldText, charSpacing: 2, margin: 0, isTextBox: true });
            s.addText(m.title, { x: MX, y: 2.05, w: 7.6, h: 1.5, fontSize: 40, bold: true, color: 'FFFFFF', fontFace: FONTS.head, valign: 'top', margin: 0, isTextBox: true, objectName: 'Title' });
            this.text(s, m.subtitle, { x: MX, y: 3.6, w: 7.4, h: 1.0, fontSize: 17, color: 'D5D8E6' });
            this.text(s, `For: ${m.audience}\n${date}`, { x: MX, y: 4.68, w: 7.4, h: 0.98, fontSize: 12, color: BRAND.onInkMuted });
            await this.button(s, { x: MX, y: 5.8, w: 2.6, h: 0.5 }, 'Start: contents', 'toc', 'Open the main contents', { fill: BRAND.iris, icon: 'home', iconColor: 'FFFFFF', size: 13, name: 'Start contents' });
            await this.button(s, { x: MX + 2.8, y: 5.8, w: 2.75, h: 0.5 }, 'How to use this course', 'howto', 'How the navigation works', { fill: BRAND.inkRaised, line: BRAND.inkLine, icon: 'cursor-arrow-rays', iconColor: 'FFFFFF', size: 13 });
            if (shot) {
                const box = { x: 8.55, y: 1.0, w: 4.2, h: 5.3 };
                await this.image(s, shot, box, { callouts: false, valign: 'middle' });
                s.addText('Real screen, fictional data', { x: 8.55, y: 6.4, w: 4.2, h: 0.28, fontSize: 10, color: BRAND.onInkFaint, align: 'center', margin: 0, isTextBox: true });
            }
        });
    }

    /** Main table of contents: one row per learning group, one clickable card per module. */
    toc({ title = 'Contents', intro } = {}) {
        this.ctx = { module: null, sub: null, section: 'Contents' };
        this.next = { key: 'toc', kind: 'toc' };
        this.queue.push(async () => {
            const s = this.slide('content', `SAY: This is home. Every slide has a Contents button top left that brings you back here.\nSAY: The course has ${this.course.modules.length} modules in ${this.course.groups.length} learning groups, in the order an organisation administrator usually needs them. Click a card to open a module.\n${this.course.modules.map((m) => `${m.code} ${m.name}: ${m.desc}`).join('\n')}`);
            this.head(s, title, `${this.meta.title}`);
            await this.button(s, { x: W - MX - 4.75, y: 0.9, w: 2.65, h: 0.42 }, 'How to use this course', 'howto', 'How the navigation works', { line: BRAND.border, color: BRAND.text, icon: 'cursor-arrow-rays', iconColor: BRAND.iris, size: 11.5 });
            if (this.has('review')) await this.button(s, { x: W - MX - 1.95, y: 0.9, w: 1.95, h: 0.42 }, 'Course review', 'review', 'Practice and course summary', { line: BRAND.border, color: BRAND.text, icon: 'flag', iconColor: BRAND.iris, size: 11.5 });
            const groups = this.course.groups, top = 1.52, bottom = 6.96, gap = 0.09;
            const rh = (bottom - top - (groups.length - 1) * gap) / groups.length;
            const gw = 1.66, cw = 2.5, cg = 0.12;
            for (const [gi, g] of groups.entries()) {
                const y = top + gi * (rh + gap);
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: MX, y, w: gw, h: rh, rectRadius: 0.08, fill: { color: BRAND.surfaceMuted }, line: { color: BRAND.surfaceMuted, width: 0 }, objectName: `Group ${g.id}` });
                s.addText([{ text: `GROUP ${g.id}`, options: { fontSize: 9, bold: true, color: BRAND.textMuted, charSpacing: 1.5, breakLine: true } }, { text: g.name, options: { fontSize: 12.5, bold: true, color: BRAND.text } }],
                    { x: MX + 0.14, y: y + 0.06, w: gw - 0.24, h: rh - 0.12, valign: 'middle', margin: 0, isTextBox: true, objectName: `Group ${g.id} label` });
                const mods = this.course.modules.filter((m) => m.group === g.id).map((m) => this.mods.get(m.code));
                for (const [mi, m] of mods.entries()) {
                    const x = MX + gw + 0.14 + mi * (cw + cg), b = { x, y, w: cw, h: rh };
                    s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { ...b, rectRadius: 0.08, fill: { color: 'FFFFFF' }, line: { color: m.color.line, width: 1 }, objectName: `Card ${m.code}` });
                    s.addText(nn(m.num), { x: x + 0.12, y: y + 0.1, w: 0.48, h: 0.48, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.08, fill: { color: m.color.hex }, line: { color: m.color.hex, width: 0 }, color: 'FFFFFF', fontSize: 15, bold: true, fontFace: FONTS.head, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: `Card ${m.code} number` });
                    s.addText(m.name, { x: x + 0.7, y: y + 0.06, w: cw - 0.82, h: 0.5, fontSize: 12, bold: true, color: BRAND.text, valign: 'middle', margin: 0, isTextBox: true, objectName: `Card ${m.code} name` });
                    s.addText(m.desc, { x: x + 0.12, y: y + 0.58, w: cw - 0.24, h: rh - 0.6, fontSize: 10, color: BRAND.textSecondary, valign: 'top', margin: 0, isTextBox: true, objectName: `Card ${m.code} description` });
                    checkFit(`${this.meta.num}#${this.n} ${m.code}`, m.name, cw - 0.82, 0.5, 12);
                    checkFit(`${this.meta.num}#${this.n} ${m.code}`, m.desc, cw - 0.24, rh - 0.6, 10);
                    this.hot(s, b, m.code, `Module ${m.num}: ${m.name}`, `Module ${m.code}`);
                }
            }
        });
    }

    /** Start a module: the module introduction (divider) and the submodule contents slide. */
    mod(code) {
        const m = this.mods.get(code);
        if (!m) throw new Error(`Unknown module ${code}`);
        this.ctx = { module: code, sub: null, section: `${m.code}  ${m.name}` };
        this.next = { key: code, kind: 'divider' };
        this.queue.push(async () => {
            const g = this.course.groups.find((x) => x.id === m.group);
            const s = this.slide('divider', `TRANSITION: Module ${m.num}, ${m.name}. ${m.intro}\nSAY: By the end of this module you will be able to: ${m.objectives.join('; ')}.\nWHO: ${m.who}\nNAVIGATE: Module contents lists the ${m.subs.length} submodules; Contents (top left) returns to the main contents.`);
            s.addText(`MODULE ${nn(m.num)}   ·   GROUP ${g.id}: ${g.name.toUpperCase()}`, { x: MX, y: 1.1, w: 7.9, h: 0.3, fontSize: 12, bold: true, color: 'FFFFFF', charSpacing: 1.5, margin: 0, isTextBox: true });
            s.addText(nn(m.num), { x: MX, y: 1.42, w: 2.4, h: 1.15, fontSize: 72, bold: true, color: 'FFFFFF', fontFace: FONTS.head, margin: 0, valign: 'middle', isTextBox: true, objectName: 'Module number' });
            s.addText(m.name, { x: MX, y: 2.6, w: 7.9, h: 0.95, fontSize: 38, bold: true, color: 'FFFFFF', fontFace: FONTS.head, margin: 0, valign: 'middle', isTextBox: true, objectName: 'Module title' });
            this.text(s, m.intro, { x: MX, y: 3.62, w: 7.6, h: 0.95, fontSize: 16, color: 'FFFFFF' });
            s.addText('YOU WILL BE ABLE TO', { x: MX, y: 4.68, w: 5, h: 0.28, fontSize: 11, bold: true, color: 'FFFFFF', charSpacing: 1.5, margin: 0, isTextBox: true });
            s.addText(m.objectives.map((o, i) => ({ text: o, options: { bullet: { indent: 16 }, breakLine: i < m.objectives.length - 1, paraSpaceAfter: 5 } })), { x: MX, y: 5.02, w: 7.6, h: 1.85, fontSize: 14.5, color: 'FFFFFF', valign: 'top', margin: 0, isTextBox: true });
            checkFit(`${this.meta.num}#${this.n}`, m.objectives.join('\n'), 7.3, 1.85, 14.5, 1.35);
            // Right panel: icon, who, where, buttons
            const px = 8.75, pw = W - MX - px, py = 1.1, ph = 5.75;
            s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: px, y: py, w: pw, h: ph, rectRadius: 0.12, fill: { color: 'FFFFFF', transparency: 88 }, line: { color: 'FFFFFF', transparency: 60, width: 0.75 }, objectName: 'Module panel' });
            s.addImage({ data: await this.ico(m.icon, 'FFFFFF'), x: px + 0.3, y: py + 0.3, w: 0.8, h: 0.8, altText: `${m.name} icon`, objectName: 'Module icon' });
            s.addText([{ text: 'WHO USES IT', options: { fontSize: 10, bold: true, charSpacing: 1.5, breakLine: true } }, { text: m.who, options: { fontSize: 12.5 } }], { x: px + 0.3, y: py + 1.3, w: pw - 0.6, h: 1.25, color: 'FFFFFF', valign: 'top', margin: 0, isTextBox: true });
            s.addText([{ text: 'WHERE IN PEOPLEOS', options: { fontSize: 10, bold: true, charSpacing: 1.5, breakLine: true } }, { text: m.where, options: { fontSize: 12.5 } }], { x: px + 0.3, y: py + 2.65, w: pw - 0.6, h: 1.25, color: 'FFFFFF', valign: 'top', margin: 0, isTextBox: true });
            checkFit(`${this.meta.num}#${this.n} who`, 'WHO\n' + m.who, pw - 0.6, 1.25, 12.5);
            checkFit(`${this.meta.num}#${this.n} where`, 'WHERE\n' + m.where, pw - 0.6, 1.25, 12.5);
            await this.button(s, { x: px + 0.3, y: py + ph - 1.22, w: pw - 0.6, h: 0.46 }, 'Module contents', `${code}.contents`, `Module ${m.num} contents`, { fill: 'FFFFFF', color: m.color.hex, icon: 'list-bullet', iconColor: m.color.hex, name: `${code} contents` });
            await this.button(s, { x: px + 0.3, y: py + ph - 0.66, w: pw - 0.6, h: 0.42 }, 'Skip to the summary', `${code}.summary`, `Module ${m.num} summary`, { fill: m.color.deep, line: 'FFFFFF', icon: 'check-circle', iconColor: 'FFFFFF', size: 11.5, name: `${code} summary` });
        });
        this.next = { key: `${code}.contents`, kind: 'contents' };
        this.queue.push(async () => {
            const s = this.slide('content', `SAY: Module ${m.num} has ${m.subs.length} submodules. Click a row to jump to it; the section name in the top bar brings you back here.\n${m.subs.map((x) => `${x.id} ${x.name}: ${x.desc}`).join('\n')}`);
            this.head(s, `Module ${m.num}: ${m.name}`, 'Module contents');
            const rows = [...m.subs.map((x) => ({ key: `${code}.${x.id}`, tile: x.id, name: x.name, desc: x.desc, count: this.members(code, x.id).length }))];
            if (this.has(`${code}.checklist`)) rows.push({ key: `${code}.checklist`, icon: 'clipboard-document-check', name: 'Administrator checklist', desc: 'What to have done, and checked, before you move on.', count: 1 });
            rows.push({ key: `${code}.summary`, icon: 'check-circle', name: 'Module summary', desc: 'Key points, the next module and related modules.', count: 1 });
            const top = 1.55, bottom = 6.92, gap = 0.1;
            const cols = rows.length > 7 ? 2 : 1;
            const per = Math.ceil(rows.length / cols);
            const rh = Math.min(0.74, (bottom - top - (per - 1) * gap) / per);
            const cw = (W - 2 * MX - (cols - 1) * 0.25) / cols;
            for (const [i, r] of rows.entries()) {
                const col = Math.floor(i / per), row = i % per;
                const x = MX + col * (cw + 0.25), y = top + row * (rh + gap), b = { x, y, w: cw, h: rh };
                const isSub = !!r.tile;
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { ...b, rectRadius: 0.08, fill: { color: isSub ? m.color.softer : 'FFFFFF' }, line: { color: isSub ? m.color.line : BRAND.border, width: 0.75 }, objectName: `Row ${r.key}` });
                const tb = { x: x + 0.12, y: y + 0.1, w: 0.72, h: rh - 0.2 };
                if (isSub) s.addText(r.tile, { ...tb, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.06, fill: { color: m.color.hex }, line: { color: m.color.hex, width: 0 }, color: 'FFFFFF', fontSize: 14, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
                else s.addImage({ data: await this.ico(r.icon, m.color.hex), x: tb.x + (tb.w - 0.34) / 2, y: tb.y + (tb.h - 0.34) / 2, w: 0.34, h: 0.34, altText: '', objectName: `Row icon ${r.key}` });
                const nx = x + 1.0, nw = cw - 1.0 - 1.55;
                s.addText([{ text: r.name, options: { fontSize: 15, bold: true, color: BRAND.text, breakLine: true } }, { text: r.desc, options: { fontSize: 12, color: BRAND.textSecondary } }], { x: nx, y: y + 0.04, w: nw, h: rh - 0.08, valign: 'middle', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n} ${r.key}`, `${r.name}\n${r.desc}`, nw, rh - 0.08, 12.5);
                const n = this.go(r.key);
                s.addText([{ text: `Slide ${n}`, options: { fontSize: 12, bold: true, color: m.color.hex, breakLine: true } }, { text: r.count === 1 ? '1 slide' : `${r.count} slides`, options: { fontSize: 10.5, color: BRAND.textMuted } }], { x: x + cw - 1.5, y, w: 1.0, h: rh, align: 'right', valign: 'middle', margin: 0, isTextBox: true });
                s.addImage({ data: await this.ico('chevron-right', m.color.hex), x: x + cw - 0.4, y: y + (rh - 0.24) / 2, w: 0.24, h: 0.24, altText: '', objectName: `Row chevron ${r.key}` });
                this.hot(s, b, r.key, `${r.name} (slide ${n})`, r.key);
            }
        });
    }

    /** Enter a submodule: the next slide becomes its anchor ('M05.5.2'). */
    sub(id) {
        const m = this.modOf(this.ctx.module);
        if (!this.subOf(m, id)) throw new Error(`Unknown submodule ${id} in ${this.ctx.module}`);
        this.ctx = { ...this.ctx, sub: id };
        this.next = { key: `${m.code}.${id}` };
    }

    /** Administrator checklist for the current module (kind 'checklist'). */
    checklist({ title, items, intro, notes }) {
        const m = this.modOf(this.ctx.module);
        this.ctx = { ...this.ctx, sub: null };
        this.next = { key: `${m.code}.checklist`, kind: 'checklist' };
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: Use this checklist before you move on, and keep it as evidence of what was set up.\n${items.map((it) => '[ ] ' + (Array.isArray(it) ? it.join(': ') : it)).join('\n')}`);
            this.head(s, title ?? `${m.name}: administrator checklist`, 'Administrator checklist');
            let top = 1.55;
            if (intro) { this.text(s, intro, { x: MX, y: 1.5, w: W - 2 * MX, h: 0.4, fontSize: 14, color: BRAND.textSecondary }); top = 2.0; }
            const cols = items.length > 6 ? 2 : 1, per = Math.ceil(items.length / cols), gap = 0.1;
            const cw = (W - 2 * MX - (cols - 1) * 0.3) / cols, rh = Math.min(0.95, (6.92 - top - (per - 1) * gap) / per);
            items.forEach((it, i) => {
                const [head, detail] = Array.isArray(it) ? it : [it, ''];
                const x = MX + Math.floor(i / per) * (cw + 0.3), y = top + (i % per) * (rh + gap);
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x, y, w: cw, h: rh, rectRadius: 0.08, fill: { color: 'FFFFFF' }, line: { color: m.color.line, width: 0.75 }, objectName: `Checklist row ${i + 1}` });
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: x + 0.2, y: y + (rh - 0.3) / 2, w: 0.3, h: 0.3, rectRadius: 0.05, fill: { color: 'FFFFFF' }, line: { color: m.color.hex, width: 1.75 }, objectName: `Checkbox ${i + 1}` });
                const runs = [{ text: head, options: { fontSize: 14, bold: true, color: BRAND.text, breakLine: !!detail } }];
                if (detail) runs.push({ text: detail, options: { fontSize: 12, color: BRAND.textSecondary } });
                s.addText(runs, { x: x + 0.7, y: y + 0.05, w: cw - 0.9, h: rh - 0.1, valign: 'middle', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n}`, `${head}\n${detail}`, cw - 0.9, rh - 0.1, 12.5);
            });
        });
    }

    /** Module summary; ends the module. */
    summary({ points, related, notes }) {
        const m = this.modOf(this.ctx.module);
        this.ctx = { ...this.ctx, sub: null };
        this.next = { key: `${m.code}.summary`, kind: 'summary' };
        const rel = related ?? m.related ?? [];
        this.queue.push(async () => {
            const nxt = this.modOf(this.nextModule(m.code));
            const s = this.slide('content', notes ?? `SAY: Recap module ${m.num}:\n${points.map((p) => '- ' + p).join('\n')}\nNEXT: ${nxt ? `module ${nxt.num}, ${nxt.name}` : 'the course review'}. RELATED: ${rel.map((c) => this.mods.get(c).name).join(', ')}.\nASK: "What will you set up or check first?"`);
            this.head(s, `${m.name}: what to remember`, 'Module summary');
            const check = await this.ico('check-circle', m.color.hex);
            let y = 1.62;
            const lw = 7.0;
            for (const p of points) {
                const h = Math.max(0.42, (estLines(p, lw - 0.6, 15) * 15 * 1.22) / 72 + 0.12);
                s.addImage({ data: check, x: MX, y: y + 0.02, w: 0.3, h: 0.3, altText: '', objectName: 'Check' });
                s.addText(p, { x: MX + 0.45, y, w: lw - 0.45, h, fontSize: 15, color: BRAND.text, valign: 'top', margin: 0, isTextBox: true });
                y += h + 0.1;
            }
            if (y > 6.95) WARN.push(`${this.meta.num}#${this.n}: summary points overflow (${y.toFixed(2)}in)`);
            const px = 8.0, pw = W - MX - px;
            s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: px, y: 1.55, w: pw, h: 5.37, rectRadius: 0.1, fill: { color: m.color.softer }, line: { color: m.color.line, width: 0.75 }, objectName: 'Where next panel' });
            s.addText('WHERE NEXT', { x: px + 0.25, y: 1.72, w: pw - 0.5, h: 0.28, fontSize: 10.5, bold: true, color: m.color.hex, charSpacing: 1.5, margin: 0, isTextBox: true });
            const bw = pw - 0.5;
            if (nxt) await this.button(s, { x: px + 0.25, y: 2.08, w: bw, h: 0.6 }, `Next: ${nn(nxt.num)} ${nxt.name}`, nxt.code, `Module ${nxt.num}: ${nxt.name}`, { fill: nxt.color.hex, icon: 'arrow-right-circle', iconColor: 'FFFFFF', size: 13, name: 'Next module' });
            else if (this.has('review')) await this.button(s, { x: px + 0.25, y: 2.08, w: bw, h: 0.6 }, 'Next: course review', 'review', 'Course review and practice', { fill: BRAND.iris, icon: 'arrow-right-circle', iconColor: 'FFFFFF', size: 13, name: 'Next module' });
            await this.button(s, { x: px + 0.25, y: 2.8, w: (bw - 0.15) / 2, h: 0.46 }, 'Module contents', `${m.code}.contents`, `Module ${m.num} contents`, { line: m.color.line, color: m.color.hex, icon: 'list-bullet', iconColor: m.color.hex, size: 11.5, name: 'Module contents' });
            await this.button(s, { x: px + 0.25 + (bw + 0.15) / 2, y: 2.8, w: (bw - 0.15) / 2, h: 0.46 }, 'Main contents', 'toc', 'Home: main contents', { line: m.color.line, color: m.color.hex, icon: 'home', iconColor: m.color.hex, size: 11.5, name: 'Main contents' });
            if (rel.length) {
                s.addText('RELATED MODULES', { x: px + 0.25, y: 3.55, w: pw - 0.5, h: 0.28, fontSize: 10.5, bold: true, color: m.color.hex, charSpacing: 1.5, margin: 0, isTextBox: true });
                let ry = 3.92;
                for (const c of rel.slice(0, 4)) {
                    const r = this.mods.get(c);
                    if (!r) throw new Error(`Related module ${c} unknown`);
                    const b = { x: px + 0.25, y: ry, w: bw, h: 0.52 };
                    s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { ...b, rectRadius: 0.08, fill: { color: 'FFFFFF' }, line: { color: r.color.line, width: 0.75 }, objectName: `Related ${c}` });
                    s.addText(nn(r.num), { x: b.x + 0.08, y: ry + 0.08, w: 0.42, h: 0.36, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.05, fill: { color: r.color.hex }, line: { color: r.color.hex, width: 0 }, color: 'FFFFFF', fontSize: 11.5, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
                    s.addText(r.name, { x: b.x + 0.62, y: ry, w: bw - 0.7, h: 0.52, fontSize: 12.5, bold: true, color: BRAND.text, valign: 'middle', margin: 0, isTextBox: true });
                    this.hot(s, b, c, `Module ${r.num}: ${r.name}`, `Related ${c}`);
                    ry += 0.62;
                }
            }
        });
        this.ctx = { module: null, sub: null, section: 'Course' };
    }

    /** Course review: everything after the last module (practice, answers, course summary). */
    review() {
        this.ctx = { module: null, sub: null, section: 'Course review' };
        this.next = { key: 'review' };
    }

    // ------------------------------------------------------------ instructional layouts
    /** How the deck's navigation works: a replica of the navigation bar with numbered explanations. */
    howto({ notes } = {}) {
        if (this.next.key) this.next.alias = 'howto'; else this.next.key = 'howto';
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? 'SAY: The course works like the application: a bar at the top, a home button, and the module you are in highlighted in its colour. Everything with a frame is clickable in slideshow mode.\nDEMO: click Contents, open a module card, click a submodule row, then use the section name to come back.');
            this.head(s, 'How to move around this course', 'Using this course');
            const A = this.assets, sh = this.pres.shapes, m = this.modOf('M05') ?? [...this.mods.values()][0];
            const bx = MX, by = 1.75, bw = W - 2 * MX, bh = 0.62;
            s.addShape(sh.ROUNDED_RECTANGLE, { x: bx, y: by, w: bw, h: bh, rectRadius: 0.08, fill: { color: BRAND.ink }, line: { color: BRAND.ink, width: 0 }, objectName: 'Replica bar' });
            s.addImage({ data: A.logo, x: bx + 0.18, y: by + 0.15, w: 0.32, h: 0.32, altText: 'PeopleOS logo' });
            s.addText([{ text: 'People', options: { color: 'FFFFFF' } }, { text: 'OS', options: { color: BRAND.goldText } }], { x: bx + 0.58, y: by, w: 1.1, h: bh, fontSize: 14, bold: true, fontFace: FONTS.head, valign: 'middle', margin: 0, isTextBox: true });
            const chip = (x, w, text, fill, line, color = 'FFFFFF', bold = true) => s.addText(text, { x, y: by + 0.13, w, h: 0.36, shape: sh.ROUNDED_RECTANGLE, rectRadius: 0.08, fill: { color: fill }, line: { color: line, width: 1 }, color, fontSize: 11, bold, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
            chip(bx + 1.85, 1.45, '    Contents', BRAND.inkRaised, BRAND.inkLine);
            s.addImage({ data: A.home, x: bx + 1.98, y: by + 0.21, w: 0.2, h: 0.2, altText: '' });
            chip(bx + 3.45, 2.5, `${nn(m.num)}  ${m.name}`, m.color.hex, m.color.onInk);
            s.addText(`${m.subs[1]?.id ?? m.subs[0].id}  ${m.subs[1]?.name ?? m.subs[0].name}`, { x: bx + 6.1, y: by, w: 2.2, h: bh, fontSize: 11, color: BRAND.onInkMuted, valign: 'middle', margin: 0, isTextBox: true });
            s.addText('3 of 14', { x: bx + 8.35, y: by, w: 0.8, h: bh, fontSize: 10, color: BRAND.onInkFaint, align: 'right', valign: 'middle', margin: 0, isTextBox: true });
            s.addShape(sh.ROUNDED_RECTANGLE, { x: bx + 9.25, y: by + 0.27, w: 1.2, h: 0.08, rectRadius: 0.04, fill: { color: BRAND.inkRaised }, line: { color: BRAND.inkLine, width: 0.5 } });
            s.addShape(sh.ROUNDED_RECTANGLE, { x: bx + 9.25, y: by + 0.27, w: 0.26, h: 0.08, rectRadius: 0.04, fill: { color: m.color.onInk }, line: { color: m.color.onInk, width: 0 } });
            for (const [i, ic] of [[0, 'prev'], [1, 'next']]) {
                s.addShape(sh.ROUNDED_RECTANGLE, { x: bx + 10.7 + i * 0.5, y: by + 0.13, w: 0.4, h: 0.36, rectRadius: 0.08, fill: { color: BRAND.inkRaised }, line: { color: BRAND.inkLine, width: 0.75 } });
                s.addImage({ data: A[ic], x: bx + 10.8 + i * 0.5, y: by + 0.21, w: 0.2, h: 0.2, altText: '' });
            }
            const marks = [[bx + 2.45, 1], [bx + 4.6, 2], [bx + 7.0, 3], [bx + 9.7, 4], [bx + 11.05, 5]];
            for (const [x, n] of marks) this.badge(s, n, x, by + bh + 0.1, 0.32, HEX.callout);
            s.addText('Replica of the bar at the top of every instructional slide', { x: MX, y: by - 0.32, w: 7, h: 0.26, fontSize: 10.5, italic: true, color: BRAND.textMuted, margin: 0, isTextBox: true });
            const items = [
                [1, 'Contents (Home)', 'Returns to the main contents from any slide. It is in the same place on every slide.'],
                [2, 'Current module', 'Highlighted in the module\'s own colour. Click it to go back to the start of the module.'],
                [3, 'Current submodule', 'Number and name of the part you are in. Click it to open the module contents.'],
                [4, 'Module progress', 'How far through the module you are: slide 3 of 14.'],
                [5, 'Previous / Next', 'One slide back or forward; the arrow keys do the same in slideshow mode.'],
                [6, 'Cards, rows and buttons', 'Anything drawn as a card or button on a contents, module or summary slide is a link.'],
            ];
            const cw = (W - 2 * MX - 0.3) / 2;
            items.forEach(([n, h, b], i) => {
                const x = MX + (i % 2) * (cw + 0.3), y = 3.05 + Math.floor(i / 2) * 1.02;
                s.addShape(sh.ROUNDED_RECTANGLE, { x, y, w: cw, h: 0.9, rectRadius: 0.08, fill: { color: BRAND.canvas }, line: { color: BRAND.border, width: 0.75 } });
                this.badge(s, n, x + 0.18, y + 0.29, 0.32, HEX.callout);
                s.addText([{ text: h, options: { bold: true, fontSize: 14, color: BRAND.text, breakLine: true } }, { text: b, options: { fontSize: 12, color: BRAND.textSecondary } }], { x: x + 0.66, y: y + 0.06, w: cw - 0.84, h: 0.78, valign: 'middle', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n}`, `${h}\n${b}`, cw - 0.84, 0.78, 12.5);
            });
            s.addText('The footer shows the course, the module and submodule you are in, and the slide number in the module\'s colour.', { x: MX, y: 6.18, w: W - 2 * MX, h: 0.3, fontSize: 12, color: BRAND.textSecondary, margin: 0, isTextBox: true });
            await this.button(s, { x: MX, y: 6.52, w: 2.6, h: 0.42 }, 'Try it: open Contents', 'toc', 'Home: main contents', { fill: BRAND.iris, icon: 'home', iconColor: 'FFFFFF', size: 12, name: 'Try contents' });
        });
    }

    /** Navigation instructions: where a screen is, as a breadcrumb, with steps and the screen itself. */
    navigate({ title, path: crumbs, steps, img, crop, callouts = false, tip, notes, maxAspect }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: To get there: ${crumbs.join(' › ')}.\n${steps.map((t, i) => `(${i + 1}) ${t}`).join(' ')}${tip ? `\nTIP: ${tip}` : ''}`);
            this.head(s, title, 'Navigation');
            const t = this.tones, sh = this.pres.shapes;
            let x = MX; const y = 1.6, h = 0.44;
            const chev = await this.ico('chevron-right', BRAND.textMuted);
            s.addText('WHERE', { x, y, w: 0.8, h, fontSize: 10.5, bold: true, color: t.hex, charSpacing: 1.5, valign: 'middle', margin: 0, isTextBox: true });
            x += 0.85;
            crumbs.forEach((c, i) => {
                const last = i === crumbs.length - 1, w = tw(c, 12.5, true) + 0.4;
                s.addText(c, { x, y, w, h, shape: sh.ROUNDED_RECTANGLE, rectRadius: 0.08, fill: { color: last ? t.hex : 'FFFFFF' }, line: { color: last ? t.hex : BRAND.border, width: 1 }, color: last ? 'FFFFFF' : BRAND.text, fontSize: 12.5, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true, objectName: `Breadcrumb ${i + 1}` });
                x += w;
                if (!last) { s.addImage({ data: chev, x: x + 0.05, y: y + 0.11, w: 0.22, h: 0.22, altText: '', objectName: 'Breadcrumb separator' }); x += 0.32; }
            });
            if (x > W - MX) WARN.push(`${this.meta.num}#${this.n}: breadcrumb too wide`);
            const lw = img ? 4.55 : W - 2 * MX;
            let sy = 2.35;
            for (const [i, st] of steps.entries()) {
                const hh = Math.max(0.42, (estLines(st, lw - 0.55, 14) * 14 * 1.22) / 72 + 0.12);
                this.badge(s, i + 1, MX, sy + 0.02, 0.34, t.hex);
                s.addText(st, { x: MX + 0.5, y: sy, w: lw - 0.5, h: hh, fontSize: 14, color: BRAND.text, valign: 'top', margin: 0, isTextBox: true });
                sy += hh + 0.1;
            }
            if (tip) {
                const th = Math.max(0.6, (estLines('TIP  ' + tip, lw - 0.3, 12.5) * 12.5 * 1.22) / 72 + 0.26);
                const ty = Math.max(sy + 0.1, 6.92 - th);
                s.addShape(sh.ROUNDED_RECTANGLE, { x: MX, y: ty, w: lw, h: th, rectRadius: 0.08, fill: { color: t.soft }, line: { color: t.soft, width: 0 }, objectName: 'Tip' });
                s.addText([{ text: 'TIP  ', options: { bold: true, color: t.hex, fontSize: 10.5, charSpacing: 1 } }, { text: tip, options: { fontSize: 12.5, color: BRAND.text } }], { x: MX + 0.15, y: ty + 0.1, w: lw - 0.3, h: th - 0.2, valign: 'top', margin: 0, isTextBox: true });
                sy = ty + th;
            }
            if (sy > 6.95) WARN.push(`${this.meta.num}#${this.n}: navigation steps overflow`);
            if (img) {
                const im = await this.image(s, img, { x: MX + lw + 0.35, y: 2.35, w: W - MX - (MX + lw + 0.35), h: 4.3 }, { crop, callouts, valign: 'top', maxAspect: maxAspect ?? 0.7 });
                s.addText(this.sourceLine(im.entry, im.cropped), { x: MX + lw + 0.35, y: 6.72, w: W - MX - (MX + lw + 0.35), h: 0.24, fontSize: 9, color: HEX.faint, margin: 0, isTextBox: true });
            }
        });
    }

    /** Step-by-step procedure without a screenshot: a vertical numbered path, optional side card. */
    procedure({ title, steps, side, notes, intro }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: ${title}.\n${steps.map((st, i) => `${i + 1}. ${st[0]}: ${st[1]}`).join('\n')}`);
            this.head(s, title, 'Procedure');
            const t = this.tones, sh = this.pres.shapes;
            const lw = side ? 7.6 : W - 2 * MX;
            let y = 1.6;
            if (intro) { this.text(s, intro, { x: MX, y: 1.5, w: lw, h: 0.5, fontSize: 14, color: BRAND.textSecondary }); y = 2.1; }
            const avail = 6.92 - y, n = steps.length;
            const rh = Math.min(1.0, (avail - (n - 1) * 0.08) / n);
            s.addShape(sh.LINE, { x: MX + 0.21, y: y + 0.2, w: 0, h: (n - 1) * (rh + 0.08), line: { color: t.line, width: 2 }, objectName: 'Procedure path' });
            steps.forEach(([head, body], i) => {
                const yy = y + i * (rh + 0.08);
                this.badge(s, i + 1, MX, yy, 0.42, t.hex);
                s.addText([{ text: head, options: { bold: true, fontSize: 15, color: BRAND.text, breakLine: !!body } }, ...(body ? [{ text: body, options: { fontSize: 12.5, color: BRAND.textSecondary } }] : [])], { x: MX + 0.65, y: yy - 0.03, w: lw - 0.65, h: rh, valign: 'top', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n}`, `${head}\n${body ?? ''}`, lw - 0.65, rh, 12.5);
            });
            if (side) this.sideCard(s, side, { x: MX + lw + 0.4, y: 1.6, w: W - MX - (MX + lw + 0.4), h: 5.32 });
        });
    }

    sideCard(s, side, b) {
        const t = this.tones;
        s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { ...b, rectRadius: 0.1, fill: { color: t.softer }, line: { color: t.line, width: 0.75 }, objectName: 'Side card' });
        let y = b.y + 0.22;
        for (const [head, body] of side) {
            const h = (estLines(body, b.w - 0.5, 12.5) * 12.5 * 1.22) / 72 + 0.36;
            s.addText([{ text: head.toUpperCase(), options: { bold: true, fontSize: 10, color: t.hex, charSpacing: 1.2, breakLine: true } }, { text: body, options: { fontSize: 12.5, color: BRAND.text } }], { x: b.x + 0.25, y, w: b.w - 0.5, h, valign: 'top', margin: 0, isTextBox: true });
            y += h + 0.14;
        }
        if (y > b.y + b.h + 0.05) WARN.push(`${this.meta.num}#${this.n}: side card overflows`);
    }

    /** Form-field explanation: the form with numbered fields, and what to enter in each. fields: [{n, name, what, rule}] */
    fields({ title, img, crop, fields, notes, maxAspect, place }) {
        this.queue.push(async () => {
            const e = lib.entry(img);
            const s = this.slide('content', notes ?? `SAY: ${title}. What goes in each field:\n${fields.map((f, i) => `${i + 1}. ${f.name}${f.rule ? ` (${f.rule})` : ''}: ${f.what}`).join('\n')}\nSCREEN: ${e.screen}, ${e.user} (${e.role}), fictional data.`);
            this.head(s, title, 'Form fields');
            const t = this.tones;
            const wanted = fields.filter((f) => f.n != null).map((f) => String(f.n));
            const renumber = Object.fromEntries(fields.filter((f) => f.n != null).map((f) => [String(f.n), fields.indexOf(f) + 1]));
            const im = await this.image(s, img, { x: MX, y: 1.55, w: 6.6, h: 5.15 }, { crop, callouts: wanted, renumber, valign: 'top', maxAspect: maxAspect ?? 1.05, place });
            s.addText(this.sourceLine(e, im.cropped), { x: MX, y: 6.74, w: 6.6, h: 0.22, fontSize: 9, color: HEX.faint, margin: 0, isTextBox: true });
            const tx = 7.5, twd = W - MX - tx;
            const RULE = { Required: [BRAND.dangerSoft, BRAND.danger], Optional: [BRAND.surfaceMuted, BRAND.textSecondary], Automatic: [BRAND.successSoft, BRAND.success], Locked: [BRAND.warningSoft, BRAND.warning] };
            let y = 1.55;
            const rh = Math.min(1.05, (6.95 - y - (fields.length - 1) * 0.08) / fields.length);
            fields.forEach((f, i) => {
                s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: tx, y, w: twd, h: rh, rectRadius: 0.06, fill: { color: i % 2 ? 'FFFFFF' : t.softer }, line: { color: BRAND.border, width: 0.5 }, objectName: `Field ${i + 1}` });
                if (im.drawn.includes(String(i + 1))) this.badge(s, i + 1, tx + 0.12, y + 0.12, 0.3);
                else s.addText(String(i + 1), { x: tx + 0.12, y: y + 0.12, w: 0.3, h: 0.3, shape: this.pres.shapes.OVAL, fill: { color: BRAND.surfaceMuted }, line: { color: BRAND.border, width: 0.75 }, color: BRAND.textSecondary, fontSize: 11, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
                if (f.rule) {
                    const [rf, rt] = RULE[f.rule] ?? RULE.Optional;
                    s.addText(f.rule, { x: tx + twd - 1.05, y: y + 0.12, w: 0.95, h: 0.26, shape: this.pres.shapes.ROUNDED_RECTANGLE, rectRadius: 0.13, fill: { color: rf }, line: { color: rf, width: 0 }, color: rt, fontSize: 9.5, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
                }
                s.addText(f.name, { x: tx + 0.55, y: y + 0.08, w: twd - (f.rule ? 1.7 : 0.7), h: 0.34, fontSize: 13.5, bold: true, color: BRAND.text, valign: 'middle', margin: 0, isTextBox: true });
                s.addText(f.what, { x: tx + 0.55, y: y + 0.42, w: twd - 0.7, h: rh - 0.48, fontSize: 12, color: BRAND.textSecondary, valign: 'top', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n} field ${i + 1}`, f.what, twd - 0.7, rh - 0.48, 12);
                y += rh + 0.08;
            });
        });
    }

    /** Action / button explanation. actions: [[button label, what it does, who may use it]] */
    actions({ title, img, crop, actions, notes, maxAspect, intro }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: ${title}.\n${actions.map((a) => `- ${a[0]}: ${a[1]}${a[2] ? ` (${a[2]})` : ''}`).join('\n')}`);
            this.head(s, title, 'Actions and buttons');
            const t = this.tones, sh = this.pres.shapes;
            const lw = img ? 7.15 : W - 2 * MX;
            let y = 1.55;
            if (intro) { this.text(s, intro, { x: MX, y: 1.5, w: lw, h: 0.56, fontSize: 13.5, color: BRAND.textSecondary }); y = 2.12; }
            const rh = Math.min(0.9, (6.95 - y - (actions.length - 1) * 0.08) / actions.length);
            const bw = 2.2;
            for (const [label, what, who] of actions) {
                s.addText(label, { x: MX, y: y + (rh - 0.42) / 2, w: bw, h: 0.42, shape: sh.ROUNDED_RECTANGLE, rectRadius: 0.08, fill: { color: 'FFFFFF' }, line: { color: BRAND.border, width: 1.25 }, color: BRAND.text, fontSize: 12, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true, shadow: { type: 'outer', color: '000000', opacity: 0.12, blur: 2, offset: 1, angle: 90 }, objectName: `Button replica ${label}` });
                const runs = [{ text: what, options: { fontSize: 13, color: BRAND.text, breakLine: !!who } }];
                if (who) runs.push({ text: who, options: { fontSize: 11, color: t.hex, bold: true } });
                s.addText(runs, { x: MX + bw + 0.25, y, w: lw - bw - 0.25, h: rh, valign: 'middle', margin: 0, isTextBox: true });
                checkFit(`${this.meta.num}#${this.n} ${label}`, `${what}\n${who ?? ''}`, lw - bw - 0.25, rh, 12.5);
                y += rh + 0.08;
            }
            s.addText('Button labels are shown as they appear in PeopleOS.', { x: MX, y: 6.74, w: lw, h: 0.22, fontSize: 9, italic: true, color: HEX.faint, margin: 0, isTextBox: true });
            if (img) {
                const x = MX + lw + 0.35;
                const im = await this.image(s, img, { x, y: 1.55, w: W - MX - x, h: 5.1 }, { crop, callouts: false, valign: 'top', maxAspect: maxAspect ?? 1.2 });
                s.addText(this.sourceLine(im.entry, im.cropped), { x, y: 6.74, w: W - MX - x, h: 0.22, fontSize: 9, color: HEX.faint, margin: 0, isTextBox: true });
            }
        });
    }

    /** Calculation example: operand lines, a total, and how it works. lines: [[op, label, value]] */
    calculation({ title, intro, lines, total, explain, source, notes }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: ${title}. ${intro ?? ''}\n${lines.map(([op, l, v]) => `${op} ${l}: ${v}`).join('\n')}\n= ${total[0]}: ${total[1]}\nSOURCE: ${source}`);
            this.head(s, title, 'Calculation example');
            const t = this.tones, sh = this.pres.shapes;
            const cw = 6.9, cx = MX;
            let y = 1.55;
            if (intro) { this.text(s, intro, { x: cx, y, w: cw, h: 0.55, fontSize: 13.5, color: BRAND.textSecondary }); y += 0.62; }
            const rh = Math.min(0.58, (6.95 - y - 1.35) / lines.length);
            s.addShape(sh.ROUNDED_RECTANGLE, { x: cx, y, w: cw, h: lines.length * rh + 1.3, rectRadius: 0.1, fill: { color: t.softer }, line: { color: t.line, width: 0.75 }, objectName: 'Calculation card' });
            const sym = { '+': '+', '-': '−', '−': '−', '×': '×', '=': '=', '': '' };
            let yy = y + 0.15;
            for (const [op, label, value] of lines) {
                if (op) s.addText(sym[op] ?? op, { x: cx + 0.2, y: yy + (rh - 0.34) / 2, w: 0.34, h: 0.34, shape: sh.OVAL, fill: { color: 'FFFFFF' }, line: { color: t.hex, width: 1.25 }, color: t.hex, fontSize: 14, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
                s.addText(label, { x: cx + 0.7, y: yy, w: cw - 3.0, h: rh, fontSize: 14.5, color: BRAND.text, valign: 'middle', margin: 0, isTextBox: true });
                s.addText(value, { x: cx + cw - 2.35, y: yy, w: 2.15, h: rh, fontSize: 16, color: BRAND.text, fontFace: FONTS.head, align: 'right', valign: 'middle', margin: 0, isTextBox: true });
                yy += rh;
            }
            s.addShape(sh.LINE, { x: cx + 0.2, y: yy + 0.08, w: cw - 0.4, h: 0, line: { color: t.hex, width: 1.5 }, objectName: 'Total rule' });
            s.addText('=', { x: cx + 0.2, y: yy + 0.33, w: 0.34, h: 0.34, shape: sh.OVAL, fill: { color: t.hex }, line: { color: t.hex, width: 0 }, color: 'FFFFFF', fontSize: 14, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
            s.addText(total[0], { x: cx + 0.7, y: yy + 0.2, w: cw - 3.5, h: 0.6, fontSize: 16, bold: true, color: BRAND.text, valign: 'middle', margin: 0, isTextBox: true });
            s.addText(total[1], { x: cx + cw - 2.9, y: yy + 0.15, w: 2.7, h: 0.7, fontSize: 28, bold: true, color: t.hex, fontFace: FONTS.head, align: 'right', valign: 'middle', margin: 0, isTextBox: true, objectName: 'Result' });
            const ex = cx + cw + 0.4, ew = W - MX - ex;
            s.addImage({ data: await this.ico('calculator', t.hex), x: ex, y: 1.58, w: 0.42, h: 0.42, altText: '', objectName: 'Calculator icon' });
            this.sideCard(s, explain, { x: ex, y: 2.15, w: ew, h: 4.25 });
            this.text(s, `Source: ${source}`, { x: ex, y: 6.5, w: ew, h: 0.46, fontSize: 9, color: HEX.faint });
        });
    }

    /** Warning / important note. level: 'warning' (amber), 'blocked' (red) or 'important' (module colour). */
    warning({ title, level = 'warning', headline, body, points = [], todo, img, crop, notes, maxAspect }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: ${headline} ${body ?? ''}\n${points.map((p) => '- ' + p).join('\n')}${todo ? `\nWHAT TO DO: ${todo}` : ''}`);
            this.head(s, title, level === 'important' ? 'Important' : 'Warning');
            const t = this.tones, sh = this.pres.shapes;
            const [fg, bg, icon, word] = { warning: [BRAND.warning, BRAND.warningSoft, 'exclamation-triangle', 'WARNING'], blocked: [BRAND.danger, BRAND.dangerSoft, 'no-symbol', 'BLOCKED'], important: [t.hex, t.soft, 'information-circle', 'IMPORTANT'] }[level];
            const lw = img ? 6.6 : W - 2 * MX;
            s.addShape(sh.ROUNDED_RECTANGLE, { x: MX, y: 1.55, w: lw, h: 1.5, rectRadius: 0.1, fill: { color: bg }, line: { color: fg, width: 1.25 }, objectName: 'Notice' });
            s.addImage({ data: await this.ico(icon, fg), x: MX + 0.25, y: 1.75, w: 0.62, h: 0.62, altText: word.toLowerCase(), objectName: 'Notice icon' });
            s.addText(word, { x: MX + 1.1, y: 1.66, w: 3, h: 0.26, fontSize: 10.5, bold: true, color: fg, charSpacing: 1.5, margin: 0, isTextBox: true });
            s.addText(headline, { x: MX + 1.1, y: 1.94, w: lw - 1.3, h: 1.0, fontSize: 17, bold: true, color: BRAND.text, valign: 'top', margin: 0, isTextBox: true });
            checkFit(`${this.meta.num}#${this.n}`, headline, lw - 1.3, 1.0, 17);
            let y = 3.25;
            if (body) { const h = (estLines(body, lw, 14) * 14 * 1.22) / 72 + 0.1; this.text(s, body, { x: MX, y, w: lw, h, fontSize: 14, color: BRAND.text }); y += h + 0.1; }
            if (points.length) {
                s.addText(points.map((p, i) => ({ text: p, options: { bullet: { indent: 16 }, breakLine: i < points.length - 1, paraSpaceAfter: 6 } })), { x: MX, y, w: lw, h: 6.0 - y, fontSize: 13.5, color: BRAND.text, valign: 'top', margin: 0, isTextBox: true });
                y += (estLines(points.join('\n'), lw - 0.3, 13.5) * 13.5 * 1.4) / 72 + 0.1;
            }
            if (todo) {
                const th = Math.max(0.62, (estLines('WHAT TO DO  ' + todo, lw - 0.3, 13) * 13 * 1.22) / 72 + 0.26);
                const ty = y + 0.15;
                s.addShape(sh.ROUNDED_RECTANGLE, { x: MX, y: ty, w: lw, h: th, rectRadius: 0.08, fill: { color: BRAND.successSoft }, line: { color: BRAND.successSoft, width: 0 }, objectName: 'What to do' });
                s.addText([{ text: 'WHAT TO DO  ', options: { bold: true, color: BRAND.success, fontSize: 10.5, charSpacing: 1 } }, { text: todo, options: { fontSize: 13, color: BRAND.text } }], { x: MX + 0.15, y: ty + 0.1, w: lw - 0.3, h: th - 0.2, valign: 'top', margin: 0, isTextBox: true });
                y = ty + th;
            }
            if (y > 6.97) WARN.push(`${this.meta.num}#${this.n}: warning text overflows (${y.toFixed(2)})`);
            if (img) {
                const x = MX + lw + 0.35;
                const im = await this.image(s, img, { x, y: 1.55, w: W - MX - x, h: 5.1 }, { crop, callouts: false, valign: 'top', maxAspect: maxAspect ?? 1.2 });
                s.addText(this.sourceLine(im.entry, im.cropped), { x, y: 6.74, w: W - MX - x, h: 0.22, fontSize: 9, color: HEX.faint, margin: 0, isTextBox: true });
            }
        });
    }

    /** Troubleshooting: symptom → cause → what to do. rows: [[you see, why, do this, ref]]; four per slide. */
    troubleshoot({ title, rows, notes, perSlide = 4 }) {
        const pages = [];
        for (let i = 0; i < rows.length; i += perSlide) pages.push(rows.slice(i, i + perSlide));
        pages.forEach((page, pi) => this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: ${title}.\n${page.map((r) => `- You see: ${r[0]} Why: ${r[1]} Do: ${r[2]}${r[3] ? ` [${r[3]}]` : ''}`).join('\n')}`);
            this.head(s, pages.length > 1 ? `${title} (${pi + 1} of ${pages.length})` : title, 'Troubleshooting');
            const t = this.tones, sh = this.pres.shapes;
            const cols = [['YOU SEE', 3.85], ['WHY', 3.65], ['WHAT TO DO', 4.63]];
            const icons = { 'YOU SEE': await this.ico('eye', t.hex), WHY: await this.ico('question-mark-circle', t.hex), 'WHAT TO DO': await this.ico('wrench-screwdriver', t.hex) };
            let x = MX;
            for (const [h, w] of cols) {
                s.addImage({ data: icons[h], x, y: 1.58, w: 0.26, h: 0.26, altText: '' });
                s.addText(h, { x: x + 0.34, y: 1.56, w: w - 0.4, h: 0.3, fontSize: 10.5, bold: true, color: t.hex, charSpacing: 1.5, valign: 'middle', margin: 0, isTextBox: true });
                x += w;
            }
            const top = 1.98, rh = Math.min(1.2, (6.95 - top - (page.length - 1) * 0.1) / page.length);
            page.forEach((r, i) => {
                const y = top + i * (rh + 0.1);
                s.addShape(sh.ROUNDED_RECTANGLE, { x: MX, y, w: W - 2 * MX, h: rh, rectRadius: 0.08, fill: { color: i % 2 ? 'FFFFFF' : t.softer }, line: { color: BRAND.border, width: 0.75 }, objectName: `Problem ${i + 1}` });
                let cx = MX;
                cols.forEach(([, w], ci) => {
                    const txt = r[ci];
                    const tagW = ci === 0 && r[3] ? 0.62 : 0;
                    s.addText(txt, { x: cx + 0.15, y: y + 0.08, w: w - 0.3 - tagW, h: rh - 0.16, fontSize: 12.5, bold: ci === 0, color: BRAND.text, valign: 'top', margin: 0, isTextBox: true });
                    checkFit(`${this.meta.num}#${this.n} r${i + 1}c${ci + 1}`, txt, w - 0.3 - tagW, rh - 0.16, 12.5);
                    if (tagW) s.addText(r[3], { x: cx + w - 0.72, y: y + 0.1, w: 0.58, h: 0.26, shape: sh.ROUNDED_RECTANGLE, rectRadius: 0.13, fill: { color: BRAND.surfaceMuted }, line: { color: BRAND.border, width: 0.5 }, color: BRAND.textSecondary, fontSize: 9.5, bold: true, align: 'center', valign: 'middle', margin: 0, isTextBox: true });
                    cx += w;
                });
            });
        }));
    }

    /** Course summary (inside the course review). */
    finale({ points, next, notes }) {
        this.queue.push(async () => {
            const s = this.slide('content', notes ?? `SAY: Recap the course:\n${points.map((p) => '- ' + p).join('\n')}\nPOINT TO: ${next.join('; ')}`);
            this.head(s, 'What an organisation administrator should remember', 'Course summary');
            const t = this.tones;
            const check = await this.ico('check-circle', t.hex);
            let y = 1.62;
            for (const p of points) {
                const h = Math.max(0.42, (estLines(p, 6.9, 15) * 15 * 1.22) / 72 + 0.1);
                s.addImage({ data: check, x: MX, y: y + 0.02, w: 0.3, h: 0.3, altText: '' });
                s.addText(p, { x: MX + 0.45, y, w: 7.0, h, fontSize: 15, color: BRAND.text, valign: 'top', margin: 0, isTextBox: true });
                y += h + 0.08;
            }
            if (y > 6.95) WARN.push(`${this.meta.num}#${this.n}: finale overflows`);
            const px = 8.4, pw = W - MX - px;
            s.addShape(this.pres.shapes.ROUNDED_RECTANGLE, { x: px, y: 1.55, w: pw, h: 5.37, rectRadius: 0.1, fill: { color: t.softer }, line: { color: t.line, width: 0.75 } });
            s.addText([{ text: 'NEXT STEPS & HELP', options: { fontSize: 10.5, bold: true, color: t.hex, charSpacing: 1.5, breakLine: true } }, ...next.map((n, i) => ({ text: n, options: { fontSize: 13, color: BRAND.text, bullet: { indent: 14 }, breakLine: i < next.length - 1, paraSpaceAfter: 5 } }))],
                { x: px + 0.25, y: 1.75, w: pw - 0.5, h: 3.9, valign: 'top', margin: 0, isTextBox: true });
            await this.button(s, { x: px + 0.25, y: 5.95, w: pw - 0.5, h: 0.5 }, 'Back to the main contents', 'toc', 'Home: main contents', { fill: BRAND.iris, icon: 'home', iconColor: 'FFFFFF', size: 12.5, name: 'Back to contents' });
            s.addText(lib.RELEASE_LINE, { x: MX, y: 6.62, w: 7.4, h: 0.3, fontSize: 10.5, bold: true, color: BRAND.danger, margin: 0, isTextBox: true });
        });
    }

    // Builders of the base deck that create their own section dividers are replaced by mod()/review().
    section() { throw new Error('ModularDeck: use mod(code) / sub(id) instead of section()'); }
    closing() { throw new Error('ModularDeck: use finale() inside review() instead of closing()'); }

    // ------------------------------------------------------------ build
    async prepare() {
        const ic = (n, c) => D.icon(n, c);
        this.assets = {
            logo: await D.logo(),
            home: await ic('home', 'FFFFFF'),
            prev: await ic('chevron-left', 'FFFFFF'), next: await ic('chevron-right', 'FFFFFF'),
            prevOff: await ic('chevron-left', '5A5F86'), nextOff: await ic('chevron-right', '5A5F86'),
        };
    }

    validatePlan() {
        for (const m of this.mods.values()) {
            for (const k of [m.code, `${m.code}.contents`, `${m.code}.summary`]) if (!this.has(k)) throw new Error(`Module ${m.code} has no slide "${k}" (did you call mod() and summary()?)`);
            for (const x of m.subs) {
                if (!this.has(`${m.code}.${x.id}`)) throw new Error(`Submodule ${x.id} of ${m.code} has no slides`);
            }
            for (const c of m.related ?? []) if (!this.mods.has(c)) throw new Error(`${m.code} relates to unknown module ${c}`);
        }
        if (!this.has('toc')) throw new Error('No contents slide (toc)');
        for (const p of this.plan) if (p.kind === 'content' && p.module && !p.sub && !['contents', 'summary', 'checklist'].includes(p.kind)) WARN.push(`slide ${p.key ?? '?'} in ${p.module} sits outside any submodule`);
    }

    navMap() {
        return {
            deck: this.meta.num, file: this.meta.file, title: this.meta.title, slides: this.plan.length,
            toc: this.go('toc'),
            keys: Object.fromEntries(this.plan.flatMap((p, i) => [[p.key, i + 1], [p.alias, i + 1]]).filter(([k]) => k)),
            plan: this.plan.map((p, i) => ({ n: i + 1, key: p.key, alias: p.alias, kind: p.kind, module: p.module, sub: p.sub, section: p.section })),
            modules: [...this.mods.values()].map((m) => ({
                code: m.code, num: m.num, name: m.name, color: m.color.hex, group: m.group,
                start: this.go(m.code), contents: this.go(`${m.code}.contents`), summary: this.go(`${m.code}.summary`),
                checklist: this.has(`${m.code}.checklist`) ? this.go(`${m.code}.checklist`) : null,
                subs: m.subs.map((x) => ({ id: x.id, name: x.name, start: this.go(`${m.code}.${x.id}`) })),
                related: (m.related ?? []).map((c) => ({ code: c, start: this.go(c) })),
                next: this.nextModule(m.code) ? this.go(this.nextModule(m.code)) : (this.has('review') ? this.go('review') : null),
            })),
            review: this.has('review') ? this.go('review') : null,
            howto: this.has('howto') ? this.go('howto') : null,
        };
    }

    async save() {
        await this.prepare();
        this.defineModularLayouts();
        this.validatePlan();
        const r = await super.save();
        if (this.n !== this.plan.length) throw new Error(`Slide count ${this.n} differs from the plan (${this.plan.length}): a builder created more than one slide per queued item`);
        const dir = path.join(__dirname, '../nav-maps');
        fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(path.join(dir, `${String(this.meta.num).padStart(2, '0')}.json`), JSON.stringify(this.navMap(), null, 2));
        return r;
    }
}

module.exports = { ModularDeck, tw };
