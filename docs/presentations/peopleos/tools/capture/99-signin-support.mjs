// Deck 7: sign-in and account support states, captured from the real screens on the disposable training database:
// wrong password, forgot password, a complete password reset by e-mail link, and an invited user who has not activated.
// Accounts used are fictional Northwind Retail users. Reset links are read from the mail log and never shown.
import { start, context, open, go, shot, finish, idle, settle, toasts, closeToasts, PERSONAS, BASE } from './lib.mjs';
import { accountPassword } from './d-accounts.mjs';
import { latestLink } from './d-mail.mjs';
import { writeFileSync, existsSync } from 'node:fs';
import { randomBytes } from 'node:crypto';

const s = await start('99-signin-support');
const D = { deck: [7], prereq: 'None (public pages)' };
const WORK = new URL('../.work/', import.meta.url).pathname;
const guest = async () => { const p = await (await context(s, 'guest', { fresh: true })).newPage(); p.__persona = 'guest'; return p; };
const hide = (e) => { e.url = e.url.replace(/(invitation|password-reset\/reset)[^ ]*/, '$1/[token]'); return e; };

// 1. Wrong password.
{
    const g = await guest();
    await g.goto(`${BASE}/admin/login`); await settle(g);
    await g.fill('input[type=email]', PERSONAS.nwhr.email);
    await g.fill('input[type=password]', 'not-the-right-password');
    await g.click('button[type=submit]');
    await idle(g, 2000);
    await shot(s, g, 'support/99-sup-01-wrong-password', { ...D, workflow: 'Login problems', screen: 'Sign in (failed)', action: 'Sign in with a wrong password',
        expected: '"These credentials do not match our records." — the same message for a wrong password, an unknown e-mail, an invited (not activated) or a suspended account',
        callouts: [{ loc: g.locator('.fi-fo-field-wrp-error-message'), n: 1, label: 'Generic message' }, { loc: g.getByRole('link', { name: 'Forgot password?' }), n: 2, label: 'Forgot password?' }] });
}

// 2. Forgot password → reset by link → sign in.
{
    const g = await guest();
    await g.goto(`${BASE}/admin/password-reset/request`); await settle(g);
    await g.locator('[id="form.email"]').fill(PERSONAS.nwhr.email);
    await shot(s, g, 'support/99-sup-02-forgot-password', { ...D, workflow: 'Password reset', screen: 'Forgot password?', action: 'Enter the work e-mail address and Send email',
        expected: 'The same answer whether or not the address exists', callouts: [{ loc: g.locator('[id="form.email"]'), n: 1, label: 'E-mail address' }, { loc: g.getByRole('button', { name: 'Send email' }), n: 2, label: 'Send email' }] });
    await g.getByRole('button', { name: 'Send email' }).click();
    await idle(g, 2500);
    console.log('    reset request:', (await toasts(g)).join(' / '));
    await shot(s, g, 'support/99-sup-03-reset-requested', { ...D, workflow: 'Password reset', screen: 'Forgot password? (after Send email)', action: 'Send email',
        expected: '"If an account uses that address, we have sent it a link to choose a new password." The link expires in 60 minutes', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Neutral answer' }] });
    const link = latestLink(PERSONAS.nwhr.email, /\/admin\/password-reset\/reset/);
    console.log('    reset link found:', !!link);
    if (link) {
        const r = await guest();
        await r.goto(link); await settle(r);
        const pw = `Tr-${randomBytes(9).toString('base64url')}-8`;
        await r.locator('input[type=password]').nth(0).fill(pw);
        await r.locator('input[type=password]').nth(1).fill(pw);
        hide(await shot(s, r, 'support/99-sup-04-reset-form', { ...D, workflow: 'Password reset', screen: 'Reset your password (link from the e-mail)', action: 'Open the link and choose a new password',
            expected: 'E-mail address, new password and confirmation; Reset password', callouts: [{ loc: r.locator('input[type=password]').nth(0), n: 1, label: 'New password' }, { loc: r.getByRole('button', { name: /Reset password/ }), n: 2, label: 'Reset password' }] }));
        await r.getByRole('button', { name: /Reset password/ }).click();
        await idle(r, 3000);
        console.log('    reset ->', r.url().replace(/reset[^ ]*/, 'reset/[token]'), (await toasts(r)).join(' / '));
        if (!/password-reset\/reset/.test(r.url())) writeFileSync(`${WORK}acct-nw-rohit.pw`, pw, { mode: 0o600 });
        hide(await shot(s, r, 'support/99-sup-05-reset-done', { ...D, workflow: 'Password reset', screen: 'Sign in (after the reset)', action: 'Reset password',
            expected: 'Back to Sign in with a confirmation; other sessions of the account are ended', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] }));
        // The link works once.
        const r2 = await guest();
        await r2.goto(link); await settle(r2);
        await r2.locator('input[type=password]').nth(0).fill(pw);
        await r2.locator('input[type=password]').nth(1).fill(pw);
        await r2.getByRole('button', { name: /Reset password/ }).click().catch(() => {});
        await idle(r2, 2500);
        hide(await shot(s, r2, 'support/99-sup-06-reset-link-used', { ...D, workflow: 'Password reset', screen: 'Reset your password (used link)', action: 'Use the same reset link again',
            expected: 'Refused with one message for any bad, used or expired link', callouts: [] }));
    }
}

// 3. An invited user who never activated the account.
{
    const a = await open(s, 'nwadmin', '/admin/users');
    if (!await a.locator('main').getByText('kunal.bose@northwind.example').count()) {
        await go(a, '/admin/users/create');
        await a.locator('[id="form.name"]').fill('Kunal Bose');
        await a.locator('[id="form.email"]').fill('kunal.bose@northwind.example');
        await a.locator('main button[type=submit]').filter({ hasText: 'Create user' }).first().click();
        await idle(a, 3000);
        console.log('    invited:', (await toasts(a)).join(' / '));
    }
    await go(a, '/admin/users');
    await shot(s, a, 'support/99-sup-07-user-invited', { ...D, workflow: 'Account activation problems', screen: 'Admin › Setup › Users', action: 'Find the user in Users',
        expected: 'Status Invited and last login Never: the person has not yet used the invitation link (it expires after 72 hours)', prereq: 'Tenant administrator (user.manage)',
        callouts: [{ loc: a.locator('tr', { hasText: 'Kunal Bose' }), n: 1, label: 'Invited, never signed in' }] });
    const g = await guest();
    await g.goto(`${BASE}/admin/login`); await settle(g);
    await g.fill('input[type=email]', 'kunal.bose@northwind.example');
    await g.fill('input[type=password]', 'guessing-a-password');
    await g.click('button[type=submit]');
    await idle(g, 2000);
    await shot(s, g, 'support/99-sup-08-invited-cannot-sign-in', { ...D, workflow: 'Account activation problems', screen: 'Sign in (invited user)', action: 'The invited person tries to sign in before activating',
        expected: 'Generic "These credentials do not match our records." — fix: resend the invitation (Tenants › Send invitation for a first administrator; Users for others)', callouts: [{ loc: g.locator('.fi-fo-field-wrp-error-message'), n: 1, label: 'Message' }] });
}
await finish(s);
