# Employee Experience & HR Service Delivery (Phase 12)

For: engineers extending PeopleOS employee experience and the HR service desk.

**Status:**
- Phase 12 is implemented and validated (see `docs/PeopleOS-Phase-12-Report.md`), awaiting
  architectural review.
- Production readiness is NOT DECLARED.
- Statutory status is unchanged: 24 rule versions, 0 verified, 5 open notices.

Baseline `8448291` (Phase 11 approved).

**How Phase 12 was built:**
1. Discovery came first (§1, §2, Appendix A). It stopped at §69 because bank, statutory and person data
   had no safe domain entry point.
2. The owner decided on 2026-10-02 to add minimal People / Employment domain actions (§6).
3. The service desk was then built on top of the existing modules, not beside them.

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

| Area | What was built | Where |
|---|---|---|
| Canonical request / case | `tickets` extended (not replaced) as the one HR service request / case:<br>- pinned service version;<br>- encrypted, field-classified form data;<br>- transition-mapped lifecycle;<br>- team / agent / owner;<br>- confidentiality;<br>- business-hours SLA with pause;<br>- escalation level;<br>- domain-action hand-off;<br>- idempotency, correlation and operation ids;<br>- `lock_version`.<br>Supporting tables: `ticket_transitions` (status history), `ticket_access_grants` (explicit access) and `service_desk_reminder_logs` | `ServiceDesk\Models\Ticket`, `RequestLifecycle` |
| Service catalogue | `service_definitions` and `service_definition_versions`, with the Phase 11 configuration lifecycle (Draft → Pending approval → Scheduled / Active → Superseded, or Archived):<br>- prepared by one person, approved by another;<br>- frozen from submission;<br>- checksummed;<br>- requests pinned to the version.<br>Thirteen starter services are seeded as drafts | `ServiceCatalogue`, `ServiceDefinitionResource` |
| Forms | A service's form is a published Configuration Form version plus its domain action's fields. Field security (standard / sensitive / restricted, employee-visible) is set on the version. Unknown keys are dropped | `ServiceForms` |
| Intake | Checks who may raise what for whom, eligibility (lifecycle states, organisation scope, rule), the form and attachments. Idempotent per employee and key. Locked numbering | `ServiceRequests`, `Support\Numbering\NumberSequences` |
| Domain hand-off | Ten handlers call the owning domain's action (§5) and run once under the request lock, with separation of duties | `DomainActions`, `DomainActionExecutor`, `ServiceDesk\DomainActions\*` |
| Workflow | Approvals in the existing engine. `ServiceDeskWorkflowBridge` re-checks separation of duties | §8 |
| SLA | `SlaCalendar` contract with business-hours (holiday calendar, location timezone, service hours) and calendar-hours implementations. `ServiceSlaPolicy` (effective-dated, per-priority targets). `SlaClock` pauses, resumes and restarts the clock | §11 |
| Assignment | Team (role), agent and owner, in organisation scope. Claim, assign, reassign and unassign run under the lock. Least-loaded auto-assignment | `CaseAssignment` |
| Escalation and reminders | `peopleos:service-desk:process` (hourly, `withoutOverlapping()->onOneServer()`, tenant-bound job) | `ServiceDeskProcessor`, §12 |
| Access | One access model for policy, queries, search, comments, attachments, fields and the API | `CaseAccess`, §7 |
| Knowledge | Draft → Review → Approved → Published → Archived. Immutable versions with a hash. Readers and search see the published version only. Per-version acknowledgement with hash, source and IP, never deleted | `KnowledgeBase`, §15 |
| Grievances | Organisation scope for grievance managers. No blanket platform-admin access. Checked access grants. Signed, audited attachment route. Locked numbering | `Grievances`, `GrievanceAttachmentController` |
| Experience layer | My HR (Requests, Tasks, Approvals, Documents, Policies, Services, Notifications). Team HR requests. Unified tasks through domain sources and a Phase 13 survey hook. Employee 360 Requests tab. Timeline references | §17 |
| Analytics and bulk | Service analytics with small-group and complementary suppression. Bulk assign / move / escalate with operation ids | `ServiceDeskAnalytics`, `ServiceDeskBulk` |
| API | Read-only `/api/v1/service-desk/*` (scope `servicedesk.read`) | §13 |

## 4. What existing modules continue to own

Service Delivery owns:
- requests, cases and the catalogue;
- intake, routing, assignment and SLA;
- case communication and service status;
- the employee-facing request experience;
- service analytics.

Every other module stays the system of record:

| Domain | Owner, and how the desk reaches it |
|---|---|
| Employee, Person, bank, statutory, address, emergency contact, family | People / Employment, through the §6 actions |
| Reporting lines | Employment (`ChangeManagerAction::change`) |
| Leave | Leave (`Leaves::request`). The desk never touches requests, the ledger, balances or accruals |
| Attendance | Attendance (`Regularisations::request`). Never punches or records directly |
| Letters | Letters (`Letters::generate` / `approve` / `issue`). The desk retrieves no salary or employment data |
| Compensation | Compensation. The desk links a proposal made in Compensation and never writes `employee_salary_assignments` |
| Payroll | Payroll. A payroll query is a case; a correction is made with Payroll's own approved actions and may be referenced in the case |
| Documents | Documents. My Documents reads through `EmployeeDocumentPolicy` (own documents need `document.own`); case attachments use the same private disk |
| Workflow | Workflow (approvals) |
| Knowledge, grievances | Knowledge and Grievance, extended in place |
| Performance, learning, career, talent, succession | Their modules. They appear only through task sources and Needs Attention, never copied |

There is no RecruitmentEdge / RMS dependency.

## 5. Service-to-domain interaction

| Handler key | Owning domain entry point | Timing | Approval | Executor permission |
|---|---|---|---|---|
| `leave.request` | `Leaves::request` (idempotency key `sd-{correlation}`) | At submission | The Leave domain's own | — (requester `leave.apply`) |
| `attendance.regularisation` | `Regularisations::request` | At submission | The Attendance domain's own (reviewer ≠ subject) | — (requester `attendance.regularise`) |
| `letter.request` | `Letters::generate` (source = the request) | At submission | Letters' own (approver ≠ requester) | — |
| `compensation.proposal` | Links a `CompensationChange` the executor proposed in Compensation | Link | Compensation's own chain | `compensation.propose` |
| `profile.bank_account` | `ChangeBankAccountAction::add` | After approval / ready | Per service (workflow) | `employee.sensitive.update` |
| `profile.statutory_identity` | `ChangeStatutoryIdentityAction::handle` | After approval / ready | Per service | `employee.sensitive.update` |
| `profile.address` | `ChangeAddressAction::add` / `update` | After approval / ready | Per service | `employee.update` (add: `employee.create`) |
| `profile.emergency_contact` | `ChangeEmergencyContactAction` | After approval / ready | Per service | as above |
| `profile.family_member` | `ChangeFamilyMemberAction` | After approval / ready | Per service | as above |
| `employment.manager_change` | `ChangeManagerAction::change` | After approval / ready | Per service | `employee.position` |

**Approval rules:**
- A service whose domain approves itself (at-submission handlers) cannot add a second approval: the
  catalogue refuses the version.
- A service needing approval must name an active, published workflow.
- The catalogue refuses audiences a handler does not accept. For example, profile changes are never
  offered to managers for their reports.

**Status tracking:** the case follows its domain through `ServiceDeskDomainListener`, which reads only
the domain's own events:
- a leave or regularisation decision resolves the case;
- a letter approval moves the case back to work;
- an issued letter resolves it.

## 6. People Domain Change Actions

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

## 7. Security model

The chain is Auth → Tenant → Role → Permission → Organisation scope → Relationship scope → Field
security → Record. It is enforced by `CaseAccess`, which every layer uses:
- **Queries:** `visible()` restricts any Ticket query in SQL. It is used by the HR queue, My HR, search,
  Employee 360, bulk operations and the task sources. The ticket's `ScopedByEmployee` global scope
  applies organisation scope first.
- **Records:** `canView()` / `canWork()` back `TicketPolicy`.
- **Services:** every operation calls `canWork()`, and `RequestLifecycle` checks it again under the row
  lock (`asWorker`).
- **The API:** its own fixed filter (§13).

| Who | Sees | Works |
|---|---|---|
| Requester / employee | Own requests visible to the employee (drafts only if they raised them); employee-visible comments; own form values, sensitive ones masked | Comment, close & rate, reopen, cancel |
| Agent (`servicedesk.agent`) | Standard and sensitive cases of employees in their organisation scope, never their own case as its subject | Acknowledge, claim, assign, move, reply, resolve |
| Read-only (`servicedesk.view`, e.g. auditor) | As the agent | Nothing |
| Restricted cases | Only `servicedesk.confidential` holders who are the assignee, the owner (HR who opened it) or explicitly granted (`ticket_access_grants`, reasoned, revocable, audited). No "HR sees everything", no platform-admin bypass | As seen |
| Manager (`servicedesk.team`) | Status of standard cases of manager-visible services, for people they manage through the configured reporting relationships (`PerformanceRelationships`; never mentor / buddy / project). No comments, no form data | Raise manager-available services for a report |

**Field security:**
- The service version classifies each field; a domain action's stricter class wins.
- **Sensitive** values are masked unless the reader holds the action's view permission (for example
  `employee.sensitive.view`).
- **Restricted** values need explicit case access.
- HR-only fields are never shown to the employee.
- Form data is encrypted at rest and masked entirely in audit.
- Values for a domain change are purged once the change is executed, rejected or cancelled.

**Reads:** every read of a sensitive or restricted case is audited (`CONFIDENTIAL_CASE_VIEWED`).

**Grants:** the reason given for an access grant is masked in the audit field diff, because it can
describe the case.

## 8. Workflow model

- The catalogue version names the workflow (`approval_required`, `workflow_key`). Submission starts
  the existing `WorkflowEngine` with the request as subject, and the case waits in `awaiting_approval`
  (SLA paused).
- `ServiceDeskWorkflowBridge` (a `WorkflowCompleted` listener, like the Leave, Learning, Succession and
  Workforce bridges) decides under the request lock:
  - **approved by someone other than the requester or the employee:** a domain change becomes `ready`
    and a plain case returns to work;
  - **approved by the requester or the employee:** refused (`REQUEST_APPROVAL_SOD_REFUSED`), and HR
    may restart approval;
  - **rejected:** resolved as not approved, with values purged.
- The executor of a change is a third person: never the requester, the employee concerned or an
  approver (`DomainActionExecutor`).
- Workflow waiting is not service time: `awaiting_approval` pauses the SLA by default.
- Legacy categories with a `workflow_key` still start their workflow as automation, without gating the
  request.

## 9. Notification model

- Events: `ServiceDeskEvent` → `NotificationEventBridge::onServiceDesk` (tenant rules first, else
  in-app to the named users) and `WebhookEventBridge` (allow-listed names, scalar context only).
- The context is the number, service name and code, status, priority, employee id and, for
  escalations, due time and level. It never carries the free-text subject, description, form data,
  comments, resolutions or grievance details.
- Titles read like "Resolved: HR request TKT-2026-00001 (Bank account change)".
- New event names, mapped to the prompt's examples:

| Prompt example | Event name |
|---|---|
| ServiceRequestSubmitted | `servicedesk.ticket.created` |
| Assigned | `servicedesk.ticket.assigned` |
| Reassigned | `servicedesk.ticket.reassigned` |
| Escalated | `servicedesk.ticket.escalated` |
| Waiting for employee | `servicedesk.ticket.waiting_for_employee` |
| Resolved | `servicedesk.ticket.resolved` |
| Closed | `servicedesk.ticket.closed` |
| Cancelled | `servicedesk.ticket.cancelled` |
| KnowledgeArticlePublished | `kb.article.published` |
| PolicyAcknowledged | `kb.policy.acknowledged` |

  Further new events: `acknowledged`, `approval_required`, `ready_to_execute`, `sla_warning`, the
  `servicedesk.reminder.*` reminders, `kb.article.review_requested` and `kb.reminder.acknowledgement`.
- Profile changes emit the six `EmploymentEvent` names (§6) with references only.

## 10. Case lifecycle

| Status | Next statuses (`peopleos.servicedesk.transitions`) |
|---|---|
| draft | submitted, cancelled |
| submitted | acknowledged, assigned, in_progress, awaiting_approval, waiting_employee, resolved, cancelled |
| acknowledged | submitted, assigned, in_progress, awaiting_approval, waiting_employee, resolved, cancelled |
| assigned | acknowledged, submitted, in_progress, awaiting_approval, waiting_employee, waiting_hr, resolved, cancelled |
| in_progress | acknowledged, submitted, awaiting_approval, waiting_employee, waiting_hr, resolved, cancelled |
| awaiting_approval | in_progress, resolved, cancelled |
| waiting_employee | in_progress, waiting_hr, resolved, cancelled |
| waiting_hr | in_progress, waiting_employee, resolved, cancelled |
| resolved | closed, in_progress (reopen) |
| closed | in_progress (reopen) |
| cancelled | — |

`awaiting_approval` extends the suggested foundation so that approval time is distinct from service
time. Existing rows were renamed reversibly (new → submitted, open → in_progress, pending →
waiting_employee).

`RequestLifecycle::move` is the only status writer. Each move:
1. locks the row and checks the caller's `lock_version`;
2. refuses moves outside the map and same-state moves;
3. requires a reason for cancel and reopen, and a resolution to resolve;
4. stamps the lifecycle timestamps and pauses / resumes / restarts the SLA;
5. appends `ticket_transitions` (from, to, actor, via user / system / workflow / bulk, reason,
   operation id);
6. writes exactly one audit event and dispatches a value-free event.

Comments and the status history are append-only.

## 11. SLA model

**Policy.** `service_sla_policies` holds, effective-dated:
- per-priority first-response and resolution targets in hours;
- business or calendar mode;
- the warning share;
- escalation role, repeat interval and maximum level;
- optional pause statuses and service hours.

A policy referenced by an approved service version cannot be edited; a change is a new policy row.

**Calendar.** `SlaCalendar` is the smallest reusable contract (`addMinutes`, `minutesBetween`). It has
two implementations:
- **BusinessHoursCalendar** counts only:
  - configured service days and hours (`peopleos.servicedesk.business_hours` or the policy's);
  - outside the employee's attendance holidays (`HolidayResolver`): public and company holidays stop
    the clock, optional ones do not, and a half day keeps the first half;
  - in the work location's timezone (`AttendanceTimezone`).
- **CalendarHoursCalendar** counts 24 × 7. Legacy categories use it, keeping their old hours.

There is no second holiday calendar.

**Clock (`SlaClock`):**
- The due times are snapshotted at submission.
- Pause statuses stop the clock; the defaults are `waiting_employee` and `awaiting_approval`, and they
  are configurable per policy.
- Resuming moves the due times later by the paused service minutes (`sla_paused_minutes` records
  them).
- Reopening restarts the resolution clock.
- Done statuses stop it.

## 12. Escalation model

`peopleos:service-desk:process` runs hourly, `withoutOverlapping()->onOneServer()`, per tenant. With
`--queue` it dispatches `ProcessServiceDesk`, a `TenantAwareJob` with `BindTenantContext` that is unique
per tenant. The legacy `peopleos:servicedesk:tick` is an alias.

**Each run:**
- promotes due catalogue versions;
- warns once when the share of SLA used reaches the policy's threshold;
- escalates breached requests by one level per repeat interval up to the policy's maximum (legacy: one
  level), notifying the escalation role holders who can see the case and the assignee;
- reminds requests waiting for the employee (every N days, at most M times) and for HR (every H hours);
- auto-closes resolved requests after `auto_close_days`;
- escalates overdue grievances daily;
- sends a weekly policy-acknowledgement reminder (one tenant sweep per week).

**Idempotency:**
- Every notification first claims a `service_desk_reminder_logs` row, unique on tenant, kind, subject
  and a deterministic bucket (due time, level, waiting-period number, day, week).
- Escalation levels move under the request lock, and the guard re-checks "still open, not paused,
  level not reached".
- A reminder never triggers another.

**Bounded work:** reads go through the `(tenant, status, due_at)` index in id chunks, and each request
is moved in its own transaction.

## 13. API boundary

`/api/v1/service-desk/{services, requests, requests/{number}, requests/{number}/comments, knowledge, tasks}`
is read-only, uses scope `servicedesk.read`, and keeps the v1 page shape. API keys belong to a tenant,
and the integration has no employee context. It never receives:
- restricted cases (404, like another tenant's ids) or drafts;
- internal or restricted notes, or comments on sensitive cases;
- attachments (only `has_attachment`);
- free-text subjects, descriptions or resolutions;
- sensitive or HR-only form fields (only standard, employee-visible fields of standard cases);
- workflow internals (tasks are request number, type, status and due time).

Knowledge returns only published articles with no audience rule.

There are no write endpoints. Profile data has no API write path; a future one must expose explicit
action endpoints that call the §6 actions.

## 14. Audit boundary

The existing immutable, hash-chained audit is used; there is no new audit table.

**Actions:**
- REQUEST_CREATED, REQUEST_SUBMITTED, REQUEST_ASSIGNED, REQUEST_REASSIGNED, REQUEST_STATUS_CHANGED,
  REQUEST_ESCALATED, REQUEST_RESOLVED, REQUEST_CLOSED, REQUEST_CANCELLED;
- COMMENT_CREATED, ATTACHMENT_UPLOADED, ATTACHMENT_DOWNLOADED, CONFIDENTIAL_CASE_VIEWED;
- KNOWLEDGE_PUBLISHED, POLICY_ACKNOWLEDGED.

These are `AuditAction` cases. Metadata `event` names finer steps (REQUEST_CLAIMED, REQUEST_APPROVED,
REQUEST_APPROVAL_SOD_REFUSED, DOMAIN_ACTION_EXECUTED, CASE_ACCESS_GRANTED / REVOKED).

**What each record carries:**
- Ticket audits carry the correlation id. The ticket's subject, description, resolution and form data
  are masked.
- Profile changes carry the request number (`approval_reference`) and the request's operation id.
- Comment bodies are never audited.
- Bulk runs carry one operation id and a BULK_OPERATION summary.

`ticket_transitions` is the case's own status history (domain data shown on the case), not an audit
table.

## 15. Knowledge base and policy acknowledgement

**Lifecycle:**
- An author (`kb.manage`) writes a draft and submits it.
- A reviewer (`kb.review`, never the author) approves it or returns it with a note.
- The author publishes an approved article as a new `article_versions` row: title, summary, body,
  SHA-256 content hash, reviewer and approver. Versions are never updated or deleted.
- Readers, search and acknowledgement use `published_version`. A revision is a new draft while the
  published version keeps being served.

**Acknowledgement:**
- It is per version and records the version id, hash, source and IP address.
- It is locked: a concurrent repeat records nothing more.
- Version 1 never counts for version 2, and history is never changed or deleted.

**Audience:** the rule-engine audience (company / location / lifecycle conditions) decides who reads an
article. It is evaluated per article over the small published set, after an SQL search on the
published versions.

## 16. Confidential cases and grievances

**Employee-relations cases:**
- Disciplinary matters, harassment and investigations are catalogue services with confidentiality
  `restricted`, usually not visible to the employee and available to HR only.
- Access follows Tenant → `servicedesk.confidential` → classification → explicit scope (assignee,
  owner, grant) → record.
- Grants and revocations lock the case, are reasoned and are audited.

**Grievances** stay their own module, extended:
- grievance managers see non-confidential cases only in their organisation scope;
- confidential categories are open only to handler roles, the assignee and grantees, with no
  platform-admin bypass;
- only a handler with access grants access;
- note attachments use a signed, re-authorised, audited route, and employees get only notes marked
  visible to them;
- numbers come from the locked sequence.

## 17. Experience layer

- **My HR** (`MyHr`, Me → My HR) has seven tabs:
  - **Requests:** CaseAccess.
  - **Tasks:** `ExperienceTasks`, combining the domain sources `WorkflowTaskSource`,
    `ServiceDeskTaskSource`, `PolicyAcknowledgementTaskSource` and the Phase 13 `SurveyTaskProvider`
    hook (null now), plus Needs Attention.
  - **Approvals:** workflow tasks.
  - **Documents:** own documents through `EmployeeDocumentPolicy::isOwn` and signed downloads, plus
    letter status.
  - **Policies:** published versions and acknowledgement.
  - **Services:** the catalogue's `availableTo` with eligibility, and Request.
  - **Notifications.**

  The experience layer stores nothing.
- **Team HR requests** (`TeamRequests`) is the manager view (§7). It is status only, and lets a
  manager raise manager-available services.
- **Employee 360 → Requests** shows service history through CaseAccess: service and status, never
  subjects.
- **Timeline** gets references only ("HR request TKT-… raised / resolved / cancelled") for standard,
  employee-visible cases. Sensitive and restricted cases are omitted. Profile changes record their own
  `bank` / `statutory` / `personal` entries, which are hidden without `employee.sensitive.view`.
- **HR side:**
  - HR queue (`TicketResource`): filters for unassigned, mine, team, SLA risk, overdue, priority,
    service and dates; bulk actions.
  - Case detail: request, protected form data, assignment and SLA, domain change, conversation, status
    history, audit.
  - Catalogue with versions.
  - SLA policies.
  - Analytics.
  - Knowledge review actions.

## 18. Analytics, reporting privacy and bulk operations

**Analytics** (`ServiceDeskAnalytics`, `servicedesk.analytics`, organisation scope):
- **Metrics:** received, open, overdue backlog, resolved, average first response and resolution
  (excluding paused time), SLA compliance, breached.
- **Breakdowns:** by status, priority, service, team and department.
- **Suppression:**
  - A group with fewer than `servicedesk.analytics_min_group` distinct employees is suppressed.
  - If only one group would be suppressed, the next smallest is too (complementary).
  - Restricted cases appear only as a suppressed total.
  - The dimensions are fixed and combined only with the date range.

**Bulk operations** (`ServiceDeskBulk`, `servicedesk.bulk`):
- assign, move to a working status, and escalate;
- at most 500 cases, each authorised and moved in its own transaction;
- an idempotent skip when a case is already in the target state;
- one operation id and a BULK_OPERATION summary;
- a per-case result (done / skipped / refused + reason).

Resolve, close and cancel are never bulk.

## 19. Concurrency and locks

**Lock order** (deadlock-free with Phase 11 payroll / compensation):
1. request row;
2. employee row (submission, profile actions);
3. number sequence row;
4. the owning domain's own locks;
5. the audit chain, always last.

One request per transaction. `NumberSequences::ensure` creates a sequence row outside the
transaction, so two first-of-year inserts cannot deadlock on the gap lock.

**Read after the lock with a locking read.** Under MySQL REPEATABLE READ, a transaction's snapshot is
taken at its first plain read, which can come before it waits for a lock. A check made after the lock
(duplicate bank account, idempotency key) must therefore be a locking read, which always sees the
latest committed rows. The first MySQL run found exactly this:
- the bank-account duplicate check was a plain read and missed an account added by a concurrent
  request;
- it is now `lockForUpdate`, as is the idempotency re-check.

Proven on MySQL (`tests/MySql/ServiceDeskConcurrencyTest.php`, run twice, with lock-removal proofs; see
the Phase 12 report):
- idempotent submission;
- claim, double resolve, reassign vs resolve, assignment vs assignment;
- escalation vs pause, overlapping SLA runs;
- duplicate execution and execution retry;
- concurrent acknowledgement;
- revoked grant mid-flight;
- duplicate bank account;
- simultaneous statutory changes;
- concurrent catalogue approval.

## 20. Limits and deferred work

- **Surveys:** the hook only (Phase 13).
- **Payroll corrections:** cases only; the correction is made in Payroll.
- **Document requests:** cases (HR replies with a private attachment). Employees upload documents only
  where `document.upload` already allows.
- **Compensation:** link only (the proposal is created in Compensation).
- **Write API:** none for the service desk.
- **Business hours:** come from the employee's holiday calendar and location timezone; per-team service
  calendars are not modelled.
- **Knowledge audience:** evaluated per article in PHP over the published set (bounded, tenant-bound).
- **Manager visibility:** reuses `peopleos.performance.manager_relationship_types` (via
  `PerformanceRelationships`), as Phase 11 does.
- **Starter catalogue:** the starter SLA policy and draft services are seeded when a tenant is
  provisioned (and by `db:seed`). Tenants that existed before Phase 12 are not backfilled; HR creates
  their services in the catalogue.

## Appendix A. Discovery: the §69 stop and its resolution

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

