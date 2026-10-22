// Fictional accounts created by the onboarding / internal-tenant capture scripts. Passwords are generated once and kept
// in tools/.work (gitignored, 0600); they are never written into a script, a document or a screenshot.
import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { PERSONAS } from './lib.mjs';

const WORK = new URL('../.work/', import.meta.url).pathname;

export function accountPassword(key) {
    const f = `${WORK}acct-${key}.pw`;
    if (!existsSync(f)) { mkdirSync(WORK, { recursive: true }); writeFileSync(f, `Tr-${randomBytes(9).toString('base64url')}-7`, { mode: 0o600 }); }
    return readFileSync(f, 'utf8').trim();
}
export const activated = (key) => existsSync(`${WORK}acct-${key}.activated`);
export const markActivated = (key) => writeFileSync(`${WORK}acct-${key}.activated`, new Date().toISOString());

// Register the personas so open(s, 'nwadmin', …) signs them in through the real sign-in page.
const ACCOUNTS = {
    nwadmin: { key: 'nw-farah', email: 'farah.khan@northwind.example', who: 'Farah Khan', role: 'Tenant Super Admin (Northwind Retail, first administrator)' },
    nwhr: { key: 'nw-rohit', email: 'rohit.menon@northwind.example', who: 'Rohit Menon', role: 'HR Executive (Northwind Retail)' },
    meadmin: { key: 'me-sana', email: 'sana.qureshi@markedge-internal.example', who: 'Sana Qureshi', role: 'Tenant Super Admin (Markedge internal HR)' },
};
for (const [persona, a] of Object.entries(ACCOUNTS)) {
    PERSONAS[persona] = { email: a.email, who: a.who, role: a.role, get password() { return accountPassword(a.key); } };
}
export { ACCOUNTS };
