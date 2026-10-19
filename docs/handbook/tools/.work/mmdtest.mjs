import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const { chromium } = createRequire(process.cwd() + '/../../../package.json')('playwright');
const md = readFileSync('../../customer-guide/modules/01-workspace-and-self-service.md', 'utf8');
const codes = [...md.matchAll(/```mermaid\n([\s\S]*?)```/g)].map(m => m[1].replace(/(\w+)\{"([^"]*)"\}/g, '$1{{"$2"}}'));
const b = await chromium.launch(); const p = await b.newPage();
await p.setContent('<html><body></body></html>');
await p.addScriptTag({ path: 'node_modules/mermaid/dist/mermaid.min.js' });
for (const [ww, rs, ns, fs] of [[200, 30, 30, 16], [320, 22, 40, 16], [420, 18, 45, 16], [360, 20, 50, 18]]) {
  const r = await p.evaluate(async ({ codes, ww, rs, ns, fs }) => {
    mermaid.initialize({ startOnLoad: false, theme: 'base', themeVariables: { fontSize: fs + 'px' }, flowchart: { htmlLabels: true, wrappingWidth: ww, rankSpacing: rs, nodeSpacing: ns, padding: 10 } });
    const out = [];
    for (let i = 0; i < codes.length; i++) { const { svg } = await mermaid.render('t' + ww + i, codes[i]); const vb = svg.match(/viewBox="([^"]+)"/)[1].split(' ').map(Number); out.push(vb[2] + 'x' + vb[3]); }
    return out;
  }, { codes, ww, rs, ns, fs });
  // printable box 665 x 877 css px (176mm x 232mm): scale = min(665/w, 877/h)
  console.log(ww, rs, ns, fs, r.map(s => { const [w, h] = s.split('x').map(Number); return s + '@' + Math.min(665 / w, 877 / h, 1).toFixed(2); }).join('  '));
}
await b.close();
