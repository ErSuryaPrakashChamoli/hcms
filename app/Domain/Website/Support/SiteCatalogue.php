<?php

namespace App\Domain\Website\Support;

/**
 * The starting content for the website's product, platform and solution pages: what PeopleOS actually does, module
 * by module, written for buyers. Operators change all of it in Platform → Public website; this only seeds the first
 * draft. Claims stay to features that exist in the product (no customer names, figures or certifications).
 */
final class SiteCatalogue
{
    /**
     * Product modules: slug => [title, menu description, icon, hue, visual, eyebrow, headline, highlight, intro, features, spotlight].
     *
     * @return array<string, array<string, mixed>>
     */
    public static function modules(): array
    {
        return [
            'core-hr' => [
                'title' => 'Core HR', 'menu' => 'Records, documents and letters', 'icon' => 'users', 'hue' => 'iris', 'visual' => 'people',
                'headline' => 'One true record for', 'highlight' => 'every employee.',
                'intro' => 'Effective-dated employee records with full history, documents and letters — so the answer to "what was true on that date?" is always one click away.',
                'features' => [
                    ['identification', 'Employee 360', 'Personal, job, pay and document details on one page, with every change kept against the date it took effect.'],
                    ['document-text', 'Letter studio', 'Design offer, confirmation and relieving letters in rich text or Word, with variables, a salary annexure and signatories.'],
                    ['table-cells', 'Bulk uploads', 'Add or update people, bank details and more from spreadsheets, checked row by row before anything is saved.'],
                    ['clipboard-document-check', 'Employee codes', 'Per-company code series with prefixes and suffixes, automatic or manual on the hire wizard.'],
                    ['document-check', 'Documents', 'Collect, verify and share employee documents; employees download their own in one go.'],
                    ['sparkles', 'Celebrations', 'Birthdays and work anniversaries surfaced on the right day, with wishes from colleagues.'],
                ],
                'spotlight' => ['History that explains itself', 'Every field change records who made it, when it takes effect and why — and the audit trail is tamper-evident.', ['Effective-dated changes for transfers, promotions and pay', 'Field-level audit with before and after values', 'Role-based access down to organisation scope']],
            ],
            'organisation' => [
                'title' => 'Organisation', 'menu' => 'Companies, locations, positions', 'icon' => 'building-office-2', 'hue' => 'blue', 'visual' => 'people',
                'headline' => 'Your structure,', 'highlight' => 'drawn to the truth.',
                'intro' => 'Model companies, locations, departments, designations and positions exactly as you run them — and see the organisation as a living map.',
                'features' => [
                    ['building-office-2', 'Multi-company', 'Several legal entities in one workspace, each with its own statutory profile and settings.'],
                    ['map-pin', 'Locations and regions', 'States and regions drive holidays, professional tax and labour welfare rules automatically.'],
                    ['user-group', 'Positions and hierarchy', 'Budgeted positions, reporting lines and a position hierarchy you can explore.'],
                    ['chart-pie', 'Organisation map', 'A visual map of teams and reporting lines, always generated from live records.'],
                    ['briefcase', 'Designations and grades', 'Designations, grades and employment types that policies and pay can follow.'],
                    ['arrows-right-left', 'Governed changes', 'Structural changes go through review, so nothing moves without a record.'],
                ],
                'spotlight' => ['Design before you move', 'Plan a re-organisation on the organisation designer, then apply it with effective dates.', ['Drafts that do not touch live records', 'Effective-dated moves for whole teams', 'Every change recorded in the audit trail']],
            ],
            'onboarding' => [
                'title' => 'Onboarding', 'menu' => 'Offers, pre-joining and day one', 'icon' => 'user-plus', 'hue' => 'teal', 'visual' => 'workspace',
                'headline' => 'Day one that feels', 'highlight' => 'like day ten.',
                'intro' => 'From accepted offer to a productive first week: a guided hire wizard, pre-joining tasks and checklists that close themselves.',
                'features' => [
                    ['user-plus', 'Hire wizard', 'A step-by-step hire that assigns the code, position, policies and salary in one pass.'],
                    ['clipboard-document-check', 'Checklists', 'Tasks for HR, IT, managers and the joiner, each with an owner and a due date.'],
                    ['document-check', 'Pre-joining', 'Joiners share documents and details before day one, so HR verifies instead of chasing.'],
                    ['check-badge', 'Background checks', 'Hand checks to a verification partner and track the result on the profile.'],
                    ['megaphone', 'Welcome', 'Introduce new joiners to the company with an announcement on their first day.'],
                    ['computer-desktop', 'Asset hand-over', 'Laptops and access cards assigned and acknowledged as part of onboarding.'],
                ],
                'spotlight' => ['Nothing falls through the cracks', 'Every onboarding task has an owner, a date and a reminder — and HR sees what is late at a glance.', ['Templates by company, location or role', 'Reminders before tasks fall due', 'A clear view of every joiner in progress']],
            ],
            'exit' => [
                'title' => 'Exit & alumni', 'menu' => 'Clearance, settlement, alumni', 'icon' => 'arrow-right-start-on-rectangle', 'hue' => 'slate', 'visual' => 'workspace',
                'headline' => 'Goodbyes, handled', 'highlight' => 'with grace.',
                'intro' => 'Resignations, clearance, full and final settlement and exit letters in one flow — and a door left open through the alumni portal.',
                'features' => [
                    ['arrow-right-start-on-rectangle', 'Resignation and exit', 'Employees resign in the app; managers and HR agree the last working day.'],
                    ['clipboard-document-check', 'Clearance stages', 'Clearance across teams, including asset recovery, before the settlement is released.'],
                    ['calculator', 'Full and final', 'Settlement from the last month\'s pay, leave encashment, notice recovery and manual lines.'],
                    ['chat-bubble-left-right', 'Exit interviews', 'Structured exit interviews with insights on why people leave.'],
                    ['document-text', 'Exit letters', 'Relieving and experience letters generated, approved and issued from templates.'],
                    ['heart', 'Alumni portal', 'Former employees keep access to their letters and can raise requests.'],
                ],
                'spotlight' => ['Settlement without spreadsheets', 'The final settlement is worked out from payroll and leave records, reviewed and approved in one place.', ['Leave encashment posted to the leave ledger', 'Notice-period recovery worked out automatically', 'Sign-in closes when the exit completes']],
            ],
            'attendance' => [
                'title' => 'Attendance', 'menu' => 'Shifts, check-in, regularisation', 'icon' => 'clock', 'hue' => 'green', 'visual' => 'attendance',
                'headline' => 'Time that adds up,', 'highlight' => 'every single day.',
                'intro' => 'Shifts, web and biometric punches, regularisation and a calendar that explains every day — ready for payroll before payroll asks.',
                'features' => [
                    ['clock', 'Shifts and rosters', 'Fixed, flexible and rotating shifts with weekly offs, grace and half-day rules.'],
                    ['map-pin', 'Web check-in', 'Check in from the browser, with location when the policy needs it.'],
                    ['finger-print', 'Biometric devices', 'Import punches from attendance devices and reprocess days when rules change.'],
                    ['calendar-days', 'Attendance calendar', 'Each day\'s status, planned week-offs and holidays, colour-coded and explained.'],
                    ['arrow-path-rounded-square', 'Regularisation', 'Employees correct missed punches; managers approve from their inbox.'],
                    ['table-cells', 'Monthly upload', 'Bring a whole month of attendance from a spreadsheet when that is how a site works.'],
                ],
                'spotlight' => ['An exception centre, not a hunt', 'Missing punches, late marks and unapproved days come to one queue before the month closes.', ['Paid days worked out for payroll', 'Status changes with a reason and an audit trail', 'Policies by location, shift or grade']],
            ],
            'leave' => [
                'title' => 'Leave', 'menu' => 'Policies, balances, holidays', 'icon' => 'calendar-days', 'hue' => 'gold', 'visual' => 'attendance',
                'headline' => 'Time off,', 'highlight' => 'without the back-and-forth.',
                'intro' => 'Leave policies that match your rules, balances that are always right, and holiday calendars for every state you work in.',
                'features' => [
                    ['calendar-days', 'Leave policies', 'Entitlements, accrual, sandwich rules, notice and limits per leave type.'],
                    ['chart-bar', 'Accurate balances', 'Every grant, use and lapse is a ledger entry, so balances can always be explained.'],
                    ['arrows-right-left', 'Carry forward', 'Year-end carry forward and encashment run as a reviewed batch.'],
                    ['sun', 'Holiday library', 'National and state holiday lists to adopt into each location\'s calendar.'],
                    ['user-group', 'Team calendar', 'Managers see who is away before they approve.'],
                    ['device-phone-mobile', 'Apply anywhere', 'Employees apply, cancel and track leave from any device.'],
                ],
                'spotlight' => ['Balances you never have to defend', 'An append-only ledger behind every balance means a question about leave is answered with its history.', ['Grants, accruals and lapses all on the ledger', 'Comp-off and optional holidays supported', 'Leave flows straight into payroll']],
            ],
            'payroll' => [
                'title' => 'Payroll', 'menu' => 'Guided runs, payslips, structures', 'icon' => 'banknotes', 'hue' => 'iris', 'visual' => 'payroll',
                'headline' => 'Payroll that closes', 'highlight' => 'on time, every time.',
                'intro' => 'A guided monthly run with pre-payroll checks, one-click payslip publishing and finalised runs that stay locked — no midnight spreadsheets.',
                'features' => [
                    ['clipboard-document-check', 'Pre-payroll checks', 'Joiners, leavers, unapproved attendance and missing details flagged before you run.'],
                    ['calculator', 'Salary structure builder', 'Build components and formulas in five steps — pick from a list or write them in plain English.'],
                    ['calendar-days', 'Your pay cycle', 'Calendar months or cycles like 25th to 24th, with dated changes when the cycle moves.'],
                    ['document-check', 'Publish payslips', 'Review, finalise and publish payslips to every employee at once.'],
                    ['lock-closed', 'Locked when final', 'A finalised run cannot change; reopening is a recorded, approved step.'],
                    ['banknotes', 'Bonus, advances, loans', 'Bonus runs, salary advances and loan recovery handled inside payroll.'],
                ],
                'spotlight' => ['A run you can follow step by step', 'The payroll run is a checklist: inputs, checks, calculation, review, publish. Everyone knows what is done and what is next.', ['Variance review against last month', 'Payroll auditor for anomalies', 'Year-to-date salary for every employee']],
            ],
            'compliance' => [
                'title' => 'Compliance & tax', 'menu' => 'Registers, returns, TDS', 'icon' => 'scale', 'hue' => 'coral', 'visual' => 'payroll',
                'headline' => 'Statutory work,', 'highlight' => 'generated not typed.',
                'intro' => 'Registers, challans and returns produced from payroll for every state you operate in — plus TDS with a guided investment declaration.',
                'features' => [
                    ['scale', 'Reports centre', 'Registers, challans and returns by month, quarter, half-year or year, for all 36 states and union territories.'],
                    ['cloud-arrow-up', 'One-go download', 'Every report for a period in a single ZIP, ready to file or share with your consultant.'],
                    ['currency-rupee', 'Income tax (TDS)', 'Old and new regime projections, with tax worked out every month.'],
                    ['clipboard-document-check', 'Investment declarations', 'A guided wizard for employees to declare investments and upload proofs.'],
                    ['document-text', 'Form 12BB', 'Declarations turned into Form 12BB without retyping.'],
                    ['bell-alert', 'Due dates', 'A compliance control room showing what is due and what is done.'],
                ],
                'spotlight' => ['Compliance as a by-product of payroll', 'Because returns are generated from the finalised run, the numbers you file always match the numbers you paid.', ['Statutory rules owned centrally and versioned', 'Company-wise statutory profiles', 'Gratuity worked out at exit']],
            ],
            'expenses' => [
                'title' => 'Expenses', 'menu' => 'Claims, advances, reimbursements', 'icon' => 'receipt-percent', 'hue' => 'teal', 'visual' => 'workspace',
                'headline' => 'Claims in,', 'highlight' => 'money out, nothing lost.',
                'intro' => 'Employees claim with receipts, approvers decide in a tap, and reimbursements flow through payroll or a payout batch.',
                'features' => [
                    ['receipt-percent', 'Expense claims', 'Itemised claims with receipts, categories and policy limits.'],
                    ['banknotes', 'Advances', 'Travel and salary advances dispensed, tracked and recovered.'],
                    ['arrow-path-rounded-square', 'Approvals', 'Multi-level approvals with delegation when someone is away.'],
                    ['calculator', 'Policies and limits', 'Limits by grade, category and city, checked as the claim is made.'],
                    ['document-text', 'Expense reports', 'Printable reports with a cover letter for finance.'],
                    ['currency-rupee', 'Pay out', 'Reimburse through payroll or a separate payout run.'],
                ],
                'spotlight' => ['Finance sees everything once', 'One desk for claims, advances and payouts, with every decision and attachment on the record.', ['Receipts kept with each line', 'Advance ledger per employee', 'Exports for your accounting team']],
            ],
            'performance' => [
                'title' => 'Performance', 'menu' => 'Goals, reviews, 360, calibration', 'icon' => 'chart-bar', 'hue' => 'plum', 'visual' => 'analytics',
                'headline' => 'Reviews people', 'highlight' => 'actually believe in.',
                'intro' => 'Goals, check-ins, reviews and 360 feedback in cycles you design — calibrated fairly and connected to increments.',
                'features' => [
                    ['chart-bar', 'Review cycles', 'Annual, half-yearly or quarterly cycles with the stages you need.'],
                    ['check-badge', 'Goals and KRAs', 'Cascade goals, track progress and weigh outcomes.'],
                    ['chat-bubble-left-right', '360 feedback', 'Feedback from peers, reports and even external reviewers.'],
                    ['chart-pie', 'Calibration', 'Calibration board and 9-box grid to keep ratings fair across teams.'],
                    ['calculator', 'Increment calculator', 'Turn ratings into increments within budget, then apply them to salaries.'],
                    ['presentation-chart-line', 'Insights', 'Performance analytics across teams, managers and cycles.'],
                ],
                'spotlight' => ['From rating to raise, in one flow', 'Ratings feed the increment calculator, and approved increments update salaries with effective dates.', ['Budgets by team and grade', 'Manager and HR review stages', 'History kept for every cycle']],
            ],
            'learning' => [
                'title' => 'Learning', 'menu' => 'Programmes, courses, certificates', 'icon' => 'academic-cap', 'hue' => 'blue', 'visual' => 'analytics',
                'headline' => 'Growth that', 'highlight' => 'shows up in the work.',
                'intro' => 'Assign programmes, track completion and issue certificates — with a dashboard that shows where skills are growing.',
                'features' => [
                    ['academic-cap', 'Programmes', 'Group courses into programmes for roles, joiners or compliance.'],
                    ['book-open', 'Courses', 'Your own content and links, with completion tracking.'],
                    ['document-check', 'Certificates', 'Certificates issued on completion, downloadable by the employee.'],
                    ['light-bulb', 'Skills', 'A skills catalogue linked to roles and learning.'],
                    ['presentation-chart-line', 'Learning dashboard', 'Completion and progress across teams at a glance.'],
                    ['user-circle', 'My learning', 'Employees see what is assigned, due and done.'],
                ],
                'spotlight' => ['Compliance training, done on time', 'Mandatory programmes with due dates and reminders, and a clear record of who completed what.', ['Assign by role, team or location', 'Reminders before due dates', 'Exportable completion records']],
            ],
            'engagement' => [
                'title' => 'Engagement', 'menu' => 'Announcements, recognition, pulse', 'icon' => 'heart', 'hue' => 'coral', 'visual' => 'people',
                'headline' => 'A workplace people', 'highlight' => 'feel part of.',
                'intro' => 'Announcements, recognition, surveys and celebrations that bring distributed teams together.',
                'features' => [
                    ['megaphone', 'Announcements', 'Company news with attachments, targeted to the right people.'],
                    ['trophy', 'Recognition', 'Peer-to-peer appreciation that everyone can see.'],
                    ['chart-pie', 'Happiness index', 'Quick pulse surveys and a trend of how people feel.'],
                    ['calendar-days', 'Company events', 'Events with invitations and attendance.'],
                    ['sparkles', 'Birthdays and wishes', 'Celebrate birthdays and anniversaries with wishes from colleagues.'],
                    ['heart', 'Fundraisers', 'Run giving campaigns with your people.'],
                ],
                'spotlight' => ['Listen, then act', 'Pulse results by team and location show where to look next — without exposing individual answers.', ['Anonymous where it matters', 'Targeted by team, location or grade', 'Engagement on the home feed']],
            ],
            'helpdesk' => [
                'title' => 'Helpdesk', 'menu' => 'HR tickets, knowledge, grievances', 'icon' => 'lifebuoy', 'hue' => 'slate', 'visual' => 'workspace',
                'headline' => 'Answers in minutes,', 'highlight' => 'not inbox threads.',
                'intro' => 'An HR service desk with categories, service levels and a knowledge base — plus a confidential channel for grievances.',
                'features' => [
                    ['lifebuoy', 'HR tickets', 'Requests routed to the right team with service levels and escalation.'],
                    ['book-open', 'Knowledge articles', 'Policies and how-tos employees can search before they ask.'],
                    ['shield-check', 'Grievances', 'A confidential grievance process with restricted access.'],
                    ['chat-bubble-left-right', 'Request discussion', 'Comment threads and attachments on every request.'],
                    ['bell-alert', 'Service levels', 'Due times and escalations that keep requests moving.'],
                    ['presentation-chart-line', 'Desk insights', 'Volumes, response times and backlog by category.'],
                ],
                'spotlight' => ['One front door for HR', 'Employees raise anything from one place and always see where their request stands.', ['Categories with owners', 'Attachments kept securely', 'Satisfaction after closure']],
            ],
            'assets' => [
                'title' => 'Assets', 'menu' => 'Register, assignment, recovery', 'icon' => 'computer-desktop', 'hue' => 'gold', 'visual' => 'workspace',
                'headline' => 'Every laptop,', 'highlight' => 'accounted for.',
                'intro' => 'An asset register with assignment, acknowledgement and recovery built into onboarding and exit.',
                'features' => [
                    ['computer-desktop', 'Asset register', 'Laptops, phones, cards and more with serials and condition.'],
                    ['user-circle', 'Assignment', 'Assign to employees with acknowledgement and history.'],
                    ['arrow-right-start-on-rectangle', 'Recovery at exit', 'Assets recovered as a clearance stage before settlement.'],
                    ['clipboard-document-check', 'Requests', 'Employees request assets; the desk fulfils them.'],
                    ['table-cells', 'Import', 'Bring your existing register from a spreadsheet.'],
                    ['presentation-chart-line', 'Asset desk', 'What is assigned, available or due back, at a glance.'],
                ],
                'spotlight' => ['Recovery that cannot be skipped', 'Exit clearance includes asset recovery, so the settlement waits until everything is back.', ['Recovery deductions when needed', 'Condition recorded on return', 'Full assignment history']],
            ],
            'self-service' => [
                'title' => 'Employee self-service', 'menu' => 'My day, payslips, requests', 'icon' => 'device-phone-mobile', 'hue' => 'green', 'visual' => 'selfservice',
                'headline' => 'HR in everyone\'s', 'highlight' => 'pocket.',
                'intro' => 'Check in, apply for leave, download payslips and raise requests from any device — HR answers fewer questions and people get on with their day.',
                'features' => [
                    ['clock', 'My day', 'Check in and out, see today\'s shift and what needs attention.'],
                    ['document-text', 'Payslips and YTD', 'Every payslip and year-to-date salary, protected by a second password when you want.'],
                    ['calendar-days', 'Leave and attendance', 'Apply for leave, regularise days and see balances by month or year.'],
                    ['clipboard-document-check', 'Requests', 'One place for every request, with its status and discussion.'],
                    ['currency-rupee', 'Tax declarations', 'Declare investments with a guided wizard and upload proofs.'],
                    ['user-circle', 'My profile', 'Update details, download documents and see who you work with.'],
                ],
                'spotlight' => ['Designed for phones first', 'Large targets, a bottom bar and screens that make sense on a small display — no app install needed.', ['Works on any modern browser', 'Approvals for managers on the go', 'Notifications for what matters']],
            ],
            'analytics' => [
                'title' => 'Analytics', 'menu' => 'Report library, dashboards', 'icon' => 'presentation-chart-line', 'hue' => 'blue', 'visual' => 'analytics',
                'headline' => 'Every number,', 'highlight' => 'one question away.',
                'intro' => 'A library of ready-made reports across people, time, pay and performance — and dashboards leaders read without a briefing.',
                'features' => [
                    ['presentation-chart-line', 'Report library', '149 ready-made report templates across 41 datasets.'],
                    ['chart-bar', 'People analytics', 'Headcount, attrition, diversity and movement over time.'],
                    ['banknotes', 'Compensation analytics', 'Pay distribution, cost and change by team and grade.'],
                    ['chart-pie', 'Dashboards', 'Shareable dashboards built from the same trusted data.'],
                    ['cloud-arrow-up', 'Exports', 'Download any report as a spreadsheet when you need to go further.'],
                    ['lock-closed', 'Access-aware', 'Reports respect each person\'s access, down to organisation scope.'],
                ],
                'spotlight' => ['Answers that respect access', 'Every report runs with the reader\'s own permissions, so analytics never leaks what a person should not see.', ['Scheduled and on-demand reports', 'Filters by company, location and period', 'Consistent definitions across reports']],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> platform pages */
    public static function platform(): array
    {
        return [
            'security' => [
                'title' => 'Security & privacy', 'menu' => 'Isolation, audit, MFA, SSO', 'icon' => 'shield-check', 'hue' => 'iris', 'visual' => 'security',
                'headline' => 'Built to be', 'highlight' => 'trusted with people data.',
                'intro' => 'Each organisation\'s data is isolated by default, every change is recorded in a tamper-evident trail, and sign-in is protected end to end.',
                'features' => [
                    ['lock-closed', 'Isolation by default', 'Organisation data is fenced in at the data layer; a query without an organisation returns nothing.'],
                    ['document-check', 'Tamper-evident audit', 'Every change is chained to the one before it, so edits to history are detectable.'],
                    ['key', 'Two-step verification', 'Authenticator apps and recovery codes, required by role when you choose.'],
                    ['finger-print', 'Single sign-on', 'Sign in through your identity provider, with automatic account matching.'],
                    ['globe-asia-australia', 'Network rules', 'IP allow-lists, idle timeouts and session controls per organisation.'],
                    ['cloud-arrow-up', 'Your data, portable', 'Full data export, retention policies and legal holds when you leave.'],
                ],
                'spotlight' => ['Least access, always', 'Roles grant exactly what a person needs, scoped to the companies, locations or teams they look after.', ['Every permission change audited', 'Separate platform operators with MFA', 'Sensitive fields masked in history']],
            ],
            'workflows' => [
                'title' => 'Workflows & approvals', 'menu' => 'Approval chains, delegation', 'icon' => 'arrow-path-rounded-square', 'hue' => 'teal', 'visual' => 'workspace',
                'headline' => 'Approvals that', 'highlight' => 'never get stuck.',
                'intro' => 'Approval chains for every request, with delegation, escalation and an approvals centre that clears a queue in minutes.',
                'features' => [
                    ['arrow-path-rounded-square', 'Approval chains', 'Manager, HR, finance or any role — in sequence or in parallel.'],
                    ['arrows-right-left', 'Delegation', 'Approvers hand over while away, with the record showing who decided.'],
                    ['bell-alert', 'Escalation', 'Requests that wait too long move up automatically.'],
                    ['check-badge', 'Approvals centre', 'Every pending decision in one queue, with context to decide well.'],
                    ['bolt', 'One-click actions', 'Sixty common HR actions a click away from the actions panel.'],
                    ['clipboard-document-check', 'Policy wizards', 'Configure policies step by step instead of in long forms.'],
                ],
                'spotlight' => ['Changes with a paper trail', 'Configuration changes go through review, and every decision keeps its reason.', ['Maker-checker where it matters', 'Reasons recorded on decisions', 'Full history per request']],
            ],
            'integrations' => [
                'title' => 'Integrations & API', 'menu' => 'REST API, webhooks, SCIM', 'icon' => 'puzzle-piece', 'hue' => 'blue', 'visual' => 'workspace',
                'headline' => 'Plays well with', 'highlight' => 'the rest of your stack.',
                'intro' => 'A documented REST API, webhooks and user provisioning — so PeopleOS stays the source of truth while your other systems keep up.',
                'features' => [
                    ['code-bracket', 'REST API', 'Read and write employees, attendance, leave and more with scoped API keys.'],
                    ['bolt', 'Webhooks', 'Signed events when people join, move or leave.'],
                    ['users', 'SCIM provisioning', 'Keep directory accounts in step with employee records.'],
                    ['finger-print', 'Single sign-on', 'OpenID Connect sign-in with your identity provider.'],
                    ['clock', 'Attendance devices', 'Bring punches from biometric devices.'],
                    ['check-badge', 'Background checks', 'Verification partners report results straight to the profile.'],
                ],
                'spotlight' => ['Safe by design', 'Outbound calls only reach public addresses, keys are scoped per organisation and every call is rate-limited.', ['Idempotent writes', 'Versioned endpoints', 'An API reference inside the product']],
            ],
            'data-migration' => [
                'title' => 'Data migration', 'menu' => '60+ importers with guides', 'icon' => 'cloud-arrow-up', 'hue' => 'green', 'visual' => 'import',
                'headline' => 'From spreadsheets', 'highlight' => 'to live in days.',
                'intro' => 'Bring people, salaries, balances, attendance and history across with importers that check every row before anything is saved.',
                'features' => [
                    ['table-cells', 'Sixty-plus importers', 'Templates for people, pay, leave, attendance, assets and more.'],
                    ['book-open', 'Format guides', 'Every column explained, with a filled-in example to start from.'],
                    ['check-badge', 'Validate first', 'Errors are shown row by row before a single record is written.'],
                    ['arrows-right-left', 'New or update', 'Add new records or update existing ones from the same template.'],
                    ['document-check', 'Recent uploads', 'See what was imported, by whom and with what result.'],
                    ['shield-check', 'Audited', 'Imports run as one recorded operation you can trace later.'],
                ],
                'spotlight' => ['Opening balances, done right', 'Leave balances, year-to-date salary and history come across so the first payroll is right.', ['Staging before commit', 'Clear error messages', 'Re-run safely after fixes']],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> solutions by team and by industry: slug => [group, title, menu, icon, hue, headline, highlight, intro, links] */
    public static function solutions(): array
    {
        return [
            'hr-teams' => ['group' => 'team', 'title' => 'HR teams', 'menu' => 'Run the whole lifecycle', 'icon' => 'user-group', 'hue' => 'iris',
                'headline' => 'Spend your day on people,', 'highlight' => 'not paperwork.',
                'intro' => 'Hiring to exit, policies to payroll inputs — one connected workspace with the checks done for you.',
                'links' => ['core-hr', 'onboarding', 'attendance', 'leave', 'helpdesk', 'analytics']],
            'payroll-finance' => ['group' => 'team', 'title' => 'Payroll & finance', 'menu' => 'Close pay with confidence', 'icon' => 'banknotes', 'hue' => 'gold',
                'headline' => 'Numbers that reconcile', 'highlight' => 'the first time.',
                'intro' => 'Guided payroll runs, statutory reports from the same data and locked finalised runs your auditors will like.',
                'links' => ['payroll', 'compliance', 'expenses', 'analytics']],
            'managers' => ['group' => 'team', 'title' => 'Managers', 'menu' => 'Approve and lead in minutes', 'icon' => 'briefcase', 'hue' => 'teal',
                'headline' => 'Lead your team,', 'highlight' => 'not your inbox.',
                'intro' => 'Approvals with context, a team calendar and reviews that take minutes — on desktop or phone.',
                'links' => ['leave', 'attendance', 'performance', 'self-service']],
            'employees' => ['group' => 'team', 'title' => 'Employees', 'menu' => 'Self-service that delights', 'icon' => 'user-circle', 'hue' => 'green',
                'headline' => 'Everything about work,', 'highlight' => 'in one calm place.',
                'intro' => 'Payslips, leave, attendance, requests and declarations — answered in seconds, from any device.',
                'links' => ['self-service', 'leave', 'compliance', 'engagement']],
            'manufacturing' => ['group' => 'industry', 'title' => 'Manufacturing', 'menu' => 'Shifts, sites and contract staff', 'icon' => 'wrench-screwdriver', 'hue' => 'slate',
                'headline' => 'Shift-floor reality,', 'highlight' => 'handled.',
                'intro' => 'Rotating shifts, biometric punches, overtime and state-wise compliance across plants and sites.',
                'links' => ['attendance', 'payroll', 'compliance', 'assets']],
            'it-services' => ['group' => 'industry', 'title' => 'IT & services', 'menu' => 'Hybrid teams that scale', 'icon' => 'cpu-chip', 'hue' => 'blue',
                'headline' => 'Scale teams', 'highlight' => 'without scaling HR.',
                'intro' => 'Fast onboarding, hybrid attendance, goals and reviews, and integrations with the tools you already use.',
                'links' => ['onboarding', 'performance', 'learning', 'integrations']],
            'healthcare' => ['group' => 'industry', 'title' => 'Healthcare', 'menu' => 'Rosters, credentials, round the clock', 'icon' => 'beaker', 'hue' => 'coral',
                'headline' => 'Care teams,', 'highlight' => 'always rostered right.',
                'intro' => 'Round-the-clock rosters, credential documents and mandatory training tracked for every caregiver.',
                'links' => ['attendance', 'core-hr', 'learning', 'leave']],
            'retail' => ['group' => 'industry', 'title' => 'Retail', 'menu' => 'Stores, seasons, self-service', 'icon' => 'shopping-bag', 'hue' => 'plum',
                'headline' => 'Every store,', 'highlight' => 'one view of people.',
                'intro' => 'Store-wise attendance, seasonal hiring and phone-first self-service for teams who are never at a desk.',
                'links' => ['attendance', 'onboarding', 'self-service', 'payroll']],
            'logistics' => ['group' => 'industry', 'title' => 'Logistics', 'menu' => 'Field staff and many locations', 'icon' => 'truck', 'hue' => 'green',
                'headline' => 'People on the move,', 'highlight' => 'in sync.',
                'intro' => 'Location-aware check-in, advances and expenses for field staff, and compliance across every state you operate in.',
                'links' => ['attendance', 'expenses', 'compliance', 'self-service']],
        ];
    }

    /** @return array<string, array<string, mixed>> AI for HR pages (what the PeopleOS assistants and intelligence actually do) */
    public static function ai(): array
    {
        return [
            'assistants' => [
                'title' => 'HR assistants', 'menu' => 'Answers for employees, managers and HR', 'icon' => 'chat-bubble-left-right', 'hue' => 'iris', 'visual' => 'assistant',
                'headline' => 'Ask in plain words,', 'highlight' => 'get answers with sources.',
                'intro' => 'Assistants for employees, managers, HR and policy questions answer from your own PeopleOS data — and show the facts each answer is based on.',
                'features' => [
                    ['user-circle', 'Employee assistant', 'Leave balances, payslips, holidays and requests, answered for the person asking.'],
                    ['user-group', 'Manager assistant', 'Who is away, what is waiting for approval and how the team is doing.'],
                    ['sparkles', 'HR copilot', 'Drafts, summaries and next steps for HR work, always for a person to review.'],
                    ['book-open', 'Policy assistant', 'Answers grounded in your published policies and knowledge articles.'],
                    ['check-badge', 'Sources shown', 'Every answer lists the facts it used, so people can check it.'],
                    ['arrow-path-rounded-square', 'Suggested, never done', 'Assistants suggest actions with links; a person always decides.'],
                ],
                'spotlight' => ['Grounded in your data', 'Answers come from the records the person is allowed to see — never from someone else\'s.', ['Respects roles and organisation scope', 'Shows the facts behind each answer', 'Works without an external model when you choose']],
            ],
            'payroll-auditor' => [
                'title' => 'Payroll auditor', 'menu' => 'Anomalies flagged before you pay', 'icon' => 'shield-check', 'hue' => 'gold', 'visual' => 'payroll',
                'headline' => 'A second pair of eyes', 'highlight' => 'on every payroll run.',
                'intro' => 'The payroll auditor checks each run for unusual changes and explains every finding with evidence, before anyone is paid.',
                'features' => [
                    ['bell-alert', 'Unusual changes', 'Big jumps or drops in pay against previous months are flagged.'],
                    ['document-check', 'Evidence attached', 'Each finding shows the numbers behind it.'],
                    ['user-group', 'Joiners and leavers', 'Part-month pay and settlements are checked against dates.'],
                    ['scale', 'Statutory sense-checks', 'Contributions that look out of line are surfaced for review.'],
                    ['lock-closed', 'Nothing changed for you', 'Findings are indicators for a reviewer; payroll is never altered.'],
                    ['clipboard-document-check', 'Part of the run', 'Review findings as a step of the guided payroll run.'],
                ],
                'spotlight' => ['Catch it before payday', 'Problems found during review cost minutes; problems found after payday cost trust.', ['Rules you can explain to auditors', 'Findings kept with the run', 'Works on every pay cycle']],
            ],
            'people-search' => [
                'title' => 'Plain-English search', 'menu' => 'Find people and settings by asking', 'icon' => 'magnifying-glass', 'hue' => 'teal', 'visual' => 'assistant',
                'headline' => 'Type the question,', 'highlight' => 'skip the filters.',
                'intro' => '"Joiners in Pune last quarter" or "Where do I set the pay cycle?" — PeopleOS turns plain questions into the right list or the right settings page.',
                'features' => [
                    ['magnifying-glass', 'People in plain English', 'Departments, locations, lifecycle words and date phrases understood.'],
                    ['presentation-chart-line', 'Workforce analyst', 'Headcount and movement questions answered from your reports.'],
                    ['wrench-screwdriver', 'Settings finder', '"What do you want to configure?" takes you straight to the setting.'],
                    ['lock-closed', 'Permission-aware', 'Results only include what the person may already see.'],
                    ['bolt', 'Instant', 'Answers in the command bar, on any page.'],
                    ['table-cells', 'Export', 'Turn any answer into a report you can download.'],
                ],
                'spotlight' => ['Less clicking, more knowing', 'The command bar understands what you mean and takes you there.', ['Works from every page', 'Remembers recent searches', 'Respects access everywhere']],
            ],
            'trust' => [
                'title' => 'AI with guard-rails', 'menu' => 'Your data boundary, your choice', 'icon' => 'lock-closed', 'hue' => 'slate', 'visual' => 'security',
                'headline' => 'Helpful AI,', 'highlight' => 'without the data risk.',
                'intro' => 'Every question and fact is classified before anything could leave PeopleOS. Secrets never do, and each organisation decides what may.',
                'features' => [
                    ['lock-closed', 'Secrets never leave', 'Passwords, keys, tokens and signatures are never sent or stored in the AI log.'],
                    ['shield-check', 'Restricted data, your call', 'Bank and statutory numbers, salaries and ratings are shared only if you allow it.'],
                    ['key', 'Switch it off', 'Choose "none" and every answer stays deterministic inside PeopleOS.'],
                    ['document-check', 'AI log', 'Every interaction is recorded for review.'],
                    ['finger-print', 'Pattern checks', 'A PAN or account number pasted into a question is caught too.'],
                    ['check-badge', 'Human in the loop', 'Assistants suggest; people decide.'],
                ],
                'spotlight' => ['A boundary you can explain', 'Classification is by field and by pattern, so the rule is simple to audit.', ['Three clear levels: none, standard, restricted', 'Applies to every assistant', 'Recorded in the AI log']],
            ],
        ];
    }

    /**
     * Letter generators (Tools): slug => [title, menu, intro, template]. Templates use {placeholders}; a placeholder ending
     * in _date is a date field. Everything is filled in the visitor's browser; nothing is sent anywhere.
     *
     * @return array<string, array<string, string>>
     */
    public static function letters(): array
    {
        $sign = "\n\nYours sincerely,\n\n{signatory_name}\n{signatory_designation}\n{company_name}";

        return [
            'offer-letter' => ['title' => 'Offer letter generator', 'menu' => 'Make a job offer', 'icon' => 'envelope',
                'intro' => 'Create a clear, professional offer letter in a minute. Fill in the details and copy or print the letter.',
                'template' => "{letter_date}\n\nDear {candidate_name},\n\nSubject: Offer of employment\n\nWe are delighted to offer you the position of {designation} in our {department} team at {company_name}, based in {work_location}.\n\nYour annual cost to company will be {annual_ctc}, as set out in the salary annexure that will accompany your appointment letter. We would like you to join us on {joining_date}.\n\nThis offer is subject to satisfactory background verification and the submission of the documents listed by our HR team. Please confirm your acceptance by signing and returning a copy of this letter by {acceptance_date}.\n\nWe look forward to welcoming you to the team.".$sign],
            'appointment-letter' => ['title' => 'Appointment letter generator', 'menu' => 'Confirm the appointment', 'icon' => 'document-check',
                'intro' => 'Formalise a new joiner\'s appointment with their role, date of joining, probation and reporting details.',
                'template' => "{letter_date}\n\nDear {employee_name},\n\nSubject: Letter of appointment\n\nFurther to your acceptance of our offer, we are pleased to appoint you as {designation} at {company_name} with effect from {joining_date}. Your employee code is {employee_code}.\n\nYou will report to {manager_name} and be based at {work_location}. You will be on probation for {probation_months} months from your date of joining, after which your confirmation will be reviewed.\n\nYour annual cost to company is {annual_ctc}. Your terms of employment, leave and other benefits are governed by the company's policies, as amended from time to time.\n\nPlease sign and return a copy of this letter as a token of your acceptance.".$sign],
            'internship-offer-letter' => ['title' => 'Internship offer letter generator', 'menu' => 'Welcome an intern', 'icon' => 'academic-cap',
                'intro' => 'Offer an internship with its dates, stipend and mentor in a friendly, professional letter.',
                'template' => "{letter_date}\n\nDear {intern_name},\n\nSubject: Internship offer\n\nWe are pleased to offer you an internship as {internship_role} at {company_name}, from {start_date} to {end_date}.\n\nYou will work with {mentor_name}, who will guide you through your projects. You will receive a monthly stipend of {monthly_stipend}.\n\nPlease confirm your acceptance by replying to this letter. We look forward to learning together.".$sign],
            'confirmation-letter' => ['title' => 'Confirmation letter generator', 'menu' => 'Confirm after probation', 'icon' => 'check-badge',
                'intro' => 'Confirm an employee after a successful probation, with the effective date.',
                'template' => "{letter_date}\n\nDear {employee_name},\n\nSubject: Confirmation of employment\n\nWe are pleased to inform you that, following a review of your performance during probation, your employment as {designation} with {company_name} is confirmed with effect from {confirmation_date}.\n\nAll other terms of your employment remain unchanged. We appreciate your contribution and wish you continued success.".$sign],
            'salary-increment-letter' => ['title' => 'Salary increment letter generator', 'menu' => 'Share a raise', 'icon' => 'banknotes',
                'intro' => 'Tell an employee about their revised salary and when it takes effect.',
                'template' => "{letter_date}\n\nDear {employee_name},\n\nSubject: Revision of salary\n\nIn recognition of your contribution, we are pleased to revise your annual cost to company from {current_ctc} to {revised_ctc} with effect from {effective_date}.\n\nThe revised breakup of your salary is available in your payslips and on PeopleOS. All other terms of your employment remain unchanged.\n\nThank you for your commitment. We look forward to your continued success with {company_name}.".$sign],
            'experience-letter' => ['title' => 'Experience letter generator', 'menu' => 'Certify service', 'icon' => 'identification',
                'intro' => 'Certify an employee\'s period of service and role for their future employers.',
                'template' => "{letter_date}\n\nTO WHOMSOEVER IT MAY CONCERN\n\nThis is to certify that {employee_name} (employee code {employee_code}) was employed with {company_name} from {joining_date} to {last_working_date}. At the time of leaving, {employee_name} held the position of {designation} in the {department} department.\n\nDuring this period we found {employee_name} sincere and diligent. We wish them every success in the future.".$sign],
            'relieving-letter' => ['title' => 'Relieving letter generator', 'menu' => 'Release after exit', 'icon' => 'arrow-right-start-on-rectangle',
                'intro' => 'Confirm that an employee has been relieved of their duties after completing the exit formalities.',
                'template' => "{letter_date}\n\nDear {employee_name},\n\nSubject: Relieving letter\n\nThis is to confirm that your resignation dated {resignation_date} has been accepted and that you have been relieved of your duties as {designation} at {company_name} at the close of business on {last_working_date}.\n\nWe confirm that you have completed the exit formalities and that your full and final settlement will be processed as per company policy.\n\nWe thank you for your contribution and wish you the very best.".$sign],
            'resignation-acceptance-letter' => ['title' => 'Resignation acceptance letter generator', 'menu' => 'Accept a resignation', 'icon' => 'clipboard-document-check',
                'intro' => 'Acknowledge a resignation and confirm the last working day and hand-over.',
                'template' => "{letter_date}\n\nDear {employee_name},\n\nSubject: Acceptance of resignation\n\nWe acknowledge receipt of your resignation dated {resignation_date} from the position of {designation}. Your resignation has been accepted, and your last working day will be {last_working_date}.\n\nPlease complete the hand-over of your responsibilities to {handover_to} and the exit clearance before your last working day.\n\nWe thank you for your contribution to {company_name} and wish you well.".$sign],
        ];
    }

    /** @return array<string, array<string, string>> calculators (Tools): slug => [kind, title, menu, icon, intro, note] */
    public static function calculators(): array
    {
        return [
            'gratuity-calculator' => ['kind' => 'gratuity', 'title' => 'Gratuity calculator', 'menu' => 'Estimate gratuity at exit', 'icon' => 'calculator',
                'intro' => 'Estimate gratuity from the last drawn basic salary and dearness allowance and the years of service.',
                'note' => 'An estimate using the commonly applied formula: 15 days\' wages for each year of service (wages ÷ 26) for organisations covered by the Payment of Gratuity Act, and wages ÷ 30 for those that are not. Service of more than six months in the final year counts as a full year under the Act. Eligibility usually needs five years of continuous service (except on death or disablement). Check the current ceiling and your own policy before paying.'],
            'ctc-calculator' => ['kind' => 'ctc', 'title' => 'CTC to in-hand salary calculator', 'menu' => 'See the monthly take-home', 'icon' => 'currency-rupee',
                'intro' => 'Split an annual CTC into a monthly breakup and see the take-home before income tax.',
                'note' => self::ctcNote()],
            'leave-encashment-calculator' => ['kind' => 'leave_encashment', 'title' => 'Leave encashment calculator', 'menu' => 'Value unused leave', 'icon' => 'calendar-days',
                'intro' => 'Work out the value of encashed leave from the monthly salary used for encashment and the days encashed.',
                'note' => 'An estimate: per-day pay is the monthly salary used for encashment (often basic plus dearness allowance) divided by the days your policy uses (30 or 26). Taxability depends on whether the employee is in service or retiring.'],
            'notice-period-calculator' => ['kind' => 'notice', 'title' => 'Notice period calculator', 'menu' => 'Find the last working day', 'icon' => 'clock',
                'intro' => 'Find the last working day from the resignation date and notice period, and the recovery for any days not served.',
                'note' => 'An estimate: notice is counted in calendar days from the day after resignation. Recovery for days not served is worked out on monthly basic ÷ 30 unless your policy says otherwise.'],
        ];
    }

    /**
     * Calculator notes an earlier release seeded and that statutory changes have since made wrong (the CTC note said
     * PF was capped at ₹15,000). A published page still carrying one word for word shows the current note instead;
     * a note an operator rewrote is left alone.
     */
    private const SUPERSEDED_NOTES = [
        'ctc' => [
            'An illustration only. Employer and employee PF are 12% of basic (optionally on a basic capped at ₹15,000 a month); gratuity, when included in CTC, is taken as 4.81% of basic. Professional tax varies by state. Income tax is not deducted here: it depends on the employee\'s regime and declarations.',
        ],
    ];

    /** The assumptions to show under a calculator's result: the stored note, or the current one if it is outdated. */
    public static function calculatorNote(string $kind, ?string $note): ?string
    {
        if (! in_array(trim((string) $note), self::SUPERSEDED_NOTES[$kind] ?? [], true)) {
            return $note;
        }
        foreach (self::calculators() as $calculator) {
            if ($calculator['kind'] === $kind) {
                return $calculator['note'];
            }
        }

        return $note;
    }

    private static function ctcNote(): string
    {
        $pf = CalculatorRules::inr(CalculatorRules::PF_WAGE_CEILING);

        return 'An illustration only. PF is 12% from the employee and 12% from the employer on PF wages (basic + DA; the basic here). '
            ."PF is compulsory when PF wages are {$pf} a month or less, the EPFO wage ceiling from ".CalculatorRules::PF_CEILING_FROM.', and contributions may be restricted to that ceiling. '
            ."An employee can opt out only if their PF wages are above {$pf} when they join and they have never been a PF member. "
            .'ESI covers gross pay of '.CalculatorRules::inr(CalculatorRules::ESI_WAGE_CEILING).' a month or less ('.CalculatorRules::inr(CalculatorRules::ESI_WAGE_CEILING_DISABILITY).' for a person with a disability): '
            .'0.75% from the employee (nil at a daily average wage of ₹'.CalculatorRules::ESI_EXEMPT_DAILY_WAGE.' or less) and 3.25% from the employer. '
            .'Once covered, an employee stays in ESI until the end of the contribution period (April–September or October–March). '
            .'PF administration and EDLI charges are left out. Gratuity, when included in CTC, is taken as 4.81% of basic. '
            .'Professional tax varies by state. Income tax is not deducted here: it depends on the employee\'s regime and declarations.';
    }

    /** @return list<array{0: string, 1: string}> HR glossary terms and plain definitions */
    public static function glossary(): array
    {
        return [
            ['Attrition', 'The rate at which people leave an organisation over a period, usually shown as a percentage of average headcount.'],
            ['Basic salary', 'The fixed core part of pay. Many other components (HRA, PF, gratuity) are worked out as a share of it.'],
            ['Background verification (BGV)', 'Checks on a candidate\'s identity, education, employment history and records before or after joining.'],
            ['Comp-off', 'A day off given in place of extra time worked, for example on a weekly off or holiday.'],
            ['Cost to company (CTC)', 'The total yearly cost of employing someone: gross pay plus employer contributions such as PF and gratuity.'],
            ['Dearness allowance (DA)', 'An allowance linked to the cost of living; with basic salary it is often the base for PF, gratuity and leave encashment.'],
            ['Effective date', 'The date from which a change (a transfer, a new salary, a promotion) applies, which may differ from the day it was entered.'],
            ['Employee self-service (ESS)', 'Screens where employees handle their own requests, payslips, leave and details without going through HR.'],
            ['ESI', 'Employees\' State Insurance: a social security scheme providing medical and cash benefits to eligible employees.'],
            ['Full and final settlement (F&F)', 'The last payment to a leaving employee: pending salary, leave encashment and gratuity, less recoveries.'],
            ['Gratuity', 'A lump sum paid to an employee on leaving after (usually) five years of continuous service.'],
            ['Gross salary', 'Pay before deductions: basic plus all allowances, but excluding employer contributions.'],
            ['HRA', 'House rent allowance: part of pay meant for rent, partly tax-exempt under conditions.'],
            ['Increment', 'An increase in salary, often after the annual performance review.'],
            ['Labour welfare fund (LWF)', 'A small state-level contribution by employer and employee that funds welfare schemes for workers.'],
            ['Loss of pay (LOP)', 'Unpaid days, for example leave taken without a balance, deducted from the month\'s pay.'],
            ['Net pay (take-home)', 'What the employee receives after all deductions such as PF, professional tax and income tax.'],
            ['Notice period', 'The time an employee or employer must give before employment ends, as set in the contract.'],
            ['Onboarding', 'Everything that turns a new joiner into a productive team member: documents, accounts, introductions and training.'],
            ['Pay cycle', 'The period a month\'s payroll covers, for example the calendar month or the 25th to the 24th.'],
            ['Payroll run', 'The monthly process of collecting inputs, calculating pay, reviewing, finalising and publishing payslips.'],
            ['Probation', 'An initial period of employment during which performance is reviewed before confirmation.'],
            ['Professional tax (PT)', 'A state tax on employment, deducted from salary in the states that levy it.'],
            ['Provident fund (PF)', 'A retirement savings scheme to which employer and employee each contribute a share of basic pay.'],
            ['Regularisation', 'Correcting an attendance record, for example a missed punch, with a reason and an approval.'],
            ['Statutory compliance', 'Meeting legal obligations such as PF, ESI, professional tax, labour welfare fund and the related registers and returns.'],
            ['TDS', 'Tax deducted at source: income tax the employer deducts from salary each month and deposits with the government.'],
            ['Variable pay', 'Pay that depends on performance or results, such as a bonus or incentive.'],
        ];
    }
}
