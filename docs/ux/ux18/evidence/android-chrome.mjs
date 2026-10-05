// UX.18 Android validation: Chrome inside the Android emulator (AVD Pixel 6, Android 14, google_apis x86_64). Not a
// physical device. The host's showcase is reached through `adb reverse tcp:8090 tcp:8090`. Signs in as personas, opens
// each role's Home, checks the role bar, sideways overflow and script errors, opens the command center and presses the
// Android hardware Back key (KEYCODE_BACK), which must close the overlay and keep the page, then Back again leaves it.
//   node android-chrome.mjs <out.json> <shotsDir>
import { _android as android } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const [OUT, SHOTS] = process.argv.slice(2);
mkdirSync(SHOTS, { recursive: true });
const BASE = 'http://127.0.0.1:8090';
const adb = (...args) => execFileSync(process.env.ADB ?? 'adb', args, { encoding: 'utf8' }).trim();
const [device] = await android.devices();
const info = { model: await device.model(), serial: device.serial(), chrome: adb('shell', 'dumpsys', 'package', 'com.android.chrome').match(/versionName=(\S+)/)?.[1] ?? 'unknown', android: adb('shell', 'getprop', 'ro.build.version.release') };
console.log(JSON.stringify(info));
await device.shell('am force-stop com.android.chrome');
const context = await device.launchBrowser();
const rows = [];

async function signIn(page, email) {
    await context.clearCookies();
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', email);
    await page.fill('input[type=password]', process.env.SHOWCASE_PASSWORD ?? 'password');
    await Promise.all([page.waitForURL(/\/admin$/, { timeout: 120000 }), page.click('button[type=submit]')]);
    await page.waitForLoadState('networkidle');
}

const page = await context.newPage();
for (const [role, email] of [['employee', 'priya.nair@demo.local'], ['manager', 'amit.verma@demo.local'], ['hr', 'neha.kapoor@demo.local'], ['executive', 'meera.iyer@demo.local'], ['administrator', 'kavya.menon@demo.local']]) {
    const errors = [];
    const onErr = (e) => errors.push(String(e?.message ?? e).slice(0, 100));
    page.on('pageerror', onErr);
    await signIn(page, email);
    const m = await page.evaluate(() => {
        const nav = document.querySelector('.pos-bottom-nav');
        return { width: innerWidth, overflow: document.documentElement.scrollWidth - innerWidth, bar: !!nav && getComputedStyle(nav).display !== 'none',
            experience: nav?.dataset.experience ?? null, items: [...(nav?.querySelectorAll('.pos-bottom-item') ?? [])].map((a) => (a.lastElementChild?.childNodes[0]?.textContent ?? a.innerText).trim()) };
    });
    await page.screenshot({ path: `${SHOTS}/android-chrome-${role}-home.png` });
    // Arrive on My work through the app, open the command center, then the hardware Back key.
    await page.evaluate(() => window.Livewire.navigate('/admin/my-work'));
    await page.waitForURL(/\/admin\/my-work$/); await page.waitForLoadState('networkidle');
    await page.locator('.pos-command-trigger').first().click();
    await page.waitForFunction(() => document.documentElement.classList.contains('pos-command-open'));
    await page.screenshot({ path: `${SHOTS}/android-chrome-${role}-palette.png` });
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
    await page.waitForTimeout(1200);
    const afterFirstBack = await page.evaluate(() => ({ url: location.pathname, open: document.documentElement.classList.contains('pos-command-open') }));
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
    await page.waitForTimeout(1800);
    const afterSecondBack = await page.evaluate(() => location.pathname).catch(() => 'left the app');
    page.off('pageerror', onErr);
    const row = { role, ...m, errors, backClosesPalette: afterFirstBack.open === false && afterFirstBack.url === '/admin/my-work', secondBackLeaves: afterSecondBack === '/admin', afterSecondBack };
    rows.push(row);
    console.log(JSON.stringify(row));
}
writeFileSync(OUT, JSON.stringify({ environment: 'Android emulator (AVD), not a physical device', ...info, rows }, null, 1));
await context.close();
await device.close();
