// Capture script for the "Your workspace" chapter (prefix ws). Read-only: opens screens, menus and dialogs,
// never submits a decision, never changes passwords, MFA, settings or seeded records.
// Usage: node ws.mjs [shot-number ...]   (no arguments = all)
import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';

const want = new Set(process.argv.slice(2));
const s = await start('ws');
const problems = [];

async function clipAround(page, loc, pad = 28) {
    const b = await loc.boundingBox();
    const vp = page.viewportSize();
    const x = Math.max(0, b.x - pad), y = Math.max(0, b.y - pad);
    return { x, y, width: Math.min(vp.width - x, b.width + pad * 2), height: Math.min(vp.height - y, b.height + pad * 2) };
}
async function mark(page, name, items) {
    const missing = await annotate(page, items);
    if (missing.length) { problems.push(`${name}: MISSING ${missing.join(', ')}`); console.log('MISSING', name, missing); }
}
const sec = (page, title) => page.locator(`.pos-sec-title:has-text("${title}")`).first();

const shots = {
    async '01'() {
        const p = await open(s, 'guest', '/admin/login');
        await mark(p, 'ws-01', [
            { sel: 'input[type=email]', n: 1 }, { sel: 'input[type=password]', n: 2 }, { sel: 'text=Forgot password?', n: 3 },
            { loc: p.locator('label:has-text("Remember me")').first(), n: 4 }, { sel: 'button[type=submit]', n: 5 },
        ]);
        await shot(p, 'ws-01-sign-in', { clip: await clipAround(p, p.locator('.fi-simple-main').first(), 40) });
        record(s, { name: 'ws-01-sign-in', persona: 'guest', path: '/admin/login', caption: 'The PeopleOS sign-in page' });
    },
    async '02'() {
        const p = await open(s, 'guest', '/admin/password-reset/request');
        await mark(p, 'ws-02', [{ sel: 'input[type=email]', n: 1 }, { sel: 'button[type=submit]', n: 2 }, { sel: 'text=back to login', n: 3 }]);
        await shot(p, 'ws-02-forgot-password', { clip: await clipAround(p, p.locator('.fi-simple-main').first(), 40) });
        record(s, { name: 'ws-02-forgot-password', persona: 'guest', path: '/admin/password-reset/request', caption: 'Forgot password page' });
    },
    async '03'() {
        const p = await open(s, 'employee', '/admin/profile');
        await mark(p, 'ws-03', [
            { sel: 'input[type=password]', n: 1 }, { loc: p.getByRole('button', { name: 'Save changes' }), n: 2 },
            { loc: p.getByText('Authenticator app', { exact: true }).first(), n: 3 }, { loc: p.getByRole('button', { name: 'Set up' }), n: 4 },
        ]);
        await shot(p, 'ws-03-account-security', { clip: { x: 0, y: 0, width: 1440, height: 450 } });
        record(s, { name: 'ws-03-account-security', persona: 'employee', path: '/admin/profile', caption: 'Account security page (password and authenticator app)' });
    },
    async '04'() {
        const p = await open(s, 'employee', '/admin');
        await mark(p, 'ws-04', [
            { sel: '.pos-ws-brief', n: 1 }, { sel: '[data-pos-action="request_leave_header"]', n: 2 },
            { loc: p.locator('.pos-ws-actions button:has-text("Start something")'), n: 3 }, { sel: '.pos-welcome-note', n: 4 },
            { loc: sec(p, 'For you'), n: 5 }, { loc: p.getByRole('button', { name: 'Not now' }).first(), n: 6 },
            { sel: '.pos-intel-title', n: 7 },
        ]);
        await shot(p, 'ws-04-home-employee');
        record(s, { name: 'ws-04-home-employee', persona: 'employee', path: '/admin', caption: 'Home for an employee (For you view)' });
    },
    async '05'() {
        const p = await open(s, 'employee', '/admin');
        const y = await sec(p, 'Your day').evaluate((e) => e.getBoundingClientRect().top + window.scrollY);
        await p.evaluate((y) => window.scrollTo(0, y - 110), y);
        await p.waitForTimeout(500);
        console.log('scrollY', await p.evaluate(() => window.scrollY));
        await mark(p, 'ws-05', [
            { loc: sec(p, 'Your day'), n: 1 }, { loc: p.getByRole('button', { name: 'Check in' }).first(), n: 2 },
            { loc: sec(p, 'My requests'), n: 3 }, { loc: sec(p, 'What changed'), n: 4 },
            { loc: sec(p, 'Your people'), n: 5 }, { loc: sec(p, 'Your momentum'), n: 6 }, { loc: sec(p, 'Start something'), n: 7 },
        ]);
        await shot(p, 'ws-05-home-employee-day', { fullPage: false });
        record(s, { name: 'ws-05-home-employee-day', persona: 'employee', path: '/admin', caption: 'Lower part of the employee Home: Your day, My requests, What changed' });
    },
    async '06'() {
        const p = await open(s, 'manager', '/admin');
        await mark(p, 'ws-06', [
            { sel: '.pos-lens-scroll', n: 1 }, { loc: p.locator('.pos-ws-actions a:has-text("Review")'), n: 2 },
            { loc: sec(p, 'Decisions waiting for you'), n: 3 }, { loc: p.getByRole('button', { name: 'Review' }).first(), n: 4 },
            { sel: '.pos-intel-title', n: 5 },
        ]);
        await shot(p, 'ws-06-home-manager');
        record(s, { name: 'ws-06-home-manager', persona: 'manager', path: '/admin', caption: 'Home for a manager, with the View as chips and Decisions waiting for you' });
    },
    async '07'() {
        const p = await open(s, 'hr', '/admin');
        await mark(p, 'ws-07', [{ sel: '.pos-lens-scroll', n: 1 }, { loc: sec(p, 'People operations'), n: 2 }, { loc: p.getByText('Probation decisions overdue').first(), n: 3 }]);
        await shot(p, 'ws-07-home-hr');
        record(s, { name: 'ws-07-home-hr', persona: 'hr', path: '/admin', caption: 'Home for an HR Manager (People operations view)' });
    },
    async '08'() {
        const p = await open(s, 'executive', '/admin');
        await mark(p, 'ws-08', [{ sel: '.pos-ws-brief', n: 1 }, { loc: p.locator('.pos-ws-actions a:has-text("Open Workforce pulse")'), n: 2 }, { loc: sec(p, 'Workforce pulse'), n: 3 }]);
        await shot(p, 'ws-08-home-executive');
        record(s, { name: 'ws-08-home-executive', persona: 'executive', path: '/admin', caption: 'Home for an executive (Company view)' });
    },
    async '09'() {
        const p = await open(s, 'admin', '/admin');
        await mark(p, 'ws-09', [{ loc: p.locator('.pos-ws-actions a:has-text("Open Admin Centre")'), n: 1 }, { loc: sec(p, 'Governance'), n: 2 }, { loc: sec(p, 'At a glance'), n: 3 }]);
        await shot(p, 'ws-09-home-admin');
        record(s, { name: 'ws-09-home-admin', persona: 'admin', path: '/admin', caption: 'Home for a Tenant HR Admin (Administration view)' });
    },
    async '10'() {
        const p = await open(s, 'employee', '/admin/my-work');
        await p.locator('.fi-user-menu-trigger').click();
        await p.waitForTimeout(700);
        const panel = p.locator('.fi-dropdown-panel:visible').last();
        await mark(p, 'ws-10', [
            { sel: '.pos-command-trigger', n: 1 }, { sel: '.fi-topbar-database-notifications-btn', n: 2 }, { sel: '.pos-quick-launch', n: 3 },
            { loc: panel.getByText('Account security'), n: 4 }, { loc: panel.locator('.fi-theme-switcher').first(), n: 5 },
            { loc: panel.getByText('My profile'), n: 6, badge: 'tl' }, { loc: panel.getByText('Preferences'), n: 7 },
        ]);
        await shot(p, 'ws-10-top-bar-and-avatar-menu');
        record(s, { name: 'ws-10-top-bar-and-avatar-menu', persona: 'employee', path: '/admin/my-work', caption: 'Top bar and the avatar menu with personal pages' });
    },
    async '11'() {
        const p = await open(s, 'manager', '/admin/leave-requests');
        await p.locator('.pos-spacebar-more').click();
        await p.waitForTimeout(800);
        await mark(p, 'ws-11', [
            { loc: p.locator('.fi-sidebar a:has-text("My work")').first(), n: 1 },
            { loc: p.locator('.fi-sidebar a').filter({ hasText: /^\s*Time & leave\s*$/ }).first(), n: 2 },
            { sel: '.pos-spacebar-title', n: 3 }, { sel: '.pos-spacebar-scroll', n: 4 }, { sel: '.pos-spacebar-more', n: 5 }, { sel: '.pos-spacebar-find', n: 6 },
        ]);
        await shot(p, 'ws-11-navigation-area-bar');
        record(s, { name: 'ws-11-navigation-area-bar', persona: 'manager', path: '/admin/leave-requests', caption: 'Sidebar sub-menu, area bar and the All in Time & leave menu' });
    },
    async '12'() {
        const p = await open(s, 'manager', '/admin/my-work');
        await p.keyboard.press('Control+k');
        await p.waitForTimeout(1200);
        await p.locator('.pos-command-input').fill('leave');
        await p.waitForTimeout(2500);
        await settle(p, 800);
        await mark(p, 'ws-12', [{ sel: '.pos-command-input', n: 1 }, { sel: '.pos-command-modes', n: 2 }, { loc: p.locator('.pos-command-group').first(), n: 3 }]);
        await shot(p, 'ws-12-command-center');
        record(s, { name: 'ws-12-command-center', persona: 'manager', path: '/admin/my-work (Ctrl K, "leave")', caption: 'Search and command center after typing "leave"' });
        console.log('cmd groups', await p.locator('.pos-command-group').allInnerTexts());
    },
    async '13'() {
        const p = await open(s, 'employee', '/admin/my-work');
        await p.locator('.pos-rail-ai').click();
        await p.waitForTimeout(1200);
        const panel = p.locator('.pos-ai-panel');
        console.log('ai panel', (await panel.innerText()).slice(0, 800));
        await mark(p, 'ws-13', [{ loc: panel.locator('[role=tablist]').first(), n: 1 }, { loc: panel.locator('[aria-label="Suggested questions"]').first(), n: 2 }, { loc: panel.locator('textarea, input[type=text]').first(), n: 3 }]);
        await shot(p, 'ws-13-ask-peopleos');
        record(s, { name: 'ws-13-ask-peopleos', persona: 'employee', path: '/admin/my-work (Ask PeopleOS)', caption: 'The Ask PeopleOS panel' });
    },
    async '14'() {
        const p = await open(s, 'employee', '/admin/my-work');
        await mark(p, 'ws-14', [
            { loc: p.locator('.pos-lens').first(), n: 1 }, { loc: p.getByText('Do this next', { exact: false }).first(), n: 2 },
            { loc: p.getByRole('button', { name: 'Later' }).first(), n: 3 }, { loc: sec(p, 'About you'), n: 4 }, { loc: sec(p, 'At a glance'), n: 5 },
        ]);
        await shot(p, 'ws-14-my-work');
        record(s, { name: 'ws-14-my-work', persona: 'employee', path: '/admin/my-work', caption: 'My work for an employee' });
    },
    async '15'() {
        const p = await open(s, 'manager', '/admin/approvals');
        const card = p.locator('.pos-decision-pane .pos-approval-card:visible').first();
        await mark(p, 'ws-15', [
            { loc: p.locator('.pos-sec-title').filter({ hasText: 'This week' }).first(), n: 1 }, { loc: p.locator('.pos-queue-row').first(), n: 2 },
            { loc: card, n: 3, pad: 2 }, { loc: card.locator('.pos-change, [class*=change]').first(), n: 4 },
            { loc: card.locator('[data-decision="approve"]'), n: 5 }, { loc: card.locator('[data-decision="reject"]'), n: 6 }, { loc: card.getByText('View details'), n: 7 },
        ]);
        await shot(p, 'ws-15-approval-center');
        record(s, { name: 'ws-15-approval-center', persona: 'manager', path: '/admin/approvals', caption: 'Approval Center: queue on the left, the selected decision with its context on the right' });
    },
    async '16'() {
        const p = await open(s, 'manager', '/admin/approvals');
        const card = p.locator('.pos-decision-pane .pos-approval-card:visible').first();
        await card.locator('[data-decision="reject"]').click(); // opens the reason box only (client side); nothing is sent
        await p.waitForTimeout(500);
        await mark(p, 'ws-16', [{ loc: card.locator('textarea'), n: 1 }, { loc: card.locator('form button[type=submit]'), n: 2 }, { loc: card.locator('form').getByRole('button', { name: 'Cancel' }), n: 3 }]);
        await shot(p, 'ws-16-approval-reason', { clip: await clipAround(p, card, 30) });
        await card.locator('form').getByRole('button', { name: 'Cancel' }).click();
        record(s, { name: 'ws-16-approval-reason', persona: 'manager', path: '/admin/approvals', caption: 'Reject chosen: the reason box (not submitted)' });
    },
    async '17'() {
        const p = await open(s, 'hr', '/admin/task-inbox');
        console.log('task inbox hr', (await p.locator('main').innerText()).slice(0, 600));
        await mark(p, 'ws-17', [{ loc: p.locator('.fi-tabs').first(), n: 1 }, { loc: p.locator('.fi-sidebar a:has-text("Task inbox")').first(), n: 2 }]);
        await shot(p, 'ws-17-task-inbox', { clip: { x: 0, y: 0, width: 1440, height: 520 } });
        record(s, { name: 'ws-17-task-inbox', persona: 'hr', path: '/admin/task-inbox', caption: 'Task inbox (My tasks)' });
    },
    async '18'() {
        const p = await open(s, 'employee', '/admin/notifications');
        await mark(p, 'ws-18', [
            { loc: p.locator('[aria-label="Notification groups"]').first(), n: 1 }, { loc: p.getByText('Unread only').first(), n: 2 },
            { loc: p.getByText('Mark all read').first(), n: 3 }, { loc: p.getByRole('link', { name: 'Preferences' }).first(), n: 4 },
            { loc: p.getByRole('button', { name: 'Open' }).first(), n: 5 }, { loc: p.locator('[aria-label="Mark read"]').first(), n: 6 }, { loc: p.locator('[aria-label="Snooze"]').first(), n: 7, badge: 'tr' },
        ]);
        await shot(p, 'ws-18-notifications', { clip: { x: 0, y: 0, width: 1440, height: 520 } });
        record(s, { name: 'ws-18-notifications', persona: 'employee', path: '/admin/notifications', caption: 'Notifications page' });
        return;
        await shot(p, 'ws-18-notifications');
        record(s, { name: 'ws-18-notifications', persona: 'employee', path: '/admin/notifications', caption: 'Notifications page' });
    },
    async '19'() {
        const p = await open(s, 'employee', '/admin/my-day');
        await mark(p, 'ws-19', [
            { loc: p.getByRole('button', { name: 'Apply leave' }), n: 1 }, { loc: p.getByRole('button', { name: 'Regularise attendance' }), n: 2 },
            { loc: p.getByRole('button', { name: 'Ask HR' }), n: 3 }, { loc: p.getByRole('button', { name: 'Check in' }).first(), n: 4 },
            { loc: p.getByText('Leave balance').first(), n: 5 }, { loc: p.getByText('NEEDS ATTENTION', { exact: false }).first(), n: 6 },
        ]);
        await shot(p, 'ws-19-my-day');
        record(s, { name: 'ws-19-my-day', persona: 'employee', path: '/admin/my-day', caption: 'My day for an employee' });
    },
    async '20'() {
        const p = await open(s, 'manager', '/admin/preferences');
        await mark(p, 'ws-20', [
            { loc: p.locator('#pref-density').locator('..'), n: 1 }, { loc: p.locator('#pref-lens').locator('..'), n: 2 },
            { loc: p.locator('#pref-pins').locator('..').locator('..'), n: 3 }, { loc: p.getByRole('link', { name: 'Notification preferences' }), n: 4 },
        ]);
        await shot(p, 'ws-20-preferences');
        record(s, { name: 'ws-20-preferences', persona: 'manager', path: '/admin/preferences', caption: 'Preferences (manager, who has two Home views)' });
    },
    async '21'() {
        const p = await open(s, 'employee', '/admin', { width: 390, height: 844, mobile: true });
        await mark(p, 'ws-21', [{ sel: '.pos-bottom-nav', n: 1, pad: 2 }, { sel: '.pos-bottom-action', n: 2 }]);
        await shot(p, 'ws-21-home-phone');
        record(s, { name: 'ws-21-home-phone', persona: 'employee', path: '/admin (390x844, touch)', caption: 'Home at phone width with the bottom bar' });
    },
    async '22'() {
        const p = await open(s, 'manager', '/admin', { theme: 'dark' });
        await shot(p, 'ws-22-home-dark');
        record(s, { name: 'ws-22-home-dark', persona: 'manager', path: '/admin (dark theme)', caption: 'Home in dark mode' });
    },
};

for (const [k, fn] of Object.entries(shots)) {
    if (want.size && !want.has(k)) continue;
    try { await fn(); console.log('ok', k); } catch (e) { problems.push(`${k}: ${e.message.split('\n')[0]}`); console.log('FAIL', k, e.message.split('\n')[0]); }
}
console.log('PROBLEMS', JSON.stringify(problems, null, 1));
await finish(s);
