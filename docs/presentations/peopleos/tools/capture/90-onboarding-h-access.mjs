// Deck 4 stage 7: roles, a second user with a role and an access scope, and that user's activation.
// EXECUTED on the disposable training database (Northwind Retail). Re-runnable.
import { start, open, context, go, shot, finish, idle, settle, toasts, closeToasts, dump } from './lib.mjs';
import { accountPassword, activated, markActivated } from './d-accounts.mjs';
import { latestLink } from './d-mail.mjs';
import { chooseSearchable } from './d-ui.mjs';

const s = await start('90-onboarding-h-access');
const D = { deck: [4, 8], workflow: 'Roles, users and access scope', prereq: 'Signed in as the tenant administrator (user.manage, role.view)' };
const pg = await open(s, 'nwadmin', '/admin/roles');
const hide = (e) => { e.url = e.url.replace(/invitation\/[A-Za-z0-9]+/, 'invitation/[token]'); return e; };

await shot(s, pg, 'client-onboarding/90-onb-54-roles', { ...D, screen: 'Admin › Setup › Roles', action: 'Open Roles', expected: '14 system roles provisioned with the tenant (Account Owner … Tenant Super Admin), each with its permission count and users; New role',
    callouts: [{ loc: pg.locator('tr', { hasText: 'HR Executive' }), n: 1, label: 'System role' }, { loc: pg.getByRole('link', { name: 'New role' }), n: 2, label: 'New role' }] });
await pg.locator('tr', { hasText: 'HR Executive' }).getByRole('link', { name: 'Edit' }).click();
await idle(pg, 2000);
await shot(s, pg, 'client-onboarding/90-onb-55-role-permissions', { ...D, screen: 'Roles › HR Executive (edit)', action: 'Open a role to see its permissions', expected: 'Permission keys grouped by module; only a Tenant Super Admin edits role permissions',
    callouts: [] });

await go(pg, '/admin/users');
if (!await pg.locator('main').getByText('rohit.menon@northwind.example').count()) {
    await go(pg, '/admin/users/create');
    await pg.setViewportSize({ width: 1440, height: 1250 });
    await pg.locator('[id="form.name"]').fill('Rohit Menon');
    await pg.locator('[id="form.email"]').fill('rohit.menon@northwind.example');
    await chooseSearchable(pg, pg.locator('main'), 0, 'HR Executive', 'HR Exec');
    await pg.keyboard.press('Escape');
    await idle(pg, 500);
    // Access scope: Companies is the second select once the role is chosen.
    const companies = pg.locator('main label', { hasText: /^\s*Companies\s*$/ }).locator('xpath=ancestor::div[.//button][1]');
    await companies.locator('button').first().click();
    await idle(pg, 500);
    await pg.keyboard.type('Northwind', { delay: 30 });
    await idle(pg, 1200);
    await pg.locator('[role=option]:visible, .fi-select-input-option:visible').filter({ hasText: 'Northwind Retail' }).first().click();
    await pg.keyboard.press('Escape');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-56-new-user', { ...D, screen: 'Admin › Setup › Users › New user', action: 'Name, e-mail, role HR Executive, access scope limited to the Northwind Retail company',
        expected: 'The user is invited by e-mail; the scope restricts which employees and units they can reach (dimensions combine with AND)',
        callouts: [{ loc: pg.locator('[id="form.email"]'), n: 1, label: 'Work e-mail' }, { loc: pg.locator('main button', { hasText: 'HR Executive' }), n: 2, label: 'Roles' }, { loc: pg.locator('main button', { hasText: 'Northwind Retail' }), n: 3, label: 'Access scope: company' }, { loc: pg.getByRole('button', { name: 'Create user', exact: true }), n: 4, label: 'Create user' }] });
    await pg.locator('main button[type=submit]').filter({ hasText: 'Create user' }).first().click();
    await idle(pg, 3000);
    console.log('    user', pg.url(), (await toasts(pg)).join(' / '));
    await pg.setViewportSize({ width: 1440, height: 900 });
    await shot(s, pg, 'client-onboarding/90-onb-57-user-created', { ...D, screen: 'Users › Edit user (after Create user)', action: 'Create user', expected: 'Status Invited; account actions such as Send invitation again',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
    await closeToasts(pg);
}
await go(pg, '/admin/users');
await shot(s, pg, 'client-onboarding/90-onb-58-users', { ...D, screen: 'Admin › Setup › Users', action: 'Back to the Users list', expected: 'Farah Khan (Tenant Super Admin, Active) and Rohit Menon (HR Executive, Invited)',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Rohit Menon' }), n: 1, label: 'Invited user' }] });

// Rohit activates the account and signs in: an HR Executive sees far fewer menus.
if (!activated('nw-rohit')) {
    const link = latestLink('rohit.menon@northwind.example', /\/admin\/invitation\//);
    if (link) {
        const g = await (await context(s, 'guest', { fresh: true })).newPage();
        await g.goto(link);
        await settle(g);
        const pw = accountPassword('nw-rohit');
        await g.locator('[id="form.password"]').fill(pw);
        await g.locator('[id="form.passwordConfirmation"]').fill(pw);
        await g.getByRole('button', { name: 'Activate my account' }).click();
        await idle(g, 3000);
        if (!/invitation/.test(g.url())) markActivated('nw-rohit');
        console.log('    rohit activation ->', g.url().replace(/invitation\/[A-Za-z0-9]+/, 'invitation/[token]'));
    }
}
if (activated('nw-rohit')) {
    const r = await open(s, 'nwhr', '/admin');
    await shot(s, r, 'client-onboarding/90-onb-59-hr-executive-home', { ...D, workflow: 'User readiness: first sign-in with a limited role', screen: 'Home (HR Executive, Northwind Retail)',
        action: 'The invited HR Executive activates the account and signs in', expected: 'A narrower menu than the administrator (no Admin role editing); Home shows people operations',
        callouts: [{ loc: r.locator('aside nav').first(), n: 1, label: 'Menu for this role' }] });
    await go(r, '/admin/roles');
    await shot(s, r, 'client-onboarding/90-onb-60-hr-executive-roles-denied', { ...D, deck: [4, 8, 7], workflow: 'User readiness: first sign-in with a limited role', screen: 'Roles (as HR Executive)',
        action: 'The HR Executive opens the Roles screen directly', expected: 'Refused: the role does not include role.view', callouts: [] });
}
await finish(s);
