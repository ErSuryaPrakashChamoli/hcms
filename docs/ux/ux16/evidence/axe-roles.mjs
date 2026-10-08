// UX.15 accessibility audit: axe-core (WCAG 2.0/2.1/2.2 A + AA) across workspaces, personas, themes and open states.
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { personaContext } from './auth.mjs';
const require = createRequire(import.meta.url);
const axeSrc = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const BASE = 'http://127.0.0.1:8090';
const P = { emp: 'priya.nair@demo.local', mgr: 'amit.verma@demo.local', hr: 'neha.kapoor@demo.local', exec: 'meera.iyer@demo.local', admin: 'kavya.menon@demo.local' };
const CHECKS = [
  // UX.16: every role's Home, My Work and People, the 360 viewer panels, notifications and role palette queries.
  ['priya.nair@demo.local', '/admin'], ['priya.nair@demo.local', '/admin/my-work'], ['priya.nair@demo.local', '/admin/people'], ['priya.nair@demo.local', '/admin', 'palette-bank'],
  ['amit.verma@demo.local', '/admin'], ['amit.verma@demo.local', '/admin/my-work'], ['amit.verma@demo.local', '/admin/people'], ['amit.verma@demo.local', '/admin/employees/3'],
  ['neha.kapoor@demo.local', '/admin'], ['neha.kapoor@demo.local', '/admin/my-work'], ['neha.kapoor@demo.local', '/admin/people'], ['neha.kapoor@demo.local', '/admin/employees/20'],
  ['meera.iyer@demo.local', '/admin'], ['meera.iyer@demo.local', '/admin/my-work'], ['meera.iyer@demo.local', '/admin', 'palette-workforce'],
  ['kavya.menon@demo.local', '/admin'], ['kavya.menon@demo.local', '/admin/my-work'], ['kavya.menon@demo.local', '/admin/employees/3'], ['kavya.menon@demo.local', '/admin/notifications'], ['kavya.menon@demo.local', '/admin', 'palette-permission'],
  ['arjun.bose@demo.local', '/admin'], ['arjun.bose@demo.local', '/admin/my-work'], ['kavya.menon@demo.local', '/admin/preferences'],
];
const themes = (process.env.THEMES ?? 'light,dark').split(',');
const b = await chromium.launch();
const pages = new Map();
const out = [];
for (const theme of themes) {
  for (const [email, path, state] of CHECKS) {
    const key = email + theme;
    if (!pages.has(key)) { const { page } = await personaContext(b, BASE, email, { viewport: { width: 1440, height: 1000 }, colorScheme: theme, reducedMotion: 'reduce' }); pages.set(key, page); }
    const p = pages.get(key);
    await p.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await p.evaluate((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    await p.goto(BASE + path, { waitUntil: 'networkidle' }); await p.waitForTimeout(700);
    try {
      if (state === 'decide') { await p.locator('.pos-decision-pane [data-decision="approve"]:visible').first().click(); await p.waitForTimeout(300); }
      if (state === 'drawer-approval') { await p.locator('text=Review').first().click(); await p.waitForTimeout(1200); }
      if (state === 'drawer-person') { await p.locator('.pos-person-card').first().click(); await p.waitForTimeout(1200); }
      if (state === 'peek') { await p.locator('.pos-table [data-person]').first().hover(); await p.waitForTimeout(1500); }
      if (state === 'drawer-change') { await p.locator('[x-on\\:click*="type: \\\'change\\\'"]').first().click().catch(async () => { const row = p.locator('button.pos-stream-row').filter({ hasText: 'Joining' }).first(); await row.click(); }); await p.waitForTimeout(1200); }
      if (state?.startsWith('palette-')) { await p.keyboard.press('Control+k'); await p.waitForTimeout(400); await p.keyboard.type(state.slice(8), { delay: 20 }); await p.waitForTimeout(1200); }
      if (state === 'command-pick') { await p.keyboard.press('Control+k'); await p.waitForTimeout(400); await p.keyboard.type('transfer', { delay: 20 }); await p.waitForTimeout(1200); await p.keyboard.press('Enter'); await p.waitForTimeout(1200); }
      if (state === 'drawer-pulse') { await p.locator('.pos-figure').first().click(); await p.waitForTimeout(1200); }
      if (state === 'area-menu') { await p.locator('.pos-spacebar-more').click(); await p.waitForTimeout(400); }
      if (state === 'modal') { await p.waitForTimeout(1500); }
      if (state === 'lens') { const c = p.locator('.pos-module-context .pos-lens-chip'); if ((await c.count()) > 1) { await c.nth(1).click(); await p.waitForTimeout(1200); } }
      if (state === 'review-create') { await p.fill('input[wire\\:model="data.name"]', 'People Analytics'); await p.fill('input[wire\\:model="data.code"]', 'PAN'); await p.waitForTimeout(800); }
      if (state === 'review-edit') { await p.fill('input[wire\\:model="data.name"]', 'Engineering & Platform'); await p.waitForTimeout(800); }
      if (state === 'drawer-form') { await p.locator('.fi-ta-row').first().locator('button:has-text("Edit"), a:has-text("Edit")').first().click(); await p.waitForTimeout(1500); }
      if (state === 'view-journey' || state === 'view-records') { await p.waitForTimeout(800); }
    } catch (e) { out.push({ theme, email, path, state, error: String(e).slice(0, 160) }); }
    await p.evaluate(axeSrc);
    const r = await p.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } }));
    out.push({ theme, who: email.split('@')[0], path, state: state ?? '', violations: r.violations.map((v) => ({ id: v.id, impact: v.impact, n: v.nodes.length, sample: v.nodes.slice(0, 2).map((n) => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\n').slice(1, 2).join(' ')) })) });
  }
}
console.log(JSON.stringify(out, null, 1));
await b.close();
