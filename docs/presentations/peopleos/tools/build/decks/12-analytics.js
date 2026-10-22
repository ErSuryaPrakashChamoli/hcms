// Deck 12 — Reporting and analytics training.
'use strict';
const common = require('./common');

module.exports = (lib) => {
    const { Deck, has, find } = lib;
    const d = new Deck({
        num: 12, file: '12-PeopleOS-Reporting-and-Analytics-Training.pptx', short: 'Reporting and analytics',
        title: 'Reporting and Analytics Training',
        subtitle: 'Read the dashboards, use the analytics pages, run and export reports, and check the audit trail, knowing what each figure means and where the gaps are.',
        audience: 'HR analysts, HR leaders, executives, payroll leads and auditors',
    });
    const A = (n) => `analytics/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 12: missing ${name}`); };
    const wide = (name, o) => { if (has(name)) d.wide({ img: name, ...o }); else lib.WARN.push(`deck 12: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 12: missing ${o.left.img} / ${o.right.img}`); };
    const first = (...n) => n.find((x) => has(x));
    const SRC = 'Captured on the training database on 21 Oct 2026 as Kavya Menon (Tenant HR Admin) and Meera Iyer (Executive); tools/capture/80-insights.mjs. Figures reflect the fictional Demo Group after the other training workflows ran (22 people).';

    d.cover({ shot: A('80-ins-04-hr-overview-dashboard'), notes: `SAY: Every figure in PeopleOS is computed from the records the viewer is allowed to see. This deck shows where figures live, how to read them, how to export, and what is not available yet.\nEVIDENCE: ${SRC}` });
    d.statusLegend();
    common.statusSimple(d, [{ head: 'Some figures hide small groups', body: 'People, performance, compensation and HR service analytics and talent pools suppress counts below 5. The HR overview, Workforce pulse, reports and exit insights show exact counts to permitted roles.', tone: 'info' }], 'Which figures do you report to leadership today?');
    d.numbered({
        title: 'What you will be able to do', eyebrow: 'Learning objectives',
        items: [
            ['Read the HR overview and Workforce pulse', 'and explain how each figure is calculated'],
            ['Use People analytics and the area dashboards', 'attendance, leave, pay, performance, learning, talent, exits, service'],
            ['Run and export a report', 'and know where the export is recorded'],
            ['Check who changed what', 'Change history, event detail and integrity'],
            ['Reconcile figures with source data', 'filters, "as of" dates and drill-downs'],
            ['Name the current gaps', 'report builder, executive datasets, payroll reports'],
        ],
        notes: 'ASK: "Which figure do you report most often today?" Use the answer to choose which slides to dwell on.' });
    d.table({ title: 'Where the figures live', eyebrow: 'Map of insights',
        columns: ['Screen', 'Menu path', 'Who can open (system roles)', 'Status'],
        colW: [2.7, 3.8, 3.9, 1.73],
        rows: [
            ['HR overview (Dashboards)', 'Insights › Analytics › Dashboards', 'Tenant HR Admin, HR Manager, Executive, Auditor', 'VERIFIED IN RUNNING APPLICATION'],
            ['Workforce pulse', 'Insights › Analytics › Workforce Command Centre', 'Tenant HR Admin, Executive, Auditor', 'VERIFIED IN RUNNING APPLICATION'],
            ['Workforce Intelligence', 'Insights › Analytics › Workforce Intelligence', 'Tenant HR Admin, HR Manager, Executive', 'VERIFIED IN RUNNING APPLICATION'],
            ['People analytics', 'Insights › Analytics › People analytics', 'HR roles, Manager, Payroll and Attendance Admin, Executive, Auditor', 'VERIFIED IN RUNNING APPLICATION'],
            ['Reports and export', 'Insights › Analytics › Reports', 'Tenant HR Admin, HR Manager, Executive, Auditor', 'INCOMPLETE'],
            ['Change history', 'Insights › Audit › Change history', 'Tenant HR Admin, HR Manager, Payroll Admin, Account Owner, Auditor', 'VERIFIED IN RUNNING APPLICATION'],
            ['Area dashboards', 'Pay, Growth, Workforce, Lifecycle, Service Desk menus', 'By area permission', 'VERIFIED IN RUNNING APPLICATION'],
        ],
        notes: 'SOURCE: docs/reports/feature-inventory.md (who can open, computed from the application for each system role) and the captures in this deck. Reports is marked Incomplete because of the report-builder defect F44 and the executive dataset gap F45; running and exporting starter reports works.' });

    // ---- 1. Dashboards
    d.section(1, 'Dashboards', 'The HR overview, the Workforce pulse and the signals behind them.');
    wide(A('80-ins-04-hr-overview-dashboard'), { title: 'HR overview dashboard', eyebrow: 'Dashboards',
        caption: 'Headcount, attrition (12 months), absenteeism (30 days), people cost, headcount trend and by department, needs attention, mandatory learning completion.',
        notes: `HOW TO READ: Headcount 22 = active employees today. Attrition 4.5% = exits in the last 12 months ÷ average headcount. Absenteeism 0.0% = absent days ÷ scheduled working days, last 30 days. People cost shows "—" because no payroll has been finalised (statutory rules unverified).\nEVIDENCE: ${SRC}` });
    d.cards({ title: 'How the headline figures are calculated', eyebrow: 'Dashboards',
        cards: [
            { head: 'Headcount', body: 'Active employees on the date, within your access scope. The trend uses month-end headcounts.' },
            { head: 'Attrition (12 months)', body: 'Exits in the last 12 months ÷ average headcount over the period. 4.5% in the training data.' },
            { head: 'Absenteeism (30 days)', body: 'Absent days ÷ scheduled working days over the last 30 days, from processed attendance records.' },
            { head: 'People cost', body: 'From finalised and paid payroll only. Shows "—" until a run can be finalised (statutory rules unverified).', tone: 'warn' },
        ],
        notes: 'SOURCE: tile captions on the HR overview and Workforce Intelligence screens ("Exits in the last 12 months ÷ average headcount"; "Absent days ÷ scheduled working days, last 30 days").\nAVOID: comparing people cost with finance figures today: it is empty by design until payroll is finalised.' });
    wide(A('80-ins-02-workforce-pulse'), { title: 'Workforce pulse: what moved this month', eyebrow: 'Dashboards',
        caption: 'Top of the page: movement (moves, promotions, joiners, exits against last month), size, decisions waiting and planning. Further down: attendance, performance, critical skills, exceptions. Select a figure to see the people behind it.',
        notes: `OBSERVE: "18.2% of headcount moved this month" reflects the training workflows (a hire, a transfer, a promotion, an exit).\nSAY: Drill-down opens the people behind a figure, limited to those you may see.\nEVIDENCE: ${SRC}` });
    shot(A('80-ins-03-workforce-intelligence'), { title: 'Workforce Intelligence: signals with reasons', eyebrow: 'Dashboards',
        steps: ['Attrition and capacity: aggregate facts only.', 'Critical skills: fewest advanced or expert holders first.'],
        result: 'PeopleOS does not score, rank or predict whether an individual will leave or be promoted: the page says so. Critical skills is empty here: no skill levels are recorded in the training data.',
        notes: 'SAY: Signals explain themselves; nothing here is an automated decision about a person.' });
    cmp({ title: 'Executives: home lens and dataset access', eyebrow: 'Dashboards',
        left: { label: 'Executive Home', img: first('executive-demo/80-ins-18-executive-home'), callouts: false, caption: 'An executive\'s Home opens on the workforce lens: pulse, decisions and signals.' },
        right: { label: 'HR overview as an executive', tone: 'bad', img: A('80-ins-20-dashboard-executive-f45'), callouts: false, caption: 'Headline tiles fill in, but report-based widgets show "You cannot report on the … dataset." (F45): the Executive role lacks dataset permissions.' },
        notes: 'KNOWN GAP F45: the Executive role template lacks analytics dataset permissions, so report-based widgets and reports are empty for executives until a Tenant Super Admin extends the role. Headline tiles (headcount, attrition, absenteeism, trend) do show.' });

    // ---- 2. People analytics
    d.section(2, 'People analytics', 'One page, ten areas, each limited to what your role may see.');
    wide(A('80-ins-01-people-analytics'), { title: 'People analytics', eyebrow: 'People analytics',
        caption: 'Top of the page shown: People, Workforce, Attendance, Leave, Performance, Learning. Further down: Career & talent, Compensation, HR service, Engagement. "As of" reports on a past date.',
        notes: 'SAY: Each area appears only if your role has that analytics permission. Here counts below 5 people are suppressed ("fewer than 5", e.g. joiners and exits under People, pending requests under Leave). Facts only: no individual scores, rankings or predictions.\nEXERCISE: change "As of" to 1 October and compare headcount.' });
    shot(A('80-ins-19-people-analytics-executive'), { title: 'The same page for an executive', eyebrow: 'People analytics',
        steps: ['Aggregated figures only.', 'Areas without dataset permission are not shown.'], result: 'What appears depends on the role, never on who asks.' });

    // ---- 3. Area dashboards
    d.section(3, 'Area dashboards', 'Attendance, leave, pay, performance, learning, talent, workforce, exits and service.');
    const leaveCal = find(/(hr-admin|manager)\/.*leave-calendar/)[0];
    if (leaveCal) cmp({ title: 'Attendance and leave', eyebrow: 'Area dashboards',
        left: { label: 'Attendance exception centre', img: 'hr-admin/20-att-14-exception-centre', callouts: ['1'], caption: 'Exceptions, missing punches, late, absent, overtime pending, regularisations and failed punches (30 days).' },
        right: { label: 'Leave calendar', img: leaveCal, callouts: false, caption: 'Who is away and when (approved, pending, cancellation requested). Reasons are never shown.' } });
    else shot('hr-admin/20-att-14-exception-centre', { title: 'Attendance exception centre', eyebrow: 'Area dashboards', steps: ['Exception counts for 30 days.'], result: 'Missing punches, late, absent, overtime pending, regularisations and failed punches.' });
    cmp({ title: 'Payroll: control room and auditor', eyebrow: 'Area dashboards',
        left: { label: 'Payroll control room', img: 'hr-admin/60-pay-01-control-room', callouts: false, caption: 'Readiness, runs and the statutory rules that block finalisation.' },
        right: { label: 'Payroll Auditor', img: 'hr-admin/60-pay-11-payroll-auditor', callouts: false, caption: 'Findings with severity and evidence for a run; rule-based despite the "AI" label. Some findings show raw technical text (display gap).' },
        notes: 'GAP: payroll cost reports ("Payroll cost by period") read finalised and paid runs only; none exist while statutory rules are unverified.' });
    cmp({ title: 'Compensation and performance', eyebrow: 'Area dashboards',
        left: { label: 'Compensation analytics', img: A('62-comp-11-compensation-analytics'), callouts: false, caption: 'Distributions and compa-ratios; groups below the minimum size are suppressed. Export compensation (CSV) is audited.' },
        right: { label: 'Performance analytics', img: A('80-ins-14-performance-analytics'), callouts: false, caption: 'Review progress and rating distribution for a cycle (FY27).' } });
    cmp({ title: 'Learning and talent', eyebrow: 'Area dashboards',
        left: { label: 'Learning & development', img: A('65-learn-03-learning-dashboard'), callouts: false, caption: 'Top of the page: enrolments, completion, mandatory training. Further down: popular courses, skill gaps, providers, costs.' },
        right: { label: 'Talent & succession', img: A('65-talent-01-talent-dashboard'), callouts: false, caption: 'Positions without a ready-now successor and talent pools (empty in the training data).' } });
    cmp({ title: 'Workforce, exits and HR service', eyebrow: 'Area dashboards',
        left: { label: 'Workforce overview', img: A('65-wf-06-workforce-dashboard'), callouts: false, caption: 'Positions, vacancies, budgets and plans (mostly zero). Shows 21 employees: captured 19 seconds before the training hire that makes 22 elsewhere.' },
        right: { label: 'HR service analytics', img: A('80-ins-16-service-analytics'), callouts: false, caption: '"Fewer than 5 employees raised requests in this period; no figures are shown." Privacy suppression.' },
        });
    shot(A('80-ins-15-exit-insights'), { title: 'Exit insights', eyebrow: 'Area dashboards', steps: ['Reasons for leaving and average exit-interview ratings.'], result: 'Averages use employee answers only.' });

    // ---- 4. Reports
    d.section(4, 'Reports and export', 'Six starter reports run and export today; building new reports has a known defect.');
    shot(A('80-ins-05-reports-list'), { title: 'Reports', eyebrow: 'Reports',
        steps: ['A starter report with its dataset and chart.', 'Run.', 'New report.'],
        result: 'Starter reports: Absence by department (30 days), Exits by month, Headcount by department, Mandatory learning completion, Payroll cost by period, Rating distribution.' });
    shot(A('80-ins-06-report-result'), { title: 'Run a report', eyebrow: 'Reports',
        steps: ['Grouped results, computed from the records you may see.', 'Export CSV.'],
        result: 'Headcount by department: Engineering 12, Design 4, (no department) 3, Consulting 2, Finance 1.',
        notes: 'RECONCILE: the "—" group is employees without a department; fix them in the employee register, then re-run.' });
    shot(A('80-ins-07-report-exported'), { title: 'Export CSV', eyebrow: 'Reports',
        steps: [], result: 'The CSV downloads immediately (observed: headcount-by-department-20261021-073623-1.csv), with no on-screen message. The export is recorded in Change history as an Export event.',
        notes: 'SAY: Exports respect your access scope and the dataset\'s sensitive-field permission. Every export is audited: see the first row of the Change history slide.' });
    shot(A('80-ins-08-report-builder-f44'), { title: 'Report builder: a known defect', eyebrow: 'Reports', status: 'INCOMPLETE',
        steps: ['Dataset chosen: Employees.', ['·', 'Group by (closed here) offered only "No grouping" when opened during the capture.']],
        result: 'Defect F44: the Fields list fills, but Filters, Group by, chart axis and Sort by (lower on the form) offer no dataset fields, so new grouped reports cannot be built. Use the starter reports and People analytics until it is fixed.',
        notes: 'EVIDENCE: app/Filament/Support/ReportBuilderSchema.php resolves the dataset with an absolute path; recorded in docs/reports/documentation-gaps.md F44 and re-observed live on 21 Oct 2026.' });
    shot(A('80-ins-09-dashboard-builder'), { title: 'Dashboard builder', eyebrow: 'Reports',
        steps: ['New dashboard.'], result: 'The HR overview dashboard: audience Everyone, 8 widgets. Widgets that use report grouping are affected by F44.' });
    d.cards({ title: 'Scheduled reports: know the limits', eyebrow: 'Reports',
        cards: [
            { head: 'Runs as the owner', body: 'A scheduled run uses the report owner\'s access. Starter reports have no owner, so their schedules fail (F46).', tone: 'warn' },
            { head: 'Notices are in-app', body: 'Completed schedules notify in the application only; only the owner can download a scheduled export.' },
            { head: 'Needs the scheduler', body: 'Schedules run through peopleos:reports:run-due, which needs the scheduler running in production (gate 8).', tone: 'warn' },
        ],
        notes: 'SOURCE: documentation-gaps F46 (code); architecture contract §10. Scheduled runs were not exercised in this training (no scheduler runs locally).' });

    // ---- 5. Audit
    d.section(5, 'Audit and operational reports', 'Who changed what, when, why, and proof that the record was not altered.');
    shot(A('80-ins-10-change-history'), { title: 'Change history', eyebrow: 'Audit',
        steps: ['When.', 'The latest event: the report export above.'],
        result: 'Every recorded change with action, module, record, who and reason. Filter, then View any event.',
        notes: 'OBSERVE: the export of "Headcount by department" appears as an Export event, followed by the training team\'s hires, manager changes and position edits.' });
    cmp({ title: 'One event: details and integrity', eyebrow: 'Audit',
        left: { label: 'Event detail', img: 'security-compliance/80-ins-11-audit-event-detail', callouts: false, caption: 'Who, when, action, reason and the field-level before → after values.' },
        right: { label: 'Who and where · Integrity', img: 'security-compliance/80-ins-11b-audit-integrity', maxAspect: 0.85, callouts: false, caption: 'Actor and roles, source, IP, request id; Hash, Previous hash and "Hash verified ✓".' },
        notes: 'SAY: Each event is chained to the one before it. Editing a stored event would break the chain.' });
    cmp({ title: 'Change intelligence and AI interactions', eyebrow: 'Audit',
        left: { label: 'Change intelligence', img: A('80-ins-12-change-intelligence'), callouts: false, caption: 'Changes by person and date with Explain. Sensitive values stay masked unless you may see them.' },
        right: { label: 'AI interactions', img: A('80-ins-13-ai-interactions'), callouts: false, caption: 'Every assistant question and answer, with the user, assistant and sources. Read only.' } });
    shot(A('80-ins-17-ask-peopleos'), { title: 'Ask PeopleOS: answers with sources', eyebrow: 'Audit',
        steps: ['The question: "What is our attrition?"'],
        result: 'Answer: 4.5% (exits in the last 12 months ÷ average headcount), with its source (Workforce metrics, computed live). The external language model is off by default; answers are rule-based and logged.' });

    // ---- 6. Reconcile
    d.cards({ title: 'Reconcile before you publish a figure', eyebrow: 'Reconciliation',
        cards: [
            { head: 'Same date', body: 'Set "As of" (People analytics) or the report period to the date of your source figure.' },
            { head: 'Same scope', body: 'Your figures cover only the people your role and scope reach. Compare with an administrator if numbers differ.' },
            { head: 'Explain blanks', body: 'A "—" group means the field is empty on some records (e.g. no department). Fix the records, re-run.' },
            { head: 'Small groups', body: 'People, performance, compensation and service analytics and talent pools suppress counts below 5 on purpose. Use a wider grouping instead of asking for the detail.' },
            { head: 'Drill down', body: 'Select a figure on the Workforce pulse to see the people behind it; compare with the Employees register filters.' },
            { head: 'Keep evidence', body: 'Export the CSV; the export is recorded in Change history with your name and time.' },
        ] });
    d.table({ title: 'Reports you may expect but will not find today', eyebrow: 'Gaps',
        columns: ['Desired report', 'Situation', 'Status'],
        colW: [3.5, 6.4, 2.23],
        rows: [
            ['Payroll register, payslip and statutory return reports', 'Need finalised runs; finalisation blocked until statutory rules are verified (0 of 24)', 'DISABLED BY SAFETY OR COMMERCIAL GATES'],
            ['New grouped or filtered custom reports', 'Report builder Group by, filter and sort lists empty (F44)', 'INCOMPLETE'],
            ['Executive dashboards with figures', 'Executive role lacks dataset permissions (F45); fix by role configuration', 'AVAILABLE ONLY AFTER CONFIGURATION'],
            ['Scheduled starter reports', 'Starter reports have no owner, so scheduled runs fail (F46)', 'INCOMPLETE'],
            ['PDF exports of reports', 'CSV only', 'NOT IMPLEMENTED'],
            ['Predictive attrition per person', 'Deliberately not built: facts only, no individual predictions', 'NOT IMPLEMENTED'],
        ],
        notes: 'SOURCE: documentation-gaps F44, F45, F46; Workforce Intelligence page text; release gate 5.' });
    d.exercise({ title: 'Practice', tasks: [
        'Open the HR overview. What is the attrition figure and how is it calculated?',
        'Run "Headcount by department" and export it. Where can an auditor see that you exported it?',
        'In People analytics, which figures show "fewer than 5" and why?',
        'An executive says some HR overview widgets say "You cannot report on…". What is the cause and who can fix it?',
    ], notes: 'ANSWER KEY: (1) 4.5%: exits in the last 12 months ÷ average headcount. (2) Insights › Audit › Change history: an Export event with your name. (3) Figures where fewer than 5 people are counted (e.g. joiners and exits under People, pending requests under Leave): privacy suppression. (4) The Executive role has no dataset permission (F45); a Tenant Super Admin extends the role.' });
    d.table({ title: 'Answer key', eyebrow: 'Practice', columns: ['#', 'Answer'], colW: [0.6, 11.53], rows: [
        ['1', '4.5%: exits in the last 12 months ÷ average headcount (HR overview, Attrition tile).'],
        ['2', 'Insights › Audit › Change history lists an Export event with the report name, your name and the time.'],
        ['3', 'Figures where fewer than 5 people are counted (for example joiners and exits under People, pending requests under Leave) show "fewer than 5" to protect privacy.'],
        ['4', 'Report-based widgets need dataset permissions the Executive role lacks (F45). A Tenant Super Admin can add them to the role.'],
    ] });
    d.closing({ points: ['Figures are computed from what your role may see; some analytics hide small groups.', 'HR overview, Workforce pulse and People analytics answer most questions.', 'Starter reports run and export to CSV; every export is audited.', 'Change history proves who changed what, with a hash chain that can be verified.', 'Known gaps: report builder (F44), executive datasets (F45), payroll reports until payroll is finalised.'],
        next: ['Deck 03: the HR screens behind these figures', 'Deck 08: audit and access controls', 'Ask your administrator for dataset permissions'] });
    return d;
};
