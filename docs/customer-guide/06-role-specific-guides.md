# 6. Role guides

> **In this chapter:** what each ready-made PeopleOS role is for, what it can open and do, and a typical day.
> **Who should read it:** every user (find your role), and administrators deciding who gets which role.

## 6.1 How roles work

- **A role is a set of permissions.** PeopleOS ships with 14 system roles. Your administrator gives each person one or
  more roles and can create custom roles.
- **People usually combine roles.** A manager normally has both **Manager** and **Employee**; an HR manager has **HR
  Manager** and **Employee**. Self-service pages (My HR, My day, My career…) need the user account to be linked to an
  employee record.
- **Organisation scope narrows what a role reaches.** An HR manager can be limited to one company, a manager to their
  team. A role with no scope reaches the whole organisation — so administrators should set scopes deliberately.
- **The menu adapts.** You only see the areas and modules your roles allow. The order of the menu follows your main
  role (for example Insights comes second for an executive).
- **Nobody can give more than they have.** A person who assigns roles cannot give a role more powerful than their own;
  only a Tenant Super Admin (or Markedge) can give the Tenant Super Admin role. Role changes are recorded in the audit
  trail.

The module lists below were produced from the application itself: for each role, PeopleOS was asked which modules that
role may open. "Can open" does not always mean "can change": many screens are read-only for some roles.

### Roles at a glance

| Role | Main purpose | Menu areas | Modules it can open* |
|---|---|---|---|
| Tenant Super Admin | Full control of your organisation | All nine | 186 |
| Account Owner | First administrator after self-service sign-up: set-up, users, security, data export | Home, Admin, Organisation, People, Workflows, Insights, My work, Communication | 43 |
| Tenant HR Admin | Configures PeopleOS for HR | All nine | 182 |
| HR Manager | Operates HR processes | All nine | 86 |
| HR Executive | Day-to-day HR administration | Home, My work, People, Organisation, Insights, Admin | 39 |
| Payroll Admin | Runs and approves payroll | Home, My work, People, Insights, Organisation, Admin | 63 |
| Attendance Admin | Attendance configuration and exceptions | Home, My work, People, Organisation, Insights, Workflows, Admin | 41 |
| Performance Admin | Intended for performance cycles (see note) | Home, My work, People, Organisation, Admin | 22 |
| Asset Admin | Company assets | Home, My work, People, Organisation, Admin | 21 |
| Manager | People manager | Home, My work, People, Organisation, Insights, Admin | 62 |
| Employee | Standard self-service | Home, My work, People, Organisation (+ Services and Communication with an employee record) | 38 |
| Executive | Command centre, dashboards and reports | Home, Insights, People, Organisation, My work, Admin | 23 |
| Alumni | Former employee: own payslips and documents | Home, My work, People | 5 |
| Auditor | Read-only access with the full audit trail | All nine | 135 |

\*Counted for the role on its own, without an employee record; personal pages add a few more.

> **Note — Performance Admin.** As shipped, the Performance Admin role contains only view access to the organisation
> structure, not the performance permissions its name suggests. If you use it, ask your administrator to add the
> performance permissions in **Admin › Setup › Roles** (a Tenant Super Admin can edit roles).

---

## 6.2 Employee

**Responsible for:** keeping your own information up to date, requesting leave and services, completing reviews and
learning, acknowledging policies.

**What you can open:** Home; My work (inbox, approvals and tasks addressed to you, notifications); your own leave
requests, balances, transactions and attendance records; regularisations (attendance corrections); your payslips,
tax declarations and compensation changes; your goals, appraisals, check-ins, feedback, one-on-ones, development needs
and plans; courses, learning paths, programmes, training sessions, certificates and skill assessments; My assets;
My requests, My grievances, My exit and the Knowledge base; the Assistant. With an employee record you also get My HR,
My day, My career, My learning, My compensation and your read-only **My profile**.

**What you can do:** request and cancel leave; ask for attendance corrections; view payslips; record your tax regime and investment declarations (payroll staff verify them);
raise HR requests and grievances; set and update goals; write self-reviews and give feedback; enrol in and complete
learning; acknowledge policies, announcements and assets; answer surveys; resign (start your own exit).

**What you cannot do:** see other employees' private data, approve requests, or change your own HR record directly —
changes to bank details, statutory numbers, address, emergency contacts and family go through an HR request with
approval.

**A typical day:** open **Home** → act on *Need attention* items → check in if your organisation uses web check-in
(*Your day*) → request leave or a service when needed → read announcements.

**First tasks:** secure your account (3.2 step 4); check **My profile**; acknowledge policies in **My HR**; add missing
documents (Home tells you, for example "PAN not on file").

**Dashboards to review:** Home and My day.

---

## 6.3 Manager

**Responsible for:** approving the team's leave and attendance corrections, coaching and reviewing performance,
supporting learning and development, onboarding newcomers and clearing leavers.

**What you can open (beyond Employee):** **My work › Team HR requests**; leave and attendance records, the leave
calendar, the exception centre, shifts, work schedules and holiday calendars for your team; onboarding plans for your
team; learning assignments; team positions; the directory and employee records within your scope; **People › My team**
(with team career and team learning); people analytics.

**What you can do:** approve or reject leave and attendance regularisations (in **My work › Approvals**); approve
overtime where your organisation records it; set team goals and complete manager reviews; give feedback and hold
one-on-ones (your private notes stay private); assign and approve learning; assess skills; agree development plans;
complete exit clearance items assigned to you.

**What you cannot do:** see people outside your scope, change salaries, run payroll, or see your team's talent-pool or
succession candidacy unless HR shares it.

**A typical day:** **Home** (team view) → **My work** decisions, most urgent first → **My team** for who is away today →
check-ins and one-on-ones as scheduled.

**First tasks:** confirm your team list in **My team**; review pending decisions; check that your team's goals exist.

**Dashboards to review:** Home ("Your team" lens), My team, Leave calendar, people analytics.

---

## 6.4 HR Manager

**Responsible for:** running HR operations — employee records, onboarding, leave, background checks, documents, HR
service desk, grievances, knowledge base, communication, surveys, letters, exits and alumni.

**What you can open:** All nine areas, including People › Lifecycle (directory, employees, imports, background
checks, onboarding, letters, exits, alumni), Time & leave, Services (HR queue, grievances, knowledge), Communication
(announcements, surveys, campaigns, feedback inbox), Insights (reports and analytics), users (view only), Change
Centre (view) and the audit trail (view).

**What you can do:** hire employees and record lifecycle and position changes; verify documents (not ones you uploaded
yourself); manage leave fully; approve attendance; run the service desk and grievances; publish knowledge and
announcements; manage and approve surveys and see results within the privacy rules; generate letters and handle exits;
propose and review compensation changes; build and export reports; use the HR assistant.

**What you cannot do:** run payroll or compliance returns, approve or execute compensation changes, see sensitive
personal data reserved for payroll, change configuration (view only) or manage users and roles.

**A typical day:** **Home** (People operations lens) → **My work** → **Services › HR queue** → onboarding and exits in
progress → leave calendar.

**First tasks:** check your organisation scope (which companies you look after); review open HR requests and
onboarding plans.

**Dashboards to review:** Home, Service analytics, people analytics, exit insights, survey results.

---

## 6.5 HR Executive

**Responsible for:** day-to-day HR administration under an HR manager.

**What you can open:** directory and employees, background checks (view), onboarding and onboarding templates,
leave (requests, balances, calendar, types, transactions, encashments), organisation and people set-up screens (view),
users (view), people analytics, My work.

**What you can do:** create and update employee records; act on onboarding tasks; upload documents; apply for leave on
behalf of employees; act on tasks assigned to you.

**What you cannot do:** approve leave, verify documents, handle exits, letters, payroll or configuration.

**A typical day:** **My work › Task inbox** → onboarding tasks → employee record updates.

---

## 6.6 Tenant HR Admin

**Responsible for:** configuring PeopleOS for your organisation: structure, people set-up data, custom fields, forms,
policies, workflows, notifications, modules, integrations and security — and operating any HR area when needed.

**What you can open:** almost every module (182 for the role alone), including **Admin › All modules**, the Change
Centre, Packs & Blueprints, users, settings, feature flags, security policy, single sign-on, API keys, webhooks and the
integration hub.

**What you can do:** configure and publish policies, workflows and configuration changes (and roll them back); invite
users and assign roles within the role-grant limits; manage every HR, payroll, compliance and talent area; set up
integrations.

**What you cannot do:** edit role permissions (view only), request the full data export (Tenant Super Admin or Account
Owner only), or give the Tenant Super Admin role.

**A typical day:** **Home** (Administration lens: governance items such as "Multi-factor sign-in is not required") →
**Admin › All modules** → Change Centre → users.

**First tasks:** follow [Chapter 7 — Administrator guide](07-administrator-guide.md).

**Dashboards to review:** Home (Administration), Change intelligence, Change history.

---

## 6.7 Tenant Super Admin and Account Owner

**Tenant Super Admin — responsible for:** full control of your organisation, including roles, security and the data
export. Usually one or two trusted people. Markedge invites your first Tenant Super Admin when it creates your
organisation.

**Account Owner — responsible for:** the first administration of an organisation created through self-service sign-up
(switched off today): companies and structure, people records and imports, users and role assignments, settings,
security policy, notification rules, the audit trail and the data export. Payroll, statutory, compensation and
sensitive personal data need a specialist role, given explicitly.

**Only these roles can:** request a **full data export** (**Admin › Platform › Data export**). Only a Tenant Super
Admin can give the Tenant Super Admin role and edit role permissions.

**First tasks:** secure your account with MFA; review **Admin › Platform › Security policy**; invite a second
administrator so you are never locked out; follow [Chapter 7](07-administrator-guide.md).

---

## 6.8 Payroll Admin

**Responsible for:** salary components and structures, monthly payroll runs, payslips, adjustments, tax declarations
and investment proofs, and the statutory side of payroll.

**What you can open:** **People › Pay** — payroll control room, payroll runs, payslips, adjustments, salary components
and structures, tax declarations, Payroll Auditor, compensation changes, cycles, budgets, pay ranges and analytics,
compliance control room, compliance rules, regulatory notices, rule verification and export layouts (view); attendance
and leave screens (view); directory and employees; change history; the Assistant.

**What you can do:** prepare, calculate, validate, approve and finalise payroll runs — subject to separation of duties
(the same person cannot perform every step); view and update sensitive employee data needed for pay (bank, statutory
identifiers); execute approved compensation changes; review anomalies with the Payroll Auditor.

**What you cannot do:** propose or approve compensation changes (only execute approved ones); change statutory rules
(provided and verified on the platform side); approve leave.

> **Important.** Payroll that relies on statutory rules not yet verified cannot be finalised while enforcement is on
> (the default). Check **People › Pay › Control room** before each month. See the Pay chapter.

**A typical day (payroll week):** control room → attendance and leave cut-off → calculate the run → review warnings and
Payroll Auditor → approvals → finalise → payslips.

---

## 6.9 Attendance Admin

**Responsible for:** attendance configuration and exceptions: devices, punch imports, shifts, work schedules, holiday
calendars, attendance records and regularisations.

**What you can open:** all of **People › Time & leave** (attendance screens fully, leave screens to view), the
exception centre, policies (view), directory and employees, people analytics.

**What you can do:** set up shifts, schedules, holiday calendars and devices; import punches; resolve exceptions;
approve regularisations and overtime.

**A typical day:** **Exception centre** → regularisations → device and import checks.

---

## 6.10 Asset Admin

**Responsible for:** the company asset register.

**What you can open:** **Organisation › Assets** (asset register, categories, models), directory and employees, My work.

**What you can do:** add assets, assign them to employees, transfer, return, send for repair, mark lost, retire and
dispose; recover assets during exits.

**A typical day:** **My work** (asset acknowledgements, exit recoveries) → asset register.

---

## 6.11 Performance Admin

**Intended for:** managing performance cycles. **As shipped** the role can open only the organisation structure,
people set-up screens (view), skills, Home and My work. Ask a Tenant Super Admin to add the performance permissions it
needs, or use a custom role.

---

## 6.12 Executive

**Responsible for:** understanding the workforce and making decisions; no transactional HR work.

**What you can open:** **Insights** — Workforce Command Centre, people analytics, reports, dashboards, Workforce
Intelligence; Organisation analytics and the workforce dashboard; compensation analytics; the org map; the Assistant.

**What you can do:** view figures and drill into them, open dashboards, ask the workforce assistant questions.

> **Note.** As shipped, the Executive role can open **Reports** but has no permission on the report datasets, so
> report lists and report tiles show "You cannot report on the … dataset." Ask your administrator to add the dataset
> permissions your executives need.

**What you cannot do:** see or change individual HR transactions, approve requests or change configuration.

**A typical day:** **Home** (Company lens) → **Insights › Workforce Command Centre** → dashboards.

---

## 6.13 Auditor

**Responsible for:** independent review. The role sees almost every area **read-only**, plus the full audit trail and
the AI interactions log.

**What you can open:** 135 modules, including Change history, Change intelligence, AI interactions, users, roles,
settings, feature flags, workflows and their runs, policies, payroll runs and compliance screens (view), and all tasks.

> **Note.** As shipped, the Auditor role also includes permission to manage webhooks. If your auditors must be strictly
> read-only, ask a Tenant Super Admin to remove it.

**A typical review:** **Insights › Audit › Change history** (filter by module, person or date) → open a change to see
before/after, reason and approval → Change intelligence for patterns.

---

## 6.14 Alumni

**For:** former employees after their exit is completed and an alumni profile is created. Their working account is
suspended at exit; the alumni access is separate.

**What they can open:** Home, My work, notifications, their own payslips and tax declarations; an alumni portal for
their documents and requests where set up.

---

## 6.15 When you don't have permission

| What you see | What it means | What to do |
|---|---|---|
| A menu area or module is missing | Your roles don't include it, a feature is switched off, or the page needs an employee record | Ask your administrator; mention the module name |
| A page titled "This page is not available to you" (error 403) | You opened a page or record that your role or organisation scope does not include. Nothing was shown or changed. | Go back to Home; ask your HR administrator if you think you should have access |
| A person or record is missing from a list | It is outside your organisation scope, or a filter/lens hides it | Clear filters; ask your administrator about your scope |
| A button you expect is missing | The action needs another permission, or the record's status doesn't allow it (for example a finalised run) | Check the record's status; ask your administrator |
| "Your network is not allowed to access this tenant." | Your organisation restricts access to approved networks | Connect from an approved network (for example the office or VPN) |

Administrators: change roles in **Admin › Setup › Users** (edit the user) and permissions in **Admin › Setup › Roles**.

---

## 6.16 Markedge (provider) roles — not part of your organisation

Markedge's own operators work in a separate platform console. They create organisations, manage subscriptions and
plans, maintain statutory rules and country packs, and operate the service. They do not appear in your users list.
If an operator needs to enter your organisation, the access requires a reason, lasts at most 60 minutes and is recorded
in your audit trail.
