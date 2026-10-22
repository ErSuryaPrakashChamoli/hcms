# PeopleOS go-live and support handover checklist

**For:** the customer sponsor, the customer HR lead and Markedge support. Editable copy: `PeopleOS-Go-Live-Handover-Checklist.xlsx`.

Operations rows depend on hosting, monitoring and on-call, which are blocked release gates today.

| Area | Item | Responsible | Evidence | Status | Date | Signed by |
|---|---|---|---|---|---|---|
| Access | Customer administrators active; Markedge implementation accounts removed or suspended | Customer admin + Markedge lead | Users list export | | | |
| Access | Every role tested against the agreed role matrix (allowed and restricted pages) | Customer admin | Access test log | | | |
| Access | MFA required for administrators (Security policy); operator MFA enrolled in production | Customer admin; Markedge operators | Security policy screenshot; readiness | | | |
| Access | HR users have organisation scope where they should not see the whole tenant | Customer admin | Users › scope screenshot | | | |
| Configuration | Organisation, job architecture and locations match the signed design | Customer HR lead | Org map; lists | | | |
| Configuration | Leave types and policy versions published; sample balances reconciled | Customer HR | Balances export | | | |
| Configuration | Shifts, schedules and holiday calendars assigned; a sample week processed | Customer HR (attendance) | Attendance records | | | |
| Configuration | Workflows and notification rules reviewed; delivery log shows sent items | Customer HR | Delivery log | | | |
| Data | Headcount reconciled by company, unit and status (zero unexplained differences) | Customer data owner | Reconciliation sheet | | | |
| Data | No duplicate people; managers set for all active employees | Customer HR | Directory and register filters | | | |
| Payroll and statutory | Statutory registrations recorded; statutory rule verification status agreed (0 of 24 verified today) | Customer payroll; Markedge | Compliance control room | | | |
| Payroll and statutory | Parallel or reference payroll compared before the first live run (only when finalisation is allowed) | Customer payroll | Comparison sheet | | | |
| Operations | Queue worker and scheduler running; no failed jobs (Platform › Operations) | Markedge platform operations | Operations screenshot | | | |
| Operations | Backup schedule, restore test and recovery objectives agreed and evidenced | Markedge infrastructure | Restore test record | | | |
| Operations | Monitoring and on-call contacts confirmed | Markedge | On-call rota | | | |
| Support | Support channel, hours and escalation route communicated to the customer | Markedge support | Support handbook | | | |
| Support | Known defects and workarounds shared (F25 import, F38 apply on behalf, F44 report builder, F17 feedback) | Markedge support | Known-issues list | | | |
| Documentation | Training decks delivered; pilot users trained; exercise completion recorded | Markedge trainer | Attendance list | | | |
| Governance | Quarterly access review and policy review dates agreed | Customer HR lead | Calendar invite | | | |
| Sign-off | Go-live approval signed by the customer sponsor and Markedge, with the release gate decision recorded | Customer sponsor; Markedge | Signed approval | | | |
