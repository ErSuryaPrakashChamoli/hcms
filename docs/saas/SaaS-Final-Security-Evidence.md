# Final security evidence

Date: 11 October 2026, at code commit `064c262`. **For:** the owner and the independent security reviewer.

This consolidates the security evidence of SaaS.9–SaaS.13 and the final programme. The reviewer's materials stay where
they are:
- `SaaS-11-External-Security-Review-Checklist.md` (checklist and sign-off);
- `SaaS-12-Independent-Security-Review-Pack.md` (areas mapped to evidence);
- `docs/architecture/security-invariants.md` (invariants 1–125).

**This is internal evidence.** It is not the independent review (gate 4, not engaged).

## 1. Findings and disposition

| ID | Finding | Severity | Disposition | Evidence |
|---|---|---|---|---|
| F1 / S11-4 | Leaked password in public history and in the files of the public branch `feature/sep_28_phase_1` | P1 | **Open, owner.** Accepted nowhere locally (re-checked 11 Oct); external systems unchecked | Yes/no scans; O-1, O-2, O-3 |
| F8 | Operators enrol at first sign-in | P2 | Gate: readiness fails in production on an unenrolled operator. **Enrolment pending** (0 of 1 locally) | `PlatformReadinessTest` |
| E1–E3 | Escalation to Tenant Super Admin (user form, blueprint import, SSO default) | P1 | Fixed (SaaS.10) | `RoleGrantsTest`; mutants |
| S11-1 | Role permission changes unaudited | P2 | Fixed | `RoleGrantsTest` |
| S11-2 | Platform indexable | P2 | Fixed (noindex header, `robots.txt`) | `SecurityHardeningTest` |
| S11-3 | Production gate blind to the release switches | P2 | Fixed | `ProductionConfigurationTest` |
| S11-5 / F11 | A restore could resurrect a deleted tenant | P1 (deletion) | Fixed (register and reconciliation; fails closed when unreachable) | `DeletionReconciliationTest`; MySQL drill; mutants |
| D1 | Deploys re-granted permissions tenants removed | P2 | Fixed | `SystemRoleTopUpTest`; real data (790 pairs identical, re-checked SaaS.13) |
| D2 | Showcase seeders guarded only by database name | P3 | Fixed | `DatabaseSeederTest` |
| R4b | World-readable `.env`; checker blind to file permissions | P2 | Fixed (gate); development `.env` is 600 (re-checked 11 Oct) | `ProductionConfigurationTest` |
| SaaS.13 | Demo accounts not detected in production | P2 | Fixed (readiness FAIL) | `PlatformReadinessTest` |
| Final | Payroll in a jurisdiction with no statutory rules completed with no deductions | **P1 (compliance)** | **Fixed `064c262`** (blocking in production) | `EnterpriseTest` |
| B6 | CI never ran tests on the branch (Pint failed first) | P2 | Fixed | CI-equivalent runs |
| B11 | npm build-tool advisories | P2 | Fixed; 0 advisories (re-checked SaaS.13) | `npm audit`, `composer audit` |
| L1, L3–L9 | SaaS.10 residual risks (audit gap locks; no CAPTCHA; owner MFA off by default; and others) | P2–P3 | Accepted or pending decisions (D10) | `SaaS-11-Security-Findings.md` §6 |

## 2. Controls and current evidence

| Control | Evidence (current) |
|---|---|
| Security chain: authentication → tenant → role → permission → organisation scope → relationship scope → field security → record | Architecture and isolation suites in the full run; fail-closed `TenantContext`; scope bypasses only in allow-listed platform services (architecture test) |
| Tenant-aware queued jobs | Architecture test: every `ShouldQueue` class implements `TenantAwareJob` and binds its tenant; suspended tenants' work is deferred |
| RMS independence | Architecture test: no RecruitmentEdge or RMS namespace, model, connection or migration |
| MFA on every sign-in path | Per-request enforcement; remember-me and SSO challenged; downloads covered; `MultiFactorAuthenticationTest` |
| Audit integrity | Hash chains; daily verification; the restore drill verified the chains |
| Secrets | 0 current secrets in logs, tracked files or evidence (SaaS.12 scan); `.env` 600; production refuses a demo seed password and world-readable files |
| Error responses | No exception detail in production (panel and API) |
| Headers | `X-Frame-Options: SAMEORIGIN`; `Content-Security-Policy: frame-ancestors 'self'`; `nosniff`; `Referrer-Policy`; `Permissions-Policy`; `X-Robots-Tag`; HSTS over HTTPS (at least a year in production). Live on 8090, 11 Oct |
| API | Hashed, scoped keys; a per-key rate limit only for a verified key; idempotency (SaaS.2, SaaS.9 race tests) |
| Webhooks and integrations | Outbound SSRF guard; signed callbacks; payload cleaning (`OutboundSsrfTest`, `SecurityHardeningTest`) |
| Export, deletion, holds | Maker-checker, holds, cooling-off, signed certificates, restore reconciliation (`Offboarding/*`) |
| Statutory | The verified-rules gate cannot be bypassed (mutants G1–G5); jurisdictions without rules are blocked |
| Unavailable dependencies | The deletion register fails closed; health readiness degrades with a 503 on configuration errors in production; the signup provisioning lock waits, then retries |

## 3. Open risks and compensating controls

| Risk | Compensating control | Owner action |
|---|---|---|
| **The CSP limits framing only**: there is no script or style policy. Filament and Livewire rely on inline scripts, so a strict CSP needs nonce support and testing | Blade escaping everywhere; validated input; output-encoding tests; `nosniff` | The independent reviewer assesses XSS; a full CSP is a candidate hardening task once that review is done |
| Leaked password usable elsewhere | Refused by every PeopleOS database here | O-1 |
| Public repository | — | O-2, O-3 |
| No alerting or error reporting | Logs and health endpoints | O-11 |
| Operators not enrolled | Readiness fails production | O-14 |
| Owner MFA off by default (D10-8) | Signup is off | D10-8 before signup |

## 4. Scans (latest)

| Scan | Date | Result |
|---|---|---|
| `composer audit` | 11 Oct | 0 advisories |
| `npm audit` | 11 Oct | 0 vulnerabilities |
| Current secrets (`APP_KEY` encoded and decoded, `DB_PASSWORD`, the seed password) in 2,956 tracked files, the final documents and the logs | 11 Oct | 0 occurrences |
| Leaked value in the logs and the final documents | 11 Oct | 0 |
| Leaked value in public refs | 11 Oct | Present in `feature/sep_28_phase_1` (2 files); absent from current files of this branch |
| Leaked value accepted (local accounts) | 11 Oct | 0 of 19 databases; 0 MySQL accounts; 0 `.env` values |
