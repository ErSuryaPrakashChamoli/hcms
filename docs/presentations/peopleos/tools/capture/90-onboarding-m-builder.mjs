// Deck 4 stage 10b: the workflow builder for the draft "Probation review" workflow (opened, not published).
import { start, open, shot, finish, idle, dump } from './lib.mjs';
import './d-accounts.mjs';

const s = await start('90-onboarding-m-builder');
const pg = await open(s, 'nwadmin', '/admin/workflows');
await pg.locator('tbody tr', { hasText: 'Probation review' }).getByRole('link').first().click();
await idle(pg, 1500);
await pg.setViewportSize({ width: 1440, height: 1500 });
await idle(pg, 1500);
await pg.getByRole('link', { name: 'Open builder' }).or(pg.getByRole('button', { name: 'Open builder' })).first().click();
await idle(pg, 3000);
await pg.setViewportSize({ width: 1440, height: 900 });
await idle(pg, 800);
await dump(pg, 'main', 40);
await shot(s, pg, 'client-onboarding/90-onb-94-workflow-builder', { deck: [4, 10], workflow: 'Workflow and approval configuration', screen: 'Workflow › Versions & builder › Open builder',
    action: 'Open the builder of the draft version', expected: 'Start → End nodes; steps (approval, task, notification, condition …) are added between them, then the version is checked and published',
    prereq: 'Workflow created; workflow.manage', note: 'Opened only; no step added or published in this walkthrough. The "Generate document" step type is skipped at run time (F33).', callouts: [] });
await finish(s);
