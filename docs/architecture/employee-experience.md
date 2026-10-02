# Employee Experience & HR Service Delivery (Phase 12)

For: engineers extending PeopleOS employee experience and the HR service desk.

**Status:** in progress. The §69 stop (§4) was resolved by the owner's decision of 2026-10-02: add
minimal People / Employment domain actions (§5). Those actions and the domain control fixes are built
(Phase 12.1); the service-desk sections below §5 are completed as they are built.

Baseline `8448291` (Phase 11 approved). Discovery covered the repository itself; the earlier Phase 12
note (`phase-12-employee-experience.md`, blueprint build of 2026-09-27) describes the first version of
these modules.

## 1. Current employee-experience architecture

| Area | What exists |
|---|---|
| Employee 360 | Filament `EmployeeResource` view with tabs (Timeline, Employment, Compensation, Documents, Requests, …). The record of the employee; access via `EmployeePolicy`, organisation scope (`AccessScopes`), `viewSensitive` for bank / statutory |
| Self-service ("Me") | **My Day:** check in / out, leave balances, latest payslip, Needs Attention, quick actions.<br>**Other pages:** My Team (direct reports), My Learning, My Career, My Compensation, Announcements feed.<br>**Own-record lists:** tickets, grievances, payslips, leave and others, shown through the same resources with a "Me" navigation group |
| Needs Attention | `Experience\Services\NeedsAttention`: per-person counts. It reads the domain tables directly (counts only): acknowledgements, learning, reviews, tickets, assets, workflow tasks, missing bank / PAN, tax declaration |
| Notifications | `Notifier::send(users, channels, subject, body, event, source)` (in-app / email / log); `NotificationEngine::fire` runs tenant rules and templates; the Filament database-notification bell is the inbox |
| Timeline | `Lifecycle\Services\Timeline::record(employee, category, title, occurredOn, description, source, metadata)`; the Timeline tab hides `compensation`, `bank` and `statutory` categories without `employee.sensitive.view` |
| Documents | `Documents::store()` writes to a private disk (`peopleos.documents.disk`) under `tenants/{tenant}/employees/{employee}/{ulid}`; `downloadUrl()` signs a temporary route; the controller re-authorises (`EmployeeDocumentPolicy::view`) and audits sensitive categories |

## 2. Existing request infrastructure (reused, not duplicated)

| Module | Records | Notes |
|---|---|---|
| Service desk (`App\Domain\ServiceDesk`) | `ticket_categories` (SLA hours, first-response hours, default assignee / assignee role, escalation role, optional workflow key), `tickets` (`TKT-YYYY-NNNNN`; `new → open → pending → resolved → closed`; satisfaction; linked article and workflow instance), `ticket_comments` (internal flag, one attachment) | `ServiceDesk` service: `open`, `assign`, `comment`, `waitOnEmployee`, `resolve`, `close`, `reopen`, `tick` (one SLA escalation; auto-close). Policy: agents (`servicedesk.view`, organisation scope), the assignee, the employee. Signed, re-authorised, audited attachment downloads |
| Grievances (`App\Domain\Grievance`) | `grievance_categories` (confidential flag, anonymous, handler roles, SLA days), `grievances` (explicit `access_user_ids`, anonymous complainant), `grievance_notes` (employee-visible flag) | `Grievances::canAccess` decides per case; every case view is audited |
| Knowledge base (`App\Domain\Knowledge`) | `articles` (rule-engine audience, acknowledgement flag), `article_versions` (snapshot per publish), `article_reads` (read / acknowledgement **per version**) | `KnowledgeBase`: publish, archive, visibleTo, recordRead, acknowledge, pendingAcknowledgements |
| Communication | `announcements`, `announcement_reads` | Feed, read, acknowledge |
| Configuration Forms | `forms`, `form_versions` (fields JSON, `VersionStatus` draft / published / retired, `rules()` validation), `form_submissions` | Reusable field definitions and validation; no sensitive-field classification |
| Workflow | `WorkflowEngine::start(workflow, subject, context, initiator)`, `completeTask(task, decision, note, actor)`, `WorkflowCompleted` (bridges for Leave, Learning, Succession, Workforce), `EscalationEngine` for task reminders / escalation | No service-desk bridge yet |

**Gaps found in the existing modules:**

| Area | Gap |
|---|---|
| Catalogue and forms | No versioned service catalogue; no structured request form |
| Lifecycle | No transition map (statuses set ad hoc) |
| Concurrency | No row locks anywhere in the service desk |
| Numbering | Ticket and grievance numbers come from an unlocked "last number + 1" read, so concurrent submissions can collide |
| SLA | Counted in calendar hours only, with no business hours and no pause |
| Domain changes | No hand-off to domain actions |
| Idempotency | None for requests |
| Grievances | `grievance.manage` holders see non-confidential cases in every organisation (no organisation scope) |
| Grievance attachments | A note's attachment link calls a method that does not exist, and there is no download route |
| Knowledge base | A published article's live body can be edited without republishing; there is no review or approval by a second person |

## 3. What Phase 12 adds

- **Canonical request model:** extend `tickets` as the one HR service request / case, with:
  - service and pinned service version;
  - structured form data (encrypted, field-classified);
  - the full controlled lifecycle (transition map);
  - team (role) and agent assignment with organisation scope;
  - confidentiality classification with explicit grants;
  - business-hours SLA with pause;
  - escalation levels;
  - idempotency key;
  - domain-action hand-off;
  - locked numbering and `lock_version`.
- **Service catalogue:** effective-dated, versioned service definitions (Draft → Pending Approval →
  Approved → Scheduled → Active → Superseded → Archived, as in Phase 11). They reuse Configuration Form
  versions for fields and `ticket_categories` as the category level.
- **Workflow bridge:** approvals stay in the existing engine; a service-desk `WorkflowCompleted` bridge
  re-checks separation of duties.
- **SLA calendar contract:** reuses the attendance holiday calendars (`HolidayResolver`) and location
  timezone (`AttendanceTimezone`), with configured service hours. No second holiday calendar.
- **Knowledge base:** a review / approval lifecycle, immutable published versions, and acknowledgement
  with version hash and source.
- **Experience layer:** My HR hub (requests, tasks, approvals, documents, policies, services,
  notifications), manager Team requests, HR queue, analytics, read API `/api/v1/service-desk`,
  reminders and escalation processor, audit, events.

## 4. Service-to-domain interaction (the stop condition)

| Service | Domain entry point | Status |
|---|---|---|
| Leave application / query | `Leaves::request(...)` (idempotency key, balance, overlap), approvals via `LeaveRequestPolicy` (not own, in scope) | Safe |
| Attendance correction | `Regularisations::request(...)` | Safe. **12.1:** the reviewer is recorded and can never review their own |
| Salary / employment / experience letters | `Letters::generate(template, employee, extra, requester, source)`, `approve`, `issue` | Safe. **12.1:** `approve` refuses approver = requester (a template without approval is still issued on the requester's behalf, as before) |
| Compensation query | `CompensationOutput::on()` with `CompensationAccess::level()` | Safe (read) |
| Salary change request | `CompensationChanges::propose(employee, data, actor)` (proposer ≠ reviewer ≠ approver ≠ executor; never own) | Safe when proposed by an HR proposer, not by the employee |
| Policy acknowledgement | `KnowledgeBase::acknowledge(article, employee)` | Safe (caller must check audience) |
| Manager change | `ChangeManagerAction::handle(employee, manager, type, from, reason)` | **12.1:** `ChangeManagerAction::change` requires `employee.position` and both people in scope |
| Document request / upload | `Documents::store(...)`, `review(...)` | **12.1:** `review` refuses verifier = uploader and records the verifier. Employees open their own documents with `document.own` |
| Payroll query / payslip | `ViewPayslip` page (audited), API `payroll/payslips/{number}` | No payslip download action. A payroll query is a case only |
| **Bank account change** | Direct `EmployeeBankAccount` save in the Employee 360 relation manager | **12.1:** `ChangeBankAccountAction` (§5) |
| **PAN / UAN update** | Direct `EmployeeStatutoryDetail` save in the Employee 360 action | **12.1:** `ChangeStatutoryIdentityAction` / `ChangeStatutoryApplicabilityAction` (§5) |
| **Address / emergency contact / family** | Direct saves in Employee 360 relation managers (`UpdatePersonAction` covers core person fields only, without approval) | **12.1:** `ChangeAddressAction` / `ChangeEmergencyContactAction` / `ChangeFamilyMemberAction` (§5) |

Phase 12 §11 requires "Service Request → Approval → **Existing** People Domain Action". For bank, PAN /
UAN, address, emergency contact and family data no such action existed, and Service Delivery must not
write those tables itself. This was the §69 stop condition.

**Owner's decision (2026-10-02):**
- Add the minimal People / Employment domain actions (§5); "manual HR fulfilment only" is rejected.
- Fix the control gaps in their own domains:
  - letter approver ≠ requester;
  - regularisation reviewer recorded, never one's own;
  - document verifier ≠ uploader;
  - manager change needs `employee.position` and both people in scope;
  - employees can open their own documents (`document.own`).

Discovery confirmed the expected owners, so the stop did not recur:
- Employment owns `employee_bank_accounts` and `employee_statutory_details`.
- People owns `person_addresses`, `person_emergency_contacts` and `person_family_members` (they belong
  to the lifetime Person).
- The Employee 360 screens were the only writers. No seeder, import, console command or API wrote them.

## 5. People Domain Change Actions

### Ownership

People / Employment stay the system of record. Service Desk owns the request, case, intake, routing,
SLA, assignment, communication and service status, and never the profile data. There are no
`service_desk_*` copies of profile tables.

### Actions

| Action | Owner | Records | Permission | Event |
|---|---|---|---|---|
| `Employment\Actions\ChangeBankAccountAction` (`add`, `update`, `remove`) | Employment | `employee_bank_accounts` | `employee.sensitive.update` | `employee.bank_account_changed` |
| `Employment\Actions\ChangeStatutoryIdentityAction` (`handle`) | Employment | `employee_statutory_details`: PAN, Aadhaar reference, UAN, PF number, ESIC number | `employee.sensitive.update` | `employee.statutory_identity_changed` |
| `Employment\Actions\ChangeStatutoryApplicabilityAction` (`handle`) | Employment | `employee_statutory_details`: PF / ESIC / PT applicability, PT state, tax regime | `employee.sensitive.update` | `employee.statutory_applicability_changed` |
| `People\Actions\ChangeAddressAction` (`add`, `update`, `remove`) | People | `person_addresses` | add `employee.create`, change `employee.update`, remove `employee.delete` | `employee.address_changed` |
| `People\Actions\ChangeEmergencyContactAction` (same) | People | `person_emergency_contacts` | as above | `employee.emergency_contact_changed` |
| `People\Actions\ChangeFamilyMemberAction` (same) | People | `person_family_members` | as above | `employee.family_member_changed` |

There is no `UpdateEmployeeProfile` action:
- Identifiers and applicability are separate actions because they are separate concerns (who someone
  is for the state vs which schemes apply).
- The three person-record actions share `ChangePersonRecordAction`, which names the record, its rules
  and its event.
- `ChangeManagerAction::change(...)` is the checked entry point for a manager change. Its `handle()`
  stays for the composite actions (hire, transfer, promote, rehire) that already authorised the whole
  operation.

Every action does the same things (`Employment\Concerns\ChangesProfileData`):
1. Authorises the actor itself (`ProfileChangeGuard`): an active user, the permission, and the
   employee within the actor's organisation scope. The employee must also be reachable in the current
   tenant (tenant global scope).
2. Validates with one rule set for every caller (the former Employee 360 form rules). A refusal is a
   `ProfileChangeRefused`.
3. Locks the employee row, so one profile change runs at a time per employee.
4. Saves through the model, so the normal audit runs (values masked as before).
5. Records a timeline entry (category `bank`, `statutory` or `personal`) and an `EmploymentEvent`.

Specific checks:
- The bank action refuses an account number already on file for the employee.
- The person actions refuse a record that belongs to another person.

### Approval boundary

Discovery found no existing rule that a profile edit by HR needs approval: Employee 360 edits by a
holder of the permission were immediate. Phase 12 keeps that rule and invents none.

Approval applies to employee-initiated changes made through the Service Desk:
- The service definition names the workflow.
- The existing workflow engine runs the approval (no second approval engine).
- The Service Desk enforces requester ≠ approver ≠ executor before it calls the action.
- The executor is an HR user who holds the action's permission and has the employee in scope. The
  action re-checks that permission and scope itself.

### Employee 360 usage

The Employee 360 screens call these actions and no longer save the models:
- **Bank Accounts, Addresses, Emergency Contacts and Family tabs:** create / edit / delete.
- **"Edit statutory details" header action:** runs identity and applicability in one transaction.
- **"Change manager" action:** calls `ChangeManagerAction::change`.

A refusal shows a notification and halts the form (`Filament\Support\ProfileChangeActions`). The
screens and permissions look the same as before; nothing about Employee 360 was redesigned.

### Service Desk usage

The catalogue entry for a profile change names the action key, for example `bank_account.add`.

The request keeps:
- the requested action, status, requester and approval history (the workflow instance);
- execution status;
- the resulting record reference and the operation id.

**Values while pending:** the proposed values are held encrypted on the request only while it is
pending:
- They are readable only by holders of the action's permission.
- They never appear in comments, notifications, webhooks, the API or analytics.
- They are purged when the request is executed, rejected or cancelled. The case then keeps no copy of
  the data.

**Execution:**
- The executor runs the action under a lock on the request row.
- The origin is `ChangeOrigin('service_desk', <request number>, <operation id>)`.
- `AuditRecorder::withinOperation` stamps the profile audit rows with the same operation id.

### API usage

Phase 12 adds no write API for profile data. `/api/v1/service-desk` is read-only and never returns
these values or a pending change's values. A future write API must expose explicit action endpoints
that call these same actions, never a generic "update profile" endpoint.

### Audit

The normal model audit is the record of the change:
- Values are masked: account number, PAN, Aadhaar reference, UAN and the other configured sensitive
  attributes.
- The reason is recorded.
- The request number goes in `approval_reference`, and the operation id is set when the change runs
  from a request.

To trace a change in either direction:
- "Which request caused this change?" → the audit row's `approval_reference` and operation id.
- "Which action changed this record?" → the request's execution reference, the timeline metadata and
  the event context.

The Service Desk stores the references, not a copy of the audit.

### Events

`EmploymentEvent` gained six reserved names (table above). The context carries:
- `operation` (added / updated / removed);
- `source` (`employee_360`, `service_desk`);
- the request reference and the operation id.

It never carries values. The webhook bridge forwards scalar context only, and the notification bridge
renders templates from the same context.

### Idempotency

A request is executed once:
- The executor locks the request row and checks its execution status in the same transaction as the
  action.
- A retry (repeated click, job retry, concurrent executor) finds it executed and returns the stored
  reference without a second mutation.
- A failure rolls back both the action and the status, so a retry runs it exactly once.

The bank action also refuses an account already on file, so the same account cannot be added twice by
two different requests.

### Security

The chain is Auth → Tenant → Role → Permission → Organisation Scope → Relationship Scope → Field
Security → Record, enforced inside each action whatever the caller:
- **Self-service** is limited to requesting a change to one's own record. The Service Desk derives the
  employee from the requester, so employee A can never request a change to employee B's record.
- **Sensitive data:** the Timeline tab treats `bank`, `statutory` and `personal` entries as sensitive
  (hidden without `employee.sensitive.view`).

## 6. Remaining design topics

The remaining topics depend on the decision above. They will be completed with the implementation:
- security model, workflow model, notification model;
- case lifecycle, SLA model, escalation model;
- API boundary, audit boundary.

The non-negotiable boundaries already established:
- **Ownership:** Service Desk owns requests, cases, catalogue, intake, routing, assignment, SLA, case
  communication and service analytics. Every other module stays the system of record.
- **Security chain:** Auth → Tenant → Role → Permission → Organisation Scope → Relationship Scope →
  Field Security → Record, enforced in services, policies, queries and the API (not only in Filament).
- **No direct domain writes:** no direct write to another module's tables, and no salary mutation
  outside Compensation.
- **Statutory:** unchanged (24 rule versions, 0 verified, 5 open notices); production readiness NOT
  DECLARED.
