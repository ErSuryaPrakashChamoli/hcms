# PeopleOS security and architecture invariants

These hold for every change (Phase 0.2, extended in Phase 0.3 by the architecture contract). The architecture tests in `tests/Feature/Architecture/ArchitectureTest.php`
enforce the mechanical ones on every CI run; the rest are reviewed.

## Tenancy

1. Every domain model uses `BelongsToTenant` except the six platform-level models: `Tenant`, `User`
   (looked up before a tenant is known; listings use `forCurrentTenant()`), `Permission` (platform
   catalogue), `AuditEvent` and `AuditEventChange` (nullable tenant for platform events; reads scoped
   explicitly; writes only through `AuditRecorder`), `ComplianceRule` (platform-owned statutory rules) and `ComplianceRuleVerification` (its append-only verification history, Phase 5; audited through `AuditRecorder` platform events).
2. With no tenant bound, tenant-owned queries return nothing (`TenantScope` adds `1 = 0`); creating a
   tenant-owned record without a bound tenant throws; moving a record between tenants throws.
3. `TenantContext::bypass()` / `withoutTenancy()` appear only in the allow-listed platform services
   (audit recorder and verifier, access scopes, API key resolution, tenant provisioning, SSO login,
   the queue tenant middleware). Adding a new site requires editing the allow-list in the test.
4. Tenant resolution runs before route-model binding (`ResolveTenant` and `AuthenticateApiKey` are
   prepended to the middleware priority list ahead of `SubstituteBindings`). Controllers that look
   records up by id do so after the tenant is bound.
5. Every queued job under `app/` implements `TenantAwareJob` (`tenantId()`) and returns
   `[new BindTenantContext]` from `middleware()`; the worker re-binds the tenant before `handle()` and
   restores the previous context afterwards (architecture test).
5a. Scheduled commands run with `withoutOverlapping()->onOneServer()` and iterate tenants explicitly
   with `runAs`; they never query tenant-owned models before binding a tenant.
5b. Tenancy violations (`MissingTenantException`, `TenantMismatchException`) render as a plain 403
   without tenant details.

## Organisational access scope (ABAC)

6. `user_access_scopes` rows restrict a user to companies / locations / business units / divisions /
   departments / teams. No rows = tenant-wide (subject to permissions). Rows within one dimension are
   OR-ed; dimensions are AND-ed. A user always reaches their own employee record and (relationship
   scope, ADR-0004) every employee who reports to them today through any reporting type.
7. The scope is enforced at the query layer by the `AccessScope` global scope on `Employee`, on every
   employee-linked model (`ScopedByEmployee`) and on organisation units (`ScopedByOrganisation`), so
   Filament tables, relation managers, global and table search, report datasets, CSV exports,
   assistants and services all see the same restricted set. Record-level policies (`PermissionPolicy`,
   `TicketPolicy`, `EmployeeDocumentPolicy`) check `AccessScopes::allows()` as defence in depth and
   refuse any record whose `tenant_id` is not the bound tenant.
8. System contexts (console commands, queue workers, API keys) have no authenticated user and are not
   user-scoped; they remain tenant-bound. API keys are tenant-wide integration credentials; per-key
   organisational scope is a deferred item.
9. Hidden navigation is never an authorisation boundary; every resource model has a registered
   policy (architecture test) and every state change goes through a domain service.

## Files

10. Employee documents and ticket attachments live on the private documents disk and are served only
    through temporary signed routes that re-authorise the record after the tenant is bound and
    write a `DOWNLOAD` audit event. `Storage::url()` is never used in application code.

## Audit

11. Every `Auditable` model writes field-level before/after changes with actor, roles, tenant, IP,
    user agent, source, request id and reason. Sensitive attributes are masked.
12. Audit rows are append-only: model events and the Eloquent builder both refuse update, delete and
    force-delete; the per-tenant hash chain is verified by `peopleos:audit:verify`.
13. Bulk operations run inside `AuditRecorder::operation()`, which stamps every event with one
    `operation_id` and writes a `BULK_OPERATION` summary (entity count, success and failure counts,
    affected ids, error).
14. Sensitive views (bank, statutory, payslips, grievances, documents, AI answers) are audited with a
    purpose.

## Statutory data

15. Compliance rules carry `verification_status`; packs load as `illustrative`. With
    `peopleos.compliance.enforce_verified_rules` on (default in production) payroll cannot be
    finalized on illustrative rules. Nothing in the repository claims legal compliance.

## Historical integrity and versioning

16. Historical business facts are never overwritten: positions, reporting, salary, organisation units,
    schedules, policy versions, rules, custom fields and addresses are effective-dated; changes create
    new rows. Future-dated rows cannot rewrite the past.
17. Published policy, workflow and form versions are immutable; a running workflow instance stays on
    the version it started from; submissions reference their form version.
18. One Person has exactly one Employee per tenant (database unique constraint); re-employment
    re-activates that Employee rather than creating another.

## Integration and AI

19. PeopleOS carries no RecruitmentEdge / RMS namespace, model, migration, database connection or
    package (architecture test). External systems are represented by external references and mappings;
    PeopleOS owns its primary keys.
20. No integration path bypasses PeopleOS authorisation: API keys carry scopes and bind the tenant
    before any lookup; inbound events (future) are idempotent and audited; outbound webhooks are signed.
21. AI assistants run as the user through the same tenant, permission, scope and classification layers,
    receive redacted facts only, never write, and are logged.

## Data classification

22. `config('peopleos.data_classification')` lists the highly sensitive, financial, statutory and
    confidential classes; every highly-sensitive attribute is masked in audit, excluded or encrypted
    (architecture test). Tenant administrators are not implicitly entitled to sensitive fields.

## Phase 5 additions

- Statutory output rows (`statutory_return_actions`, `statutory_snapshots`, `statutory_reconciliations`
  and the per-type return runs, entries and revisions) are derived from finalized payroll or
  append-only. They are not individually `Auditable`; every lifecycle step is a
  `STATUTORY_OUTPUT_*` audit event plus a `statutory_return_actions` row. Snapshots, actions,
  reconciliations and revision records refuse updates and deletes; return lines are frozen once the
  return leaves the editable statuses.
- Statutory identifiers (registration numbers, UAN, ESI IP number, PAN on returns) are encrypted,
  hashed with the app key for uniqueness and shown masked; unmasking needs
  `compliance.sensitive.view` and is recorded as `STATUTORY_OUTPUT_ACCESSED`.
- Statutory returns are visible only with `compliance.returns.view` and within the company access
  scope; a reporting line never grants access.

## Phase 14 additions

23. **Livewire requests carry the full tenant chain.** `ResolveTenant` and `EnforceSecurityPolicy` are
    persistent panel middleware, so `/livewire/update` binds the tenant and enforces the IP allow-list and
    idle timeout like a page load (`TenantIsolationHardeningTest`).
24. **User ids from input are resolved inside the tenant.** Form pickers and actions use
    `User::forCurrentTenant()`; talent review participants are validated in the domain; report
    schedule and event-bridge recipients are filtered to the tenant.
25. **Identity checks see the whole tenant, disclose minimally.** `PersonMatcher` and
    `EmployeeCodeGenerator` ignore the caller's organisation scope (never the tenant). A match outside
    the caller's scope is returned without name, code or ids, and the caller cannot override it.
26. **SCIM never leaks another tenant's login.** A userName held anywhere returns 409 with a neutral
    message (create, replace, patch).
27. **Scoped audit reads.** Change Intelligence and the audit list apply the organisation scope
    through employee- and person-linked records; classified values are masked without
    `employee.sensitive.view`.
28. **AI data boundary (ADR-0016).** Prohibited data (passwords, keys, tokens, secrets) never leaves
    PeopleOS and is not stored in the AI log. Restricted data leaves only under the tenant's
    `restricted` policy. Each external call is audited with counts only; the AI log is readable only with
    `ai.admin` (or by its author).
29. **Logs never carry secrets or protected identifiers.** Every channel has the
    `RedactSensitiveLogData` tap (keys and values); slow-query logs never carry bindings; failed-job logs
    carry the exception class.
30. **Files are served only through authorised, audited routes.** `local.serve = false`. Grievance
    evidence is tenant-prefixed and fingerprinted. Every document download is audited. A stored report
    export is re-downloadable only by its producer (or the owner, for a scheduled run).
31. **Queue and scheduler.** A tenant-aware job without a tenant fails; suspended tenants' jobs and
    scheduled runs are skipped (retention excepted). Per-tenant failures are isolated.

## SaaS.2 additions (foundation hardening)

32. **Account security is enforced per request.** `EnforceAccountSecurity` (persistent panel middleware, and on
    every protected download) requires a session to have proved its authenticator (`MultiFactor::isVerified`),
    sends users who must use MFA and have none to set-up, and sends unverified local addresses to verification.
    MFA "required" is never a Filament route-time flag. Every login (password, remember-me, SSO) starts unproven
    (`SecureNewSession` on the `Login` event).
33. **A dead session is signed out, not refused.** `EnsureSessionIsValid` runs before authentication (middleware
    priority) and signs out an inactive user, a tenant user of an inaccessible tenant, or a session whose session
    epochs (`SessionSecurity`) changed. Suspension, MFA reset, password reset and "sign out everywhere" raise
    epochs and clear remember-me tokens; no session rows are deleted.
34. **Protected web routes run the full stack.** Any route serving tenant data outside the panel uses
    `web, auth, auth.session, EnsureSessionIsValid, ResolveTenant, EnforceSecurityPolicy, EnforceAccountSecurity`
    (see `routes/web.php`), never `auth` alone.
35. **Operators reach a tenant only through a grant.** `ResolveTenant` binds a platform operator to a tenant only
    through a valid, unexpired `PlatformTenantAccess` grant (reason, optional reference, time box), audited on the
    platform chain and the tenant's chain; everything recorded during it carries `platform_access_id`. No tenant
    identity is ever created for an operator. Platform `tenant.*` keys are never effective for tenant users.
36. **Identity links are one-time and reveal nothing.** Invitations store only a SHA-256 of their token and work
    once for one invited user of an accessible tenant; password resets are silent, token-checked before any
    policy message, locked against double use, and never queued. Administrators never choose or see passwords.
37. **Status changes go through their service.** Tenant suspension and reactivation use `TenantSuspensions`
    (conditional update, never a lock on the tenants row); the tenant edit form cannot change the status. A
    `Tenant`'s own changes are audited in its own chain (`auditTenantId()`); `AuditRecorder` writes to the
    platform chain only when asked (`platform: true`).
38. **Outbound workflow webhooks are signed** with the PeopleOS scheme (`X-PeopleOS-Timestamp`,
    `X-PeopleOS-Signature`, a delivery id stable across retries); configured headers cannot override them.

## SaaS.3 additions (entitlements, shadow mode)

39. **Entitlements never replace authorisation.** A commercial entitlement answers "does this tenant have the
    capability?"; permissions, scopes, field security and policies alone decide what a user may do. No permission,
    policy, gate, scope or navigation item reads an entitlement.
40. **Shadow mode never blocks.** HCM code calls `Entitlements::observe()` and ignores the result; it never throws, and
    `Decision::enforced()` is always false. Commercial evaluation fails open (UNKNOWN, logged); security never does.
41. **Only platform operators change entitlements**, through `EntitlementConfiguration`, with a reason, audited on the
    tenant chain and the platform chain; history is never rewritten (changes start today or later). Missing
    configuration is UNKNOWN, never DENY.

## SaaS.4 additions (commercial plans, shadow mode)

42. **Plans never reach authorisation or HCM code.** Only the entitlement services and the two platform pages
    reference plan or assignment models (architecture test). A plan neither grants nor removes a permission.
43. **Only platform operators change plans and assignments**, through `PlanCatalog` and `EntitlementConfiguration`,
    with a reason, audited on the platform chain (and on the tenant's chain for assignments). Tenant administrators,
    whatever their roles, are refused in the services and cannot open the pages.
44. **A published plan version never changes**, and neither does what it says about any capability. A tenant
    assigned to a version keeps it when the catalogue evolves. Assignments start today or later; history is kept.
45. **No plan switches a protected capability off**, and the core and security controls cannot appear in a plan.
    Tenants without a plan stay UNKNOWN: no default plan is ever assigned silently.

## SaaS.5 additions (packaging, limits, pricing boundary)

46. **Commercial entitlement never enters authorisation, now enforced by a test.** Only an exact list of files outside
    the entitlement domain may use it: the 13 shadow call sites, the platform pages and commands, the container
    bindings and the retention purge. Identity, roles, permissions, scopes, policies and tenancy never do. Every call
    site observes and ignores the result (invariant 39 made mechanical).
47. **A limit's states never collapse.** Unlimited, not included (its module is not entitled), not set (no agreed
    limit), no commercial answer, unmeasured, within and exceeded each have their own outcome and reason. Missing
    configuration is still never DENY.
48. **No price in a plan, no price in an entitlement decision.** The plan tables carry no money columns, and no
    entitlement code touches pricing or billing (architecture test).

## SaaS.6 additions (commercial subscriptions and trials)

49. **Only platform operators change a subscription**, through `CommercialSubscriptions`, with a reason, audited on the
    tenant chain and the platform chain with the state and plan version before and after and the effective date.
    Periods are append-only (model guards): never edited, never deleted, voided only if they never took effect.
50. **One writer and one source of truth for the plan in force.** A subscription-managed tenant refuses manual plan
    assignment; the subscription's projection always equals its entitled periods (asserted after every MySQL race).
51. **A lapse is never a denial.** An expired or cancelled subscription means no plan in force (UNKNOWN). The commercial
    status never changes an entitlement outcome. Nothing that authorises references the subscription context, and the
    entitlement engine never depends on it (architecture tests).
52. **Commercial and technical lifecycles stay apart.** No SaaS.6 code changes `tenants.status`; the settlement covers
    suspended tenants without touching them.

## SaaS.7 additions (billing, tax and payments)

53. **Only platform operators change billing, tax and payment records**, through their services (`OperatorChange`: operator and reason
    in the service), audited on the platform chain and, for tenant records, the tenant chain. Tax rules are verified by an operator
    other than their author. Every tenant user is refused every billing page.
54. **Money is exact and currency-explicit.** Integer minor units with an ISO-4217 code; no float, `round()` or FX in billing, tax or
    payment calculation (architecture test); amounts of different currencies are never combined.
55. **An issued invoice never changes**: number (gap-free, from the series covering the day), tax, totals and snapshots are fixed in
    one transaction; model guards refuse any edit or deletion of invoices, lines and tax lines.
56. **Tax fails closed.** Without a determiner, a verified rule in force or a priced outcome, issue is refused; no default tax exists.
    Country-specific code stays in its jurisdiction module; nothing is reported as legally "supported" by software.
57. **A payment is confirmed only by a verified provider event, a server-side provider fetch or an operator-recorded transfer.**
    Webhooks are signature- and timestamp-verified over the raw body, stored once per event id (a different body under a known id is
    refused), encrypted at rest, and resolve the tenant only from the verified provider reference; there is no browser confirmation
    route. Only an exact amount in the invoice's currency settles it.
58. **Billing never authorises.** Payment state, invoices and prices never reach entitlements, authorisation, subscriptions or HCM;
    only payment reconciliation runs for suspended tenants (`RunsForSuspendedTenants`, exact allow-list).

## SaaS.7 completion additions (approved commercial decisions)

59. **Dual control for money going out or coming off the books.** Price publication, credit notes (incl. an invoice's
    cancellation), refunds, invoice write-offs and payment-exception resolutions run only through an approval request that
    another platform operator approves; the operation executes in the approving transaction. Executors refuse anything but an
    approved, unexecuted request of their action (`FinancialApprovals::claim`), and the approval model refuses self-approval,
    approved rows created directly and any second decision or execution. Approvals are platform records no tenant user reaches.
60. **A billed quantity never changes.** A billing period freezes its quantity, evidence (peak day, employee ids and their
    SHA-256, daily counts, method) and amount, and its invoice line copies them; later HR data never recalculates it (model
    guard: only the draft link moves, when a discarded draft is replaced from the frozen period). One period per subscription,
    kind and start (unique index); the run only drafts.
61. **No silent repricing.** A published price version never changes; an increase reaches an existing subscriber only through a
    re-pin backed by a recorded notice at least 30 days earlier, at a period start or annual renewal; annual terms change only
    at renewal.
62. **Corrections are documents, never edits.** Credit notes are numbered in their own series, mirror the invoice's original
    tax, never exceed it in total, and never change; refunds never exceed their credit note or their payment.
63. **Settlement is not the invoice.** A settlement snapshot (amount, currency, implied rate, source) is written once beside a
    payment; invoice and payment amounts and currencies never change for it, and no amount is ever converted.
64. **A short payment is never assumed to be TDS.** Only an operator's declaration (amount from the customer's statement, Indian
    supplier and customer, INR) changes the amount due; the invoice is paid only with its certificate recorded.
65. **No live payment provider.** Razorpay is enabled only with `rzp_test_` keys and a webhook secret, outside production; its
    webhooks are HMAC-verified over the raw body and applied once per event id; it is the only code that calls a provider over
    HTTP (architecture test).

66. **No hardcoded commercial value.** Prices, customer deals, tax rates, statutory parameters and Markedge policy are read
    from versioned data (`plan_price_versions`, `negotiated_price_versions`, `tax_rules`, `configuration_versions`, policy
    defaults in `config/peopleos.php`); code holds algorithms only. A value that is missing or expired for a day is refused
    ([CONFIGURATION_MISSING], TAX_CONFIGURATION_MISSING, NO_PRICE_CONFIGURED), never assumed or taken from an older version.
67. **Missing tax configuration is never 0 %.** No rule, an unverified, rejected, retired or expired rule, an unknown condition
    or an unresolved place of supply refuses the invoice with its reason code; a zero-rated or not-taxable outcome is a
    verified rule's explicit treatment with its conditions met.
68. **Two operators for every commercial configuration change.** Tax rules and the statutory dataset (verified by an operator
    other than the loader, author or submitter), negotiated price publication and policy changes (executed only by the
    approval desk for another operator); a policy change is never approved after its start date.
69. **Customer deals are the customer's alone.** Negotiated prices are tenant-owned and fail-closed; another tenant's deal is
    not found when pinning terms; a deal never changes the standard catalogue or another customer's price.
70. **No converted or borrowed price.** A deal is in its market's currency and is never based on another currency's price;
    billing terms only pin a price of the tenant's billing market; the INR reporting value of an export invoice is a recorded
    fact with its rate source, never used to compute an amount.
71. **Statutory sources are links, not code.** A rule's or parameter's source URL is an `https://` address (validated), shown
    with `rel="noopener noreferrer"`; the dataset is read only from the shipped directory by version (`YYYY.MM`).
72. **History never moves.** Issued invoices keep their prices, price source, tax legs, rule versions, wording and reporting
    value in their rows and snapshot; periods freeze their price source and discount; published price, deal and rule
    versions are immutable; a change is always a new version from a date.

73. **A selling entity is changed by two operators.** A supplier profile version (identity and registrations, incl. the LUT)
    applies only after another operator approves it; pending, rejected and withdrawn versions are never used for invoices or
    numbering, and the model refuses an approval by its maker.
74. **Local rates are never assumed.** Where a state rule requires local rates, the customer's recorded local tax jurisdiction
    and its verified rule decide; no locality, no rule or an unverified rule refuses the invoice; the state rate never stands
    alone and one locality's rule never applies to another.
75. **An invoice records the configuration it used.** Policy versions (or shipped defaults), the due-date source, the
    numbering limit and the local jurisdiction are frozen with the invoice; later configuration never changes it.
76. **No business value in commercial code** (architecture test): no tax rate, literal amount, GSTIN, PAN or SAC in billing,
    tax, payments or their pages; one reader of the policy defaults; no environment setting duplicates a policy.
77. **Chargebacks are never silently dropped.** Until they are processed (B-12, with the provider's activation), a verified
    dispute event is stored, marked `chargeback_not_processed`, logged and flagged to operators.
78. **Tenant lifecycle is authorised in the service.** Suspend, reactivate, provision, edit a tenant, send its first
    administrator's invitation and release its deferred work refuse anyone but a platform operator (the console excepted),
    whatever page or path reaches them; each records a reason on the tenant chain and the platform chain.
79. **Queued work is never lost to a suspension.** A suspended tenant's job is stored encrypted and dispatched once after
    reactivation; nothing is released while the tenant is suspended; payment-provider reconciliation still runs.
80. **Reactivation restores, never upgrades.** A tenant returns to the status the suspension interrupted.
81. **No pending invitation nobody received.** An invitation exists only if its e-mail was accepted by the transport; a
    failure is recorded and leaves the tenant provisioned.
82. **A notification is never resent automatically after an unknown outcome**, and a person resends a failed one only with
    `notification.resend` and a reason, once.
83. **Every response carries the framing, sniffing and referrer headers**; HSTS only over HTTPS.
84. **Only a verified API key claims its rate-limit bucket**; everything else is limited per IP.
85. **Uniqueness checks on tenant-owned tables never look at other tenants** (architecture test).
86. **The platform audit trail shows the platform chain only**, and the operations page shows counts and states only.
87. **Audit integrity has recorded, recent evidence**: a broken chain fails readiness; no result within 48 hours is STALE.

SaaS.9 additions (tenant data exit: `docs/architecture/saas-9-offboarding.md`):

88. **Every table is classified exactly once** in the data inventory (category, child or platform). A new table fails
    the architecture test until it is classified. Owning a `tenant_id` never makes a table deletable by itself.
89. **No tenant data is deleted unless every gate holds.** The tenant is closed. An export was delivered or waived, and
    none is generating. No open financial item exists. The category's approved retention has ended, no legal hold covers
    it, and nothing kept depends on it.
90. **Deletion takes two operators, an unchanged plan and the switch.** The maker never approves. The checker approves
    the fingerprinted plan only if nothing changed. Nothing is planned, approved or executed while
    `PEOPLEOS_TENANT_DELETION_ENABLED` is false (the default).
91. **Nothing a legal hold covers is deleted after the hold commits.** Every deletion batch and every retention purge
    batch re-reads the holds under the exit lock, immediately before it deletes.
92. **Markedge's billing, invoice, payment and subscription records are never deleted with a tenant**, and offboarding
    never cancels, settles or clears a financial obligation.
93. **A closed tenant has no continuing subscription**, and no new entitled period can start while it is closed or
    deleted.
94. **An export and a deletion never overlap.** No export is requested once a deletion is approved or has started. A
    deletion never starts while an export is queued or generating.
95. **An export contains this tenant's rows only, never a secret column.** Packages are encrypted on a private disk and
    reachable only through a signed link of minutes, bound to the person, inside a valid session. Every download and
    passphrase reveal is audited.
96. **No retention period and no legal minimum is invented.** A policy is never shorter than a configured legal minimum.
    A category without an approved policy is never deleted, and an absent minimum shows as `LEGAL_MINIMUM_NOT_CONFIGURED`.
97. **The audit chain is deleted only whole, newest first, by the deletion executor**, after its head hash is recorded.
    What remains is always a valid chain prefix.
98. **A tenant whose deletion has started can never be reopened**, even after the run stopped.
99. **No copy outside PeopleOS is reported deleted without an operator's recorded confirmation**, and a confirmation is
    final.
100. **No tenant user and no API key can close, hold, change retention, plan, approve or execute a deletion.** The
     services refuse anyone but platform operators, and no API route reaches the exit.
101. **Storage prefixes used for deletion are exact**: never a wildcard that could reach platform or another tenant's files.

SaaS.9 closure additions:

102. **An entered but unverified legal minimum never makes data deletable**: its category stays retained
     (`LEGAL_MINIMUM_PENDING_VERIFICATION`) until a verified version (source, reviewer, date) replaces it.
103. **A customer's contract never deletes anything.** A contractual minimum only extends retention; a contractual
     deadline the retention runs past is a recorded conflict for people to resolve.
104. **Decided retention policy versions and contract terms never change**, and an approved deletion plan is never
     rewritten by a later version.
105. **No certificate claims complete deletion while any external copy is unconfirmed**
     (`complete_including_external_copies` is false, and `external_copies_outstanding` lists them).
106. **A tenant deletion leaves no export package file of that tenant**; the export's record and checksums stay.
107. **No credential value is ever printed, logged, committed or written into documentation**; credential checks report
     yes/no only.

SaaS.10 additions (self-service signup):

108. **Signup never creates or names a platform operator**, and the first administrator of a signup tenant holds the
     explicit Account Owner role, never `*`.
109. **No tenant user grants Tenant Super Admin, or writes permissions beyond their own into a role, unless they are a
     Tenant Super Admin**; platform operators and the console only otherwise (user form, role editor, blueprint import,
     SSO default roles).
110. **One application provisions at most one tenant**: a fenced claim, a token re-checked under the row lock, and unique
     tenant link, slug and owner address.
111. **Provisioning commits tenant, owner and trial together or not at all; e-mail goes out only after the commit.**
112. **Nothing an applicant sends chooses a tenant, a slug, a status, a plan version or a trial length**: all are
     derived on the server from approved configuration.
113. **Signup answers the same for every address**, in the same time; account existence is told only to the mailbox.
114. **A signup link is stored as its hash, works once, expires, and is consumed only by a POST**; consuming it
     regenerates the session id.
115. **Signup never starts a paid subscription**: only the accepted offer's trial, through `startSignupTrial`.

SaaS.11 additions (production assurance):

116. **Every change of a role's permissions is audited** (`ROLE_CHANGED`, with the keys granted and removed), whether it
     comes from the role editor, role creation or a blueprint import (`RoleGrants::recordPermissionChange`).
117. **A release switch is never on in production without the owner's recorded authorisation**: registration and tenant
     deletion need their authorisation reference. Live payments, a demo seed password and HSTS under a year are refused
     outright (`ProductionConfigValidator::releaseGates`).
118. **PeopleOS is never indexed**: every response carries `X-Robots-Tag` (`noindex, nofollow` by default), and
     `robots.txt` disallows everything.
119. **Production readiness fails while any platform operator who can sign in has no MFA enrolled.**

SaaS.12 additions (release programme):

120. **A restore never silently brings back a deleted tenant.** Every certified deletion is in the deletion register
     outside the database, and reconciliation contains a tenant restored past its deletion (suspension, recorded
     re-application) before it serves traffic.
121. **Restore reconciliation never deletes, and never acts on a doubtful entry** (identity mismatch, failed hash or
     signature). Re-application is the ordinary deletion workflow, with holds, retention, maker-checker and cooling-off.
122. **A deploy never gives back a permission a tenant removed from a system role**; permissions a release introduces
     are audited (`ROLE_CHANGED`, no actor).
123. **No seeder runs in production**, whatever the database is called.

### Phase 14 review of raw queries and scope bypasses

| Pattern | Count | Review result |
|---|---|---|
| `TenantContext::bypass()` / `withoutTenancy()` | 26 files (SaaS.9: +8, the exit services `TenantOffboardings`, `TenantExports`, `LegalHolds`, `TenantDeletions`, `DeletionPlanner`, `DeletionExecutor`, `OffboardingDirectory` and `OffboardingAudit`: operators' work on one named tenant, every query filtered by that tenant's id, on the architecture allow-list; SaaS.8: +3, `DeferredTenantJobs` (a suspended tenant's deferred jobs, always filtered by that tenant), `PlatformOperationsOverview` (operators' grouped per-tenant counts) and `PlatformAuditTrail` (`tenant_id IS NULL` only); `PlatformReadiness` no longer bypasses; SaaS.2: +1, SaaS.3: +1; SaaS.7: +3, `BillingDirectory` and `PaymentDirectory` (operators' cross-tenant invoice and payment headers) and `ProviderEvents` (resolving a payment from a verified provider reference); SaaS.7 configuration: +0 files, one more read in the allow-listed `BillingDirectory`: the platform-chain trail of configuration changes; SaaS.6: +1, `SubscriptionDirectory`: the operators' cross-tenant overview of names and commercial states, and the platform audit chain; SaaS.4: +0 files, two more reads in the allow-listed `EntitlementDiagnostics`: tenants per plan version, counts only, and Markedge's platform audit chain) | Platform services only, each on the architecture allow-list: audit recorder / verifier (cross-tenant chains), API key resolution (before a tenant exists), SSO connection lookup by slug, tenant provisioning, access-scope rows, job tenant binding, health and readiness (counts only), invitation token lookup (before the invitee is signed in), the operators' cross-tenant entitlement shadow summary (counts only) |
| `withoutGlobalScope(AccessScope::class)` (345 call sites in 110 files) and `AccessScope::withoutScoping()` (32) | Mechanically each removes only the **organisation** scope; the fail-closed tenant scope stays. Reviewed by category (not line by line): domain services checking a target by id after an explicit `AccessScopes::allows` check, background sweeps, aggregate analytics with small-group suppression, and identity checks (Phase 14: tenant-wide on purpose) |
| `withoutGlobalScopes()` (all) | 1 | `NumberSequences::highest`. Phase 14 narrowed it to the access scope with an explicit `tenant_id` filter |
| `DB::table()` | 17 (SaaS.9: +4) | Each carries an explicit tenant id or a key of a tenant-scoped row: employee-code sequences, scheduler claims, audit-chain locks (platform), engagement answer aggregates (Phase 14 added explicit `tenant_id` filters), EPF revision rows by return id, health counts (platform, counts only). SaaS.9: `ExportPackager::rowsOf` (the one tenant filter for every inventory table: `tenant_id`, or a child's parent rows), `DeletionPlanner` (counts and dependency `EXISTS` through `rowsOf`), `DeletionExecutor` (batched deletes by ids read through `rowsOf`; the audit chain by `tenant_id`) and `TenantDeletions` (which external systems the tenant used, by `tenant_id`) |
| Interpolated SQL fragments (`selectRaw` / `whereRaw` with `{$…}`) | 5 | Interpolated values come from code constants or allow-listed dimension names (workforce dimension columns, movement-type CASE built from config keys, PersonMatcher column names); user input is always bound |
| `DB::select` / `statement` / `unprepared` | 1 | Health `select 1` |
