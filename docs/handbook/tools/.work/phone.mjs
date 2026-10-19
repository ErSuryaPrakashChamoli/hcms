import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/../../../package.json')('playwright');
const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 390, height: 844 } });
await p.goto('file://' + process.cwd() + '/../PeopleOS-Customer-Handbook.html', { waitUntil: 'load' });
console.log(await p.evaluate(() => {
  const W = document.documentElement.clientWidth; const out = [];
  for (const el of document.querySelectorAll('main *')) {
    const r = el.getBoundingClientRect();
    if (r.right > W + 1) {
      // skip children of scroll containers
      let a = el.parentElement, inScroll = false;
      while (a && a !== document.body) { const o = getComputedStyle(a).overflowX; if (o === 'auto' || o === 'scroll') { inScroll = true; break; } a = a.parentElement; }
      if (!inScroll && out.length < 10) out.push(el.tagName + '.' + el.className + ' w=' + Math.round(r.width) + ' :: ' + (el.textContent || '').slice(0, 60).replace(/\s+/g, ' '));
    }
  }
  return out;
}));
await b.close();
