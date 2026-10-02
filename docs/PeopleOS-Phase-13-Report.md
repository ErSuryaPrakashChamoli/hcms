# PHASE 13 — ENGAGEMENT, SURVEYS & COMMUNICATION FOUNDATION

## Status

```text
Phase 13: COMPLETE
Statutory production readiness: NOT DECLARED
PeopleOS production readiness: NOT DECLARED
```

Stopped for architectural review. Phase 14 has not been started.

Architecture: `docs/architecture/engagement-communication.md`. This report separates:
- Phase 13 functionality (§7–§25);
- two pre-existing audit-chain defects found and fixed by the Phase 13 concurrency evidence (§22, §26);
- known limitations (§32);
- deferred work (§33);
- statutory status (§34).

## 1–5. Git

| | |
|---|---|
| 1. Starting HEAD | `47e1b97` on `feature/oct_1_phase_1` (Phase 12 approved). Clean tree, 0 pushes |
| 2. Ending HEAD | the phase-13.7 documentation commit (the commit that adds this report) |
| 3. Branch | `feature/oct_1_phase_1` |
| 4. Commits | 7 on top of `47e1b97`:<br>• `2e95dab` 13.1 engagement discovery and survey foundation<br>• `71c207b` 13.2 survey lifecycle, responses and anonymity<br>• `3f6984f` 13.3 privacy-preserving engagement analytics and employee feedback<br>• `c285fe3` 13.4 communication approval, audience snapshot, delivery, preferences and campaigns<br>• `e7161f8` 13.5 My HR engagement and communications, admin UI and read APIs<br>• `ee9ff10` 13.6 anonymity matrix, invariants, scale, MySQL concurrency and audit-chain hardening<br>• 13.7 documentation and this report |
| 5. Pushes | 0. Nothing was pushed, merged or deployed, and no history was rewritten |
| Working tree | clean at the start; clean after the final commit |

The commits are logical review units built from one implementation: later commits complete earlier ones
(for example, 13.4 registers the providers and policies that 13.1–13.3 introduce). The validation in this
report was run on the complete Phase 13 code (the final state), not on each intermediate commit.

## 6. Discovery findings

Recorded in `docs/architecture/engagement-communication.md` §1.

**What existed:**
- **Surveys:** no survey tables. Phase 12 left only the `SurveyTaskProvider` hook, bound to a null
  provider.
- **Exit interviews** (Exit) and **performance feedback** (Performance) exist and stay with their
  owners: they are not engagement.
- **Communication:** `announcements` and `announcement_reads`, and a `Communications` service with a
  rule-engine audience. It had:
  - no review (the author published);
  - content editable after publication;
  - the audience evaluated in PHP over every employee, with no snapshot;
  - future-dated items never notified;
  - no delivery tracking, preferences or acknowledgement lock.
- **Notifications:** `Notifier` (in-app and email real; SMS / WhatsApp / push are log stubs),
  `notification_deliveries`, `NotificationEngine` rules. No preferences, no rate limiting.
- **Reused as is:**
  - the workflow engine (`WorkflowCompleted` bridges);
  - organisation and relationship scope (`AccessScopes::employeeKeys`, SQL);
  - the k = 5 small-group principle;
  - the hash-chained audit;
  - the Knowledge Base (canonical policy content) and the service desk (`ServiceRequests::openGeneric`);
  - grievances (anonymous cases);
  - the `TenantAwareJob` / `BindTenantContext` queues.

**Stop conditions (§69):** none applied.
- No existing survey infrastructure conflicts with the design.
- Anonymity, suppression, free-text privacy and audience scope can all be enforced
  architecturally.
- Communication delivers through the existing Notifier, and approval uses maker-checker or the existing
  workflow engine.
- No migration is destructive.

## 7. Survey architecture

**One canonical model** (`App\Domain\Engagement`) for every survey type (engagement, pulse, feedback,
culture, onboarding, exit, event, custom):
- `surveys`: identity;
- `survey_versions`: everything that can change, frozen per version;
- `survey_questions`.

**Principal services:**
- `Surveys`: catalogue and lifecycle;
- `SurveyResponses`: taking a survey;
- `SurveyNotices`: invitations and reminders;
- `EngagementAnalytics`: privacy-preserving results;
- `ConfidentialIdentities`: the only reveal path;
- `Feedback`;
- `Campaigns`;
- `Audiences` / `AudienceQuery`;
- `GroupKeys`;
- `EngagementProcessor`;
- `SurveyTasks`: the Phase 12 hook.

**Lifecycle:** draft → in review → approved → scheduled → open → closed → archived.
- Approval is by a different person (`engagement.approve`), or by a configured workflow with
  separation of duties re-checked (`EngagementWorkflowBridge`).
- Opening snapshots the audience and queues invitations.
- Only one version of a survey is open at a time.

## 8. Survey versioning

A version pins:
- questions (order, type, required flag, options or scale, administrator / scoring / analysis
  metadata);
- anonymity mode and response rule;
- audience criteria (a copy, pinned at submission) and the breakdown dimension;
- result visibility, reminder policy and dates.

**Immutability:**
- Model guards refuse any content change outside draft, on versions and on questions.
- Approval stores a SHA-256 checksum of the content. Publication verifies it, fixes an "opens on
  publication" date once, and re-stamps the checksum.
- A correction is a new version (copy of the latest). Responses and answers carry the version id, and
  each answer references that version's question.
- Nothing is deleted.

## 9. Anonymity architecture

Three modes are pinned per version:

| Mode | Stored | Who can identify |
|---|---|---|
| Identified | `employee_id` on the response | HR in scope (`engagement.responses`); never through analytics |
| Confidential | no employee on the response; author only in `engagement_identities` | `engagement.confidential_identity` holders, one item at a time, with a reason, audited; never in analytics |
| Anonymous | **no link at all** | nobody: no application path, no administrator bypass |

**How it is enforced:**
- **Participation is separated from content.** `survey_participations` (who was eligible / took part,
  dates only) and `survey_responses` / `survey_answers` (what was answered) share no key.
- **Content rows carry nothing linkable:**
  - random v4 UUIDs, so neither ids nor storage order follow submission order;
  - no timestamps;
  - no employee, user, participation, token or IP.
- **Group keys** are assigned at the snapshot to groups of at least k eligible people. Smaller groups
  merge into "other"; with fewer than two groups there are no keys at all.
- **No drafts and no corrections in anonymous mode**, and no echo of answers.
- **Not Auditable:** responses, answers, participations and identities are excluded from automatic
  auditing, which would stamp the user and the time. Their audit uses the new anonymous mode (§22).
- **The only paths to content** are `SurveyResponses`, `EngagementAnalytics` and
  `ConfidentialIdentities` (architecture invariant 6). Everything else is denied generic access
  (`EngagementRecordPolicy`).

**Threat model** (architecture document §3):
- **Defended:** employees, managers, HR / analysts, integrations and tenant administrators using the
  application.
- **Residual, outside the guarantee:** direct database or log access. The design still gives such
  people no linking column, no timestamps and no ordered keys.

## 10. Audience model

- **Definitions:** structured criteria over effective-dated records:
  - companies, locations, business units, divisions, departments, teams;
  - designations, grades, levels, employment types, positions;
  - establishments, lifecycle states;
  - reports of named managers, named employees.
- **Resolution:** in SQL by `AudienceQuery`, always constrained to the scope user's organisation and
  relationship scope.
- **Validation:** criteria naming units or people outside the preparer's scope are refused, and an
  approver must cover the audience.
- **Pinning:** surveys and announcements pin a copy of the criteria at submission.
- **Snapshotted at launch, not dynamic:**
  - opening a survey writes the participations;
  - publishing an announcement writes the recipients.

  Each snapshot is one bulk operation (operation id) audited as `AUDIENCE_USED`. "Who was eligible when
  it opened?" is answered by the snapshot.
- **Previews** show a count, never a list.

## 11. Response model

**Response rules:**
- once: the default, and the only rule for anonymous / confidential;
- multiple: identified, idempotency-keyed;
- once per week / month / quarter: identified.

**Enforcement:**
- the employee's participation row lock;
- for identified surveys, the unique keys `(version, employee, period_key, active_key)` and
  `(version, employee, idempotency_key)`.

A repeat submission is `already_submitted` and changes nothing.

**Locking and corrections:**
- Responses are locked once submitted.
- Identified respondents may correct while the survey is open: the old response is superseded (kept),
  and the new one points to it.
- Anonymous and confidential responses are final.

Free-text answers are encrypted at rest. Participation statuses: invited → opened → submitted, or
expired at closing.

## 12. Engagement analytics

Descriptive only:
- participation (eligible, invited, opened, submitted, expired, response rate);
- distributions;
- averages for rating, likert and number;
- trend across closed versions.

There is no index, no employee score and no inference.

**Privacy rules** (`EngagementAnalytics`):
- nothing below k = 5 respondents overall;
- groups only from the version's one pinned dimension (no ad-hoc filters);
- each group needs k;
- **complementary suppression:** suppressed groups total 0 or ≥ k, so differencing never reveals a
  small group (property-tested over 500 random cases);
- the same rules per question;
- free text overall only, at ≥ 10 respondents and comments, shuffled, with no ids or groups, for
  `engagement.comments` holders only;
- **anonymous / confidential results only after closing** (no live differencing).

**Views:**

| Viewer | Sees |
|---|---|
| HR | Surveys whose whole eligible population is in their scope |
| Managers | Their own team's group only, when the version allows it |
| Employees | Overall only, when the version allows it |
| Integrations | Overall only |

## 13. Feedback

`EmployeeFeedback` uses the same privacy design: random id, date only, encrypted body, confidential
authorship only in `engagement_identities`, anonymous authorship nowhere.

**Handling:** `engagement.feedback` holders work an inbox (identified items in their scope plus the
unattributed items), with in review / closed and a note.

**Routing to the owning domain** (no parallel case system):
- identified feedback → an HR service desk request (`ServiceRequests::openGeneric`);
- confidential feedback → the same, after a reasoned, audited reveal;
- anonymous feedback → an anonymous grievance (`Grievances::raise`, categories that allow it).

## 14. Communication architecture

Communication (intentional organisational messages) is separated from notifications (transactional).
Communication delivers through the existing `Notifier`:
- in-app and email only;
- no second engine, queue or channel;
- preferences apply to optional communication only.

The existing communication module was extended in place:
- `communication_recipients` (snapshot and delivery state);
- `communication_preferences`;
- `CommunicationDelivery`;
- `CommunicationProcessor`;
- `CommunicationTaskSource`.

## 15. Announcement model

**Fields:**
- title, type, priority, Markdown body;
- Knowledge Base article link (policy content is never copied);
- campaign, structured audience;
- pinned, acknowledgement required, publish / expiry dates;
- one private attachment (fingerprinted, signed-link download, re-checked, audited);
- version / supersedes.

**Lifecycle:** draft → in review → approved → scheduled → published → archived, or cancelled.
- Approval is by a second person (`communication.approve`) or a workflow, with separation of duties.
- Content is frozen from submission. A correction is a new version that archives the old one on
  publication.
- Acknowledgement is recorded once, under a lock (`ANNOUNCEMENT_ACKNOWLEDGED`).
- Rows from before Phase 13 keep their original rule audience; new ones use structured audiences.

## 16. Campaigns

Campaigns group surveys, announcements, Knowledge Base articles and HR services **by reference**:
draft → review → approved (a second person or a workflow) → scheduled → active → completed, or
cancelled.

**Launch** (scheduled, idempotent, one audited bulk operation):
- publishes each approved item through its own module;
- reports items that are not approved as skipped (`partial` in the audit and the bulk summary);
- never forces an item.

**Metrics** are counts from the owning modules.

## 17. Communication preferences

- **Scope:** per employee and optional category (announcement, circular, newsletter, survey
  invitations / reminders) × in-app / email.
- **Mandatory types** (policy publications, instructions) cannot be switched off.
- **Transactional notifications** are outside preferences.
- **Changes:** made by the employee only, under a row lock, audited
  (`COMMUNICATION_PREFERENCE_CHANGED`).
- **Concurrency:** delivery reads the preference under a shared lock in the same transaction, so a
  change committed first is always honoured (MySQL race 10).

## 18. Delivery model

Recipient states:
- `pending`;
- `sent`: at least one channel accepted it. In-app means the inbox entry was written; email means the
  mailer accepted it;
- `failed`: every channel failed; retried up to 3 attempts by later scheduled runs;
- `skipped`: no account, or every channel switched off.

**Claim:** each recipient is claimed under a row lock (no duplicate notification under concurrent
jobs).

**What is never claimed or stored:**
- **"Delivered" is never claimed:** no channel reports provider delivery.
- **No message body in the log:** the notification carries the title and a pointer, never the body.

**Partial delivery** is visible as counts (sent / failed / skipped / pending) per announcement, campaign
and API item.

## 19. Employee Experience integration

My HR gains four tabs; there is no new portal:

| Tab | Content |
|---|---|
| Surveys | Take open surveys (form built from the employee-visible question fields only); own history; results link where shared |
| Feedback | Give feedback; own identified items |
| Communications | Feed, body, policy link, attachment, read / acknowledge |
| Preferences | Optional categories; mandatory ones shown as always delivered |

**My tasks:**
- open, unanswered surveys (the Phase 12 hook, now bound);
- pending acknowledgements.

**Unchanged:** Employee 360 (no engagement score, no responses, no participation).

**Admin (Engagement group):**
- Surveys: versions, questions, lifecycle, results;
- Campaigns;
- Audiences;
- Feedback inbox.

The Announcements resource gains the approval actions, a structured audience and delivery counts.

## 20. API

Both APIs are read-only and use API-key scopes. Other tenants' records are 404.

| API | Returns |
|---|---|
| `/api/v1/engagement` (`engagement.read`) | `surveys`, `surveys/{code}` (employee-visible questions only), `surveys/{code}/participation` (counts), `surveys/{code}/versions/{n}/results` (overall, same privacy rules, no text), `my-surveys?employee=` (participation only for identified surveys; `not_disclosed` otherwise) |
| `/api/v1/communications` (`communications.read`) | `/`, `/{id}` (published items, aggregate counts), `preferences?employee=` |

**Never returned:**
- responses, answers, comments, response ids;
- groups;
- audience criteria, recipient lists, delivery records;
- attachments.

Tested against identity reconstruction through ids, participation, pagination, filters and timing (the
anonymity matrix).

## 21. Security

Each request passes these layers in order:
1. Authentication.
2. Tenant (fail-closed).
3. Permission (10 new `engagement.*` and `communication.approve` permissions, role templates updated).
4. Organisation scope (audiences, approvers, HR results).
5. Relationship scope (manager team groups).
6. Field security (hidden question metadata never leaves the server for employees).
7. Survey / communication scope (`access()`: hr / manager / employee).
8. Record (own participation, own preferences, own identified feedback).

There is no "trusted admin" bypass: `*` users and platform administrators get aggregates only, and
anonymous responses have no reveal path.

## 22. Audit

**New actions:**
- `SURVEY_CREATED`, `SURVEY_VERSION_CREATED`, `SURVEY_APPROVED`, `SURVEY_PUBLISHED`, `SURVEY_OPENED`,
  `SURVEY_CLOSED`, `SURVEY_ARCHIVED`, `SURVEY_INVITATION_SENT`, `SURVEY_RESPONSE_SUBMITTED`;
- `CONFIDENTIAL_RESPONSE_IDENTIFIED`, `FEEDBACK_SUBMITTED`;
- `CAMPAIGN_CREATED`, `CAMPAIGN_APPROVED`, `CAMPAIGN_PUBLISHED`, `CAMPAIGN_SCHEDULED`,
  `CAMPAIGN_CANCELLED`;
- `ANNOUNCEMENT_CREATED`, `ANNOUNCEMENT_APPROVED`, `ANNOUNCEMENT_PUBLISHED`,
  `ANNOUNCEMENT_ACKNOWLEDGED`;
- `AUDIENCE_CREATED`, `AUDIENCE_USED`;
- `COMMUNICATION_PREFERENCE_CHANGED`.

**Anonymous mode:** `AuditRecorder::record(..., anonymous: true)` records the event with no actor,
roles, IP, user agent or request id (`source = anonymous`). The entity is the survey version, never the
response. Answers and feedback text never enter audit records or webhooks. The confidential reveal
names the item and the reason, never the person.

**Two pre-existing audit-chain defects**, exposed by Phase 13's six-writer races and fixed in
`AuditRecorder` (additive, no history rewritten):
1. **Deadlocks.** Writers serialised on "the last audit row FOR UPDATE". Its InnoDB gap locks let
   concurrent writers deadlock. Writers now queue on one row per chain in the new `audit_chain_locks`
   table, which no foreign key references. The `tenants` row was tried first and rejected: every
   insert's foreign-key check holds a shared lock on it, so writers deadlocked upgrading.
2. **Chain order.** The event id (a time-ordered ULID) and time were taken before the lock. A writer
   that waited could carry an earlier id than the event it links to, so verification (in id order) saw
   a broken chain. Both are now taken inside the lock, with the id kept strictly after the previous
   event's.

## 23. Events

- `EngagementEvent` (survey.*, campaign.*, feedback.submitted) and `CommunicationEvent`
  (communication.*) carry references only (codes, names, versions, counts). They go to:
  - the notification bridge: in-app to the named users, no tenant rules;
  - the webhook bridge: allow-listed survey.published / opened / closed, campaign.launched,
    communication.published.
- `SurveyResponseSubmitted` carries tenant, version and mode only, and is bridged to nothing.
- Feedback and responses never reach webhooks.

## 24. Queues

All jobs are `TenantAwareJob` + `BindTenantContext`, unique and idempotent (invariant 18):
- `SendSurveyInvitations`;
- `ProcessEngagement`;
- `DeliverCommunication`;
- `ProcessCommunication`.

## 25. Scheduler

| Command | Frequency | Does |
|---|---|---|
| `peopleos:engagement:process` | every 15 minutes | Open / close versions on their dates, invitations, reminders, campaign launch / completion |
| `peopleos:communication:process` | every 5 minutes | Release scheduled announcements, deliver due recipients, retry failures |

Both use `withoutOverlapping()->onOneServer()`, and both have `--tenant` and `--queue` options.

## 26. Concurrency

Real MySQL with forked processes and slowed model events (`tests/MySql/EngagementConcurrencyTest.php`,
same harness and opt-in as Phases 8–12), on the dedicated disposable database `hcm_p13_concurrency`
(created for this phase).

**The 10 required races:**
1. two submissions by the same identified respondent;
2. two submissions with the same idempotency key;
3. closure vs submission;
4. two campaign launches;
5. two overlapping reminder runs;
6. two delivery jobs on one announcement;
7. concurrent acknowledgement, with and without a prior read (4 processes);
8. two survey openings plus two announcement releases (4 processes);
9. anonymous privacy under concurrency: five people at once, then one person twice at once;
10. a preference change racing a delivery that is claiming the recipient.

**Final code:**

| Run | Result | Audit chains after the run |
|---|---|---|
| Run 1 | 10/10 PASS (107.3 s) | 11 chains, 2,916 events, 0 broken |
| Run 2 | 10/10 PASS (105.9 s) | 11 chains, 2,916 events, 0 broken |
| Run 3 (clean run for the concurrency audit chain, §29) | 10/10 PASS (105.5 s) | 11 chains, 2,916 events, 0 broken |

**Cross-phase check.** The audit change is shared, so every MySQL race suite was re-run on fresh
databases on the final audit code, one process per suite:
- Compensation 7/7, Engagement 10/10, Learning 5/5, Service Desk 14/14, Talent 5/5, Workforce 6/6;
- every audit chain verified after each suite.

**Defects found by the races and fixed before the final runs:**
1. **Audit-chain deadlocks (pre-existing).** Six simultaneous anonymous submissions deadlocked on the
   "last audit row FOR UPDATE" gap locks. Fixed with per-chain lock rows (§22).
2. **Audit-chain order (pre-existing).** After fix 1, concurrent writers produced chains that read as
   broken in id order: ids were taken before the lock. Fixed by taking the id and time inside the
   lock (§22). This also explains why the Learning / Talent / Workforce "chain intact" checks failed
   in a combined run that included the earlier, broken engagement tenants.
3. **Acknowledgement deadlock (Phase 13 code).** The first version of race 7 did not cover an item
   read before acknowledging. Covering it showed that two calls take a shared lock (insert-ignore
   duplicate check) and deadlock upgrading it. Acknowledgement now serialises on the recipient row
   first.
4. **Race 9 test design.** The "same person twice" step reused someone who had already answered, so it
   could not detect a missing lock. It now uses a person who has not answered.

**Locks deliberately removed** (each removed, its races run, the file restored; final code):

| Lock removed | Expected failure observed |
|---|---|
| Participation row lock (`SurveyResponses::submit`) | Race 9: 7 responses instead of 6 (the same anonymous person twice). Race 1 still passed: the unique index is the identified backstop |
| Survey + version locks (`Surveys::open`) | Race 8: duplicate participation insert (unique-key violation) |
| Announcement lock (`Communications::release`) | Race 8: deadlock |
| Campaign lock (`Campaigns::launch`) | Race 4: a second launch reached the item publication |
| Recipient lock (`CommunicationDelivery::deliverOne`) | Race 6: recipients notified twice |
| Acknowledgement serialisation lock (`Communications::acknowledge`) | Race 7: deadlock |
| Preference shared lock (`CommunicationPreferences::channelsFor`) | Race 10: email sent after the opt-out committed |
| Version shared lock (`SurveyResponses::submit`) | Race 3: data stayed consistent (the participation lock is the second guard), but the refusal became "not invited" instead of the deterministic "not open" |
| Audit chain lock (`AuditRecorder::lockChain`) | Races 8 and 9: deadlocks |

Result: 9/9 lock groups produced their expected failures, and the original code passed after
restoration. An earlier lock-removal run (before fixes 3 and 4) is superseded by this one.

## 27. Scale

`tests/Feature/Engagement/EngagementScaleTest.php` (SQLite query counts):

| Measurement | Small | Large |
|---|---|---|
| Audience count (30 → 300 employees) | 1 query | 1 query |
| Survey opening incl. snapshot (30 → 300 eligible) | 187 | 182 (no growth; the first run also warms caches) |
| Invitations | about 7 queries per person (claim, preference, deliveries), constant per person | |
| One response submission (10 → 98 earlier responses) | 18 | 18 |
| Analytics results | 4 | 4 |
| Surveys admin list | 7 | 7 |
| Announcements admin list (12 → 42 items) | 33 | 33 |
| Communications API page | 34 | 34 |
| Engagement surveys API | 5 | 5 |
| Delivery (10 vs 30 recipients) | 10 queries per recipient, constant | |

Audiences, snapshots, group keys, analytics, the feed and approver lookup are SQL; no employee or
response set is loaded into PHP. Approver lookup was moved from a PHP loop over users to one SQL query
when the scale test showed growth. No timing benchmarks were taken.

## 28. Migration replay

**Database:** `hcm_p13_replay`, created empty for this replay:
- dedicated and disposable;
- not development (`hcm`) and not shared.

The script refused to run unless the effective database was `hcm_p13_replay`.

| Step | Result |
|---|---|
| Fresh migrate + seed | PASS: 105 migrations, demo seed. Its two announcements went through create → submit → second-person approval → publish (ANNOUNCEMENT_CREATED / APPROVED / PUBLISHED and AUDIENCE_USED, 2 each) |
| Snapshot after seed | 291 tables, 4,429 columns, 1,533 indexes (491 unique), 1,108 foreign keys, 23 CHECK constraints |
| Cycle 1: rollback (the 3 Phase 13 migrations), then re-apply | PASS. Statuses unknown to the pre-Phase 13 code are mapped back on rollback |
| Snapshot after cycle 1 | identical to the seeded snapshot |
| Cycle 2: rollback, then re-apply | PASS |
| Final snapshot | identical to cycle 1 |
| Final replay vs development (`hcm`) | Identical tables, column types / nullability / defaults / generated expressions, indexes, unique keys, foreign keys (with delete / update rules) and CHECK constraints. **One difference:** `tenants.base_currency varchar(3) NOT NULL DEFAULT 'INR'` exists only in development — the known historical difference, left unchanged |

Rolling back an additive Phase 13 migration drops its tables and columns, so Phase 13 rows (recipients,
recipient counts) do not survive a rollback; re-applying recreates the structure empty. The development
database was migrated forward only (3 Phase 13 migrations), and permissions were synced (11 created).

## 29. Audit-chain verification

| Database | Result |
|---|---|
| Development (`hcm`) | PASS: platform 40 events, demo tenant 587 events verified |
| Replay (`hcm_p13_replay`) | PASS: platform 2, demo tenant 682 events verified (includes the seeded Phase 13 announcement events) |
| Concurrency (`hcm_p13_concurrency`, after the clean run 3) | PASS: platform plus 10 race tenants, 11 chains, 2,916 events verified, 0 broken |

**Notes:**
- After each of runs 1 and 2, and after every suite of the cross-phase MySQL check, `peopleos:audit:verify`
  also passed.
- Anonymous submissions in the chains carry no actor, IP, user agent or request id (asserted in race 9
  and in the anonymity matrix).
- No historical audit row was rewritten. The two audit fixes change how new events are written,
  not the hash formula or existing rows.

## 30. Failed jobs

| Database | Failed jobs |
|---|---|
| Development (`hcm`) | 0 |
| Replay (`hcm_p13_replay`) | 0 |
| Concurrency (`hcm_p13_concurrency`) | 0 |

Development also holds 10 **pending** (not failed) Filament database-notification jobs queued on
2026-09-26. They predate Phase 13 and were left untouched.

## 31. Tests

**Authoritative full suite.** It ran on the final Phase 13 code, after the last code change:
`php artisan test` (Pest, SQLite in-memory), 2026-10-03 01:55:07 → 02:11:51. The committed tree (13.6)
is byte-identical to the tested working tree.

| Tests | Passed | Skipped | Failed | Assertions | Duration |
|---|---|---|---|---|---|
| 841 | 794 | 47 | 0 | 10,430 | 1,002.9 s |

The 47 skipped tests are the MySQL-only concurrency suites (Phases 8–13: 37 before Phase 13 plus the 10
new Engagement races). SQLite runs skip them by design; they ran on MySQL separately (§26).

**Earlier runs, not authoritative:**
- one parallel run before the audit-chain and acknowledgement fixes (811 tests, 0 failed);
- one run that overlapped the lock-removal script (841 tests, 0 failed).

Neither is used as the result.

**New Phase 13 tests (64):**
- `Engagement/` (37 tests):
  - survey lifecycle: 5
  - privacy / analytics: 7
  - anonymity matrix: 8
  - communication: 6
  - feedback / campaign / workflow / processor / jobs: 5
  - pages / My HR: 3
  - scale: 3
- `Architecture/EngagementInvariantsTest`: 17 tests covering the 24 invariants, including a
  500-case property check of complementary suppression
- `MySql/EngagementConcurrencyTest`: 10 races

**Existing tests adapted only where Phase 13 changed a rule:**
- Announcements now need a second-person approval, and the audience is structured
  (`Experience/KnowledgeAndCommunicationTest`).
- The architecture test's append-only / derived list gained the anonymity-boundary tables, each with
  its reason.

No assertion was weakened.

**Validation summary:**

| Check | Result |
|---|---|
| Architecture suites (incl. Phase 13 invariants) | PASS (in the full suite; EngagementInvariantsTest 17/17, ArchitectureTest 79/79) |
| Anonymity matrix | PASS 8/8 |
| Security (tenant, organisation, relationship, field, anonymous, API IDOR, campaign scope) | PASS (anonymity matrix, invariants 1–2 / 6 / 23 / 24, privacy and communication suites) |
| Scale | PASS 3/3, constant query counts (§27) |
| Pint (`--test`) | PASS |
| Full suite (authoritative) | PASS: 841 tests, 794 passed, 47 skipped (MySQL-only), 0 failed, 10,430 assertions |
| Concurrency | 10/10 ×3 on the final code; cross-phase 47/47; lock removal 9/9 expected failures |
| Migration replay | PASS, twice |
| Audit chain | Development PASS, replay PASS, concurrency PASS |
| Failed jobs | 0 |

## 32. Known limitations

- **Intermediate commits:** commits 13.1–13.6 were not individually tested; validation ran on the
  complete Phase 13 code.
- **Residual anonymity risk:**
  - Direct database, binary-log or server-log access is outside the guarantee.
  - k-anonymity cannot prevent homogeneity: if every member of a group above k answers the same, the
    aggregate shows it.
- **Feedback:** submission has no idempotency key (UI single-submit only), and anonymous feedback
  cannot be answered.
- **Delivery:** email "sent" is mailer acceptance (no bounce or delivery webhooks); SMS / WhatsApp /
  push are not offered.
- **Analytics scope:** results break down by one pinned dimension, and trends are overall only.
- **API context:** API keys are tenant-level, so `my-surveys` / `preferences` take an employee code;
  there is no employee-bound key.
- **Audit chain across servers:** chain order relies on one clock; app servers with clock skew could
  still misorder ids. Verification follows id order, as before.
- **Legacy announcements:** pre-Phase 13 rows keep their PHP rule audience (feed and stats evaluate it
  per employee for those rows only).
- **UX checks:** keyboard, mobile and screen-reader behaviour: NOT VERIFIED beyond server-side render
  and Livewire action tests.
- **Webhooks:** payload privacy is verified through event context and the allow-list; no live webhook
  delivery was exercised.

## 33. Deferred work

- AI or aggregate summarisation of comments (not built; would need its own privacy review);
- external / kiosk survey tokens;
- write APIs (submission, acknowledgement);
- results export;
- translated surveys and question branching;
- an engagement index (only if formula, weighting, scale and version are designed and pinned);
- bounce / delivery receipts from email providers;
- per-employee API keys for employee-facing integrations.

## 34. Statutory status

```text
24 rules
0 verified
5 open notices
UNCHANGED
Production statutory readiness: NOT DECLARED
```

Phase 13 changed no statutory rule, marked no evidence verified, did not implement the EPF September
2026 split and did not modify TDS rules. Statutory remediation remains a separate controlled track.
