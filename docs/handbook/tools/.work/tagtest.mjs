import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/../../../package.json')('playwright');
const b = await chromium.launch(); const p = await b.newPage();
await p.goto('file://' + process.cwd() + '/.work/print.html', { waitUntil: 'networkidle' });
await p.pdf({ path: '.work/untagged.pdf', format: 'A4', printBackground: true, outline: true, tagged: false, preferCSSPageSize: true, margin: { top: '16mm', bottom: '16mm', left: '0', right: '0' } });
await b.close();
