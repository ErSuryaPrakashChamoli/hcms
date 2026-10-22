// QA re-captures (read-only screens): states the first capture recorded too early or scrolled.
// Entries here replace the earlier manifest entries of the same name (manifests are merged in file order).
import { start, open, shot, finish, settle } from './lib.mjs';
import './d-accounts.mjs';

const s = await start('98-recapture');

// Northwind leave policy after publishing: the first capture was taken before the versions table refreshed.
{
    const page = await open(s, 'nwadmin', '/admin/policies/5/edit');
    await page.mouse.wheel(0, 2500);
    await page.waitForTimeout(2500);
    await page.locator('tr', { hasText: 'Initial entitlements' }).first().waitFor({ timeout: 20_000 }).catch(() => {});
    await page.evaluate(() => window.scrollTo(0, 0));
    await settle(page, 800);
    await shot(s, page, 'client-onboarding/90-onb-72-policy-published', {
        deck: [4], workflow: 'Leave policy', screen: 'Workflows › Policies › Northwind leave policy (after publishing)',
        action: 'Re-open the policy after Publish draft', expected: 'Version 1 is Published, effective 21 Oct 2026; Assignment rules decide who it applies to',
        fullPage: true, callouts: [{ loc: page.locator('tr', { hasText: 'Initial entitlements' }), n: 1, label: 'v1 Published' }],
        note: 'Re-captured during QA: the first capture showed the table before it refreshed (Draft). Database: policy_versions v1 status published at 2026-10-21 07:48.',
    });
}

// Operator view of compliance rules, scrolled fully left so code, name and status are visible.
{
    const page = await open(s, 'operator', '/admin/compliance-rules', { width: 2400, height: 1100 });
    await page.evaluate(() => document.querySelectorAll('*').forEach((e) => { if (e.scrollLeft) e.scrollLeft = 0; }));
    await settle(page, 600);
    await shot(s, page, 'platform-admin/95-pl-29-compliance-rules', {
        deck: [5, 8, 3], workflow: 'Statutory rule stewardship', screen: 'People › Pay › Compliance rules (operator view)',
        action: 'Open Compliance rules as a platform operator', expected: 'Each rule version with jurisdiction, code, status and verification; operator actions Parameters, Verification history, Evidence & coverage, Attach evidence, Submit evidence. None verified',
        status: 'IMPLEMENTED BUT NOT VERIFIED END-TO-END', note: 'Re-captured during QA with the table scrolled fully left.',
    });
}
await finish(s);
