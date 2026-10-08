// UX.19 focused screenshots: only the screens UX.19 changes, before and after, first screen (what a person sees without
// scrolling). Chromium; phone 390 × 844 (touch), tablet 768 × 1024 (touch), desktop 1440 × 900; light and dark where
// listed. One sign-in per persona through the normal login form. Fictional showcase data only.
//   BASE=<url> node capture.mjs <out dir>
import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8093';
const PASSWORD = process.env.SHOWCASE_PASSWORD ?? 'password';
const out = process.argv[2];
const SIZES = {
    phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
    tablet: { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
    desktop: { viewport: { width: 1440, height: 900 } },
};
// [name, persona e-mail, path, sizes, themes]
const SHOTS = [
    ['hr-360-header', 'neha.kapoor@demo.local', '/admin/employees/3', ['phone', 'tablet', 'desktop'], ['light', 'dark']],
    ['manager-360-header', 'amit.verma@demo.local', '/admin/employees/3', ['phone'], ['light']],
    ['admin-360-header', 'kavya.menon@demo.local', '/admin/employees/3', ['phone'], ['light']],
    ['employee-own-360', 'priya.nair@demo.local', '/admin/employees/4', ['phone', 'desktop'], ['light', 'dark']],
    ['manager-home', 'amit.verma@demo.local', '/admin', ['phone', 'desktop'], ['light']],
    ['employee-my-hr', 'priya.nair@demo.local', '/admin/my-hr', ['phone', 'desktop'], ['light']],
];

mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const sessions = {};
const list = [];
for (const [name, email, path, sizes, themes] of SHOTS) {
    if (!sessions[email]) {
        const login = await browser.newContext();
        const lp = await login.newPage();
        await lp.goto(`${BASE}/admin/login`);
        await lp.fill('input[type=email]', email);
        await lp.fill('input[type=password]', PASSWORD);
        await Promise.all([lp.waitForURL(/\/admin$/, { timeout: 120_000 }), lp.click('button[type=submit]')]);
        sessions[email] = await login.storageState();
        await login.close();
    }
    for (const size of sizes) {
        for (const theme of themes) {
            const ctx = await browser.newContext({ ...SIZES[size], storageState: sessions[email], colorScheme: theme, reducedMotion: 'reduce' });
            await ctx.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
            const page = await ctx.newPage();
            const res = await page.goto(BASE + path, { waitUntil: 'networkidle' });
            await page.evaluate(() => document.fonts.ready);
            const file = `${name}-${size}-${theme}.png`;
            await page.screenshot({ path: `${out}/${file}` });
            list.push({ file, persona: email, path, size, theme, status: res.status() });
            console.log(file, res.status());
            await ctx.close();
        }
    }
}
await browser.close();
writeFileSync(`${out}/capture-list.json`, JSON.stringify(list, null, 2));
