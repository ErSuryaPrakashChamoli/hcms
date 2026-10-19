import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/../../../package.json')('playwright');
const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1400, height: 900 } });
await p.goto('file://' + process.cwd() + '/../PeopleOS-Customer-Handbook.html', { waitUntil: 'load' });
console.log(await p.evaluate(() => ({
  direct: document.querySelectorAll('main.doc > section.chapter').length,
  all: document.querySelectorAll('section.chapter').length,
  brokenImgs: [...document.images].filter(i => i.complete && i.naturalWidth === 0).map(i => i.src).slice(0, 5),
  imgs: document.images.length,
  badLinks: [...document.querySelectorAll('a[href^="#"]')].filter(a => a.getAttribute('href').length > 1 && !document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1)))).map(a => a.getAttribute('href')).slice(0, 10),
  links: document.querySelectorAll('a[href^="#"]').length,
  horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
})));
await p.setViewportSize({ width: 390, height: 844 });
console.log('phone overflow:', await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth));
await b.close();
