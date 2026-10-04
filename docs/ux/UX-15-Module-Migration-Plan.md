# UX.15 Module Migration Plan

**Status:** written before implementation (UX.15 closure, step C.2), 4 October 2026. **Baseline:** `fcd9932` on `feature/oct_1_phase_1`.
**Companion documents:** [PeopleOS-UX-15-Experience-Elevation-Report.md](PeopleOS-UX-15-Experience-Elevation-Report.md) (P1-01 … P1-05), [UX-15-Research-and-Audit.md](UX-15-Research-and-Audit.md).

UX.15 left about 150 module pages that still read as standard Filament. This plan explains how they move into the PeopleOS experience by **composition**: shared page classes, one context read model and central component defaults. It does not redesign each page by hand. It also records the closure plan for the other four P1 items.

---

## 1. What exists today

**Inventory method.** Generated from the registered admin panel (`Filament::getPanel('admin')->getResources()`) inside the demo tenant as the HR admin, plus source metrics per resource (columns, filters, form fields, actions). Raw data: `docs/ux/ux15/closure/inventory.json`.

| Page kind | Count | Rendered by today |
|---|---|---|
| Resources | 147 | Filament resource classes |
| List pages | 147 (88 `ListRecords`, 59 `ManageRecords` with modal create/edit) | Filament table under the UX.15 area bar |
| Create pages | 48 | Filament form page |
| Edit pages | 48 | Filament form page |
| View pages | 28 | Filament infolist; relation managers as tabs |
| Relation managers | 80 classes, 117 registrations | Filament tables inside pages |
| Custom pages | 53 | 11 UX.15 workspaces; 42 earlier custom pages |

| Model trait | Resources |
|---|---|
| Has a `status` column | 126 |
| Linked to an employee (`employee_id`) | 39 |
| Effective-dated (`effective_from`) | 37 |

**What a module page looks like** (`docs/ux/ux15/closure/before/`, captured from the running showcase):

| Page type | Today |
|---|---|
| List | A "Leave Requests › List" breadcrumb, the title with a Create button, a search box and filter icon at the right of a plain table, "Per page 10" with an "all" option |
| Form | "Details" and "Effective dates" sections; Create / Create & create another / Cancel; nothing says what the change affects |
| Detail | A header with many action buttons, an infolist, then tabs |
| Phone | The table scrolls sideways; row actions sit off-screen |

There is no global Filament configuration (`configureUsing`) anywhere.

**Earlier custom pages** (My team, My day, the Payroll control room, the Talent dashboard and others) are partly styled but still use KPI tiles built from `x-filament::section` and ad-hoc Tailwind. UX.15 rejected KPI-tile dashboards.

---

## 2. Families

Families are assigned by rule (`closure/inventory.php` → `inventory-table.md`). The rule order is: Employee 360 → analytics → history → request and approval → sensitive → relationship → configuration → detail (has a view page) → search list (two or more filters, or employee-linked) → simple list.

| Family | Name | Resources | What the people using it need |
|---|---|---|---|
| A | Simple list | 9 | Find, add and change a few records |
| B | Search and filter list | 16 | Find the right people or records quickly, act on them |
| C | Detail workspace | 6 | Understand one record and act on it |
| D | Create and edit forms | 48 create + 48 edit pages, plus modal forms on 59 manage pages | Make a change knowing what it affects, then confirm |
| E | Request and approval workflow | 11 | See what is waiting, decide or route it to the Approval Center |
| F | Configuration | 62 | Know what is in effect now and later, change it safely, see history |
| G | Sensitive HR and pay | 27 | The same as B, C, D and E, with stricter disclosure |
| H | Analytics and reports | 2 resources + analytics custom pages | Read a story; keep specialised layouts |
| I | Timeline and history | 8 | What happened, when, by whom |
| J | Relationship management | 6 | Who is linked to what, from when |

---

## 3. The PeopleOS module experience layer

### 3.1 Reused, not duplicated

These UX.15 components are reused as they are:

- `x-pos.person` (peek → drawer → workspace), `PeekHost`, `DrawerHost`;
- `x-pos.state` (empty, filtered, loading, error, denied);
- `x-pos.change` (Before → After), `x-pos.status`, `x-pos.figure`, `x-pos.section`;
- the governed tokens in `theme.css`.

### 3.2 Added centrally

| Piece | Where | What it does |
|---|---|---|
| PeopleOS base pages | `app/Filament/Support/Pages/People{List,Manage}Records`, `People{Create,Edit,View}Record` | Every resource page extends one of these. They provide the context header, lens, breadcrumbs, empty states, form review and detail context. A page that overrides a method keeps its own behaviour |
| `ModuleContext` read model | `app/Domain/Experience/Services/ModuleContext.php` | Counts what the viewer can see from the **page's own table query**, so resource scopes and `modifyQueryUsing` constraints apply. It returns visible total, status breakdown with business phrases, effective-dated split (in effect / starting later / ended), changed this week, people involved and the viewer's pending decisions where the model is an Approval Center source. Counts only, never record data |
| Context strip | Render hook `RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE` → `filament/shell/module-context.blade.php` | "What matters" chips (status lens) and facts above the table |
| Status lens | Base list page, `table()` → `modifyQueryUsing` | Narrowing only: it adds a `where` to the existing query scopes (Filament accumulates them), so it can never widen what a viewer sees |
| Central component defaults | `PeopleOsUi::register()` in the panel provider | Table defaults (empty state wording from the model label, search placeholder, page sizes 10 / 25 / 50 / 100 without "all", 25 by default); employee name columns render as person chips (hover peek); modal create, edit and view open as side drawers (slide-overs) |
| Form review | `filament/shell/form-review.blade.php` (Alpine, client-side) | "Your changes": live Before → After of the fields the person has changed in this form, then the save button. It reads only what is already in the form, so it never shows anything new |
| Theme | `theme.css` part D | Context header, strip, search-first toolbar, quieter row actions, PeopleOS chips for badges, drawers, form sections, stacked table rows on phones |

### 3.3 Interaction model per family

| Family | Traditional pattern removed | PeopleOS pattern |
|---|---|---|
| Lists (A, B, E, G, I, J) | Title → Create → filter row → table | Context sentence ("214 leave requests you can see · 4 waiting for a decision · 3 changed this week") → lens chips → search first → results with person chips → contextual row actions; meaningful empty and filtered states |
| E Request and approval | Approve and Reject buttons on every row of a module table | The same, plus "4 wait for your decision → Approval Center", where decisions come with their context |
| F Configuration | Configuration table | Context of what is in effect now, what starts later and what has ended; last change; edits in a drawer; history through the audit trail where the viewer may read it |
| D Forms | 30 fields → Save | Context (what this is and what changes affect) → grouped sections (existing) → effective date (existing where the model is effective-dated) → "Your changes" review → confirm ("Save changes" / "Create department") |
| C, G Detail | Header → many buttons → infolist → tabs | Breadcrumb "Area › Record" (no "View"); context sentence (status, in effect from, last changed); existing tabs. The Employee 360 is not imposed on other entities |
| Modal forms (manage pages) | Centred modal | Side drawer (slide-over), consistent with PeopleOS drawers |
| Phone | Sideways-scrolling table | Stacked rows with the first column as the title and actions within reach |

### 3.4 Security rules for the layer

1. Counts come from the page's table query, never from a fresh model query, so a constrained table can never be out-counted.
2. The lens only narrows.
3. Person chips peek through `PersonPeek`, which enforces visibility itself; the chip carries an id the table already shows.
4. Form review reads only the values already in the form.
5. Sensitive families show counts and statuses, never amounts.
6. No base page changes authorisation: `canAccess`, `canViewAny` and the policies stay with the resource.

---

## 4. Migration order

1. **Layer and representative pages:**
   - Leave requests (E, employee and manager);
   - Employees register (C);
   - Tickets (E);
   - Leave types (F);
   - Departments, create and edit (F, D);
   - Payroll runs (G);
   - Audit events (I).

   Validate, then:
2. **Lists, all 147,** through the base list pages. Run the regression suite.
3. **Forms:** 48 create and 48 edit pages through the base form pages; modal forms through central defaults.
4. **Details:** 28 view pages through the base view page.
5. **Custom pages with KPI tiles:** move to figure strips (`x-pos.figure`) on the most used (My team, My day, Payroll control room, Talent dashboard, Learning dashboard, Workforce dashboard, Compliance control room, Team requests). Other analytics and designer pages keep their specialised layouts.
6. **Validate everything:** visual (light, dark; desktop, tablet, phone), axe, browser matrix including WebKit, and the scale database.

The order follows the brief's priority: employee and manager workflows first, then HR, sensitive, configuration and lower-frequency administration. Because the layer is shared, every family moves in step 2; the representative pages are where each pattern is checked by eye first.

## 5. Exceptions and specialised layouts kept

| Page | Why it keeps its own layout |
|---|---|
| Employee 360 (`ViewEmployee`) | Already the person workspace; simplified separately (P1-04) |
| Organisation designer, Position hierarchy, Calibration board, Leave calendar | Canvas, tree, board and calendar interactions |
| Analytics pages (people, performance, talent, compensation, service desk, workforce), Dashboard viewer, Survey results, Report view | Charts and explorers; keep the UX.15 story language where already applied |
| Payroll auditor, Compliance control room, Platform readiness, Security policy | Control rooms with domain-specific checks (they move to figure strips only) |
| Assistant page, Configuration finder | Conversational and search surfaces |

## 6. Security-sensitive pages

These get the layer with stricter rules: counts only, no amounts in context, and existing field-level and per-case visibility untouched.

| Group | Resources |
|---|---|
| Payroll | Runs, payslips, adjustments, components, statutory profiles, tax declarations |
| Compensation | Changes, cycles, ranges, budgets, structures |
| Compliance and statutory | 15 resources |
| Cases and checks | Grievances and categories, BGV cases, anonymous employee feedback |
| Audit | Audit events |

A regression test asserts that the context never counts more than the table can show for a constrained viewer.

---

## 7. Closure plan for the other P1 items

| P1 | Finding in discovery | Plan |
|---|---|---|
| P1-02 Approval performance | Per pending leave item: `LeaveRequestPolicy::approve` runs `isOwn` (one `exists` query) and `AccessScopes::allows` (one `exists` over the scope sub-select for scoped users). These are about 400 of the 594 queries for the 10k manager. `AccessScopes` is a container singleton, so a long-lived memo is unsafe | Add `AuthorizationContext`, request-scoped and active only inside `run()`. `AccessScopes::primeEmployeeIds()` decides reachability for all candidate employees with one set query (the same `employeeKeys()` constraint). `isOwn` reads the viewer's own employee ids once per pass. Outside a pass, every check runs exactly as today. Policies still decide every item. Prove equivalence with an oracle that re-implements the pre-change queries, across manager, unauthorised, cross-organisation, direct, indirect, unrelated, sensitive, tenant-boundary, suspended and changed-permission cases. Measure before and after on the 10,785-employee database |
| P1-03 Safari | `sudo` needs a password, so system packages cannot be installed. Playwright's WebKit (WPE MiniBrowser, the engine behind Safari) runs once its libraries are unpacked into the scratchpad without root (`apt-get download` + `dpkg -x`) and it starts from a clean environment (the VS Code snap leaked GIO paths) | Validate the critical journeys in WebKit (desktop and iPhone viewport). Label every result as **WebKit engine, not Apple Safari**. Real Safari runs only on macOS and iOS, an external constraint documented with the remaining risk |
| P1-04 Employee 360 | 18 panels, 17 headings, 3 panels above the fold; 4.1 screens on desktop, 8 on a phone | Progressive disclosure. **Core** (always): header ribbon, "Now and next", intelligence, people snapshot, the three latest changes. **Contextual** views behind the section navigation: Journey, Work, Growth, Rewards, Documents. **Deep:** Records (all details, custom fields, policies) one level down. Same server-side rendering and gates, so nothing new is exposed and every hash link (`#journey`, `#records`) still works |
| P1-05 Demo scope | No `user_access_scopes` rows; every demo employee is in DEMO-TECH; DEMO-SVC is empty; teams Platform and Mobile exist but are unused | Through the existing `AccessScopes::assign()`: the manager → team Platform (his line is placed in it at hire); the HR business partner and the payroll lead → company DEMO-TECH; the executive and HR admin stay tenant-wide (documented); employees are self-service by permission. Add two Demo Services employees so the organisation boundary is real. No demo bypass and no rule change. Tests use the seeders' own data |
| Cleanup | `hcm_ux_scale_showcase` and `hcm_ux_ladder_showcase` are disposable (`*_showcase` guard, synthetic) | Reuse the 10k database for the approval measurements, then drop both once their evidence is in `docs/ux/ux15/`. Port 8090 stays as it is |

---

## Appendix — page inventory

Family letters as in §2. Page types: list, create, edit, view; "(modal create/edit)" marks `ManageRecords`. RM = relation managers. Sensitivity is High for sensitive families, Medium where employee-linked. Criticality is High for request, approval, detail and sensitive families. Mobile importance is High for self-service and manager-daily screens.

| Resource | Group | Family | Pages | RM | Traits | Sensitivity | Criticality | Mobile |
|---|---|---|---|---|---|---|---|---|
| EngagementCampaign | Engagement | A | list+create+edit | 2 | status | Low | Low | Low |
| Survey | Engagement | A | list+create+edit | 2 | status | Low | Low | Low |
| TrainingSession | Learning | A | list+create+edit | 1 | status | Low | Low | Low |
| CalibrationSession | Performance | A | list (modal create/edit) | 0 | status | Low | Low | Low |
| CriticalPosition | Talent | A | list (modal create/edit) | 0 | status,effective-dated | Low | Low | Low |
| SuccessionPlan | Talent | A | list (modal create/edit) | 0 | status | Low | Low | Low |
| TalentPool | Talent | A | list (modal create/edit) | 0 | status | Low | Low | Low |
| TalentReview | Talent | A | list (modal create/edit) | 0 | status | Low | Low | Low |
| WorkforcePlan | Workforce | A | list (modal create/edit) | 0 | status,effective-dated | Low | Low | Low |
| AttendanceRecord | Attendance | B | list | 0 | status,person | Medium | Medium | High |
| Announcement | Communication | B | list+create+edit | 1 | status | Low | Medium | Medium |
| Course | Learning | B | list+create+edit | 3 | status,effective-dated | Low | Medium | Medium |
| DevelopmentPlan | Learning | B | list (modal create/edit) | 0 | status,person | Medium | Medium | Medium |
| LearningCertificate | Learning | B | list | 0 | status,person | Medium | Medium | Medium |
| LearningInstructor | Learning | B | list (modal create/edit) | 0 | status,person | Medium | Medium | Medium |
| SkillAssessment | Learning | B | list (modal create/edit) | 0 | status,person | Medium | Medium | Medium |
| LeaveBalance | Leave | B | list | 0 | person | Medium | Medium | High |
| OnboardingPlan | People | B | list | 0 | status,person | Medium | Medium | Medium |
| DevelopmentNeed | Performance | B | list (modal create/edit) | 0 | status,person | Medium | Medium | Medium |
| FeedbackEntry | Performance | B | list (modal create/edit) | 0 | status,person | Medium | Medium | High |
| Goal | Performance | B | list+create+edit | 1 | status,person | Medium | Medium | High |
| ImprovementPlan | Performance | B | list (modal create/edit) | 0 | status,person | Medium | Medium | Medium |
| OneOnOne | Performance | B | list (modal create/edit) | 0 | status,person | Medium | Medium | High |
| PerformanceCheckIn | Performance | B | list (modal create/edit) | 0 | status,person | Medium | Medium | High |
| ReadinessAssessment | Talent | B | list (modal create/edit) | 0 | status,person,effective-dated | Medium | Medium | Medium |
| AlumniProfile | Alumni | C | list+view | 2 | person | Medium | High | Low |
| Asset | Assets | C | list+create+view+edit | 4 | status | Low | High | Low |
| Article | Knowledge | C | list+create+view+edit | 1 | status,effective-dated | Low | High | Low |
| Employee | People | C | list+create+view+edit | 8 | — | Low | High | High |
| Appraisal | Performance | C | list+view | 2 | status,person | Medium | High | Low |
| Position | Workforce | C | list+view | 4 | status | Low | High | Low |
| AttendanceRegularisation | Attendance | E | list | 0 | status,person | Medium | High | High |
| CompensationChange | Compensation | E | list (modal create/edit) | 0 | status,person,effective-dated | Medium | High | Medium |
| ConfigurationChange | Configuration | E | list+view | 0 | status,effective-dated | Low | High | Medium |
| ExitCase | Exit | E | list+view | 4 | status,person | Medium | High | Medium |
| Grievance | Grievances | E | list+view | 2 | status,person | Medium | High | Medium |
| LeaveEncashment | Leave | E | list | 0 | status,person | Medium | High | Medium |
| LeaveRequest | Leave | E | list | 0 | status,person | Medium | High | High |
| Letter | Letters | E | list+view | 1 | status,person | Medium | High | High |
| PayrollAdjustment | Payroll | E | list (modal create/edit) | 0 | status,person | Medium | High | Medium |
| Ticket | Service Desk | E | list+view | 3 | status,person | Medium | High | High |
| WorkflowInstance | Workflows | E | list+view | 2 | status | Low | High | Medium |
| Role | Access | F | list+create+edit | 1 | — | Low | Low | Low |
| User | Access | F | list+create+edit | 1 | status | Low | Low | Low |
| AssetCategory | Assets | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| AssetModel | Assets | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| AttendanceDevice | Attendance | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| HolidayCalendar | Attendance | F | list+create+edit | 3 | status | Low | Low | Low |
| Shift | Attendance | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| WorkSchedule | Attendance | F | list+create+edit | 2 | status,effective-dated | Low | Low | Low |
| NotificationRule | Communication | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| NotificationTemplate | Communication | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| CustomField | Customisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| Form | Customisation | F | list+create+edit | 3 | status | Low | Low | Low |
| TenantFeature | Customisation | F | list (modal create/edit) | 0 | — | Low | Low | Low |
| TenantSetting | Customisation | F | list (modal create/edit) | 0 | — | Low | Low | Low |
| Audience | Engagement | F | list+create+edit | 1 | status | Low | Low | Low |
| ExchangeRate | Enterprise | F | list (modal create/edit) | 0 | — | Low | Low | Low |
| SsoConnection | Enterprise | F | list+create+edit | 1 | status | Low | Low | Low |
| WebhookEndpoint | Enterprise | F | list+create+edit | 2 | status | Low | Low | Low |
| ApiKey | Integrations | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| IntegrationSystem | Integrations | F | list+create+edit | 4 | status | Low | Low | Low |
| LearningPath | Learning | F | list+create+edit | 2 | status | Low | Low | Low |
| LearningProgram | Learning | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| LearningProvider | Learning | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| Skill | Learning | F | list (modal create/edit) | 0 | status,effective-dated | Low | Low | Low |
| SkillScale | Learning | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| LeaveType | Leave | F | list (modal create/edit) | 0 | status,effective-dated | Low | Low | Low |
| LetterTemplate | Letters | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| BusinessUnit | Organisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| Company | Organisation | F | list+create+view+edit | 1 | status,effective-dated | Low | Low | Low |
| CostCentre | Organisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| Department | Organisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| Division | Organisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| Establishment | Organisation | F | list (modal create/edit) | 1 | status,effective-dated | Low | Low | Low |
| LegalEntity | Organisation | F | list (modal create/edit) | 1 | status,effective-dated | Low | Low | Low |
| Location | Organisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| ProfitCentre | Organisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| Team | Organisation | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| OnboardingTemplate | People | F | list+create+edit | 2 | status | Low | Low | Low |
| Designation | People Setup | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| DocumentType | People Setup | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| EmployeeCategory | People Setup | F | list+create+edit | 1 | status | Low | Low | Low |
| EmploymentType | People Setup | F | list+create+edit | 1 | status | Low | Low | Low |
| Grade | People Setup | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| JobFamily | People Setup | F | list+create+edit | 1 | status | Low | Low | Low |
| Level | People Setup | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| WorkMode | People Setup | F | list+create+edit | 1 | status | Low | Low | Low |
| Competency | Performance | F | list (modal create/edit) | 0 | status,effective-dated | Low | Low | Low |
| Kra | Performance | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| PerformanceCycle | Performance | F | list+create+edit | 2 | status | Low | Low | Low |
| PerformanceTemplate | Performance | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| RatingScale | Performance | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| Tenant | Platform | F | list+create+edit | 1 | status | Low | Low | Low |
| Policy | Policies | F | list+create+edit | 3 | status | Low | Low | Low |
| ServiceDefinition | Service Desk | F | list+create+edit | 2 | status | Low | Low | Low |
| ServiceSlaPolicy | Service Desk | F | list+create+edit | 1 | status,effective-dated | Low | Low | Low |
| TicketCategory | Service Desk | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| CareerPath | Talent | F | list+create+edit | 2 | status,effective-dated | Low | Low | Low |
| CareerTrack | Talent | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| RoleRequirement | Talent | F | list (modal create/edit) | 0 | effective-dated | Low | Low | Low |
| Workflow | Workflows | F | list+create+edit | 3 | status | Low | Low | Low |
| WorkforceBudget | Workforce | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| WorkforceScenario | Workforce | F | list (modal create/edit) | 0 | status | Low | Low | Low |
| CompensationBudget | Compensation | G | list (modal create/edit) | 0 | status | High | High | Low |
| CompensationCycle | Compensation | G | list (modal create/edit)+view | 1 | status,effective-dated | High | High | Low |
| CompensationRange | Compensation | G | list (modal create/edit) | 0 | status,effective-dated | High | High | Low |
| SalaryStructure | Compensation | G | list+create+edit | 2 | status | High | High | Low |
| ComplianceRule | Compliance | G | list | 0 | status,effective-dated | High | High | Low |
| EpfReturn | Compliance | G | list+view | 0 | status | High | High | Low |
| EsiReturn | Compliance | G | list+view | 0 | status | High | High | Low |
| EstablishmentStatutoryProfile | Compliance | G | list (modal create/edit) | 1 | effective-dated | High | High | Low |
| ExportLayout | Compliance | G | list | 0 | status | High | High | Low |
| LwfReturn | Compliance | G | list+view | 0 | status | High | High | Low |
| ParallelRun | Compliance | G | list+view | 1 | status | High | High | Low |
| ProfessionalTaxReturn | Compliance | G | list+view | 0 | status | High | High | Low |
| RuleNotice | Compliance | G | list | 0 | status | High | High | Low |
| RuleVerification | Compliance | G | list | 0 | — | High | High | Low |
| StatutoryRegistration | Compliance | G | list (modal create/edit) | 1 | status,effective-dated | High | High | Low |
| TdsCertificate | Compliance | G | list | 0 | status,person | High | High | Low |
| TdsInvestment | Compliance | G | list (modal create/edit) | 0 | person | High | High | Low |
| TdsProfile | Compliance | G | list (modal create/edit) | 1 | — | High | High | Low |
| TdsReturn | Compliance | G | list+view | 0 | status | High | High | Low |
| EmployeeFeedback | Engagement | G | list | 0 | status,person | High | High | Low |
| GrievanceCategory | Grievances | G | list (modal create/edit) | 0 | status | High | High | Low |
| PayrollRun | Payroll | G | list+view | 2 | status | High | High | Low |
| Payslip | Payroll | G | list+view | 0 | person | High | High | High |
| SalaryComponent | Payroll | G | list+create+edit | 1 | status,effective-dated | High | High | Low |
| StatutoryProfile | Payroll | G | list (modal create/edit) | 0 | — | High | High | Low |
| TaxDeclaration | Payroll | G | list (modal create/edit) | 0 | status,person | High | High | High |
| BgvCase | People | G | list+view | 2 | status,person | High | High | Low |
| Dashboard | Analytics | H | list+create+edit | 1 | status | Low | Low | Low |
| Report | Analytics | H | list+create+view+edit | 3 | status | Low | Low | Low |
| AttendancePunch | Attendance | I | list | 0 | person | Medium | Medium | Low |
| PunchImport | Attendance | I | list+view | 1 | status | Low | Medium | Low |
| AiInteraction | Audit | I | list | 0 | — | Low | Medium | Low |
| AuditEvent | Audit | I | list+view | 0 | — | High | Medium | Low |
| NotificationDelivery | Communication | I | list | 0 | status | Low | Medium | Low |
| InboundEvent | Integrations | I | list | 0 | status | Low | Medium | Low |
| LeaveTransaction | Leave | I | list | 0 | person | Medium | Medium | Low |
| EmployeeImport | People | I | list+view | 1 | status | Low | Medium | Low |
| LearningAssignment | Learning | J | list (modal create/edit) | 0 | status,person,effective-dated | Medium | Medium | Medium |
| LearningEnrolment | Learning | J | list+view | 1 | status,person | Medium | Medium | High |
| EstablishmentAssignment | Organisation | J | list (modal create/edit) | 0 | person,effective-dated | Medium | Medium | Medium |
| CareerProfile | Talent | J | list (modal create/edit) | 0 | person | Medium | Medium | Medium |
| Successor | Talent | J | list (modal create/edit) | 0 | status,person | Medium | Medium | Medium |
| TalentDevelopmentAction | Talent | J | list (modal create/edit) | 0 | person | Medium | Medium | Medium |
