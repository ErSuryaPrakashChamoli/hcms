import { start, open, annotate, shot, finish } from '../lib.mjs';
const s = await start('org');
const page = await open(s, 'superadmin', '/admin/legal-entities');
const m = await annotate(page, [
  { sel: 'main a:has-text("New legal entity"), main button:has-text("New legal entity")', n: 1 },
  { sel: 'main th:has-text("Establishments")', n: 2 },
  { sel: 'main th:has-text("Primary")', n: 3 },
  { sel: 'main button:has-text("Submit for verification")', n: 4 },
]);
console.log(m);
await shot(page, 'org-07-legal-entities-list');
await finish(s);
