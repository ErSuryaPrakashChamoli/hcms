// Capture runner for area `org` (chapter 06-organisation-workforce-and-assets.md).
// The individual capture steps live in ./org/. Steps e.mjs (scenario, plan) and f.mjs (line, position) CREATED the
// example records EX-GROWTH, EX-FY27 and EX-DATA-01 once; they are not re-run here (re-running is refused by code
// uniqueness anyway). Files were renamed to org-01 … org-22 after capture (see inventory/org.md for the mapping).
import { execFileSync } from 'node:child_process';
const dir = new URL('./org/', import.meta.url).pathname;
for (const step of ['a.mjs', 'b.mjs', 'c2.mjs', 'd.mjs', 'g.mjs', 'g2.mjs', 'h.mjs', 'h2.mjs', 'i.mjs']) {
  console.log('== ' + step);
  execFileSync('node', [dir + step], { stdio: 'inherit', cwd: dir });
}
