# PHASE 9 — CAREER, TALENT & SUCCESSION FOUNDATION + CONTROLLED STATUTORY EVIDENCE MAINTENANCE

## Status

```text
Phase 9: COMPLETE
Statutory production readiness: NOT DECLARED
```

Stopped for owner review. Phase 10 has not been started.

## Git

| | |
|---|---|
| Starting HEAD | `335acd7` on `feature/oct_1_phase_1` (Phase 8 end; baseline verified, no discrepancy) |
| Ending HEAD | the phase-9.8 documentation commit (the commit that adds this report) |
| Branch | `feature/oct_1_phase_1` |
| Commits | 9 on top of `335acd7`:<br>• `cad1554` 9.1 discovery, schema, permissions<br>• `2935ce5` 9.2 domain services<br>• `d0829c7` 9.3 policies, analytics, API<br>• `4bbcb31` 9.4 screens and Employee 360<br>• `fcf36af` 9.5 reminders, notifications, webhooks<br>• `b4fc0a6` 9.6 statutory evidence maintenance<br>• `67d3d4d` 9.7 tests<br>• 9.7 follow-up: criticality assessments audited<br>• 9.8 docs<br>The grouping differs from the suggested 12 (the prompt allows this). |
| Pushes | 0 |
| Working tree | clean after the final commit |

## Discovery

See `docs/PeopleOS-Phase-9-Discovery.md`.

What existed before this phase:
- Career paths with text proficiency, one mutable career-aspiration row per employee, and the Phase 7
  Career Passport.
- No position-seat entity: `Designation` is the canonical role, and `employee_positions` is the
  effective-dated assignment with a `change_type`.
- No talent or succession code.
- The Phase 15 `AttritionRisk` AI service exists and is deliberately **not** used.

**Design decision:** a position is a role (`Designation`, optionally within an organisation unit).
Incumbents and movements are read from employment, and nothing duplicates position, employee or
person structures.

## Architecture

See `docs/architecture/career-talent-succession.md`.

- Three new domains: `app/Domain/Career`, `app/Domain/Talent` and `app/Domain/Succession`.
- One additive migration with 21 new tables and additive columns on `career_paths`.
- One additive Performance read contract, `CompetencyEvidenceReader`.
- Phase 8 Learning, Skills, Development and Performance are consumed through their services and
  contracts. They were not rebuilt.

## Career

| | |
|---|---|
| Career profile | One per employee:<br>• track, preferred job families and locations, target roles, mobility preferences, development priorities;<br>• sharing flags the employee controls: aspirations / goals / mobility shared with the manager — off / on / off by default;<br>• `lock_version` optimistic locking.<br>HR (`career.manage`) cannot change the sharing flags. |
| Career aspirations | Effective-dated history by term (short / medium / long).<br>A new entry supersedes the current one; text is never overwritten.<br>The legacy snapshot is mirrored for the Career Passport. |
| Career goals | Separate from performance goals, with transitions active ⇄ paused → achieved / abandoned. Closed goals are read-only, and goals are never deleted.<br>A goal can link a target role, skill, course, path or development plan.<br>Achieving a goal never changes position, pay or lifecycle. |
| Career paths | The Phase 7 model, extended with track, business unit, organisation unit and effective dates.<br>`CareerArchitecture::publishPath` writes immutable, checksummed versions. |
| Career tracks | Configurable types: individual contributor, people manager, technical specialist, functional specialist, leadership. |
| Position requirements | `RoleRequirementVersion` per role (optionally per unit), effective-dated and immutable.<br>A new version closes the previous window.<br>Covers skills (level validated on, and pinned to, the Phase 8 scale version), competencies, minimum experience, certifications and learning. |
| Gaps | `RoleGaps` reports facts only:<br>• skill level against the best-evidenced level, with basis (verified / manager / self …), self-declared level shown separately, and a different scale flagged rather than compared;<br>• competency evidence from the latest finalized appraisal;<br>• learning: completed / in progress / missing / expired;<br>• certifications: held / expired / in progress / missing, plus verified;<br>• experience.<br>No score, rank or enrolment. |
| Internal mobility | `MobilityInterest`: position / job family / department / location / track, active → withdrawn. No marketplace and no recruitment object. |
| Movement history | Read only from `employee_positions` (`change_type`). |

## Talent

| | |
|---|---|
| Talent profiles | One per employee: track, mobility level, critical-role interest, development priorities, latest review outcome, encrypted confidential notes; `lock_version`. |
| Talent pools | Explicit membership by a person, with a reason.<br>Effective-dated, unique while active (`active_key`), ended with a reason, never edited or deleted.<br>Bulk add is one audited operation, chunked. |
| Talent reviews | Sessions run draft → in progress → completed / cancelled, with participants and a population (bulk, scope-checked, never self).<br>Decisions are configurable, need a reason, and are immutable once recorded.<br>Completion is locked and every employee must be decided. An optional approval workflow is pinned to its version.<br>Decisions such as "add to pool" or "nominate successor" are **recorded, never executed**. |
| Talent assessments | Configurable versioned models (the default is two dimensions with three levels; a 9-box is one possible configuration).<br>Every dimension must be rated with an allowed value; ratings are never combined into a score.<br>Draft → final → superseded by a correction. Encrypted notes. Never about oneself. |

## Succession

| | |
|---|---|
| Critical positions | Designated by a person (`succession.manage`); unique while active.<br>Criticality, business impact, scarcity, replacement difficulty and operational dependency are recorded as immutable assessments with a reason.<br>Each position has a review frequency and a next review date. Retired positions are read-only. |
| Succession plans | One open plan per position.<br>Draft → active ⇄ under review → closed (closing needs a reason), with `lock_version`.<br>Optional activation workflow pinned to its version (`SuccessionWorkflowBridge`).<br>Encrypted confidential notes. |
| Successors | Added by people with optional strengths, gaps and notes; unique while active; removed with a reason.<br>The incumbent is refused.<br>The plan owner is notified; **the candidate is not.** |
| Readiness | Configured labels: Ready now, < 1 year, 1–2 years, Longer term, Not assessed.<br>Recorded by `succession.assess` within scope, never about oneself, with reason, evidence and a 12-month validity.<br>Immutable; superseded under an employee row lock. |
| Development actions | Items in the employee's **Phase 8 development plan**: the open plan is reused, or one is created.<br>Mapped to learning / skill gap / goal items, and linked by an immutable `TalentDevelopmentAction`.<br>Titles must be neutral, because the employee sees them in their plan.<br>No duplicate assignment and no direct enrolment. |
| Coverage analytics | Facts only:<br>• critical positions by criticality;<br>• open plans, positions with successors, positions with a current ready-now successor, positions without one;<br>• bench strength per position, next review dates and overdue reviews;<br>• recorded incumbent exit dates (open exit case, last working day).<br>No flight risk, prediction or ranking. |

## Employee 360 and screens

- **Talent navigation:**
  - Dashboard;
  - Career architecture;
  - Career paths;
  - Role requirements;
  - Career profiles;
  - Critical positions;
  - Succession plans;
  - Successors;
  - Talent pools;
  - Talent reviews;
  - Readiness;
  - Development actions;
  - Analytics.
- **Me:**
  - **My career:** profile and sharing, aspiration history, career goals, mobility, role gaps,
    movement history. Candidacy appears only with `succession.own_candidacy`.
  - **Team career:** shared fields only, development progress, succession with `succession.team`,
    and the reviews one takes part in.

  My skills and My development stay on Phase 8 My learning.
- **Employee 360:** new Career, Talent and Succession tabs, each gated by its policy.

## API

| Prefix | Scope | Endpoints |
|---|---|---|
| `/api/v1/career` | `career.read` | tracks, paths, role-requirements, profiles, goals, mobility-interests, gaps, movements |
| `/api/v1/talent` | `talent.read` | pools, memberships, reviews, reviews/{id}, analytics |
| `/api/v1/succession` | `succession.read` | critical-positions, critical-positions/{id}, plans, plans/{id}, successors, readiness, analytics |

**What the API exposes:**
- Read-only and field-filtered; employees are identified by code.
- Goals and mobility appear only where the employee shares them.

**Never returned:**
- confidential notes;
- strengths and gaps;
- reasons and evidence;
- ratings;
- aspiration text;
- development priorities;
- internal employee ids.

**Access errors:**
- a missing scope returns 403;
- a missing key returns 401;
- ids from another tenant return 404.

## Security

| Layer | Result |
|---|---|
| Tenant isolation | `BelongsToTenant` on every new model; all 22 tables have `tenant_id` (architecture test). Foreign administrators see nothing, and foreign ids return 404 (tests). |
| Organisation scope | The access scope applies to every employee-linked model. `TalentAccess` checks `AccessScopes::allowsEmployeeId`, which is fail-closed: an employee with no current position is outside every scope. Tested at policy and query level with a location-scoped HR user. |
| Relationship scope | Manager scope comes only from `PerformanceRelationships` (configured types). **Mentor, buddy and project lead grant nothing** (tests). The architecture test forbids `directReports()` / `manager_id` shortcuts. |
| Employee self-service | Employees never see their own candidacy, pool membership, readiness, talent profile or plan (tests), and never assess themselves. |
| Manager / HR scope | Managers see career fields only as shared and succession only with `succession.team`. They never see talent records by relationship alone.<br>HR access is limited to permission plus organisation scope. Review participants see only their sessions, never pools (a defect found and fixed with `TalentReviewPolicy`). |
| Field security | `confidential_notes` is encrypted and hidden (highly sensitive). It is read only through `TalentAccess::confidential()`, which needs `talent.confidential`, is never about oneself and audits a `VIEW`. Sensitive attributes are masked in audit diffs (test). |
| Confidential talent data | Talent and succession records are classified confidential. Notifications never reach the employee concerned, and no tenant rule fires with their context. Webhooks carry architecture events only. |
| API security / IDOR | Scopes are enforced per prefix, the API is field-filtered, and foreign plan / position ids return 404 (tests). |
| Audit access | The audit log stays behind `audit.view`; talent and succession permissions do not include it (test). |
| Bulk authorization | Pool and review population need `talent.manage`. Out-of-scope and self entries are skipped and counted in the operation summary (test). |
| Permissions added (15) | **career:** `career.view / manage / self / team`<br>**talent:** `talent.view / manage / assess / review / confidential / analytics`<br>**succession:** `succession.view / manage / assess / team / own_candidacy`<br>**API scopes:** `career.read`, `talent.read`, `succession.read` |

## Audit, events, queues, scheduler, notifications

- **Audit:**
  - Every Phase 9 model is `Auditable`, except the reminder log (derived de-duplication rows,
    documented in the architecture test like the Phase 7 / 8 reminder logs).
  - Explicit events cover:
    - path published;
    - criticality assessed;
    - plan status changes;
    - talent assessment completed;
    - talent review completed;
    - workflow outcomes;
    - confidential record access (`confidential_talent_access`).
  - Pool and review bulk operations carry an operation id.
- **Events:** `CareerEvent`, `TalentEvent` and `SuccessionEvent` (subject model + explicit
  recipients). They cover:
  - goal created, aspiration updated, profile updated, path published;
  - critical position created, plan created;
  - successor added / removed;
  - readiness assessed;
  - development action created;
  - pool membership changed;
  - assessment completed;
  - review completed;
  - reminders.
- **Workflow:** talent review completion and plan activation use the existing engine, pinned to the
  published version (`PEOPLEOS_TALENT_REVIEW_WORKFLOW`, `PEOPLEOS_SUCCESSION_PLAN_WORKFLOW`).
- **Queues:** `SendTalentReminders` is a `TenantAwareJob` with `BindTenantContext` and is
  `ShouldBeUnique`.
- **Scheduler:** `peopleos:talent:send-reminders` runs daily at 07:45 with
  `withoutOverlapping()->onOneServer()`. It covers critical-position reviews, plan reviews,
  readiness expiring and upcoming talent reviews, throttled through `talent_reminder_logs`.
- **Notifications:** in-app, to the record owners only (plan owner, assessor, review participants).
- **Webhooks:** `career.path.published`, `succession.critical_position.created` and
  `talent.review.completed` only.

## Analytics

`TalentAnalytics` produces database aggregates under the caller's scope:
- critical positions;
- succession coverage and ready-now coverage;
- positions without a ready-now successor;
- bench strength;
- readiness distribution;
- pool counts;
- development-action progress (plan item status);
- review decisions;
- aspiration trends;
- mobility interest;
- successor skill gaps and certification gaps.

People counts below `talent.analytics_min_group` (5) are suppressed, and nothing is ranked. The
coverage dashboard runs a constant number of queries (one grouped incumbent-exit query; previously
one per position — fixed in this phase).

## Concurrency

**Protections:**
- Unique while active (`active_key`) for successors, pool memberships, critical positions and open
  plans.
- Row locks plus state re-checks for readiness supersede (employee lock), plan transitions, review
  decision / completion, and requirement / path / model versions (locking reads for the latest
  version).
- `lock_version` on career and talent profiles, goals and plans.

**MySQL proof:** `tests/MySql/TalentConcurrencyTest.php` runs on real MySQL with forked processes,
covering:
- successor add;
- designation;
- pool add;
- readiness supersede;
- review completion;
- stale profile update;
- the audit chain afterwards.

Removing the readiness lock makes its race fail. The fork harness is now shared
(`tests/MySql/ConcurrencyHelpers.php`).

## Migration replay

Run on a temporary MySQL database, `hcm_phase9_replay`:
1. `migrate:fresh --seed`: 254 tables, 93 migrations, 876 foreign keys.
2. Columns, indexes, unique constraints and foreign keys are identical to dev, apart from the known
   `tenants.base_currency` drift.
3. The Phase 9 migration was rolled back (all 21 tables and the `career_paths` columns removed) and
   re-applied twice; the schema stayed identical.

**Defect found by the replay:** the first seed failed because two Phase 9 EPF evidence fields
exceeded MySQL `varchar(255)`. SQLite does not enforce column lengths. The fields were shortened,
and a test now checks every pack field against the column limits. The seeded database then held 24
rule versions, 0 verified, 5 open notices and the 2 Phase 9 submissions.

The temporary databases were dropped after the run.

## Statutory evidence maintenance

Official texts were retrieved on 1 Oct 2026, from official domains only (egazette.gov.in,
epfindia / epfo.gov.in, incometaxindia.gov.in). Key quotes were re-checked against the downloaded
files.

- Each document is stored with its SHA-256 (`database/data/compliance/evidence/README.md`) and
  attached as the append-only revision `pack:in.php#phase-9-2026-10-01` of EPF v2 and TDS v3.
- No payload changed and no version was published.
- **No rule was verified.**
- **No notice was added or resolved.**
- Payroll, the production gate and enforcement are unchanged, and no bypass was added.

Details: `docs/compliance/india-statutory-rule-verification.md` §10.

### EPFO

| | |
|---|---|
| Evidence retrieved | **Schemes:** EPF Scheme, 2026 (G.S.R. 525(E), supersedes the 1952 Scheme); EDLI Scheme, 2026 (G.S.R. 526(E)); EPS, 2026 (G.S.R. 527(E)) — all 29 Jun 2026, made under the Code on Social Security, 2020.<br>**Rate notifications:** S.O. 3580(E) EPS 8⅓%; S.O. 3581(E) EDLI 0.5%; S.O. 3582(E) EPF 12% (deemed from 21 Nov 2025).<br>**Corrigenda:** G.S.R. 703 / 704 / 705(E).<br>**S.O. 2702(E):** 29 May 2026, ₹15,000, no commencement clause, superseded by S.O. 5109(E).<br>**No new EPFO circular:** the "Wage Ceiling Circular 28.09.2026" re-posts E-1345653. |
| Rule versions | EPF v2 (REVIEW) received the revision. EPF v1 is unchanged: from 29 Jun 2026 its legal basis is the Code and the 2026 Schemes rather than the 1952 Act it names. Its values are consistent apart from the unnotified administrative charges. This is recorded, not changed. |
| Parameters verified | **None** (verification is a second person's act). |
| Now covered by notified text | • `wage_ceiling` (S.O. 5109(E); EPF para 18(3))<br>• `eps_wage_ceiling` (EPS para 4(1), 11(3))<br>• `employee_rate`, `employer_rate` (EPF para 18(2); S.O. 3582(E))<br>• `edli_rate` (S.O. 3581(E))<br>• `round` (EPF 18(5) / EPS 4(3) / EDLI 5(3): nearest rupee, 50 paise up) |
| Parameters unconfirmed (4 gaps; Phase 8: 9) | • `edli_wage_ceiling` — contradicted<br>• `eps_rate` — conflicting texts<br>• `admin_rate`, `admin_minimum` — the scheme leaves the percentage to a notification and none was found; the ₹500 in para 29(2) is a daily late fee |
| Conflicts (recorded, **not resolved by model judgment**) | • **EDLI ceiling:** EDLI para 5(1) applies the clause (89) ceiling (₹25,000 from 17 Sep 2026); v2 carries ₹15,000.<br>• **EPS rate:** EPS para 4(1) says 8.33%; S.O. 3580(E) says 8⅓%.<br>• **Minimum administrative charge:** EPFO FAQ ₹500 / ₹75 against v2's ₹75; no notified text found. |
| Not modelled | • the 10% rate for notified classes<br>• the S.O. 3582(E) exceptions<br>• the 9.49% EPS joint option |
| September 2026 blocker | Still not established by notified text:<br>• EPF para 18(4) uses "wages actually drawn or payable during the month";<br>• EPS para 11(1) prorates pensionable wages per wage-ceiling period for pension.<br>No EPFO ECR instruction was found. This is recorded for the future payroll remediation phase. **Payroll was not changed; the blocking notice stays open.** |
| Second verifier | **Required, not performed.** A qualified ruling is needed on the EDLI ceiling and the EPS wording. Also needed: the administrative-charge notification and EPFO's September instructions. Then a corrected version, notice resolution and verification. |
| Open notices | 3 EPF (Phase 6 v1 ceiling; Phase 7 September; Phase 8 v2 contradictions). The Phase 8 notice's "scheme texts not retrieved" is superseded by this revision; notices are immutable. |

### Income Tax

| | |
|---|---|
| Evidence retrieved | Income-tax Rules, 2026 (G.S.R. 198(E), 20 Mar 2026, in force 1 Apr 2026): rules 204 / 205 (Form 124, the successor of 12BB) / 215 / 219, Form No. 130. No CBDT salary-TDS circular for tax year 2026-27 was found. |
| Rule versions | TDS v3 (REVIEW) received the revision. No new version (the Rules resolve nothing that would justify one). |
| Confirmed transition | Unchanged from Phase 8:<br>• salary paid through 31 Mar 2026 → Income-tax Act, 1961 s.192;<br>• salary paid from 1 Apr 2026 → Income-tax Act, 2025 s.392(1), by date of payment (ITD FAQ Q6.22).<br>The Rules reference the s.392(1) payer (rule 205). |
| Parameters unconfirmed (3, unchanged) | • **New-regime salary TDS rates:** the Rules prescribe no rates; Form 130 asks "Whether opting out of taxation under section 202(1)?".<br>• **Deduction section references:** Form 124 names only 2025-Act sections (123, 124, 130, 131 …), with no correspondence to 1961 sections.<br>• **25% surcharge cap:** the Rules say only "Surcharge, wherever applicable".<br>**Nothing inferred.** |
| Open notices | 2 TDS (Phase 6 v2 legal basis; Phase 8 v3 open questions) |
| Second verifier | **Required, not performed.** |

**Statutory inventory:**
- 24 rule versions: 3 REVIEW, 21 DRAFT, **0 VERIFIED**;
- 5 open notices, the same as at the end of Phase 8.

**Statutory production readiness: NOT DECLARED.**

## Tests

| | |
|---|---|
| Tests | 600 (590 passed + 10 skipped); Phase 8 end: 544 |
| Assertions | 6,092 |
| Failures | 0 |
| Skipped | 10 — the MySQL concurrency suites in the default (SQLite) run; they were run separately on MySQL (below) |
| Architecture | 43 PASS (34 existing + 9 Phase 9 invariants: RMS independence, no payroll / statutory mutation in either direction, Performance read-only through contracts, Learning / Skills only through development plans, no AI or employment decisions, tenant context, resolver-based scope, confidential fields, no deletion of history) |
| Security | PASS. Covers:<br>• tenant isolation and organisation scope (policy + query);<br>• relationship scope (mentor / buddy / project lead), self-service, manager / HR scope;<br>• confidential succession, pool and readiness visibility;<br>• API scopes, IDOR and field filtering;<br>• audit masking and audit access;<br>• bulk authorization. |
| Domain | Career 7, Talent 7, Succession 5, automation 3, screens and actions 3 (Livewire actions through the services) |
| Scale | 4 PASS:<br>• 220-employee pool and review population, each as one audited operation;<br>• constant-query coverage dashboard (4 → 40 positions);<br>• constant-query team career page (2 → 20 reports);<br>• successor skill and certification gaps with requirements resolved once per position. |
| Statutory | 5 Phase 9 tests:<br>• status kept;<br>• EPF coverage and conflicts recorded, not decided;<br>• TDS gaps kept;<br>• file hashes;<br>• pack fields within MySQL limits.<br>The Phase 7 / 8 statutory tests now read their own revision. |
| Pint | PASS |
| MySQL concurrency | 10 PASS on real MySQL (5 Phase 8 + 5 Phase 9), with the audit chain intact afterwards. A mutation check confirmed the readiness lock is required. |
| Migration replay | PASS (above) |
| Audit chain | PASS on all three databases:<br>• **dev:** platform 40, demo 587 events;<br>• **replay:** platform 2, demo 548;<br>• **MySQL concurrency:** platform 10 plus 10 race tenants (168–209 events each), written by concurrent processes. |

Existing tests updated on purpose:
- **Statutory tests:** the Phase 7 / 8 statutory expectations now read the Phase 8 revision by its
  label, and the submission count is now 7.
- **Phase 8 MySQL suite:** it now uses the shared race harness (same races, same slowed events).

## Known Limitations

- **Positions:**
  - A position is a role (plus a unit); there is no seat or headcount entity.
  - Unit scoping matches the unit's own dimension on the current position, not child units.
- **Workflows:** no approval workflow is configured by default for talent reviews or succession
  plans. Without one, completion and activation are direct (and audited).
- **Assessment model and decisions:**
  - The default talent assessment model is two dimensions with three levels; there is no 9-box
    grid visual.
  - Review decisions are recorded; follow-ups (pool, successor, development) are separate manual
    actions by design.
- **Readiness:** labels expire after 12 months. Expiry triggers a reminder to the assessor, not an
  automatic status change.
- **Analytics:** successor skill and certification gaps read one Phase 8 skill profile and
  certificate status per successor, in chunks. They are shown on the Analytics page only.
- **Default permissions:** managers do not get `succession.team` by default, and no default role
  has `succession.own_candidacy`. Tenants grant them deliberately.
- **Development actions:** neutral titles are enforced by a keyword guard. A creative title could
  still hint at candidacy.
- **API:** read-only; there are no career, talent or succession writes through the API.
- **Statutory:** the EPF v2 EDLI ceiling, EPS rate wording and administrative charges are
  unresolved; TDS v3 has its three open questions; the September 2026 day-split is not implemented.
  None of this is verified.
- **MySQL concurrency suite:** it still needs `PEOPLEOS_MYSQL_CONCURRENCY_DB`.
- **Repository size:** about 36 MB of official PDFs as evidence copies (Phase 9 added 11 files,
  19 MB, including the 10 MB Income-tax Rules).

## Deferred Work

- **Statutory (future payroll remediation and verification):**
  - qualified rulings on the EDLI ceiling and the EPS rate wording;
  - the administrative-charge notification;
  - EPFO's September 2026 ECR instructions and the split-period calculation;
  - the s.202 → s.392 linkage, 2025-Act deduction references and the surcharge cap;
  - corrected versions;
  - second-person verification and notice resolution.
- **Talent and succession:**
  - an internal mobility marketplace and matching;
  - a 9-box visual and calibration of talent assessments;
  - succession scenario modelling;
  - headcount / position seats;
  - employee-facing candidacy communication workflows;
  - API writes.
- **Excluded by the Phase 9 prompt (§58):**
  - Workforce Planning;
  - Compensation;
  - payroll statutory remediation;
  - AI employment decisions;
  - RMS integration;
  - production deployment.
