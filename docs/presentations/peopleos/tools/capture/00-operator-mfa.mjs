// Platform operator: first sign-in is forced into authenticator set-up (platform policy: operators must use MFA).
// Enrols the operator through the real set-up screen. The QR code, the manual key and the recovery codes are
// covered before any capture and are never written to a screenshot; the key is kept in tools/.work (gitignored, 0600).
import { start, context, signInPage, shot, finish, idle, settle, totp, saveMfaSecret, passwordFor, PERSONAS } from './lib.mjs';

const s = await start('00-operator-mfa');
const ctx = await context(s, 'operator', { noAuth: true });
const page = await ctx.newPage();
page.__persona = 'operator';

await page.goto(`${process.env.TRAINING_BASE_URL ?? 'http://127.0.0.1:8098'}/admin/login`);
await settle(page);
await page.fill('input[type=email]', PERSONAS.operator.email);
await shot(s, page, 'platform-admin/01-operator-sign-in', {
    deck: [5, 8], workflow: 'Operator sign-in and MFA enrolment', screen: 'Sign in (/admin/login)',
    action: 'The platform operator signs in on the same sign-in page as tenant users', expected: 'E-mail and password fields; Forgot password?',
    callouts: [{ sel: 'input[type=email]', n: 1, label: 'Operator e-mail' }, { sel: 'input[type=password]', n: 2, label: 'Password' }, { sel: 'button[type=submit]', n: 3, label: 'Sign in' }],
});

await signInPage(page, 'operator');
await settle(page);
if (!await page.getByRole('button', { name: 'Set up' }).isVisible().catch(() => false)) { console.log('Operator already enrolled: nothing to do'); await finish(s); process.exit(0); }
await shot(s, page, 'platform-admin/02-mfa-setup-required', {
    deck: [5, 8, 7], workflow: 'Operator sign-in and MFA enrolment', screen: 'Set up two-factor authentication (forced)',
    action: 'An operator without an authenticator is held on the set-up page after the password step',
    expected: 'Nothing else in the application opens until an authenticator app is set up',
    prereq: 'Platform policy PEOPLEOS_PLATFORM_MFA_REQUIRED (default true)',
    callouts: [{ loc: page.getByRole('button', { name: 'Set up' }), n: 1, label: 'Set up' }],
});

await page.getByRole('button', { name: 'Set up' }).click();
await idle(page, 1500);
const modal = page.locator('.fi-modal-window:visible').first();
const secret = (await modal.innerHTML()).match(/[A-Z2-7]{16,}/)[0];
saveMfaSecret('operator', secret);

// Cover the QR code and the manual key (a secret) before capturing the step.
await page.evaluate(() => {
    const m = [...document.querySelectorAll('.fi-modal-window')].find((e) => e.offsetParent !== null);
    const cover = (el, label) => {
        const r = el.getBoundingClientRect();
        const d = document.createElement('div');
        Object.assign(d.style, { position: 'fixed', left: `${r.left - 4}px`, top: `${r.top - 4}px`, width: `${r.width + 8}px`, height: `${r.height + 8}px`,
            background: '#e5e7eb', border: '2px dashed #6b7280', borderRadius: '8px', zIndex: 2147483647, display: 'flex', alignItems: 'center',
            justifyContent: 'center', font: '600 13px system-ui', color: '#374151' });
        d.textContent = label;
        document.body.appendChild(d);
    };
    const qr = m.querySelector('img, svg[viewBox]:not(.fi-icon)');
    const qrBox = [...m.querySelectorAll('img, svg')].sort((a, b) => b.getBoundingClientRect().width - a.getBoundingClientRect().width)[0];
    cover(qrBox ?? qr, 'QR code redacted');
    const walker = document.createTreeWalker(m, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        if (/[A-Z2-7]{16,}/.test(walker.currentNode.textContent)) cover(walker.currentNode.parentElement, 'Key redacted');
    }
});
await shot(s, page, 'platform-admin/03-mfa-setup-modal-redacted', {
    deck: [5, 8], workflow: 'Operator sign-in and MFA enrolment', screen: 'Set up authenticator app (modal)',
    action: 'Scan the QR code (or type the key) in an authenticator app, enter the 6-digit code and the current password, then Next',
    expected: 'Set-up completes only with a valid code and the current password',
    note: 'QR code and manual key covered for the screenshot (secret). Nothing else altered.',
    callouts: [{ loc: modal.locator('input[autocomplete=off]').first(), n: 1, label: '6-digit code' }, { loc: modal.locator('input[type=password]').first(), n: 2, label: 'Current password' }, { loc: modal.getByRole('button', { name: 'Next' }), n: 3, label: 'Next' }],
});
await page.evaluate(() => document.querySelectorAll('body > div[style*="dashed"]').forEach((e) => e.remove()));

await modal.locator('input[autocomplete=off]').first().click();
await page.keyboard.type(totp(secret), { delay: 60 });
await modal.locator('input[type=password]').first().fill(passwordFor('operator'));
await modal.getByRole('button', { name: 'Next' }).click();
await idle(page, 2000);
const after = await page.locator('.fi-modal-window:visible').first().innerText().catch(() => '');
console.log('After Next:', after.replace(/[A-Za-z0-9]{8,}-?[A-Za-z0-9]*/g, '<code>').slice(0, 400));
// Recovery codes are shown once: close without capturing them.
const close = page.locator('.fi-modal-window:visible').getByRole('button', { name: /^Enable/i }).first();
console.log('Modal buttons:', await page.locator('.fi-modal-window:visible button').allInnerTexts());
if (await close.isVisible().catch(() => false)) { await close.click(); await idle(page, 2000); } else { console.log('Enable button not visible'); }
console.log('Toasts:', await page.locator('.fi-no-notification').allInnerTexts().catch(() => []));
await page.goto(`${process.env.TRAINING_BASE_URL ?? 'http://127.0.0.1:8098'}/admin`);
await settle(page);
console.log('Operator now at', page.url());
await ctx.storageState({ path: new URL(`../.work/auth/operator-${new URL(process.env.TRAINING_BASE_URL ?? 'http://127.0.0.1:8098').port}.json`, import.meta.url).pathname });
await finish(s);
