// UX.19 Employee 360 header probe: how much of the first screen the header takes, and where the section bar and the
// viewer panel ("what you are here for") start, per viewer and size. Chromium; phone 390 × 844 (touch), tablet
// 768 × 1024 (touch), desktop 1440 × 900. Sign-in through the normal login form; fictional showcase data only.
//   BASE=<url> node header-probe.mjs <out.json> [label]
import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8093';
const PASSWORD = process.env.SHOWCASE_PASSWORD ?? 'password';
// viewer → the 360 they open (the employee's own record once G12 allows it; a report for the manager; anyone for HR / admin).
const CASES = [
    ['manager', 'amit.verma@demo.local', 3],
    ['hr', 'neha.kapoor@demo.local', 3],
    ['admin', 'kavya.menon@demo.local', 3],
    ['employee (own)', 'priya.nair@demo.local', 4],
];
const SIZES = {
    phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
    tablet: { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
    desktop: { viewport: { width: 1440, height: 900 } },
};

const browser = await chromium.launch();
const rows = [];
for (const [viewer, email, id] of CASES) {
    // One sign-in per viewer (the login limiter allows a few per minute), reused at every size.
    const login = await browser.newContext();
    const lp = await login.newPage();
    await lp.goto(`${BASE}/admin/login`);
    await lp.fill('input[type=email]', email);
    await lp.fill('input[type=password]', PASSWORD);
    await Promise.all([lp.waitForURL(/\/admin$/, { timeout: 120_000 }), lp.click('button[type=submit]')]);
    const storageState = await login.storageState();
    await login.close();
    for (const [size, opts] of Object.entries(SIZES)) {
        const ctx = await browser.newContext({ ...opts, reducedMotion: 'reduce', storageState });
        const page = await ctx.newPage();
        const res = await page.goto(`${BASE}/admin/employees/${id}`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const m = await page.evaluate(() => {
            const box = (sel) => { const el = document.querySelector(sel); if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), height: Math.round(r.height) }; };
            const bar = document.querySelector('.pos-bottom-nav, [data-pos-bottom-nav], nav.pos-bnav');
            const barH = bar && getComputedStyle(bar).display !== 'none' ? Math.round(bar.getBoundingClientRect().height) : 0;
            return { header: box('.pos-360-header'), actions: box('.pos-360-actions'), nav: box('.pos-360-nav'), viewer_panel: box('.pos-360-viewer'), main: box('#pos-main'),
                viewport: innerHeight, bar: barH, overflow: document.documentElement.scrollWidth - innerWidth };
        });
        const firstScreen = m.viewport - m.bar;
        rows.push({ viewer, size, status: res.status(), ...m, header_share_of_first_screen: m.header ? +(m.header.height / firstScreen).toFixed(2) : null,
            viewer_panel_in_first_screen: m.viewer_panel ? m.viewer_panel.top < firstScreen : null });
        console.log(viewer.padEnd(15), size.padEnd(8), res.status(), 'header', m.header?.height, 'nav top', m.nav?.top, 'viewer top', m.viewer_panel?.top, 'first screen', firstScreen);
        await ctx.close();
    }
}
await browser.close();
writeFileSync(process.argv[2], JSON.stringify({ base: BASE, label: process.argv[3] ?? '', rows }, null, 2));
