# SaaS.2 — Working Baseline

Recorded on 6 October 2026, before any SaaS.2 code change.

| Item | Value |
|---|---|
| Branch | `feature/oct_1_phase_1` |
| HEAD | `349ae1d` (SaaS.1 documentation commit), as the brief requires |
| Working tree | Clean (no uncommitted or untracked files) |
| Showcase server | `127.0.0.1:8090` running from this working tree against `hcm_ux_showcase`; not touched. Code changes are live there, so new code must tolerate a database that has not run the SaaS.2 migrations |

## Findings re-verified against the code

Every SaaS.1 finding in scope was located again in the code at `349ae1d`. None had been fixed after SaaS.1.

| SaaS.1 id | Finding | Re-verified evidence | Status at baseline |
|---|---|---|---|
| W1 | Tenant "MFA required" never applies; no MFA enrolment | `AdminPanelProvider.php:204`: `isRequired` is a closure that Filament evaluates once, when routes are registered, with no tenant bound, so it is always false. No set-up route or profile page is registered | Confirmed |
| W1 (new detail) | MFA is challenged only inside Filament's login form | A remember-me cookie opens a new session without any challenge; `SsoController::callback` calls `Auth::login` directly | Confirmed (new) |
| W2 | No password reset, e-mail verification or self-service password change | No `passwordReset()`, `emailVerification()` or `profile()` on the panel; `password_reset_tokens` unused | Confirmed |
| W2 (new detail) | Filament's default reset pages enumerate accounts | `RequestPasswordReset::request()` shows the broker status (e.g. "We can't find a user with that email address"); `ResetPassword::resetPassword()` distinguishes an unknown user from an invalid token | Confirmed (would apply as soon as reset is enabled) |
| — | No invitation lifecycle | `UserForm` makes administrators type the user's password; `UserStatus::Invited` exists but nothing uses it | Confirmed |
| W4 | Operator tenant entry unaudited and reasonless | `TenantsTable.php:36-44` writes `platform.active_tenant_id` to the session; `ExitTenantController` forgets it; neither audits | Confirmed |
| W4 | Tenant record edits land in the entered tenant's audit chain | `AuditRecorder::record` takes the chain from the entity's `tenant_id` (a `Tenant` has none) and then from the bound context | Confirmed |
| W5 | Download routes skip tenant status and security policy | `routes/web.php`: five routes run `web, auth, ResolveTenant` only. No user-status, tenant-status, IP allow-list, idle-timeout, MFA or session-password check | Confirmed |
| W5 | Suspension does not end sessions | Filament's `Authenticate` answers 403 while the tenant is suspended, but the session survives and works again after reactivation; download routes keep working throughout | Confirmed |
| W5 | Tenant edit form can change `status` directly | `TenantForm.php:35-38` shows `status` on edit; `EditTenant` saves it without the reasoned suspend flow | Confirmed |
| — (new) | SSO ignores tenant status | `SsoController` / `Sso::resolveUser` link or provision the user and record a login audit for a suspended tenant before the panel refuses | Confirmed (new) |
| W14 | Provisioning drops `tier`, `region`, `trial_ends_at` | `ProvisionTenantAction.php:50-58` copies seven attributes | Confirmed |
| H-1 | Payroll population includes pre-joining employees and names states that do not exist | `PayrollRuns.php:67,82`: `whereNotIn(['pre_employee','alumni','offer_accepted','candidate'])`. `preboarding` and `onboarding` (pre-joining; "Mark as joined" is offered from both) pass, and RMS pre-employees have a null `joining_date`, which also passes the date filter | Confirmed |
| H-2 | Admin Centre checks keys that do not exist | `AdminCentre.php:65`: `customfield.view` and `feature.view`; the catalogue has `custom_field.view` and `features.view` | Confirmed |
| H-3 | API rate-limit setting undefined | `AppServiceProvider.php:539` reads `peopleos.api.rate_limit_per_minute`, which `config/peopleos.php` does not define, so 120 always applies. The named `ai` limiter is registered but never used (`AiGateway` limits itself) | Confirmed |
| H-5 | Hard-coded credentials in seeders | `DatabaseSeeder.php`: the platform administrator, the demo tenant administrator (a real-looking password for an address on the owner's domain), and demo actors. The same demo password is also printed in `docs/architecture/phase-1-foundation.md`. `UxShowcaseSeeder.php` uses a fixed password for showcase personas but refuses any database not named `*_showcase` | Confirmed, broader than reported |
| W12 / H-6 | Workflow webhook node unsigned | `Workflow/Jobs/SendWebhook.php`: no signature, timestamp or delivery id. No shipped workflow template uses webhook nodes (tests only) | Confirmed |
| W9 / H-7 | Idempotency keys never cleaned up | `api_idempotency_keys.expires_at` is written (24 h) but nothing purges rows and `EnforceIdempotency` ignores `expires_at`, so a key is blocked or replayed forever | Confirmed |

## Findings already resolved

None. HEAD is the SaaS.1 commit itself.

## Findings needing an architectural decision

These are recorded here and handled in the final report. They are not invented answers.

| Topic | Why it needs a decision |
|---|---|
| Read-only operator access | Platform operators pass every check through `Gate::before` and `User::hasPermission`, and many screens test `isPlatformAdmin()` directly. A read-only mode that misses some write paths would be worse than none. A credible one needs the platform permission catalogue (SaaS.1 G-PLAT-1) |
| Trusting the identity provider's MFA | SSO users would otherwise need a local authenticator when their tenant requires MFA. Trusting the IdP's `amr` claim is a product and security decision (SaaS.1 W3) |
| One e-mail address across tenants | `users.email` is globally unique (SaaS.1 W10, decision D-17). An invitation to an address used in another tenant fails, and the error reveals that the address exists |
| SSO enforcement | `sso_connections.enforce` is stored but unused, so SSO users can still use a local password and password reset (SaaS.1 W3) |
