import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/../../../package.json')('playwright');
const b = await chromium.launch(); const p = await b.newPage();
await p.emulateMedia({ media: 'print' });
await p.goto('file://' + process.cwd() + '/.work/print.html', { waitUntil: 'networkidle' });
console.log(await p.evaluate(() => {
  const h = [...document.querySelectorAll('h4')].find(e => e.textContent.includes('Task: Create a position'));
  const para = h.nextElementSibling; const cs = getComputedStyle(para);
  // walk up ancestors to see where font changes
  const chain = []; let e = para; while (e) { chain.push(e.tagName + (e.className ? '.' + e.className : '') + ' → ' + getComputedStyle(e).fontFamily.slice(0, 40)); e = e.parentElement; }
  return { tag: para.tagName, font: cs.fontFamily, chain: chain.slice(0, 8) };
}));
await b.close();
