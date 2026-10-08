// UX.15 closure C.13 manual review aid: keyboard path through a module list, a drawer form and its review.
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
const BASE = 'http://127.0.0.1:8090';
const b = await chromium.launch();
const { page: p } = await personaContext(b, BASE, 'kavya.menon@demo.local', { viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
const r = {};
const desc = () => p.evaluate(() => { const e = document.activeElement; if (!e || e === document.body) return 'body'; const cs = getComputedStyle(e); const ring = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 || cs.boxShadow !== 'none'; return `${e.tagName.toLowerCase()}${e.getAttribute('role') ? '[' + e.getAttribute('role') + ']' : ''} "${(e.getAttribute('aria-label') || e.innerText || e.placeholder || '').trim().replace(/\s+/g, ' ').slice(0, 40)}"${ring ? ' (focus ring)' : ' (NO RING)'}`; });
await p.goto(BASE + '/admin/leave-types', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
r.tabPath = [];
for (let i = 0; i < 28; i++) { await p.keyboard.press('Tab'); r.tabPath.push(await desc()); }
// Open the first Edit action from the keyboard: focus it, Enter, focus should move into the drawer.
const edit = p.locator('.fi-ta-row').first().locator('button:has-text("Edit"), a:has-text("Edit")').first();
await edit.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(1500);
r.drawerOpen = await p.locator('.fi-modal-slide-over .fi-modal-window:visible').count();
r.focusInDrawer = await p.evaluate(() => !!document.activeElement?.closest('.fi-modal-window'));
r.firstInDrawer = await desc();
// Type a change, the review appears with a concise live status
const name = p.locator('.fi-modal-window input[wire\\:model="mountedActions.0.data.name"], .fi-modal-window input[id$="name"]').first();
await name.fill((await name.inputValue()) + ' (edited)'); await p.waitForTimeout(800);
r.reviewInDrawer = await p.locator('.fi-modal-window .pos-form-review').isVisible().catch(() => false);
r.reviewStatus = await p.locator('.fi-modal-window .pos-form-review [aria-live]').innerText().catch(() => null);
await p.keyboard.press('Escape'); await p.waitForTimeout(1200);
r.drawerClosedByEscape = (await p.locator('.fi-modal-slide-over .fi-modal-window:visible').count()) === 0;
r.focusReturned = await desc();
// Command palette
await p.keyboard.press('Control+k'); await p.waitForTimeout(500);
r.paletteFocus = await desc();
await p.keyboard.press('Escape'); await p.waitForTimeout(300);
r.reducedMotionAnimations = await p.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running').length);
console.log(JSON.stringify(r, null, 1));
await b.close();
