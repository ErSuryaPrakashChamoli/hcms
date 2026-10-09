# External security review — checklist and sign-off

**For:** the independent security reviewer engaged by Markedge. **Status: NOT STARTED. This gate is BLOCKED until
the sign-off in §6 is complete.**

PeopleOS is a multi-tenant HR and payroll platform (Laravel 13, PHP 8.5, Filament 5, MySQL 8.4). The engineering
agent that built it carried out internal reviews in SaaS.10 and SaaS.11, but those are not independent. This
checklist tells you:
- what to review;
- what the internal reviews claim, so you can test the claims rather than trust them;
- what evidence the release decision needs from you.

## 1. Engagement prerequisites

| Item | Required | Status |
|---|---|---|
| Reviewer independent of the code's authors | yes | — |
| Written scope and rules of engagement, signed by the owner | yes | — |
| A **non-production** deployment built from the release candidate commit, with fictional data only | yes | **does not exist yet** |
| Test accounts: two tenants (each with an Account Owner and an ordinary employee), one platform operator with MFA | yes | to be created in that environment |
| Read access to the source at the release candidate commit | yes | branch `feature/oct_1_phase_1` |
| Out of scope unless agreed: social engineering, denial of service, the hosting provider's own infrastructure | — | — |

Do **not** test against the developer workstation's showcase (port 8090). It holds demo personas with a shared
fixture password by design.

## 2. Documents to read first

| Document | What it claims |
|---|---|
| `docs/architecture/security-invariants.md` | The invariants the code is meant to uphold |
| `docs/saas/SaaS-1-Security-Threat-Model.md` | The commercial domain threat model |
| `docs/saas/SaaS-10-Threat-Model.md` | Self-service signup: T1–T25 and their controls |
| `docs/saas/SaaS-11-Security-Findings.md` | Findings, fixes and open items in this phase |
| `docs/operations/production-configuration.md` | The production configuration rules and release switches |
| `docs/saas/SaaS-9-Closure-and-Release-Readiness-Report.md` | Offboarding, deletion and the credential incident |

## 3. Areas and test objectives

Mark each item **Pass**, **Fail** (with a finding reference) or **Not tested** (with the reason).

### 3.1 Tenant isolation (highest priority)
- [ ] A user of tenant A cannot read, list, export, search, update or delete any record of tenant B: through the UI, the
      REST API (`/api/v1`), file downloads, exports, reports, notifications or the AI assistant.
- [ ] Identifiers from another tenant in URLs, form fields, Livewire payloads and API bodies are refused, not merely
      hidden.
- [ ] Unscoped queries: the fail-closed `TenantContext` refuses a tenant-owned query without a tenant. Look for
      `bypass(` and raw `DB::table(` uses that could leak across tenants.
- [ ] Background jobs and the scheduler run every tenant-owned operation in that tenant's context.

### 3.2 Authentication, sessions and MFA
- [ ] Password reset, invitation activation and signup links: single use, hashed at rest, expiring, unguessable, and
      not usable across tenants.
- [ ] Platform operators: MFA is required. **Known gap (F8):** an operator with no authenticator enrols at the next
      sign-in. Confirm readiness fails in production until every operator is enrolled.
- [ ] Session fixation, idle timeout, `session_epoch` revocation on password and MFA reset, and remember-me tokens.
- [ ] Rate limits on login, MFA, password reset, signup and the API; whether they hold across several web servers.

### 3.3 Authorisation and privilege escalation
- [ ] **E1:** a user with `user.assign_roles` cannot grant a role above their own ceiling, Tenant Super Admin
      included.
- [ ] **E2:** a blueprint import writes only permissions the importer holds.
- [ ] **E3:** an SSO default role cannot be Tenant Super Admin and is never attached at sign-in.
- [ ] **S11-1:** every change of a role's permissions is audited (editor, creation, blueprint import). Look for any
      other path that writes `permission_role` without the audit.
- [ ] The Account Owner (35 explicit permissions; `config/peopleos.php` `roles.account-owner`) can reach no
      platform function and cannot become a platform operator through any path.
- [ ] Platform pages and actions refuse tenant users, and an operator's access into a tenant is time-limited and
      audited.
- [ ] Policies on every Filament resource and API controller; mass assignment (`tenant_id` must never be accepted
      from input).

### 3.4 Self-service signup (disabled; reviewed before D10-1)
- [ ] T1–T25 in `SaaS-10-Threat-Model.md`, in particular:
  - enumeration (T1);
  - automated abuse and rate-limit bypass (T2, T3);
  - duplicate provisioning under concurrency (T4, T16);
  - commercial bypass (T19).
- [ ] With registration switched **off**, every signup action is refused (not merely hidden).
- [ ] The release-gate validator refuses registration on without the recorded authorisation reference.

### 3.5 Data protection
- [ ] Encrypted columns (MFA secrets and recovery codes, bank accounts, statutory identifiers, compensation changes,
      talent and feedback records, and others: search for `'encrypted` casts) are encrypted at rest with `APP_KEY`.
      Key rotation (`APP_PREVIOUS_KEYS`) works.
- [ ] Documents are served only through authorised, audited routes; the private disk never serves files directly.
- [ ] Logs are redacted (the redaction tap on every channel). No token, password or personal identifier appears in
      logs, audit metadata or error pages.
- [ ] Exports (SaaS.9): access control, maker-checker, download expiry, audit.
- [ ] Deletion (SaaS.9, **disabled**): holds, maker-checker, cooling-off, and refusal while policies are
      unconfigured.

### 3.6 Web and API hardening
- [ ] Headers: CSP frame-ancestors, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, HSTS (at least a
      year), `X-Robots-Tag: noindex, nofollow`.
- [ ] CSRF on every state change; no GET changes state.
- [ ] XSS in every user-editable field shown elsewhere (names, comments, announcements, custom fields, letters, AI
      output).
- [ ] SSRF: the outbound guard (`PEOPLEOS_OUTBOUND_*`) on webhooks, integrations and BGV callbacks.
- [ ] Webhook and callback signatures, replay protection and idempotency keys.
- [ ] File uploads: type, size, storage path and download headers.

### 3.7 Audit integrity
- [ ] Audit events are append-only and hash-chained per tenant and for the platform. `peopleos:audit:verify` detects
      tampering (edit, delete, reorder).
- [ ] Security-relevant actions are audited: role and permission changes, operator access, MFA and session changes,
      exports, offboarding, configuration and signup decisions.

### 3.8 Supply chain and secrets
- [ ] `composer audit` and `npm audit` against current advisories; licences.
- [ ] Repository and history: the known credential incident (F1). Confirm no **other** secret is in history and the
      known value is accepted nowhere in the reviewed environment.
- [ ] Secrets delivered from a secret store; production configuration validated (`peopleos:config:validate
      --as-production` exits 0).

### 3.9 Infrastructure (if in scope)
- [ ] TLS configuration, trusted proxies, network exposure of MySQL and Redis, backup encryption and access, admin
      access to hosts.

## 4. Known open items (do not report as new findings; confirm or challenge)

| ID | Item |
|---|---|
| F1 / S11-4 | Credential in public history and in the tip of `feature/sep_28_phase_1`; owner action pending |
| F8 | Operator MFA enrolment at creation is not built; readiness gate only |
| L1–L9 | SaaS.10 residual risks (`SaaS-11-Security-Findings.md` §6) |
| D10-8 | Account Owner MFA follows the tenant policy (off by default) |
| D10-9 | No CAPTCHA |
| D-16 | Entitlements in shadow mode (plan limits not enforced) |

## 5. Findings format

For each finding give:
- an ID;
- a title;
- the severity (critical, high, medium, low), with the rating method stated;
- the affected component and commit;
- reproduction steps on the test environment;
- the impact;
- a recommendation.

Mark any finding that blocks release. The release decision stays BLOCKED while any critical or high finding is open.

## 6. Sign-off (to be completed by the reviewer)

| Field | Value |
|---|---|
| Reviewer name and organisation | |
| Independence statement (no part in building PeopleOS) | |
| Commit reviewed | |
| Environment reviewed (URL, date range) | |
| Methods (manual review, automated scanning, tools and versions) | |
| Areas tested / not tested (§3) | |
| Findings: critical / high / medium / low | |
| Findings that block release | |
| Retest date and result | |
| Signature and date | |
| Owner acceptance (name, date) | |
