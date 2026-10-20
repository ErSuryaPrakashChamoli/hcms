# SaaS.12 — Consolidated owner decision register

> **Superseded on 11 October 2026 by `SaaS-Final-Owner-Action-Register.md`** (same IDs; this file is kept as history).

Date: 10 October 2026. **For:** the owner of Markedge PeopleOS (and, where named, counsel, finance and tax advisers).

**None of these decisions has been made.** Nothing below is approved by being written here. A recommendation is
engineering's view, not a decision. Values that only the owner, counsel or a specialist can give are never filled in.

"Blocks release" refers to scope A, the production service for operator-provisioned tenants. Registration, deletion
and billing are separate scopes, and each needs its own decisions on top.

## How to record a decision

Give, for each ID:
- the choice;
- the approval reference (a document, ticket or minute);
- the date;
- for D8 and B items, who approved the values.

Engineering then enters the values through the product's governed paths (maker-checker policy versions, commercial
configuration) and never in code. Configuration references go into the environment as described in
`docs/operations/production-configuration.md` §6a.

## Group D — release decisions (most urgent)

| ID | Decision | Options and consequences | Evidence needed | Work proceeds without it? | Blocks release? |
|---|---|---|---|---|---|
| O-1 | **Leaked credential: rotate everywhere it was used** | No real option. Until it is rotated, any system that accepts the value is exposed, whatever happens to the repository | Your confirmation per system (GitHub, e-mail, hosting, other products, devices). PeopleOS here: already refuses it | Yes | **Yes** |
| O-2 | **Repository visibility** | Private stops new exposure, not existing clones. Public keeps the value readable in `feature/sep_28_phase_1` | Decision | Yes | **Yes** (G2) |
| O-3 | **`feature/sep_28_phase_1` and history** | (a) delete the remote branch; (b) push a commit removing the value; (c) rewrite history (`SaaS-11-Security-Findings.md` §2.3: force-push, PR #1 recreated, every clone re-clones, GitHub Support purge). (a) or (b) removes it from current files only | Explicit authorisation naming the option; for (c), authorisation to rewrite your own commits | Yes | **Yes** for current files; history is your risk decision |
| O-4 | **Engage the independent security reviewer** | Without it, gate G3 cannot pass | Engagement, scope and a review environment (O-8) | Yes | **Yes** |
| O-5 | **Engage a statutory specialist** | Payroll for any covered statute stays blocked by the verified-rules gate | Engagement; the package `SaaS-12-Statutory-Verification-Register.md` | Yes | **Yes** for payroll; the platform can run without payroll statutory features only if you decide so (O-12) |
| O-6 | **Recovery objectives (RPO, RTO)** | The 4 h RTO and 15 min / 24 h RPO in the DR document are engineering's planning targets, never approved | Your approval of the numbers (or others) | Yes | **Yes** (G15) |
| O-7 | **Target environments** (staging, production, an isolated restore target) | Without them, no configuration, DR, rehearsal or staging evidence can exist | Hosting choice, accounts, region (D10-11 / D8-7), secret store, mail provider | Partly | **Yes** |
| O-8 | **Authorise deployment to staging** | Needed before any staging validation | Authorisation naming the environment and commit | No (staging work) | **Yes** |
| O-9 | **Authorise production deployment** | Final step; only after every gate | Authorisation naming the release-candidate commit | No | **Yes** |
| O-10 | **Post-release monitoring window and on-call** | No window may be invented | Duration, owners, escalation contacts | Yes | **Yes** (G21, G22) |
| O-11 | **Error reporting and alerting provider** | The application has logs and health endpoints only | Provider choice; data-processing terms | Yes | **Yes** (G21) |
| O-12 | **Launch scope** | Whether production launches with payroll statutory features blocked (verified-rules gate), or waits for verification | Decision | Yes | Shapes G5 |
| O-13 | **Activation of public signup, deletion and live payments** | Each is a separate scope with its own gate. All are off; production refuses each without its recorded authorisation | Decisions below (D10, D8, B) | Yes | No for scope A |
| O-14 | **Operator MFA** | Every platform operator enrols an authenticator; readiness fails in production otherwise | Enrolment done by each operator | Yes | **Yes** (G10) |
| O-15 | **Disposable test databases** | 6 local `hcm_*` test databases contain test-factory operators; obsolete replay databases (F15) | Say whether to drop them | Yes | No |
| O-16 | **D-15** two-operator approval kept (default) or a dedicated approver role | Default works; a role is a product change | Decision | Yes | No |
| O-17 | **D-16** entitlement enforcement (shadow mode today) | Plan limits are shown, not enforced | Decision | Yes | No for scope A; yes for paid plans |

## Group A — retention and deletion (D8-1 to D8-8)

These are the same IDs as `SaaS-9-Retention-Decision-Register.md` §3, where each category is separated into:
- the verified legal minimum;
- Markedge's policy;
- the customer contract;
- the start event, the jurisdiction, holds, the deletion action, approvals and external copies.

| ID | Decision (from the SaaS.9 register) | If left open | Blocks |
|---|---|---|---|
| D8-1 | Markedge policy for customer HR data after closure, per market | Nothing is deletable (`RETENTION_POLICY_NOT_CONFIGURED`) | Deletion |
| D8-2 | Whether Markedge keeps anything on the customer's behalf for their statutory periods (payroll, attendance, tax) | Same | Deletion |
| D8-3 | Markedge's own records (invoices, payments, billing) | Markedge records are retained | Deletion |
| D8-4 | Audit trail after exit | The chain is retained | Deletion |
| D8-5 | Backup retention, and how ageing-out of a deleted tenant is proven | External copies stay "pending" or "retention required" | Deletion |
| D8-6 | Export package expiry (7 days default) | Default applies | Nothing |
| D8-7 | India hosting of books (Income-tax Rules r.46(8)) | Unknown compliance of the hosting region | Hosting decision (O-7) |
| D8-8 | State labour rules and non-India markets | Unconfigured, so safe | Deletion outside researched rules |

**Deletion activation** additionally needs:
- the production-copy rehearsal (G16);
- the deletion register disk configured (SaaS.12);
- an independent review of the deletion workflow;
- `PEOPLEOS_TENANT_DELETION_AUTHORISATION`.

## Group B — public registration (D10-1 to D10-11)

The IDs are those of `SaaS-10-Final-Report.md` §15.

**Mapping note.** Your SaaS.12 list has twelve items. "Terms of Service" and "Privacy Notice" are both **D10-4** in the
existing register. They are kept under one ID, not renumbered.

| ID | Decision | If left open | Blocks |
|---|---|---|---|
| D10-1 | Authorise public registration (after G3) and name the environment | Registration stays off; signup pages refuse every action | Registration |
| D10-2 | Offers: plans, trial lengths (D-4), countries | Signup refuses (no offer) | Registration |
| D10-3 | Review policy: `all` (default) or `flagged` (D-9) | Every application is reviewed by an operator | Nothing |
| D10-4 | Terms of Service **and** Privacy Notice (legal-approved texts at stable https addresses) | Signup refuses (no terms) | Registration |
| D10-5 | One login per address (D-17), kept | Current behaviour continues | Nothing |
| D10-6 | Retention of unconverted applications | Kept indefinitely with contact data | Registration (recommended before opening) |
| D10-7 | Account Owner permission set (35 explicit keys) | Current set stands | Nothing |
| D10-8 | MFA for Account Owners from the first sign-in (recommended: yes) | Follows the tenant policy (off by default) | Registration (recommended) |
| D10-9 | CAPTCHA or bot-protection provider | Rate limits, honeypot and review only | Nothing (P3 risk) |
| D10-10 | Tenants whose owner never activates | Kept indefinitely | Nothing |
| D10-11 | Data region at signup (D-11) | One region | Registration outside India |

## Group C — billing (B-4, B-7, B-8, B-9)

The IDs are those of `SaaS-7-Final-Configuration-Gate-Report.md`. Values are entered as governed data, never in code.

| ID | Decision | Who | If left open | Blocks |
|---|---|---|---|---|
| B-4 | Prices per market and currency | Business owner | No real price exists | Paid billing |
| B-7 | Selling entity: legal details, GSTIN, LUT, registrations | Legal, finance | No invoice can be issued | Paid billing |
| B-8 | India SAC and the INR rate-source policy | GST adviser | No India invoice | Paid billing |
| B-9 | Registrations abroad, US local rates, Greece and other US states | Tax advisers | No foreign invoice | Paid billing |

Live payments additionally need:
- the provider's live credentials, in the secret store;
- a payment rehearsal in test mode (G18);
- your authorisation. The validator refuses Razorpay in production until the code path is changed by an approved
  release.
