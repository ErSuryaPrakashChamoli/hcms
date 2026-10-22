# PeopleOS training library: accuracy register

**For:** reviewers who need to check that what the decks say is true, and maintainers who must update the decks when
the product changes. **Prepared:** 21 October 2026, application commit `72c96a73` (branch
`feature/oct_20_people_os_phase_1`), training database `hcm_training_showcase` (fictional data).

Every material claim in the 12 decks is listed here with its evidence. Screenshot evidence refers to files in
`screenshots/` (details in `SCREENSHOT-INVENTORY.md`). "Code" means the claim was confirmed by reading the named file at
commit `72c96a73`. "Doc" means a repository document. No credential or secret value appears in this register.

**Status labels** (as used on every slide): VERIFIED IN RUNNING APPLICATION · IMPLEMENTED BUT NOT VERIFIED END-TO-END ·
AVAILABLE ONLY AFTER CONFIGURATION · DISABLED BY SAFETY OR COMMERCIAL GATES · INCOMPLETE · NOT IMPLEMENTED · UNABLE TO VERIFY.

## 1. Product status and scope

| # | Claim (as stated in the decks) | Status | Evidence | Decks |
|---|---|---|---|---|
| A1 | The release gate is NO-GO; 10 of 14 gates are blocked; re-verified 13 Oct 2026 | — (gate wording: BLOCKED) | Doc: `docs/saas/SaaS-Final-Release-Gate.md` (Decision; Gates table; Re-verification log) | All |
| A2 | PeopleOS is not yet generally available | — | Doc: release gate; `docs/reports/implementation-status.md` release context | All |
| A3 | Public signup, live payments and tenant deletion are switched off | DISABLED BY SAFETY OR COMMERCIAL GATES | Screenshots `platform-admin/95-pl-10-signups`, `95-pl-19-payments`, `95-pl-24-offboarding`; Doc: release gate rows 9–11 | 4, 5, 8, 9, 10 |
| A4 | Commercial plans, subscriptions and entitlements run in shadow mode: nothing enforced or billed; no plan or price shipped | DISABLED BY SAFETY OR COMMERCIAL GATES | Screenshots `95-pl-11` to `95-pl-20`; Doc: `docs/architecture/saas-4-plans.md`, `saas-6-subscriptions.md` | 5, 9 |
| A5 | Statutory rules: 0 of 24 verified; payroll finalisation blocked; jurisdictions without rules (USA, UK, EU) blocked; gratuity not implemented | INCOMPLETE | Screenshots `hr-admin/60-stat-01`, `60-pay-09-validate-blocked`; Doc: `SaaS-Final-Statutory-Verification-Register.md`, release gate row 5 | 1, 3, 4, 7, 9, 10, 11, 12 |
| A6 | The training screens come from a disposable database with fictional people; no application file or setting was changed | — | `git status` clean outside `docs/presentations/` at completion; README; `SCREENSHOT-INVENTORY.md` environment table | All |
| A7 | No native mobile app; responsive web only | NOT IMPLEMENTED | Doc: `docs/reports/feature-inventory.md` (Channels) | 1, 9, 10 |
| A8 | Contact details, prices, hosting region and service levels are not stated in the repository | UNABLE TO VERIFY | Doc: `docs/reports/documentation-gaps.md` §5 | 1, 9, 11 |

## 2. Workflows executed in the training environment

| # | Claim | Status | Evidence | Decks |
|---|---|---|---|---|
| B1 | Employee requests leave from Home with a Before → After preview (14–15 Dec 2026 = 2 working days; CL 5 → 3) | VERIFIED IN RUNNING APPLICATION | `employee/10-leave-04-form-completed`, `10-leave-05-submitted`; script `tools/capture/10-leave-flow.mjs` | 1, 9, 11 |
| B2 | Earned leave is listed with its balance during probation but refused on submit: "Earned leave is not available during probation." | VERIFIED IN RUNNING APPLICATION | `employee/10-leave-03-policy-refusal` | 1, 7, 11 |
| B3 | Manager decides in the Approval Center with reason, team overlap and balance; reject needs a reason | VERIFIED IN RUNNING APPLICATION | `manager/10-leave-07` to `10-leave-12` | 1, 2, 9, 11 |
| B4 | Employee sees Approved / Reviewed by; balances and the append-only ledger update | VERIFIED IN RUNNING APPLICATION | `employee/10-leave-13` to `10-leave-15`; Code: `LeaveLedgerEntry` (append-only) | 1, 11 |
| B5 | Check-in from Home; missed-punch correction; manager approval reprocesses the day; HR manual punch and Process day ("21 employee(s) processed for 2026-10-20") | VERIFIED IN RUNNING APPLICATION | `employee/20-att-*`, `manager/20-att-*`, `hr-admin/20-att-*`; script `20-attendance-flow.mjs` | 1, 2, 3, 6, 11 |
| B6 | 20 Oct 2026 shows "worked on holiday" because Dussehra is in the demonstration holiday calendar | VERIFIED IN RUNNING APPLICATION | `employee/20-att-16-my-attendance`; database query of `holidays` (Dussehra 2026-10-20) | 1, 7 |
| B7 | Hire wizard refuses a definite duplicate person ("This person already exists…") and creates a new Employee 360 with onboarding started | VERIFIED IN RUNNING APPLICATION | `hr-admin/50-hire-01`, `50-hire-07`; `client-onboarding/90-onb-45` | 3, 4, 7, 11 |
| B8 | Change manager is a three-step guided change with Before → After and a reason; history is kept | VERIFIED IN RUNNING APPLICATION | `hr-admin/50-chg-05` to `08`; `client-onboarding/90-onb-48` to `51` | 3, 4 |
| B9 | Only employed people can be chosen as line managers (a Preboarding person is not offered) | VERIFIED IN RUNNING APPLICATION | `client-onboarding/90-onb-44-hire-with-manager` | 4, 7 |
| B10 | Compensation change needs four different people; the reviewer is not offered Approve | VERIFIED IN RUNNING APPLICATION | `hr-admin/62-comp-*`, `security-compliance/62-comp-07` | 3, 8, 11 |
| B11 | Compensation cannot be proposed for an alumni employee | VERIFIED IN RUNNING APPLICATION | `hr-admin/62-comp-00-refused-alumni` | 3, 7 |
| B12 | Payroll Recalculate covered 19 employees, all with exceptions; Validate refused ("19 employee(s) have blocking exceptions…") | VERIFIED IN RUNNING APPLICATION | `hr-admin/60-pay-08`, `60-pay-09` | 3, 7, 10, 11 |
| B13 | Starter report run and CSV export; the export is recorded in Change history | VERIFIED IN RUNNING APPLICATION | `analytics/80-ins-06`, `80-ins-07`, `80-ins-10` | 8, 11, 12 |
| B14 | Ask PeopleOS answers "What is our attrition?" with 4.5% and its source; the external language model is off by default | VERIFIED IN RUNNING APPLICATION | `analytics/80-ins-17-ask-peopleos`; feature flag `ai.llm` off (`client-onboarding/90-onb-87`) | 9, 10, 12 |
| B15 | Platform operator provisions a tenant; the first administrator is invited and activates; a used invitation link is refused | VERIFIED IN RUNNING APPLICATION | `client-onboarding/90-onb-01` to `12`; script `90-onboarding-a/b` | 4, 5, 7, 11 |
| B16 | Creating a company also creates a primary legal entity and a principal establishment | VERIFIED IN RUNNING APPLICATION | Fork D observation during `90-onboarding-c-org`; lists `90-onb-19`, `90-onb-21` show two entries | 4 |
| B17 | Leave policy: entitlements set on a draft version, then published (v1) | VERIFIED IN RUNNING APPLICATION | `client-onboarding/90-onb-70` to `72` | 4 |
| B18 | A statutory registration must belong to an establishment ("A EPF establishment code registration belongs to an establishment.") | VERIFIED IN RUNNING APPLICATION | `client-onboarding/90-onb-76a` | 4 |
| B19 | Audit chain verification: "Chain intact: 287 events verified" (Northwind) | VERIFIED IN RUNNING APPLICATION | `client-onboarding/90-onb-90-audit-verified` | 4, 8, 11 |
| B20 | Operator suspends a tenant: users get the generic sign-in message; reactivation restores access; both audited with reasons | VERIFIED IN RUNNING APPLICATION | `platform-admin/95-pl-06` to `09`, `95-pl-37` | 5, 7, 8, 11 |
| B21 | Legal hold placed; release needs a second operator and stays pending with one operator | VERIFIED IN RUNNING APPLICATION (placement); AVAILABLE ONLY AFTER CONFIGURATION (release) | `platform-admin/95-pl-26`, `27`, `33`, `34` | 5, 8, 10 |
| B22 | Operators enter a tenant with a reason and a ticket; access ends after 60 minutes | VERIFIED IN RUNNING APPLICATION | `platform-admin/95-pl-35`, `95-pl-36` | 5, 6, 8 |
| B23 | Password reset: neutral answer, single-use link, other sessions ended | VERIFIED IN RUNNING APPLICATION | `support/99-sup-02` to `06`; Code: `app/Domain/Identity/Services/PasswordResets.php` | 7, 8, 11 |
| B24 | Markedge's internal workforce runs in an ordinary tenant; its HR cannot open the platform pages | VERIFIED IN RUNNING APPLICATION | `internal-operations/96-int-*` | 6, 8 |
| B25 | Letters: the requester cannot approve ("The person who requested a letter cannot approve it."); a second HR person approves and issues; the employee sees it in My letters | VERIFIED IN RUNNING APPLICATION | `security-compliance/52-let-04`, `hr-admin/52-let-06`, `employee/52-let-07` | 3, 8, 11 |
| B26 | HR queue: Take it, Reply, Resolve; the employee sees Resolved (My requests opens with a status filter that hides Resolved) | VERIFIED IN RUNNING APPLICATION | `hr-admin/52-tkt-03` to `05`, `employee/52-tkt-06`, `07` | 3, 11 |
| B27 | Exit: clearance refuses the asset stage while assets are in custody; F&F calculated (net 38,254.06), approved and paid; exit completed; alumni profile | VERIFIED IN RUNNING APPLICATION | `hr-admin/70-exit-*` | 3, 9, 11 |
| B28 | Alumni portal: a former employee signs in and requests a document; HR verifies identity before issuing (first live verification) | VERIFIED IN RUNNING APPLICATION | `employee/70-alumni-01` to `03`, `hr-admin/70-alumni-04` | 3, 11 |
| B29 | A transfer effective the same day is refused ("The new position must start after the current one") | VERIFIED IN RUNNING APPLICATION | `hr-admin/50-chg-04-transfer-refused-same-day` | 3 |
| B30 | Tenant HR Admin and HR Manager see different Employee 360 record tabs (Bank & personal and Growth only for the former) | VERIFIED IN RUNNING APPLICATION | `security-compliance/50-sec-01`, `50-sec-02` | 3, 8 |
| B31 | Employee self-service executed: HR request raised, goal check-in (55% → 70%), self review submitted, course started, policy and announcement acknowledged, tax declaration saved as draft | VERIFIED IN RUNNING APPLICATION | `employee/30-self-*` | 1, 11 |
| B32 | Manager actions executed: one-on-one scheduled, feedback shared | VERIFIED IN RUNNING APPLICATION | `manager/40-team-13` to `16` | 2 |

## 3. Controls and safeguards

| # | Claim | Status | Evidence | Decks |
|---|---|---|---|---|
| C1 | Platform operators must enrol MFA (forced set-up) | VERIFIED IN RUNNING APPLICATION | `platform-admin/02-mfa-setup-required`; Code: `config/peopleos.php` `platform_mfa_required` (default true), `EnforceAccountSecurity` | 5, 8 |
| C2 | Tenant MFA is Optional unless the security policy requires it | VERIFIED IN RUNNING APPLICATION | `security-compliance/85-sec-01-security-policy`; Code: `security.mfa_required` default false | 4, 8 |
| C3 | Sign-in failures use one generic message for every cause | VERIFIED IN RUNNING APPLICATION | `support/99-sup-01`, `99-sup-08`, `platform-admin/95-pl-08` | 5, 7, 8 |
| C4 | Tenant scoping fails closed; bypass only in allow-listed platform services (architecture tests) | IMPLEMENTED BUT NOT VERIFIED END-TO-END (code and tests; no independent review) | Code: `TenantScope`, `TenantContext`; `tests/Feature/Architecture/ArchitectureTest.php`; Doc: architecture contract §1 | 8, 10 |
| C5 | Access is layered: role, organisation scope, relationship scope, field protection | VERIFIED IN RUNNING APPLICATION (scope screens, refusals) | `security-compliance/85-acc-06b`, `85-acc-08`; Doc: architecture contract §6 | 2, 3, 8, 10 |
| C6 | Only a Tenant Super Admin edits role permissions; Tenant HR Admin gets 403 (F5) | VERIFIED IN RUNNING APPLICATION | `security-compliance/85-acc-08-role-edit-denied-hr-admin` | 3, 7, 8 |
| C7 | Sensitive fields need *.sensitive.* permissions and are masked and encrypted | IMPLEMENTED BUT NOT VERIFIED END-TO-END (configuration and code; masking not shown with real identifiers) | Code: `config/peopleos.php` `data_classification`; ArchitectureTest "masks every highly sensitive attribute" | 3, 8, 10 |
| C8 | Audit events are append-only and hash-chained; event detail shows Hash, Previous hash, "Hash verified ✓" | VERIFIED IN RUNNING APPLICATION | `security-compliance/80-ins-11b-audit-integrity`; Code: ImmutableBuilder / AuditRecorder | 8, 10, 12 |
| C9 | The document uploader cannot verify the same document | VERIFIED IN RUNNING APPLICATION (rule); INCOMPLETE (shown as a server error) | `security-compliance/50-doc-03-uploader-cannot-verify` | 3, 7, 8, 11 |
| C10 | Every queued job carries its tenant (TenantAwareJob) | IMPLEMENTED BUT NOT VERIFIED END-TO-END (architecture test; no worker runs locally) | `ArchitectureTest` "makes every queued job tenant-aware" | 10 |
| C11 | Workflow instances keep the version they started on | IMPLEMENTED BUT NOT VERIFIED END-TO-END | Doc: architecture contract §8; versions screen `client-onboarding/85-wf-03` | 4, 10 |
| C12 | Statutory rates are data, never PHP; payroll consumes attendance and leave outputs | IMPLEMENTED BUT NOT VERIFIED END-TO-END | `ArchitectureTest` "makes payroll consume attendance and leave outputs…" | 10 |
| C13 | No RecruitmentEdge / RMS dependency; recruitment hand-over only through the Integration Hub or POST /api/v1/pre-employees | IMPLEMENTED BUT NOT VERIFIED END-TO-END | `ArchitectureTest` "never acquires a hard RecruitmentEdge / RMS dependency"; Doc: `integration-contract.md` §5; `docs/api/openapi.json` | 10 |
| C14 | Tenant data export is encrypted and passphrase-protected | IMPLEMENTED BUT NOT VERIFIED END-TO-END (dialog only; generation not run) | `security-compliance/85-sec-07`, `platform-admin/95-pl-32`; Doc: `saas-9-offboarding.md` §5 | 5, 8, 10 |
| C15 | No retention policy is in force; nothing is deletable until Markedge decides (D-8) | AVAILABLE ONLY AFTER CONFIGURATION | `platform-admin/95-pl-28-retention-policies` | 5, 8 |
| C16 | Backup and DR proven locally only; target-environment DR not verified | UNABLE TO VERIFY | Doc: release gate row 7; `SaaS-11-Backup-and-DR-Test-Report.md` | 8, 11 |
| C17 | Rollback: restore the backup after an interrupted migration; never re-run blindly | — (runbook) | Doc: `SaaS-Final-Deployment-and-Rollback-Runbook.md` §5 | 11 |
| C18 | Accessibility: axe-core WCAG 2.0–2.2 A/AA re-runs at 0 violations; scripted keyboard review; Safari and Android Chrome untested | — (prior evidence) | Doc: `docs/ux/UX-18-Accessibility-Audit.md`; `implementation-status.md` 5.1 | 11 |

## 4. Known defects and limits shown in the decks

| # | Defect or limit | Status | Evidence | Decks |
|---|---|---|---|---|
| D1 | F25: employee/punch import Map columns fails ("Undefined variable $svc"); reproduced in two tenants | INCOMPLETE | `client-onboarding/90-onb-93`; Code: `app/Filament/Support/ImportActions.php` map action without `use ($svc)` | 3, 4, 7, 11 |
| D2 | F38: Leave requests › Apply on behalf server error; workaround via Employee 360 | INCOMPLETE | `support/55-lv-err-01-apply-on-behalf`; Code: `ListLeaveRequests.php:27`; workaround `hr-admin/55-lv-11`, `55-lv-12` | 3, 7, 11 |
| D3 | F44: report builder field lists empty | INCOMPLETE | `analytics/80-ins-08-report-builder-f44`; Code: `ReportBuilderSchema.php:25` | 7, 11, 12 |
| D4 | F45: Executive role has no dataset permission; dashboards show "You cannot report on the … dataset." | AVAILABLE ONLY AFTER CONFIGURATION | `analytics/80-ins-20-dashboard-executive-f45` | 9, 12 |
| D5 | F26: History tabs raise a lazy-loading error outside production | INCOMPLETE | `hr-admin/50-360-10-records-history`, `hr-admin/60-pay-10-run-history-error-f26`, `client-onboarding/90-onb-57` | 3, 7 |
| D6 | F18: notification rules cannot be created or deleted from the screen | INCOMPLETE | `client-onboarding/90-onb-84-notification-rules` | 4, 7 |
| D7 | F17: Give feedback errors on MySQL although saved; HR not notified. The toast shows the raw SQL error including server host, port and database name (observed locally; whether production shows the same text is not verified) | INCOMPLETE | `employee/30-self-45-give-feedback-error`; Doc: documentation-gaps F17 | 1, 7 |
| D8 | F8: FTE fields refuse whole numbers; leave blank | INCOMPLETE | `client-onboarding/90-onb-36` note; `hr-admin/65-wf-02` | 3, 4 |
| D9 | F13: employees cannot submit a tax declaration | INCOMPLETE | Doc: documentation-gaps F13; `hr-admin/60-pay-17` | 1, 3 |
| D10 | F29: Change Centre covers only some edits; no self-approval guard | INCOMPLETE | Doc: documentation-gaps F29; `client-onboarding/90-onb-88` | 4, 8, 10 |
| D11 | F37: API key scopes not limited by the issuer's permissions | INCOMPLETE | `security-compliance/85-sec-03-api-key-form` note; Code: `ApiKeyResource.php:41` | 8, 10 |
| D12 | F39/F40: holiday type and half-day ignored; late-mark actions not applied | INCOMPLETE | Doc: documentation-gaps F39, F40 | 4, 7 |
| D13 | F12: payroll issue codes shown raw (e.g. `unverified_statutory_rule`) | INCOMPLETE | `hr-admin/60-pay-05-employee-issues` | 3 |
| D14 | New (21 Oct 2026): document SoD refusal surfaces as HTTP 500 | INCOMPLETE | `security-compliance/50-doc-03-uploader-cannot-verify` | 3, 7, 8 |
| D15 | New: Employee 360 Records › Employment returns HTTP 500 (TypeError in getRelationshipQuery) when its lazy-loaded tables load; reproduced for employee, manager and HR | INCOMPLETE | `support/50-360-err-01-records-employment`, `support/30-self-90-record-tab-500` | 2, 3, 7 |
| D16 | New: onboarding template Checklist items lazy-loading error | INCOMPLETE | `support/50-onb-err-01-template-items-lazy-loading` | 3, 7 |
| D17 | New: Leave Balances name search database error ("Unknown column 'people'") | INCOMPLETE | `support/55-lv-err-02-balances-search` | 3, 7 |
| D18 | Observation: invitation and reset e-mails show "Laravel" as sender (APP_NAME / MAIL_FROM_NAME unset in development) | AVAILABLE ONLY AFTER CONFIGURATION | `client-onboarding/90-onb-07-invitation-email` | 4 |
| D19 | Observation: no on-screen banner marks an operator's support session inside a tenant | UNABLE TO VERIFY (observation, not confirmed as a defect) | `platform-admin/95-pl-36-operator-in-tenant` | 5 |
| D20 | A manager opening a record outside their reach gets 404 "We could not find that" (not 403) | VERIFIED IN RUNNING APPLICATION | `manager/40-team-09-outside-team` | 2, 7, 8, 9 |
| D21 | Manager review: the button is offered before the stage opens; submit refused "The Manager review stage has not opened yet." Stages do not advance by date (F21) | AVAILABLE ONLY AFTER CONFIGURATION | `manager/40-team-24`, `40-team-25` | 2 |
| D22 | A goal check-in on the goal itself (when it has key results) records the value but does not change progress; progress comes from key results | VERIFIED IN RUNNING APPLICATION (observation) | `employee/30-self-25`, `30-self-26` | 1 |
| D23 | Earned leave is listed with its balance on Home and in the leave form during probation, then refused on submit | VERIFIED IN RUNNING APPLICATION (observation) | `employee/10-leave-03-policy-refusal`, `employee/30-self-04-home` | 1, 7 |
| D24 | Employee self-review submitted; the employee sees only their own review row | VERIFIED IN RUNNING APPLICATION | `employee/30-self-29-self-review-submitted` | 1 |
| D25 | Managers see Timeline, Employment, Time, Growth and Personal; Rewards and Operations are not offered | VERIFIED IN RUNNING APPLICATION | `manager/40-team-07-member-records` | 2, 8 |
| D26 | Full & final settlement: the same HR person calculated and approved it (no separation of duties enforced) | VERIFIED IN RUNNING APPLICATION (observation) | `hr-admin/70-exit-11-ff-settlement`; fork B capture log | 3, 8 |
| D27 | Onboarding tasks stay with the previous manager after a manager change | VERIFIED IN RUNNING APPLICATION (observation) | Fork B capture log (Task inbox after Change manager) | 3 |
| D28 | A resignation's requested last day was not applied; the case used the end of notice | VERIFIED IN RUNNING APPLICATION (observation) | `employee/70-exit-02-my-exit` | 3 |
| D29 | The alumnus's Home still shows "Bank account missing" and "PAN not on file" reminders | VERIFIED IN RUNNING APPLICATION (observation) | Fork B capture log | 3 |
| D30 | F26 also appears on letter and payroll-run pages and on Users › Edit right after Create user | INCOMPLETE | `support/52-let-err-01-letter-history-f26`, `hr-admin/60-pay-10`, `client-onboarding/90-onb-57` | 3, 7 |

## 5. Claims deliberately not made

- No slide states a payslip, finalised payroll, statutory return, bank file or Form 16/130 output: none can exist while
  statutory rules are unverified.
- No slide shows MFA QR codes, recovery codes, API tokens, webhook secrets, data-export passphrases, invitation or reset
  links.
- No slide presents a conceptual diagram as a screenshot: diagrams carry "Conceptual diagram, not a screenshot".
- No slide claims an integration round trip (SSO, SCIM, API, webhooks, inbound events, devices) was executed: forms and
  lists only.
- No test case is marked Pass: the UAT workbook records reference observations and leaves Pass/Fail to the customer.

## 6. Maintenance

When a defect above is fixed or a gate changes, re-capture the affected screens, change the slide status, update this
register and `FEATURE-TO-PRESENTATION-MATRIX.md`, and rebuild (`tools/build/build-all.js`).
