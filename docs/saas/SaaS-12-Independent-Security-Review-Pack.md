# SaaS.12 — Independent security review pack

**For:** the independent security reviewer. **Status: the review has NOT started.** This gate stays BLOCKED until the
reviewer signs `SaaS-11-External-Security-Review-Checklist.md` §6 and no critical or high finding is open. Engineering
prepared this pack. It is not part of the review and does not substitute for it.

**Prerequisites still missing:**
- an engagement and scope signed by the owner (O-4);
- a non-production review environment built from the release-candidate commit, with fictional data (O-7, O-8).

## 1. What to read

| Purpose | Document |
|---|---|
| Checklist and sign-off | `SaaS-11-External-Security-Review-Checklist.md` |
| Invariants the code claims (1–123) | `docs/architecture/security-invariants.md` |
| Threat models | `SaaS-1-Security-Threat-Model.md`; `SaaS-10-Threat-Model.md` (signup, T1–T25) |
| Findings to date | `SaaS-11-Security-Findings.md`; `SaaS-12-Baseline.md` |
| Production gates | `docs/operations/production-configuration.md` (§6, 6a, 6b) |
| What SaaS.12 changed | `docs/architecture/saas-12-release-programme.md` |

## 2. Review areas mapped to evidence

The reviewer should test each claim, not trust it. "Internal evidence" names the tests and code that make the claim.

| Area | Claim | Internal evidence | Known gaps |
|---|---|---|---|
| Authentication and MFA | Platform MFA is required and refused off in production. Tenant MFA is per tenant policy | `MultiFactorAuthenticationTest`; validator `PEOPLEOS_PLATFORM_MFA_REQUIRED`; readiness MFA check | Operators enrol at next sign-in (F8: readiness gate only); account-owner MFA off by default (D10-8) |
| Authorisation and privilege escalation | No one below Tenant Super Admin grants it (E1); blueprint import capped at the importer's permissions (E2); SSO cannot attach Super Admin (E3) | `RoleGrantsTest`; mutants S19–S23, S31 | — |
| Role changes | Every permission change is audited, including deploy-time introductions; tenants' removals survive deploys | `RoleGrantsTest`, `SystemRoleTopUpTest` | — |
| Tenant isolation | Fail-closed tenant scope; foreign identifiers refused | `Tenancy/*`, `TenantIsolationHardeningTest`, isolation group (189 tests) | Review the `bypass(` and `DB::table(` uses (Phase 14 review table in the invariants) |
| Field-level security | Sensitive fields need explicit permissions; encrypted at rest | `AccessScopeTest`, compensation and talent access tests; `'encrypted` casts | — |
| Payroll and statutory separation of duties | The preparer cannot approve; operators get no exception; unverified rules block | `SecurityHardeningTest` (four-eyes), `StatutorySafetyTest`; mutants G1–G5 | Gratuity not computed |
| Audit integrity | Append-only hash chains, verified daily; deletion evidence also outside the database | `AuditIntegrityVerifier` tests; `DeletionReconciliationTest` | — |
| Secret handling | No secret in logs, errors or the repository | SaaS.12 secret scans (0); error-response test; log redaction tap | **F1: a leaked value is in public history and in a public branch's files** |
| Session management | Revocation on password and MFA reset; suspension ends sessions | `SessionSecurity`; `TenantSuspensionTest` | — |
| Public signup | Off; refused when off; T1–T25 | `Signup/*`; SaaS.11 regression report | D10 decisions |
| Account Owner lifecycle | Explicit 35 keys; never an operator; owner refused on platform pages | `SignupProvisioningTest`; browser flow | D10-7, D10-10 |
| Invitation and recovery | Hashed, single-use, expiring, tenant-bound; throttled resends | `IdentityLifecycleTest`, signup activation tests | — |
| API authentication and rate limiting | Keys hashed and scoped; a verified key gets its own bucket | `SecurityHardeningTest`, API tests | — |
| Queued jobs and tenant context | Jobs bind their tenant; suspended tenants' work is skipped | `TenantDeferredWork`, job tests | Review every job class |
| Storage and documents | Private disk, served through authorised routes only | `StorageProductionTest`, `OwnDocumentAccessTest` | — |
| Export and deletion | Signed manifests; maker-checker; holds; cooling-off; restore reconciliation | `Offboarding/*` (59 + 6 tests) | Deletion off; D8 open |
| Integration Hub and webhooks | Outbound SSRF guard; signed callbacks; idempotency | `OutboundSsrfTest`, BGV callback tests | — |
| SSO | Default role capped (E3) | `RoleGrantsTest` | — |
| Dependencies and supply chain | Composer 0 advisories; npm 0 after the SaaS.12 fix | `composer audit`, `npm audit` (10 Oct 2026) | Re-run at review time |
| Deployment and environment | Validator and readiness gates; release switches need authorisation | `ProductionConfigurationTest`, `PlatformReadinessTest` | **No environment exists** |
| Logging and monitoring | Redaction tap; health endpoints; slow-query log | Validator `LOG_*` | **No alerting integration** (O-11) |
| Backup and recovery | Procedure; local rehearsal 14/14; reconciliation after restore | `SaaS-11-Backup-and-DR-Test-Report.md` | **Target DR unverified** |
| Incident response | Runbooks | `docs/production/*`, `docs/operations/runbooks/*` | No exercised on-call (O-10) |

## 3. Findings capture

For each finding the reviewer records:
- severity, with the method used;
- reproduction evidence;
- the affected component and commit;
- the business impact;
- the required fix;
- the retest evidence;
- the reviewer's closure.

Engineering fixes in-scope findings, re-runs the affected suites and asks for a retest. A finding closes only on the
reviewer's retest.
