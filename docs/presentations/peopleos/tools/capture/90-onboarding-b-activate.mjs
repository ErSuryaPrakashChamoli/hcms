// Deck 4 stage 2: the customer's first administrator activates the account from the invitation e-mail.
// EXECUTED on the disposable training database. The invitation link is read from the training server's mail log and
// is never shown: the e-mail is rendered with links removed, and the page URL (token) is not recorded.
import { start, context, open, shot, finish, idle, settle, toasts, PERSONAS } from './lib.mjs';
import { latestLink, redactedEmailFile } from './d-mail.mjs';
import { existsSync, writeFileSync } from 'node:fs';
import { accountPassword } from './d-accounts.mjs';

const WORK = new URL('../.work/', import.meta.url).pathname;

const EMAIL = 'farah.khan@northwind.example';
const s = await start('90-onboarding-b-activate');
const D = { deck: [4], workflow: 'First administrator activation', prereq: 'Tenant provisioned by the operator; invitation e-mail delivered' };
const hide = (e) => { e.url = e.url.replace(/invitation\/[A-Za-z0-9]+/, 'invitation/[token]'); return e; };

const already = existsSync(`${WORK}acct-nw-farah.pw`) && existsSync(`${WORK}acct-nw-farah.activated`);
const link = latestLink(EMAIL, /\/admin\/invitation\//);
if (!already && link) {
    const mailFile = `${WORK}mail/invitation-farah.html`;
    redactedEmailFile(EMAIL, mailFile);
    const g = await (await context(s, 'guest')).newPage();
    await g.goto(`file://${mailFile}`);
    await settle(g);
    hide(await shot(s, g, 'client-onboarding/90-onb-07-invitation-email', { ...D, screen: 'Invitation e-mail (rendered from the outgoing message)',
        action: 'The first administrator receives "You have been invited to Northwind Retail Pvt Ltd on PeopleOS"',
        expected: 'Accept the invitation button; the link works once and expires after 72 hours',
        note: 'Rendered from the message the training server wrote to its mail log; links removed. In this development environment the sender and footer show the framework default name ("Laravel") because APP_NAME / MAIL_FROM_NAME are not set: a production configuration item.',
        callouts: [{ loc: g.getByText('Accept the invitation').first(), n: 1, label: 'Accept the invitation' }, { loc: g.getByText('expires on', { exact: false }), n: 2, label: 'Expiry' }] }));

    await g.goto(link);
    await settle(g);
    hide(await shot(s, g, 'client-onboarding/90-onb-08-accept-invitation', { ...D, screen: 'Accept your invitation (/admin/invitation/…)',
        action: 'Open the link from the e-mail', expected: 'Choose a password and confirm it; Activate my account',
        callouts: [{ loc: g.locator('[id="form.password"]'), n: 1, label: 'Password' }, { loc: g.locator('[id="form.passwordConfirmation"]'), n: 2, label: 'Confirm password' }, { loc: g.getByRole('button', { name: 'Activate my account' }), n: 3, label: 'Activate my account' }] }));

    // A mismatch first: the real validation message.
    await g.locator('[id="form.password"]').fill('short');
    await g.locator('[id="form.passwordConfirmation"]').fill('different');
    await g.getByRole('button', { name: 'Activate my account' }).click();
    await idle(g, 1500);
    hide(await shot(s, g, 'client-onboarding/90-onb-09-password-rules', { ...D, deck: [4, 7], screen: 'Accept your invitation (validation)',
        action: 'Submit a short password that does not match its confirmation', expected: 'Password rules and mismatch messages; the account stays invited',
        callouts: [{ loc: g.locator('.fi-fo-field-wrp-error-message'), n: 1, label: 'Validation message' }] }));

    const pw = accountPassword('nw-farah');
    await g.locator('[id="form.password"]').fill(pw);
    await g.locator('[id="form.passwordConfirmation"]').fill(pw);
    await g.getByRole('button', { name: 'Activate my account' }).click();
    await idle(g, 3000);
    console.log('    after activate:', g.url().replace(/invitation\/[A-Za-z0-9]+/, 'invitation/[token]'), (await toasts(g)).join(' / '));
    if (!/invitation/.test(g.url())) writeFileSync(`${WORK}acct-nw-farah.activated`, new Date().toISOString());
    hide(await shot(s, g, 'client-onboarding/90-onb-10-first-sign-in-home', { ...D, screen: 'Home of a new, empty tenant',
        action: 'Activate my account', expected: 'Signed in to the new tenant as its Tenant Super Admin; Home shows the administration lens',
        callouts: [{ loc: g.getByRole('heading').first(), n: 1, label: 'Greeting' }] }));

    // The link works once.
    const g2 = await (await context(s, 'guest', { fresh: true })).newPage();
    await g2.goto(link);
    await settle(g2);
    hide(await shot(s, g2, 'client-onboarding/90-onb-11-invitation-used', { ...D, deck: [4, 7], workflow: 'First administrator activation: link reuse', screen: 'Accept your invitation (used link)',
        action: 'Open the same invitation link again', expected: 'The used link is refused (one-time link)',
        callouts: [] }));
} else {
    console.log('    activation already done or no link: skipping');
}
await finish(s);
