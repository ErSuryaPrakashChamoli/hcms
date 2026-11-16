import { browser, signedIn, BASE, shot } from './lib.mjs';
const b = await browser();
const ctx = await signedIn(b, undefined, { fresh: true });
const p = await ctx.newPage();
await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
console.log('URL', p.url(), 'TITLE', await p.title());
console.log((await p.locator('body').innerText()).slice(0, 600).replace(/\n+/g, ' | '));
await shot(p, 'probe/home', { fullPage: false });
await b.close();
