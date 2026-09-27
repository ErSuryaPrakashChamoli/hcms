# Phase 12 — Employee Experience

Blueprint §48–§54, §57, §58, §121 Phase 12. Built 2026-09-27.

## What exists

**HR Service Desk (`App\Domain\ServiceDesk`)** — `TicketCategory` (SLA hours, first-response hours, default agent or agent role, escalation role, optional workflow key); `Ticket` (`TKT-YYYY-NNNNN`, priority-adjusted SLA clocks, `new → open → pending → resolved → closed`, satisfaction 1–5, linked knowledge article, linked workflow instance); `TicketComment` (internal notes hidden from the employee, optional attachment). `ServiceDesk` service: `open` (least-loaded agent in the role, workflow start by category), `assign`, `comment` (first-response stamp, employee replies reopen pending/resolved), `waitOnEmployee`, `resolve`, `close`, `reopen`, `tick` (escalate SLA breaches once to the escalation role; auto-close resolved tickets after `servicedesk.auto_close_days`). Seven starter categories per tenant.

**Grievances (`App\Domain\Grievance`)** — `GrievanceCategory` (confidential flag, anonymous allowed, handler role ids such as the Internal Committee, SLA days); `Grievance` (`GRV-YYYY-NNNNN`, optional anonymous complainant, severity, `submitted → under_review → investigating → action_taken → resolved → closed`, `withdrawn`, explicit `access_user_ids`); `GrievanceNote` (note / evidence / action / decision / employee message, employee-visible flag). `Grievances` service: `raise`, `canAccess` (assignee, granted users, handler-role holders, the named complainant; grievance managers only for non-confidential categories or categories with no handler roles yet), `recordAccess` (every case view is audited as sensitive), `assign`, `grantAccess` (audited `PERMISSION_CHANGED`), `addNote`, `setStatus`, `resolve`, `close`, `withdraw`, `overdue`. Seven starter categories.

**Knowledge base (`App\Domain\Knowledge`)** — `Article` (markdown body, category, tags, rule-engine audience, requires acknowledgement, mandatory reading, slug), `ArticleVersion` (snapshot per publish), `ArticleRead` (read and acknowledgement per version). `KnowledgeBase` service: `publish` (version bump + snapshot + notify audience when acknowledgement is required), `archive`, `visibleTo` (audience + search), `recordRead`, `acknowledge`, `pendingAcknowledgements`, `stats`.

**Communication centre (`App\Domain\Communication`)** — `Announcement` (type announcement / circular / newsletter / policy / instruction, audience rules, pinned, acknowledgement, publish and expiry times), `AnnouncementRead`. `Communications` service: `publish` (in-app notice to the audience when live), `feedFor`, `markRead`, `acknowledge`, `pendingAcknowledgements`, `stats`.

**Needs Attention (`App\Domain\Experience\Services\NeedsAttention`)** — one personalised list per person (§57). Employee: policies and announcements to acknowledge, overdue and due-soon learning, reviews to complete, tickets waiting on them or resolved, assets to acknowledge, workflow tasks, missing bank account, missing PAN, missing tax declaration. Manager: leave approvals, regularisations, manager reviews, workflow tasks, assigned tickets and grievance cases, one-on-ones to write up, team learning overdue.

**Events** — `ServiceDeskEvent` (`servicedesk.ticket.*`, `grievance.*`, `kb.article.published`, `communication.published`) carries recipient user ids; the notification bridge fires rules first and otherwise sends in-app. `servicedesk.ticket.created` and `grievance.raised` are also workflow trigger events.

**Automation** — `peopleos:servicedesk:tick` hourly: ticket escalation and auto-close, overdue grievance reminders (once a day per case).

## Admin UI

- **Me** (the MY PEOPLEOS portal): **My Day** (greeting, check in / out, today's attendance, leave balances, latest payslip, counters, Needs Attention, quick actions Apply leave / Regularise / View payslip / Ask HR), **My Team** for anyone with current direct reports (headcount, present / on leave / absent, manager Needs Attention, team table), **My requests**, **My grievances**, **Knowledge base**, **Announcements** feed with read and acknowledge.
- **Service Desk**: Tickets (queue with filters for mine / past SLA; view page with reply, assign, wait, resolve, close & rate, reopen; conversation tab), Categories & SLAs.
- **Grievances**: Cases (only cases the viewer can access; view page audited; case file tab; add to case file, status, assign, grant access, resolve, close, withdraw), Categories & handlers.
- **Knowledge**: Articles (write, publish new version, archive; read / acknowledged counts). **Communication**: Announcements (compose, publish, archive; read / acknowledged counts).
- Employee 360 gains a **Requests** tab. Global search covers tickets, articles and assets in addition to employees. The People Control Centre shows open tickets past SLA and open grievances.

**Permissions** — `servicedesk.view|manage|request`, `grievance.view|manage|raise`, `kb.view|manage`, `communication.view|manage`. HR Admin template holds all; Manager and Employee gain `servicedesk.request`, `grievance.raise`, `kb.view`, `communication.view`.

## Conventions

- Grievance visibility is decided per case by `Grievances::canAccess`; the resource query filters by the allowed ids and never by a blanket permission. Anonymous cases have no `employee_id` and no actor in the audit trail.
- Article and announcement audiences reuse the rule engine (`EmployeeRuleContext`); an empty audience means everyone employed.
- Portal actions call the same domain services as the admin pages (§48 "same domain services, same rules, same audit").
- Tests: `tests/Feature/Experience/{ServiceDeskTest,KnowledgeAndCommunicationTest}.php`, `tests/Feature/Admin/ExperiencePagesRenderTest.php`.

## Known gaps / deferred

- No dedicated notification centre page; Filament's database-notification bell is the inbox.
- Newsletters are announcements of type newsletter; no email digest rendering yet.
- Article full-text search is `LIKE`-based; knowledge suggestions while typing a ticket are not built.
- Mobile remains "mobile-ready" through the responsive panel; no native app or portal API endpoints beyond `/api/v1`.
