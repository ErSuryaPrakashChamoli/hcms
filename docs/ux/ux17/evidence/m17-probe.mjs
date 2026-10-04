// UX.17 mobile audit probe: node m17-probe.mjs <out.json> [viewports=phone,phone2,tablet,desktop] [personaFilter]
// For every role persona, viewport and surface: HTTP status, horizontal overflow, the section headings in the
// first screen (above the bottom bar), where the role's lead section starts, page length in screens, touch
// targets under 24px / 40px, sideways-scrolling regions, the bottom bar, script errors. Showcase data only.
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const [OUT, VPS = 'phone,phone2,tablet,desktop', ONLY = ''] = process.argv.slice(2);
const SIZES = {
  phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
  phone2: { viewport: { width: 360, height: 780 }, hasTouch: true, isMobile: true },
  tablet: { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
  desktop: { viewport: { width: 1440, height: 900 }, hasTouch: false },
};
const P = {
  employee: ['priya.nair@demo.local', ['/admin', '/admin/my-work', '/admin/people', '/admin/my-hr', '/admin/notifications']],
  manager: ['amit.verma@demo.local', ['/admin', '/admin/my-work', '/admin/approvals', '/admin/my-team', '/admin/people', '/admin/employees/3', '/admin/notifications']],
  hr: ['neha.kapoor@demo.local', ['/admin', '/admin/my-work', '/admin/people', '/admin/employees', '/admin/tickets', '/admin/employees/3', '/admin/notifications']],
  executive: ['meera.iyer@demo.local', ['/admin', '/admin/my-work', '/admin/workforce-command-centre', '/admin/people', '/admin/notifications']],
  admin: ['kavya.menon@demo.local', ['/admin', '/admin/my-work', '/admin/admin-centre', '/admin/users', '/admin/audit-events', '/admin/people', '/admin/employees/3', '/admin/notifications']],
  payroll: ['arjun.bose@demo.local', ['/admin', '/admin/my-work', '/admin/people', '/admin/payroll-runs']],
};
const measure = () => {
  const nav = document.querySelector('.pos-bottom-nav');
  const navVisible = nav && getComputedStyle(nav).display !== 'none';
  const fold = innerHeight - (navVisible ? nav.getBoundingClientRect().height : 0);
  const vis = (el) => { const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return s.visibility !== 'hidden' && s.display !== 'none' && r.width > 0 && r.height > 0; };
  const heads = [...document.querySelectorAll('main h1, main h2, main h3, main .pos-sec-title, main .fi-section-header-heading, main .fi-ta-header-heading')]
    .filter(vis).map((h) => ({ t: h.textContent.trim().replace(/\s+/g, ' ').slice(0, 60), y: Math.round(h.getBoundingClientRect().top + scrollY) }));
  const firstScreen = heads.filter((h) => h.y < fold).map((h) => h.t);
  // Interactive targets (WCAG 2.5.8 exempts inline links inside sentences)
  const inter = [...document.querySelectorAll('a[href], button, [role=button], [role=tab], [role=option], input:not([type=hidden]), select, textarea, summary')].filter(vis)
    .filter((el) => !(el.tagName === 'A' && getComputedStyle(el).display === 'inline' && el.parentElement && /^(P|LI|SPAN|DD|TD)$/.test(el.parentElement.tagName) && el.parentElement.textContent.trim().length > el.textContent.trim().length + 3));
  const sz = (el) => { const r = el.getBoundingClientRect(); return Math.min(r.width, r.height); };
  const small24 = inter.filter((el) => sz(el) < 24);
  const small40 = inter.filter((el) => sz(el) < 40);
  const label = (el) => (el.getAttribute('aria-label') || el.textContent || el.getAttribute('placeholder') || el.className || '').trim().replace(/\s+/g, ' ').slice(0, 40);
  const scrollers = [...document.querySelectorAll('main *')].filter((el) => { if (el.scrollWidth <= el.clientWidth + 1 || el.clientWidth === 0) return false; const o = getComputedStyle(el).overflowX; return o === 'auto' || o === 'scroll'; })
    .map((el) => ({ cls: String(el.className).split(' ').slice(0, 2).join('.'), hidden: el.scrollWidth - el.clientWidth }));
  const lead = document.querySelector('.pos-ws-main > *, .pos-360-viewer, [data-pos-lead]');
  const navItems = navVisible ? [...nav.querySelectorAll('.pos-bottom-item')].map((a) => (a.getAttribute('aria-current') ? '*' : '') + a.textContent.trim().replace(/\s+/g, ' ').replace(/\d+ waiting/, '').trim()) : [];
  return {
    overflow: document.documentElement.scrollWidth - innerWidth,
    fold, screens: +(document.documentElement.scrollHeight / innerHeight).toFixed(1),
    firstScreen, heads: heads.slice(0, 30),
    leadY: lead ? Math.round(lead.getBoundingClientRect().top + scrollY) : null,
    targets: inter.length, small24: small24.length, small40: small40.length,
    small24Samples: [...new Set(small24.map(label))].slice(0, 8), small40Samples: [...new Set(small40.map(label))].slice(0, 12),
    scrollers: scrollers.slice(0, 6), nav: navItems,
    topbar: [...document.querySelectorAll('.fi-topbar button, .fi-topbar a')].filter(vis).map(label).slice(0, 12),
  };
};
const b = await chromium.launch();
const rows = [];
for (const vp of VPS.split(',')) {
  for (const [role, [email, paths]] of Object.entries(P)) {
    if (ONLY && !ONLY.split(',').includes(role)) continue;
    const { page: p, ctx } = await personaContext(b, BASE, email, { ...SIZES[vp], reducedMotion: 'reduce' });
    for (const path of paths) {
      const errors = [];
      const onErr = (e) => errors.push(String(e?.message ?? e).slice(0, 120));
      p.on('pageerror', onErr);
      const res = await p.goto(BASE + path, { waitUntil: 'networkidle', timeout: 120000 }).catch(() => null);
      await p.waitForTimeout(500);
      const m = await p.evaluate(measure).catch((e) => ({ err: String(e).slice(0, 100) }));
      p.off('pageerror', onErr);
      const row = { vp, role, path, status: res?.status() ?? 'ERR', ...m, errors };
      rows.push(row);
      console.log(vp, role, path, row.status, 'ovf', m.overflow, 'screens', m.screens, 'lead', m.leadY, '/', m.fold, 's24', m.small24, 's40', m.small40, 'first:', (m.firstScreen ?? []).slice(0, 6).join(' | '));
    }
    await ctx.close();
  }
}
writeFileSync(OUT, JSON.stringify(rows, null, 2));
await b.close();
