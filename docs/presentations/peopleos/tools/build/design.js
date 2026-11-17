// Central design configuration for the modular (navigable) PeopleOS training decks.
//
// ONE place to change: brand colours, module colours, learning groups, chrome geometry and icons. The builder
// (build/modular.js) and the navigation checker (build/check-nav.js) read everything from here.
//
// Brand identity is taken from the application, not invented:
//   - colours: resources/css/filament/admin/theme.css (--pos-primary, --pos-gold, --pos-hero-*, --pos-hue-*)
//   - logo:    public/favicon.svg (the PeopleOS mark: iris tile, white head, gold shoulders)
//   - icons:   the Heroicons set the application renders (vendor/blade-ui-kit/blade-heroicons) and the rail's own
//              Home icon (resources/svg/peopleos/rail-home.svg)
'use strict';
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');

const APP = path.resolve(__dirname, '../../../../..');                 // the hcms application root
const ICON_CACHE = path.join(__dirname, '../.work/icons');

// ---------------------------------------------------------------- brand (theme.css tokens)
const BRAND = {
    iris: '574BC4',          // --pos-primary: primary action, selection, links
    irisStrong: '463BA6',    // --pos-primary-strong
    irisSoft: 'EFEEFB',      // --pos-primary-soft
    gold: 'C9A25A',          // --pos-gold (decorative)
    goldText: 'E2C48C',      // the favicon's shoulder stroke: gold that reads on ink
    ink: '12163A',           // --pos-hero-ink: nav bar, cover
    inkMid: '262170',        // --pos-hero-mid
    inkRaised: '262B57',     // nav buttons on the ink bar (ink lifted ~8%)
    inkLine: '3A4074',       // button outline on the ink bar
    onInk: 'FFFFFF',
    onInkMuted: 'C3C6DD',    // secondary text on ink (≥ 7:1)
    onInkFaint: '9DA2C2',    // tertiary text on ink (≥ 4.5:1)
    text: '15171D',          // --pos-n-900
    textSecondary: '4B515D', // --pos-n-600
    textMuted: '646A77',     // --pos-n-500
    border: 'E6E8EC',        // --pos-n-150
    surfaceMuted: 'EEF0F3',  // --pos-n-100
    canvas: 'F7F8FA',
    success: '0F7A55', successSoft: 'E4F3EC',
    warning: '9A5309', warningSoft: 'FBF0DC',
    danger: 'B42335', dangerSoft: 'FBE8EB',
    info: '2A5FBF', infoSoft: 'E7EEFA',
};

// ---------------------------------------------------------------- module colours
// Eight come straight from the application's wayfinding hues (--pos-hue-*), matched to the module the product
// catalogue gives them (app/Domain/Website/Support/SiteCatalogue.php) wherever that hue is still free. Where two
// modules of this course would share a product hue, the second gets a deck-only extension hue at the same depth.
// Every module colour carries white text at ≥ 4.5:1 (checked on every build by assertContrast()).
const PALETTE = {
    deepIris: { hex: '2B2577', source: '--pos-hero-on-light (brand)' },
    blue: { hex: '2F5FB3', source: '--pos-hue-blue' },
    violet: { hex: '7442A8', source: 'deck extension' },
    teal: { hex: '0E6F7A', source: '--pos-hue-teal' },
    iris: { hex: '574BC4', source: '--pos-hue-iris' },
    olive: { hex: '5A6A17', source: 'deck extension' },
    green: { hex: '1D7656', source: '--pos-hue-green' },
    gold: { hex: '8A6418', source: '--pos-hue-gold' },
    coral: { hex: 'B04A22', source: '--pos-hue-coral' },
    plum: { hex: '94416F', source: '--pos-hue-plum' },
    ocean: { hex: '146C94', source: 'deck extension' },
    slate: { hex: '4A5878', source: '--pos-hue-slate' },
    umber: { hex: '6B5444', source: 'deck extension' },
    // Extensions for the 26-module Organisation Administrator course (same depth; white text ≥ 4.5:1).
    steel: { hex: '3C6E8F', source: 'deck extension' },
    pine: { hex: '2F6B3A', source: 'deck extension' },
    rose: { hex: 'A33A5C', source: 'deck extension' },
    magenta: { hex: '8E2C8A', source: 'deck extension' },
    indigo: { hex: '3B3F9E', source: 'deck extension' },
    bronze: { hex: '8B4A2B', source: 'deck extension' },
    cyan: { hex: '0B6F86', source: 'deck extension' },
    berry: { hex: '7D2E4F', source: 'deck extension' },
    navy: { hex: '1F3B73', source: 'deck extension' },
    graphite: { hex: '4B4F57', source: 'deck extension' },
    forest: { hex: '2E5E2E', source: 'deck extension' },
    wine: { hex: '6E2340', source: 'deck extension' },
    ink: { hex: '1E2A44', source: 'deck extension' },
};

// ---------------------------------------------------------------- chrome geometry (inches, 13.333 × 7.5 slide)
const CHROME = {
    W: 13.333, H: 7.5, MX: 0.6,
    nav: { h: 0.5, btnY: 0.09, btnH: 0.32 },
    eyebrowY: 0.58, eyebrowH: 0.24,
    titleY: 0.84, titleH: 0.6, titleSize: 26,
    contentTop: 1.48, contentBottom: 6.98,      // every content builder stays inside this band
    footer: { y: 7.07, h: 0.26 },
    pillY: 0.56, pillH: 0.27,                   // status pill that sits in the eyebrow row (wide screenshots)
};

const FONTS = { head: 'Cambria', body: 'Calibri' };

// ---------------------------------------------------------------- colour maths
const rgb = (hex) => [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));
const hexOf = (c) => c.map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('').toUpperCase();
/** Mix a colour with white (t = share of white, 0..1). */
const tint = (hex, t) => hexOf(rgb(hex).map((v) => v + (255 - v) * t));
/** Mix a colour with black (t = share of black). */
const shade = (hex, t) => hexOf(rgb(hex).map((v) => v * (1 - t)));
const lum = (hex) => {
    const [r, g, b] = rgb(hex).map((v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

/** Derived tones for one module colour. */
function tones(hex) {
    return {
        hex,                         // fills with white text: chips, dividers, badges, table headers
        soft: tint(hex, 0.9),        // card and result-box backgrounds
        softer: tint(hex, 0.95),
        line: tint(hex, 0.7),        // outlines of module-coloured cards
        onInk: tint(hex, 0.45),      // the same hue where it sits on the ink nav bar (progress fill)
        deep: shade(hex, 0.25),      // divider background depth
    };
}

function assertContrast(modules) {
    const bad = [];
    for (const m of modules) {
        const c = contrast(m.color.hex, 'FFFFFF');
        if (c < 4.5) bad.push(`${m.code} ${m.name}: white on ${m.color.hex} = ${c.toFixed(2)}:1`);
        const t = contrast(m.color.hex, m.color.soft);
        if (t < 4.5) bad.push(`${m.code} ${m.name}: ${m.color.hex} on its soft tint = ${t.toFixed(2)}:1`);
    }
    for (const [a, b] of [[BRAND.onInkMuted, BRAND.ink], [BRAND.onInkFaint, BRAND.ink], [BRAND.textMuted, 'FFFFFF']]) {
        if (contrast(a, b) < 4.5) bad.push(`${a} on ${b} = ${contrast(a, b).toFixed(2)}:1`);
    }
    if (bad.length) throw new Error(`Colour contrast below 4.5:1\n${bad.join('\n')}`);
}

// ---------------------------------------------------------------- icons (rendered from the application's SVGs)
const HEROICONS = path.join(APP, 'vendor/blade-ui-kit/blade-heroicons/resources/svg');
const ICON_SOURCES = {
    home: path.join(APP, 'resources/svg/peopleos/rail-home.svg'),
};
function iconSvg(name) {
    const file = ICON_SOURCES[name] ?? path.join(HEROICONS, `o-${name}.svg`);
    if (!fs.existsSync(file)) throw new Error(`Icon source missing: ${file}`);
    return fs.readFileSync(file, 'utf8');
}
/** PNG (base64 data URI for pptxgenjs) of an application icon in one colour. Cached under .work/icons. */
async function icon(name, color, px = 192) {
    fs.mkdirSync(ICON_CACHE, { recursive: true });
    const out = path.join(ICON_CACHE, `${name}-${color}-${px}.png`);
    if (!fs.existsSync(out)) {
        const svg = iconSvg(name).replace(/currentColor/g, `#${color}`).replace(/stroke-width="[\d.]+"/, 'stroke-width="1.8"');
        await sharp(Buffer.from(svg), { density: 600 }).resize(px, px).png().toFile(out);
    }
    return 'image/png;base64,' + fs.readFileSync(out).toString('base64');
}
/** The PeopleOS mark from public/favicon.svg. */
async function logo(px = 256) {
    fs.mkdirSync(ICON_CACHE, { recursive: true });
    const out = path.join(ICON_CACHE, `logo-${px}.png`);
    if (!fs.existsSync(out)) await sharp(fs.readFileSync(path.join(APP, 'public/favicon.svg')), { density: 600 }).resize(px, px).png().toFile(out);
    return 'image/png;base64,' + fs.readFileSync(out).toString('base64');
}

module.exports = { APP, BRAND, PALETTE, CHROME, FONTS, tint, shade, contrast, tones, assertContrast, icon, logo };
