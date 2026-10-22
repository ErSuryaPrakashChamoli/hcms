// Content shared by several decks: release status, training environment, known defects, help.
'use strict';

const SOURCE_GATE = 'docs/saas/SaaS-Final-Release-Gate.md (decision NO-GO, re-verified 13 October 2026 at 7314f7d)';

/** Simple status slide for end-user decks. */
function statusSimple(d, extra = [], ask = 'Who has used an HR self-service portal before?') {
    d.cards({
        title: 'Where PeopleOS stands today', eyebrow: 'Before you start',
        intro: 'This training uses the real application on a practice database with invented people. PeopleOS is not yet generally available.',
        cards: [
            { head: 'What works today', body: 'The screens in this deck were run end to end on the practice database: you will see exactly what you will use.', tone: 'good' },
            { head: 'Payroll is not live yet', body: 'Payslips appear only after a payroll run is finalised. Finalising is blocked until statutory rules are verified (0 of 24 today).', tone: 'warn' },
            { head: 'Known issues are named', body: 'Where something does not work as it should, the slide says so and shows the workaround.', tone: 'info' },
            ...extra,
        ],
        notes: `SAY: We trained on the real PeopleOS screens, not mock-ups. The practice database contains invented people only.\nAVOID: promising dates for general availability or payroll. The release gate is NO-GO (${SOURCE_GATE}).\nASK: "${ask}"`,
    });
}

/** Detailed release-gate slide for HR, implementation, platform and executive decks. */
function statusDetailed(d, eyebrow = 'Release status') {
    d.table({
        title: 'Release gate: NO-GO until these are resolved', eyebrow,
        intro: 'Status as written in the release gate document. Ten of fourteen gates are blocked by owner decisions, external parties or missing infrastructure.',
        columns: ['#', 'Gate', 'Gate status', 'Remaining dependency'],
        colW: [0.45, 4.1, 2.75, 4.83],
        rows: [
            ['1', 'Application and architecture integrity', 'PASS (workstation)', 'Repeat the full suites in staging'],
            ['2', 'Credential exposure and repository remediation', 'BLOCKED', 'Owner: rotation confirmation, repository visibility (O-1 to O-3)'],
            ['3', 'Authentication and operator MFA', 'BLOCKED', 'Operators enrol MFA in the target environment (O-14, O-7)'],
            ['4', 'Independent security review', 'BLOCKED', 'Engage a reviewer; triage and retest findings (O-4)'],
            ['5', 'Statutory verification and gratuity', 'BLOCKED', '0 of 24 rules verified; gratuity not implemented (O-5, O-12, O-18)'],
            ['6', 'Data protection, retention, legal holds', 'PASS (controls) / BLOCKED (policy)', 'Retention decisions with counsel (D8); privacy review'],
            ['7', 'Backup, restore and disaster recovery', 'BLOCKED', 'Recovery objectives and an isolated target (O-6, O-7)'],
            ['8', 'Hosting, monitoring and on-call', 'BLOCKED', 'Environment, alerting, on-call (O-7, O-10, O-11)'],
            ['9', 'Signup and tenant provisioning', 'PASS (disabled) / BLOCKED (activation)', 'Only if self-service signup is wanted at launch (D10)'],
            ['10', 'Billing, tax and payment controls', 'PASS (disabled) / BLOCKED (activation)', 'Only for paid billing (B-4, B-7, B-8, B-9)'],
            ['11', 'Tenant deletion and offboarding', 'PASS (disabled) / BLOCKED (activation)', 'Retention decisions, register disk, review (D8)'],
            ['12', 'Regression and target-environment testing', 'BLOCKED', 'A staging environment (O-7, O-8)'],
            ['13', 'Owner decisions and written approvals', 'BLOCKED', 'All owner action items'],
            ['14', 'Deployment, rollback, post-deployment checks', 'BLOCKED', 'Rehearse in staging; authorise (O-7 to O-9)'],
        ],
        rowsPerSlide: 7, fontSize: 12,
        notes: `SOURCE: ${SOURCE_GATE}; owner actions in docs/saas/SaaS-Final-Owner-Action-Register.md.\nSAY: The application passes its automated suites on the workstation, but release is blocked by decisions and infrastructure, not by code alone.\nAVOID: describing any gate as resolved. Nothing in this training changes a gate.`,
    });
}

/** Training environment (for technical decks). */
function environment(d, eyebrow = 'About this training', dbText = 'hcm_training_showcase, seeded with the guarded showcase seeder: invented people at the fictional Demo Group.') {
    d.cards({
        title: 'How these screens were produced', eyebrow,
        cards: [
            { head: 'Real application', body: 'PeopleOS at commit 72c96a73, run locally. No application file or setting was changed for the training.', tone: 'info' },
            { head: 'Disposable database', body: dbText, tone: 'info' },
            { head: 'Workflows executed', body: 'Requests were submitted, approved, rejected and processed so that every state shown really happened.', tone: 'good' },
            { head: 'Nothing switched on', body: 'Public signup, live payments and tenant deletion stayed off. The operator used MFA. No secret is shown.', tone: 'good' },
        ],
        notes: 'SAY: Every screenshot is a genuine capture from the running application on a disposable database. The full list, with what each one shows, is in SCREENSHOT-INVENTORY.md.\nEVIDENCE: docs/presentations/peopleos/SCREENSHOT-INVENTORY.md and PEOPLEOS-PRESENTATION-ACCURACY-REGISTER.md.',
    });
}

const HELP_EMPLOYEE = ['Your HR team: My HR › Ask HR', 'Your manager, for approvals', 'Ask PeopleOS (sidebar) explains with sources; it never decides', 'Support contacts: to be confirmed with the SaaS provider'];

module.exports = { statusSimple, statusDetailed, environment, HELP_EMPLOYEE, SOURCE_GATE };
