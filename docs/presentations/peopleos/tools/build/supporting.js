// Editable supporting documents: implementation checklist, go-live handover checklist and UAT test cases.
// Writes .xlsx (with status drop-downs) and a Markdown copy of each into docs/presentations/peopleos/supporting/.
'use strict';
const fs = require('fs');
const path = require('path');
const ExcelJS = require('exceljs');
const { STAGES } = require('./stages');
const { UAT } = require('./uat');

const OUT = path.join(__dirname, '../../supporting');
fs.mkdirSync(OUT, { recursive: true });
const NAVY = 'FF141A30', SOFT = 'FFF4F5F8';

function sheet(wb, name, columns, rows, statusCol, statusList) {
    const ws = wb.addWorksheet(name, { views: [{ state: 'frozen', ySplit: 1 }] });
    ws.columns = columns.map(([header, width]) => ({ header, width }));
    ws.getRow(1).eachCell((c) => { c.font = { bold: true, color: { argb: 'FFFFFFFF' } }; c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: NAVY } }; c.alignment = { vertical: 'middle', wrapText: true }; });
    ws.getRow(1).height = 30;
    rows.forEach((r, i) => {
        const row = ws.addRow(r);
        row.alignment = { vertical: 'top', wrapText: true };
        if (i % 2) row.eachCell({ includeEmpty: true }, (c) => { c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: SOFT } }; });
    });
    if (statusCol) {
        for (let i = 2; i <= rows.length + 1; i++) ws.getCell(`${statusCol}${i}`).dataValidation = { type: 'list', allowBlank: true, formulae: [`"${statusList.join(',')}"`] };
    }
    ws.autoFilter = { from: 'A1', to: { row: 1, column: columns.length } };
    return ws;
}

function readme(wb, title, lines) {
    const ws = wb.addWorksheet('Read me');
    ws.getColumn(1).width = 120;
    ws.addRow([title]).font = { bold: true, size: 14 };
    for (const l of lines) ws.addRow([l]).alignment = { wrapText: true };
}

const md = (t) => String(t ?? '').replace(/\|/g, '\\|').replace(/\n/g, '<br>');

async function implementation() {
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Markedge PeopleOS training';
    readme(wb, 'PeopleOS customer implementation checklist', [
        'For: the customer implementation team and the Markedge implementation lead. One row per stage; fill Status, Date, Owner sign-off and Notes as you go.',
        'Mode says how each stage is done today: Configuration in PeopleOS, Operator action in PeopleOS (Markedge), Automated by PeopleOS, Manual (outside PeopleOS), or Blocked (a known defect or a release gate).',
        'Navigation paths are the real menu paths of the application (verified in the training environment on 21 October 2026, commit 72c96a73). Deck 04 shows each screen.',
        'Release context: the PeopleOS release gate is NO-GO (10 of 14 gates blocked, re-verified 13 October 2026). Stages 10, 17 and 26 are blocked until a defect fix, statutory verification and the release decision respectively.',
    ]);
    const cols = [['#', 5], ['Stage', 26], ['Responsible', 26], ['Mode', 20], ['Required inputs', 36], ['Navigation (real menu path)', 40], ['What to enter or configure', 40], ['Expected outcome', 30], ['How to verify', 30], ['Evidence to retain', 26], ['Common errors / known limits', 40], ['Dependencies and blockers', 30], ['Acceptance criteria', 34], ['Status', 14], ['Date', 12], ['Owner sign-off', 18], ['Notes', 30]];
    const rows = STAGES.map((s) => [s.id, s.name, s.owner, s.mode, s.inputs, s.nav, s.configure, s.outcome, s.verify, s.evidence, s.errors, s.deps, s.accept, '', '', '', '']);
    sheet(wb, 'Implementation stages', cols, rows, 'N', ['Not started', 'In progress', 'Done', 'Blocked', 'Not applicable']);
    await wb.xlsx.writeFile(path.join(OUT, 'PeopleOS-Customer-Implementation-Checklist.xlsx'));
    let m = `# PeopleOS customer implementation checklist\n\n**For:** the customer implementation team and the Markedge implementation lead. The editable workbook is \`PeopleOS-Customer-Implementation-Checklist.xlsx\` (status drop-downs); this page is a read-only copy.\n\nMenu paths are real (training environment, 21 October 2026, commit \`72c96a73\`). **Mode** shows whether a stage is configured in PeopleOS, done by a Markedge operator, automated, manual outside PeopleOS, or blocked. Release gate: **NO-GO** (10 of 14 gates blocked, re-verified 13 October 2026).\n\n`;
    m += '| # | Stage | Responsible | Mode | Inputs | Navigation | Configure | Outcome | Verify | Evidence | Common errors / limits | Dependencies | Acceptance |\n|---|---|---|---|---|---|---|---|---|---|---|---|---|\n';
    for (const s of STAGES) m += `| ${s.id} | ${md(s.name)} | ${md(s.owner)} | ${md(s.mode)} | ${md(s.inputs)} | ${md(s.nav)} | ${md(s.configure)} | ${md(s.outcome)} | ${md(s.verify)} | ${md(s.evidence)} | ${md(s.errors)} | ${md(s.deps)} | ${md(s.accept)} |\n`;
    fs.writeFileSync(path.join(OUT, 'PeopleOS-Customer-Implementation-Checklist.md'), m);
}

const HANDOVER = [
    ['Access', 'Customer administrators active; Markedge implementation accounts removed or suspended', 'Customer admin + Markedge lead', 'Users list export'],
    ['Access', 'Every role tested against the agreed role matrix (allowed and restricted pages)', 'Customer admin', 'Access test log'],
    ['Access', 'MFA required for administrators (Security policy); operator MFA enrolled in production', 'Customer admin; Markedge operators', 'Security policy screenshot; readiness'],
    ['Access', 'HR users have organisation scope where they should not see the whole tenant', 'Customer admin', 'Users › scope screenshot'],
    ['Configuration', 'Organisation, job architecture and locations match the signed design', 'Customer HR lead', 'Org map; lists'],
    ['Configuration', 'Leave types and policy versions published; sample balances reconciled', 'Customer HR', 'Balances export'],
    ['Configuration', 'Shifts, schedules and holiday calendars assigned; a sample week processed', 'Customer HR (attendance)', 'Attendance records'],
    ['Configuration', 'Workflows and notification rules reviewed; delivery log shows sent items', 'Customer HR', 'Delivery log'],
    ['Data', 'Headcount reconciled by company, unit and status (zero unexplained differences)', 'Customer data owner', 'Reconciliation sheet'],
    ['Data', 'No duplicate people; managers set for all active employees', 'Customer HR', 'Directory and register filters'],
    ['Payroll and statutory', 'Statutory registrations recorded; statutory rule verification status agreed (0 of 24 verified today)', 'Customer payroll; Markedge', 'Compliance control room'],
    ['Payroll and statutory', 'Parallel or reference payroll compared before the first live run (only when finalisation is allowed)', 'Customer payroll', 'Comparison sheet'],
    ['Operations', 'Queue worker and scheduler running; no failed jobs (Platform › Operations)', 'Markedge platform operations', 'Operations screenshot'],
    ['Operations', 'Backup schedule, restore test and recovery objectives agreed and evidenced', 'Markedge infrastructure', 'Restore test record'],
    ['Operations', 'Monitoring and on-call contacts confirmed', 'Markedge', 'On-call rota'],
    ['Support', 'Support channel, hours and escalation route communicated to the customer', 'Markedge support', 'Support handbook'],
    ['Support', 'Known defects and workarounds shared (F25 import, F38 apply on behalf, F44 report builder, F17 feedback)', 'Markedge support', 'Known-issues list'],
    ['Documentation', 'Training decks delivered; pilot users trained; exercise completion recorded', 'Markedge trainer', 'Attendance list'],
    ['Governance', 'Quarterly access review and policy review dates agreed', 'Customer HR lead', 'Calendar invite'],
    ['Sign-off', 'Go-live approval signed by the customer sponsor and Markedge, with the release gate decision recorded', 'Customer sponsor; Markedge', 'Signed approval'],
];

async function handover() {
    const wb = new ExcelJS.Workbook();
    readme(wb, 'PeopleOS go-live and support handover checklist', [
        'For: the customer sponsor, the customer HR lead and Markedge support. Complete every row before support takes over from the implementation team.',
        'Rows marked Operations depend on a hosted environment, monitoring and on-call, which are blocked release gates today (gate 7 and 8).',
    ]);
    sheet(wb, 'Handover', [['Area', 18], ['Item', 70], ['Responsible', 30], ['Evidence', 28], ['Status', 14], ['Date', 12], ['Signed by', 20], ['Notes', 30]], HANDOVER.map((r) => [...r, '', '', '', '']), 'E', ['Open', 'Done', 'Blocked', 'Not applicable']);
    await wb.xlsx.writeFile(path.join(OUT, 'PeopleOS-Go-Live-Handover-Checklist.xlsx'));
    let m = '# PeopleOS go-live and support handover checklist\n\n**For:** the customer sponsor, the customer HR lead and Markedge support. Editable copy: `PeopleOS-Go-Live-Handover-Checklist.xlsx`.\n\nOperations rows depend on hosting, monitoring and on-call, which are blocked release gates today.\n\n| Area | Item | Responsible | Evidence | Status | Date | Signed by |\n|---|---|---|---|---|---|---|\n';
    for (const r of HANDOVER) m += `| ${md(r[0])} | ${md(r[1])} | ${md(r[2])} | ${md(r[3])} | | | |\n`;
    fs.writeFileSync(path.join(OUT, 'PeopleOS-Go-Live-Handover-Checklist.md'), m);
}

async function uat() {
    const wb = new ExcelJS.Workbook();
    readme(wb, 'PeopleOS UAT test cases', [
        'For: the customer UAT lead and testers. Execute each case in the customer\'s UAT tenant as the stated role, then fill Actual result, Evidence screenshot, Pass/Fail, Defect reference and Sign-off.',
        'The "Reference run" columns record what Markedge observed when the same steps were executed in the training environment on 21 October 2026 (disposable database, fictional people). They are not the customer\'s result: Pass/Fail stays blank until the customer executes the case.',
        'A reference outcome of "Not executed" means the case could not be run in the training environment; the reason is given.',
    ]);
    const cols = [['Test ID', 10], ['Module', 16], ['Role', 18], ['Preconditions', 34], ['Steps', 50], ['Expected result', 40], ['Reference run outcome (training env)', 22], ['Reference observation', 40], ['Reference screenshot(s)', 36], ['Actual result', 30], ['Evidence screenshot', 24], ['Pass/Fail', 10], ['Defect reference', 16], ['Executed by / date', 20], ['Sign-off', 16]];
    const rows = UAT.map((t) => [t.id, t.module, t.role, t.pre, t.steps.map((s, i) => `${i + 1}. ${s}`).join('\n'), t.expected, t.ref, t.observed, (t.shots ?? []).join('\n'), '', '', '', t.defect ?? '', '', '']);
    sheet(wb, 'Test cases', cols, rows, 'L', ['Pass', 'Fail', 'Blocked', 'Not run']);
    await wb.xlsx.writeFile(path.join(OUT, 'PeopleOS-UAT-Test-Cases.xlsx'));
    let m = '# PeopleOS UAT test cases\n\n**For:** the customer UAT lead and testers. Editable copy with Pass/Fail drop-downs: `PeopleOS-UAT-Test-Cases.xlsx`.\n\n"Reference run" = what Markedge observed executing the same steps in the training environment (21 October 2026, fictional data). It is **not** a customer pass: Pass/Fail is left blank until the customer executes the case.\n\n| Test ID | Module | Role | Preconditions | Steps | Expected result | Reference run | Reference observation | Reference screenshot(s) | Known defect |\n|---|---|---|---|---|---|---|---|---|---|\n';
    for (const t of UAT) m += `| ${t.id} | ${md(t.module)} | ${md(t.role)} | ${md(t.pre)} | ${md(t.steps.map((s, i) => `${i + 1}. ${s}`).join('\n'))} | ${md(t.expected)} | ${md(t.ref)} | ${md(t.observed)} | ${md((t.shots ?? []).map((s) => '`' + s + '`').join(' '))} | ${md(t.defect ?? '')} |\n`;
    fs.writeFileSync(path.join(OUT, 'PeopleOS-UAT-Test-Cases.md'), m);
}

(async () => {
    await implementation();
    await handover();
    await uat();
    console.log('supporting documents written to', OUT);
})().catch((e) => { console.error(e); process.exit(1); });
