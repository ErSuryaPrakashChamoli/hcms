# Engagement, Surveys & Communication (Phase 13)

For: engineers extending PeopleOS engagement surveys, feedback and employee communication.

**Status:**
- Phase 13 is implemented and validated (see `docs/PeopleOS-Phase-13-Report.md`), awaiting
  architectural review.
- Discovery found no §69 stop condition.

**Baseline:** `47e1b97` (Phase 12 approved). Production readiness is NOT DECLARED; statutory status is
unchanged (24 rules, 0 verified, 5 open notices).

## 1. Discovery

| Area | What exists | Phase 13 decision |
|---|---|---|
| Surveys | None. Phase 12 left an empty hook: `Experience\Contracts\SurveyTaskProvider` (bound to `NullSurveyTaskProvider`) in the My HR task list | Build the survey domain (`App\Domain\Engagement`) and bind the hook to it. No conflict |
| Exit interviews | `Exit\Services\ExitInterviews`: identified exit-interview records owned by Exit | Unchanged. An "exit feedback" survey type is a survey; exit interviews stay in Exit |
| Performance feedback | `Performance\Services\Feedback`: 360 / continuous feedback with an anonymous option | Unchanged, and not reused (a performance record, not engagement) |
| Communication | `App\Domain\Communication`:<br>- `announcements` (rule-engine audience, pinned, acknowledgement flag, Knowledge Base `article_id` link, draft → published → archived);<br>- `announcement_reads` (read / acknowledgement);<br>- `Communications` service (publish, feed, read, acknowledge, stats).<br>Publishing notifies through `ServiceDeskEvent('communication.published')` | Extend in place (§9) |
| Gaps in Communication | - no review or approval (the author publishes);<br>- content stays editable after publication;<br>- the audience is evaluated in PHP over all employees, and there is no snapshot;<br>- a future-dated announcement is never notified (no processor);<br>- no delivery tracking or preferences;<br>- acknowledgement is not locked | Closed by §9 |
| Notifications | `Notifier::send(users, channels, subject, body, event, source)` delivers synchronously through channel drivers:<br>- **in-app:** Filament database notification;<br>- **email:** Laravel Mail;<br>- **log;**<br>- SMS / WhatsApp / push are configured but map to the log driver.<br>`notification_deliveries` records queued / sent / failed / read. `NotificationEngine::fire` runs tenant rules. No preferences, no rate limiting | Communication delivers **through `Notifier`** (no second engine). Only in-app and email are real channels. Preferences apply to optional communication only |
| Knowledge Base | Phase 12: versions, review, per-version acknowledgement | Canonical for policy content. Announcements link articles (`article_id`) and never copy policy bodies |
| Service Desk | Phase 12 catalogue and requests | Communications may link a service. Identified feedback can be referred to the desk through `ServiceRequests::openGeneric` (the existing safe entry) |
| Workflow | `WorkflowEngine` with `WorkflowCompleted` bridges | Approval: maker-checker (preparer ≠ approver), as Phase 11 / 12 configuration does, or a configured workflow through `EngagementWorkflowBridge` with separation of duties re-checked. No second approval engine |
| Organisation and security | - `AccessScopes::employeeKeys()` is SQL (effective-dated positions, establishment assignments, relationship scope);<br>- `PerformanceRelationships` resolves reporting lines;<br>- per-module analytics thresholds (`analytics_min_group`, 5);<br>- Phase 12 complementary suppression | Audiences are built in SQL with the same primitives, within the preparer's scope. Privacy reuses the threshold principle, made stricter (§5) |
| Audit | Hash-chained, immutable; `Auditable` auto-audits model changes with the authenticated actor, IP and request id | `AuditRecorder::record(..., anonymous: true)` records an event with no actor, IP, user agent or request id. Anonymous response rows are never `Auditable` |

## 2. Ownership

```text
Employee / People Core (identity, organisation, employment) ── read ──► Engagement / Communication
                                                                          ├── Surveys → Responses
                                                                          ├── Feedback
                                                                          └── Campaigns → Announcements → Recipients (delivery via Notifier)
```

**Engagement reads employee context and owns none of it.** It never writes:
- Performance (no ratings, goals or PIPs);
- Learning (no assignments);
- Career, Talent or Succession;
- Compensation, Payroll or statutory data;
- the Knowledge Base, Service Desk or notifications infrastructure.

**No inference.** There is:
- no employee engagement score;
- no sentiment inference;
- no flight-risk or attrition prediction;
- no AI.

## 3. Anonymity architecture (the central requirement)

### Response modes (pinned on the survey version)

| Mode | What PeopleOS stores | Who can ever see identity |
|---|---|---|
| **Identified** | `survey_responses.employee_id` set | HR with `engagement.responses`, in scope. Never managers through analytics |
| **Confidential** | No employee on the response. The mapping lives in `engagement_identities` (subject response ↔ employee) | Only `engagement.confidential_identity` holders, one response at a time, with a mandatory reason, audited (`CONFIDENTIAL_RESPONSE_IDENTIFIED`). Never in analytics |
| **Anonymous** | **No link at all** between who took part and what was answered | Nobody: there is no application path, and no administrator bypass |

### Separating participation from content

```text
survey_participations  (identity side)          survey_responses / survey_answers  (content side)
  employee_id, status, date-only fields   ✗ no shared key ✗   random v4 UUID ids, group key, answers
```

**Participation** (`survey_participations`) records:
- who was eligible (the audience snapshot at opening);
- status: invited → opened → submitted, or expired;
- dates only (`invited_on`, `opened_on`, `submitted_on`), with no time of day and no `updated_at`.

It is what enforces one response per employee and drives reminders.

**Response content** (`survey_responses`, `survey_answers`):
- carries no employee, user, participation id, token, IP or session;
- has no timestamps at all (no `created_at` / `updated_at` / submitted date for anonymous and
  confidential responses);
- uses random **v4 UUID** primary keys, so neither ids nor storage order follow submission order
  (ordered or time-based UUIDs and auto-increment ids would);
- carries a **group key**: the survey's single pinned breakdown dimension, assigned to the participation
  at the audience snapshot.

**Group keys:** a key is assigned only to groups with at least the threshold of **eligible** employees.
Smaller groups are merged into `other`; if `other` is itself below the threshold, the key is empty
(overall only). So even a raw response row can only be attributed to a group of at least `k` people.

### Submission

One transaction:
1. Lock the employee's participation row and refuse if already submitted (one response, enforced in the
   database).
2. Mark it submitted with today's date.
3. Insert the response and answers with no link back.
4. Record `SURVEY_RESPONSE_SUBMITTED` in the audit chain with `anonymous: true`:
   - no actor, IP, user agent or request id;
   - entity = the survey version, not the response;
   - no answers.
5. Dispatch a value-free event without the employee.

**Restrictions in anonymous mode:**
- No drafts or partial saves, because a stored draft would link identity to content.
- No corrections: an anonymous response is final.
- No confirmation messages that echo answers.
- Retries are idempotent: a resubmission finds the participation already submitted and changes nothing.

### Threat model

**Defended:**
- employees, including colleagues of the respondent;
- managers;
- HR and analysts using the application (Filament, analytics, exports, API);
- integrations using API keys;
- tenant administrators using the application.

| Attack | Defence |
|---|---|
| Join a response to the employee | No column links them, and response ids are random |
| Timing correlation (participation time vs response time) | Responses carry no time; participation carries dates only |
| Order correlation (row order vs participation order) | Random UUID keys. No ordered list of responses is ever returned |
| Audit correlation | Anonymous audit events carry no actor or response id. `Auditable` is not on response, answer or participation rows |
| Small-group attribution (one respondent from a department) | Eligible-group merging at the snapshot, respondent threshold and complementary suppression in analytics (§5) |
| Filter-combination differencing | No ad-hoc filters. A survey version pins one breakdown dimension; results are overall plus that dimension only (§5) |
| Free-text authorship | Text is shown only above the text threshold, shuffled, without ids, dates or sub-threshold groups, and only to `engagement.comments` holders. No automated authorship or sentiment analysis |
| Participation screens | Employees see only their own status. Managers see no participation lists. HR sees participation counts with suppression |
| API probing (ids, pagination, filters, tokens) | The API returns no response rows, no participation of others and no per-group data below the threshold. There are no survey tokens: access is the authenticated employee plus their own participation row |

**Outside the guarantee** (documented residual risk): people with direct database, binary log or server
log access. They are not users of the application. The design still gives them no linking column, no
timestamps and no ordered keys.

## 4. Survey model and lifecycle

**One canonical model** for every survey type (engagement, pulse, feedback, culture, onboarding
feedback, exit feedback, event feedback, custom):
- `surveys`: identity: code, name, category, type;
- `survey_versions`: everything that can change, frozen per version;
- `survey_questions`: pinned to a version.

**Lifecycle (version):**

```text
Draft → In review → Approved → Scheduled → Open → Closed → Archived
```

| Step | Who / what | Recorded |
|---|---|---|
| Prepare | `engagement.manage` | |
| Approve | A different person with `engagement.approve`, or a configured workflow | `SURVEY_APPROVED` |
| Publish | Schedules the version for its open date | `SURVEY_PUBLISHED` |
| Open | Takes the audience snapshot and sends invitations | `SURVEY_OPENED` |
| Close | Expires unsubmitted participations | `SURVEY_CLOSED` |
| Archive | | `SURVEY_ARCHIVED` |

**What a version pins:**
- questions: order, type, required flag, options or scale, hidden metadata;
- anonymity mode and response rule;
- audience criteria and the breakdown dimension;
- result visibility;
- reminder policy;
- dates.

**Immutability:**
- Content freezes on submission for review. The `SurveyVersion` / `SurveyQuestion` model guards refuse
  any content change outside draft.
- After approval only the status and lifecycle dates move. The approval stores a SHA-256 checksum of
  the content, including hidden metadata. Publication verifies it, fixes an "opens on publication"
  date once, and re-stamps the checksum.
- Nothing is deleted.
- A correction is a new version, and responses stay pinned to the version they answered.
- Only one version of a survey is open at a time.

**Response rules:**
- one per version (the default, and the only rule allowed for anonymous and confidential surveys);
- multiple (identified only, idempotency-keyed);
- one per period (identified only, unique per employee and period).

## 5. Privacy model for results (engagement analytics)

**Threshold.** `k = peopleos.engagement.analytics_min_group` (default 5, the PeopleOS small-group
principle). The free-text threshold is `text_min_group` (default 10).

**Visibility rules:**
- The overall result needs at least `k` respondents. Otherwise nothing is shown, not even per question.
- Groups come only from the version's **one pinned dimension** (none, company, location, department,
  business unit, grade or line manager). There are no ad-hoc filters and no combinations.
- A group is shown only with at least `k` respondents.
- **Complementary suppression:** the suppressed groups together must hold 0 or at least `k`
  respondents. If not, the smallest visible groups are suppressed as well until they do. So
  "total − visible groups" never reveals a suppressed group. Example: all = 6, A = 5, B = 1 → both
  suppressed, only the total is shown.
- Per question, the respondent count for that question (optional questions) must also be at least `k`.
- **Free text** is shown only:
  - at the overall level, never per group (a group's comments plus the overall list would identify
    the remainder's comments by difference);
  - when the version has at least `text_min_group` respondents and the question at least
    `text_min_group` comments;
  - to `engagement.comments` holders with the HR view;
  - shuffled, without ids, dates or groups.
- **Anonymous and confidential results are released only after the version closes.** Comparing two
  live views as people submit would otherwise isolate individual answers. Identified results are live.
- **Trend:** across versions of the same survey, overall only, and each version must meet `k`.

**Who sees results:**
- **HR / analysts** (`engagement.analytics`): only surveys whose eligible population is within their
  organisation scope.
- **Managers:** only when the version lets managers see results and its dimension is the line manager,
  and then only their own team's group, above the threshold.
- **Employees:** the overall result when the version allows it (overall only).
- **Integrations** (`engagement.read`): the overall result only, under the same rules, never groups or
  comments.

**Output:** descriptive only: participation (eligible, invited, opened, submitted, response rate),
distributions, averages where meaningful (rating, likert, number). No engagement index and no
individual scores.

## 6. Audience model

**Definition.** `audiences` are reusable, criteria-based definitions:
- companies, locations, departments, business units, divisions, teams;
- designations, grades, levels, employment types, positions;
- establishments;
- lifecycle states;
- reports of given managers;
- explicitly listed employees.

**Resolution:** `AudienceQuery` resolves them **in SQL**:
- against effective-dated `employee_positions` and establishment assignments, and current reporting
  relationships, on the resolution date;
- constrained to the preparer's organisation scope (`AccessScopes::employeeKeys`).

**Pinning.** A survey version or announcement pins a **copy** of the criteria when it is submitted, so
later edits to the audience definition change nothing already approved.

**Snapshot at launch** (not dynamic):
- opening a survey writes `survey_participations`;
- publishing an announcement writes `communication_recipients`.

Each snapshot runs as a bulk operation with one operation id and records `AUDIENCE_USED`. "Who was
eligible when it opened?" is answered by the snapshot, never by today's organisation structure.

## 7. Responses, participation and corrections

| | Identified | Confidential | Anonymous |
|---|---|---|---|
| `survey_responses.employee_id` | set | null | null |
| `submitted_on` / `period_key` / `idempotency_key` on the response | set | null | null |
| Authorship | the response row | `engagement_identities` only | nowhere |
| Response rules | once, multiple, per period | once | once |
| Duplicate guard | participation row lock + unique `(version, employee, period_key, active_key)` + unique `(version, employee, idempotency_key)` | participation row lock | participation row lock |
| Correction | yes, while open: the old response is `superseded` (`active_key` null) and the new one points to it | no (final) | no (final) |
| Shown back to the employee | their own answers | "submitted" | "submitted" |
| Audit | `SURVEY_RESPONSE_SUBMITTED` with actor and response id | anonymous mode (no actor, no response id) | anonymous mode |

**Submission:** `SurveyResponses::submit`, one transaction:
1. Shared-lock the version row and require it to be open (so a closing run waits, or wins first).
2. Lock the employee's own participation (`FOR UPDATE`) and enforce the rule; a repeat is
   `already_submitted`, a no-op.
3. Write participation (date only), response and answers (random v4 ids).
4. Write the confidential identity row, if confidential.
5. Audit.

The caller gets `{status, response_id}` and the id only for identified surveys. No drafts are stored,
for any mode: a stored draft would be a person-to-answer link.

**Answers:**
- one row per answer; one row per selected option for multiple choice;
- likert and rating also carry the numeric value;
- free text is encrypted at rest (`encrypted` cast);
- answers are immutable and never deleted.

**Participation statuses:** invited → opened (the employee opened the form; date only) → submitted,
or expired at closing.

**Reminders** (`SurveyNotices`) go out through the Notifier and are claimed per participation and
bucket in `engagement_reminder_logs`:
- **Identified surveys:** only people who have not submitted.
- **Anonymous and confidential surveys:** everyone invited ("if you have already responded, thank
  you"), so delivery logs reveal nothing about who took part.
- **Buckets:** the invitation, "after N days", and one closing reminder, up to the policy maximum (≤ 3).

## 8. Feedback

`EmployeeFeedback`: identified, confidential or anonymous feedback, with the same privacy architecture
as survey content:
- random v4 id;
- `submitted_on` as a date only, no timestamps;
- encrypted body;
- no employee on confidential or anonymous items; confidential authorship only in
  `engagement_identities`.

**Audit:** identified feedback carries its actor. Confidential and anonymous feedback is audited in
anonymous mode (no actor, no entity id).

**Handling** (`engagement.feedback`):
- **The inbox:** identified items of employees in the handler's scope, plus the confidential and
  anonymous items, which have no employee.
- **In review / closed:** with a note, audited.
- **Routing:** feedback is not a case system, so it is routed to the owning domain's existing safe
  entry point:
  - identified feedback → `ServiceRequests::openGeneric` (an HR request owned by the service desk);
  - confidential feedback → the same, after `ConfidentialIdentities::reveal` (reason, audited);
  - anonymous feedback → `Grievances::raise(..., anonymous: true)`, only for categories that accept
    anonymous cases.
- **Employees** see their own identified feedback only; confidential and anonymous items cannot be
  listed back.

## 9. Communication architecture

**Communication vs notification.** A notification is transactional (a leave approved, a case updated, a
task due). A communication is an intentional organisational message (holiday notice, benefits window,
town hall, policy awareness). Communication **reuses the notification infrastructure**:
- `Notifier::send` → channel drivers → `notification_deliveries` / the Filament inbox;
- no second queue, engine or channel;
- preferences apply to optional communication only, never to transactional notifications.

**Announcements** (the existing model, extended in place):
- **Fields:** title, type, priority, body (Markdown), Knowledge Base article link, campaign, structured
  audience, pinned, acknowledgement required, publish / expiry dates, one private attachment, version /
  supersedes.
- **Lifecycle:**

  ```text
  Draft → In review → Approved → Scheduled → Published → Archived
                                    ↘ Cancelled (from review / approved / scheduled)
  ```

  - Prepared with `communication.manage`.
  - Approved by a different person with `communication.approve` (separation of duties), or decided by
    the configured workflow through `EngagementWorkflowBridge`, which re-checks separation of duties.
  - The content (title, type, priority, body, audience, acknowledgement flag, article, attachment) is
    frozen from submission by a model guard.
  - A correction is a new version (`newVersion`). When it is published, the old version is archived.

**Audience snapshot at publication** (`Communications::release`):
- `AudienceQuery` in SQL, inside the preparer's scope, writes `communication_recipients` in chunks.
- It runs as one bulk operation (operation id), with `AUDIENCE_USED` and `ANNOUNCEMENT_PUBLISHED`.
- The feed reads the snapshot (`EXISTS` on recipients). Rows from before Phase 13 (no snapshot) keep
  their original rule audience.
- Empty criteria mean everyone employed **inside the preparer's scope**, never the whole tenant by
  default.

**Delivery** (`CommunicationDelivery`, queued `DeliverCommunication`, retried by
`peopleos:communication:process`). Each recipient is claimed under a row lock in its own transaction;
the employee's preference is read under a shared lock in the same transaction. Outcomes:
- **sent:** at least one channel accepted it. In-app means the inbox entry was written; email means
  the mailer accepted it.
- **failed:** every channel failed; retried up to 3 attempts by later scheduled runs, never in a tight
  loop.
- **skipped:** no active account (`no_account`), or every channel switched off for an optional
  category (`preference`).

**"Delivered" is never claimed:** no channel reports provider delivery. Read and acknowledged come from
`announcement_reads`. The notification carries the title and a pointer ("read it in My HR →
Communications"), never the body, so delivery logs hold no message content.

**Preferences** (`communication_preferences`):
- **Scope:** per employee and optional category (announcement, circular, newsletter, survey
  invitations and reminders), with in-app and email.
- **Mandatory types are never switched off:** policy publications and instructions
  (`peopleos.communication.mandatory_types`).
- **Who changes them:** the employee only, under a row lock, audited
  (`COMMUNICATION_PREFERENCE_CHANGED`).
- **Concurrency:** a change that commits before a delivery claims the recipient is always honoured
  (MySQL race 10).

**Attachments:**
- one per announcement, added in draft;
- stored on the private document disk (`tenants/{t}/communication/{id}/…`), with the type and size
  limits of documents;
- SHA-256 fingerprinted;
- downloaded through a 15-minute signed route that re-checks the audience (or preparer / approver) and
  the fingerprint, and audits `ATTACHMENT_DOWNLOADED`.

**Boundaries:**
- Policy content stays in the Knowledge Base (announcements link `article_id`, and acknowledgement of a
  policy stays the Knowledge Base's per-version acknowledgement).
- Requests stay in the service desk (campaigns reference services).

## 10. Campaigns

`engagement_campaigns` + `campaign_items` group surveys, announcements, Knowledge Base articles and HR
services **by reference**, with an owner, an audience (for reference), dates and its own approval
(draft → review → approved → scheduled → active → completed / cancelled).

**Launch** (`Campaigns::launch`, by the scheduler on the start date, or at once when scheduled on or
after it):
- runs as one audited bulk operation under a campaign row lock;
- publishes each **approved** item through its own module (`Surveys::publish`,
  `Communications::publish`);
- leaves articles and services as references;
- reports items that are not approved as skipped (`CAMPAIGN_PUBLISHED` metadata `partial`,
  `skipped_items`, BULK_OPERATION counts), never forcing them.

Launching twice is a no-op. Cancelling stops the campaign; published items stay with their owning
modules.

**Metrics** are counts from the owning modules: recipients sent / failed / skipped / pending,
acknowledgements, survey eligible / submitted.

## 11. Employee experience

**My HR** (`App\Filament\Pages\MyHr`) gains four tabs; there is no new portal:

| Tab | Content | Service |
|---|---|---|
| Surveys | Open surveys to take (modal built from employee-visible question fields only), own history (status), results link where the version shares them | `SurveyResponses`, `EngagementAnalytics` |
| Feedback | Give feedback (mode, category, text); own identified items | `Feedback` |
| Communications | The feed (snapshot), Markdown body, policy link, attachment, mark read, acknowledge | `Communications` |
| Preferences | Optional categories × in-app / email; mandatory types listed as always delivered | `CommunicationPreferences` |

**Other surfaces:**
- **My tasks:** the Phase 12 `SurveyTaskProvider` hook is bound to `SurveyTasks` (open, unanswered
  surveys); `CommunicationTaskSource` lists pending acknowledgements.
- **Employee 360 is unchanged:** no engagement score, no responses, no participation.

**Admin (Engagement group):**
- Surveys: versions, questions, lifecycle actions and results;
- Campaigns: items, lifecycle and metrics;
- Audiences: criteria and a count preview, never a list;
- Feedback inbox.

The Announcements resource gains the approval actions, a structured audience and delivery counts.

## 12. API

Both APIs are read-only and use API-key scopes. Other tenants' codes and ids are 404.

`/api/v1/engagement/*` (`engagement.read`):

| Endpoint | Returns |
|---|---|
| `GET surveys`, `GET surveys/{code}` | Definitions; the current version's employee-visible questions (no administrator, scoring or analysis metadata) |
| `GET surveys/{code}/participation` | Eligible, submitted and response rate per opened version (counts only) |
| `GET surveys/{code}/versions/{n}/results` | Overall results under the application's rules (k, released only after closing for anonymous / confidential); text questions excluded |
| `GET my-surveys?employee=CODE` | The employee's surveys; participation status for identified surveys only (`not_disclosed` otherwise) |

`/api/v1/communications/*` (`communications.read`):

| Endpoint | Returns |
|---|---|
| `GET /`, `GET {id}` | Published / archived items with body, article slug and aggregate delivery, read and acknowledgement counts |
| `GET preferences?employee=CODE` | The employee's optional preferences and the mandatory types |

**Never returned:**
- response rows or answers, comments, response ids;
- groups;
- audience criteria or recipient lists;
- delivery records, attachment files, approval notes.

There are no survey tokens: access is the authenticated person plus their own participation row.
There are no write endpoints.

## 13. Security model

Each request passes these layers in order:
1. Authentication.
2. Tenant: the `BelongsToTenant` global scope, fail-closed.
3. Permission (`engagement.*`, `communication.*`).
4. Organisation scope: audiences are resolved inside the preparer's scope; approvers must cover the
   audience; HR results only when the whole eligible population is in scope.
5. Relationship scope: the manager's team group is derived from line relationships at the snapshot.
6. Field security: hidden question metadata never leaves the server for employees.
7. Survey / communication scope: `access()` decides hr / manager / employee per version.
8. Record: the employee's own participation, own preferences, own identified feedback.

**Generic access to response-side records is refused:** `EngagementRecordPolicy` denies all access to
participations, responses, answers and identities. They are reached only through `SurveyResponses`,
`EngagementAnalytics` and `ConfidentialIdentities` (architecture invariant 6). There is no "trusted
admin" bypass: even `*` / platform administrators get aggregates only, and anonymous responses have no
reveal path at all.

## 14. Audit

New actions:
- `SURVEY_CREATED`, `SURVEY_VERSION_CREATED`, `SURVEY_APPROVED`, `SURVEY_PUBLISHED`, `SURVEY_OPENED`,
  `SURVEY_CLOSED`, `SURVEY_ARCHIVED`, `SURVEY_INVITATION_SENT`, `SURVEY_RESPONSE_SUBMITTED`;
- `CONFIDENTIAL_RESPONSE_IDENTIFIED`, `FEEDBACK_SUBMITTED`;
- `CAMPAIGN_CREATED`, `CAMPAIGN_APPROVED`, `CAMPAIGN_PUBLISHED`, `CAMPAIGN_SCHEDULED`,
  `CAMPAIGN_CANCELLED`;
- `ANNOUNCEMENT_CREATED`, `ANNOUNCEMENT_APPROVED`, `ANNOUNCEMENT_PUBLISHED`,
  `ANNOUNCEMENT_ACKNOWLEDGED`;
- `AUDIENCE_CREATED`, `AUDIENCE_USED`;
- `COMMUNICATION_PREFERENCE_CHANGED`.

Submission and review moves also use the existing `SUBMITTED` / `REJECTED` / `STATUS_CHANGE`.

**Anonymous mode:** `AuditRecorder::record(..., anonymous: true)`:
- no actor, actor name, roles, IP, user agent or request id;
- `source = anonymous`.

Anonymous / confidential submissions and feedback are recorded this way, with the survey version as the
entity, never the response. Answers and feedback text never enter an audit record or a webhook. The
confidential reveal names the item and the reason, never the person.

**Chain hardening (Phase 13).** Six simultaneous survey submissions exposed two pre-existing defects.
Both are fixed in `AuditRecorder`; the change is additive, with no history rewritten and no hash
formula change.
1. **Deadlocks.** Writers serialised on "the last audit row FOR UPDATE", whose InnoDB gap locks let
   concurrent writers deadlock. Each chain (`tenant:{id}` / `platform`) now has a lock row in
   `audit_chain_locks`, which no foreign key references. Writers lock it before the locking read of the
   previous event. The `tenants` row is no alternative: every foreign-key check holds a shared lock on
   it.
2. **Chain order.** The event id (a time-ordered ULID) and time were taken before the lock. A writer
   that waited could carry an earlier id than the event it links to, so verification (in id order) saw
   a broken chain. Both are now taken inside the lock, and the id is kept strictly after the previous
   event's.

## 15. Events, jobs and scheduler

**Events.** `EngagementEvent` (survey.*, campaign.*, feedback.submitted) and `CommunicationEvent`
(communication.*) carry references only. They go to:
- the notification bridge: in-app to the users they name, no tenant rules;
- the webhook bridge: allow-listed only, never responses or feedback.

`SurveyResponseSubmitted` carries tenant, version and mode only, and is bridged nowhere.

**Jobs** (all `TenantAwareJob` + `BindTenantContext`, unique, idempotent):
- `SendSurveyInvitations`;
- `ProcessEngagement`;
- `DeliverCommunication`;
- `ProcessCommunication`.

**Scheduler** (`withoutOverlapping()->onOneServer()`):

| Command | Frequency | Does |
|---|---|---|
| `peopleos:engagement:process` | every 15 minutes | Opens and closes versions on their dates, sends missing invitations and due reminders, launches and completes campaigns |
| `peopleos:communication:process` | every 5 minutes | Releases scheduled announcements, delivers due recipients, retries failures |

## 16. Concurrency and lock order

**Lock order:** survey → version → participation → response rows; campaign → item modules; announcement
→ recipients; recipient → preference (shared); recipient → read row (acknowledgement); the audit chain
lock is always last.

| Race | Guard |
|---|---|
| Double submission (identified) | participation `FOR UPDATE` + unique `(version, employee, period_key, active_key)` |
| Same idempotency key | unique `(version, employee, idempotency_key)` + lookup under the participation lock |
| Closing vs submitting | version shared lock (submit) vs `FOR UPDATE` (close); the participation lock as second guard |
| Campaign launched twice | campaign `FOR UPDATE` + status check |
| Duplicate reminder runs | `insertOrIgnore` claim on the unique reminder-log key |
| Duplicate delivery jobs | recipient `FOR UPDATE` + status check |
| Concurrent acknowledgement | the person's recipient row `FOR UPDATE` first (the employee row for pre-Phase 13 items), then `insertOrIgnore` + read row `FOR UPDATE`. Without the first lock, two calls on an already-read item deadlock upgrading the insert-ignore shared lock |
| Concurrent snapshots | survey + version `FOR UPDATE` (open); announcement `FOR UPDATE` (release); unique keys as backstop |
| Anonymous submissions in parallel | participation `FOR UPDATE` (the only guard: anonymous responses have no unique key by design) |
| Preference change vs delivery | preference row `FOR UPDATE` (set) vs shared lock (delivery) |

Evidence: `tests/MySql/EngagementConcurrencyTest.php` (10 races, real MySQL, forked processes), run
twice. Lock removal makes the dependent races fail (report §26).

## 17. Scale

- Audiences are counted and snapshotted in SQL (one query; snapshots in 500-row chunks).
- Group keys come from one chunked query per dimension.
- Analytics are `GROUP BY` aggregates (constant queries).
- The feed is an `EXISTS` on recipients.
- Approver lookup is one SQL query.
- Delivery costs a fixed number of queries per recipient in bounded batches.

Measured in `tests/Feature/Engagement/EngagementScaleTest.php` (constant query counts from small to
large data).

## 18. Domain boundaries (enforced by the architecture invariants)

Engagement and Communication never write:
- Employee, Person, positions or reporting;
- Performance, Compensation, Payroll or statutory records;
- Learning, Career, Talent or Succession;
- Service Desk tickets or grievances directly (only through their services);
- Knowledge Base articles, or notification deliveries directly (only through the Notifier).

There is no RecruitmentEdge / RMS dependency, no engagement score, no sentiment or AI, and no
inference.

## 19. Known limitations and deferred work

**Residual anonymity risk:**
- People with direct database, binary-log or server-log access are outside the guarantee (§3).
- k-anonymity does not prevent homogeneity: if every member of a group above `k` gives the same
  answer, the aggregate shows it. This is inherent in reporting aggregates.

**Feedback:**
- Feedback submission has no idempotency key (UI-only, single submit).
- Anonymous feedback cannot be answered.

**Delivery and channels:**
- Email "sent" is mailer acceptance. There is no bounce or delivery webhook, so "delivered" is never
  shown.
- SMS, WhatsApp and push are not offered (log stubs only).

**Analytics:**
- Results breakdowns are limited to one pinned dimension.
- There is no engagement index and no cross-survey score.
- Trends are overall per survey.

**Deferred:**
- AI or aggregate summarisation of comments;
- external survey tokens / kiosk mode;
- write APIs;
- a results export;
- translated surveys;
- question branching.
