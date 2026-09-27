# Phase 16 — Enterprise and International

Blueprint §82, §87, §88, §96, §109, §110, §121 Phase 16. Built 2026-09-28.

## What exists

**Single sign-on (`App\Domain\Enterprise\Services\Sso`, `SsoConnection`)** — OpenID Connect authorization-code login per tenant: presets for Microsoft Entra ID, Google Workspace and Okta plus generic OIDC (authorization, token and userinfo endpoints; client secret encrypted at rest). Routes `/sso/{slug}/redirect` and `/sso/{slug}/callback` with state verification; identity is read from the provider's userinfo endpoint over TLS. Allowed email domains, auto-provisioning with a default role, audited provisioning and logins; the user record keeps `sso_subject` / `sso_connection_id`.

**SCIM 2.0 (`Scim`, `/api/scim/v2`)** — `ServiceProviderConfig`, `ResourceTypes`, `Users` (list with `userName` / `externalId` filters and pagination, create, replace, patch `active` / names / `externalId`, delete = deactivate). Authenticated with an API key carrying the `scim` scope as a Bearer token. New logins are linked to an existing employee by `employeeNumber` or work email.

**Multi-factor authentication** — Filament app authentication (TOTP with recovery codes) is enabled on the panel; `security.mfa_required` forces set-up for every tenant login. `User` stores the encrypted secret and recovery codes.

**Security policy (`SecurityPolicy`, `EnforceSecurityPolicy` middleware)** — IP allowlist (exact, wildcard, CIDR) for tenant users (platform admins exempt), session idle timeout, password rules (length, mixed case, digit) and expiry via `password_changed_at`, per-user locale applied on every request. Settings edited on the **Security policy** page.

**Advanced API (§87)** — read endpoints under `/api/v1` with per-resource scopes: employees (+ single), attendance records, leave requests and balances, payroll runs and payslips (sensitive scope), documents metadata, assets, appraisals, goals, workflow instances and tasks, `reports/{id}/run` for shared reports. Paginated (`page`, `per_page` ≤ 200), simple filters, tenant fixed by the key.

**Webhooks (§88, `Webhooks`, `WebhookEventBridge`)** — endpoints subscribe to named events from `peopleos.enterprise.webhook_events` (or `*`); every domain event class publishes a delivery per subscribed endpoint; `peopleos:webhooks:deliver` (every minute) posts JSON with `X-PeopleOS-Event`, `X-PeopleOS-Delivery`, `X-PeopleOS-Timestamp` and `X-PeopleOS-Signature: sha256=HMAC(timestamp.body)`, retries with exponential backoff up to `webhook_max_attempts`, then marks the delivery failed. Deliveries tab with payload, response excerpt and retry; "Send test" ping.

**Country framework (§96, `CountryPacks`)** — `database/data/countries/packs.php` describes Country → Currency → Tax framework → Social security → Labour rules → Documents → Localisation for IN (complete), AE, GB, US, SG, AU, CA. Companies carry `country_code`; the statutory profile's `jurisdiction` selects the engine: **india** (Phase 9 engine) or **generic** (`StatutoryEngine::applyGeneric`: `SS` employee / employer rates within floor and ceiling, `TAX` annual slabs less standard deduction spread monthly) reading rules from `database/data/compliance/<jurisdiction>.php` (`ae.php` ships as an illustrative pack). Number and date formatting per pack (Indian lakh grouping).

**Multi-currency (`CurrencyRates`, `ExchangeRate`)** — dated rates per tenant with inverse derivation; conversion to the tenant base currency (the tenant's `currency`). Exchange rates resource.

**Localisation** — `users.locale` and `tenants.locale`; middleware sets the app locale per user; supported locales in `peopleos.enterprise.locales`.

**Dedicated tenants and warehouse (§110)** — `tenants.tier` (shared / dedicated) and `tenants.region` on the platform tenant form; `peopleos:warehouse:export` writes every dataset as JSON Lines with a manifest to the warehouse disk per tenant per day; `peopleos:audit:export` writes the hash-chained audit trail to CSV; `peopleos:retention:purge` (daily) applies per-tenant retention windows to AI interactions, notification deliveries, report runs and webhook deliveries (audit events are never purged).

## Admin UI ("Enterprise" group)

Single sign-on, Webhooks (+ deliveries), Security policy, Exchange rates, Country packs. Platform → Tenants gains tier and data region.

**Permissions** — `sso.manage`, `webhook.manage`, `security.manage`, `currency.manage`, `warehouse.export`; Auditor gains `webhook.manage` for delivery inspection. API scopes extended in `peopleos.api.scopes`.

## Conventions

- Country-specific statutory logic stays behind the `statutory_engine` switch; adding a country means a pack entry plus a compliance data file, never edits to the India engine.
- Webhook payloads carry identifiers and business fields from event context, never sensitive text; consumers verify the HMAC with `Webhooks::verify`.
- SCIM and SSO write only `users`; employee data stays under HR control.
- Tests: `tests/Feature/Enterprise/{EnterpriseTest,ReadApiTest}.php`, `tests/Feature/Admin/EnterprisePagesRenderTest.php`.

## Known gaps / deferred

- SAML 2.0 is not implemented (OIDC only); SCIM groups → roles mapping is not implemented.
- Only the UAE illustrative compliance file exists beyond India; other packs carry metadata without rate tables.
- Localisation ships the plumbing (locale per user / tenant, formats per pack) but no translated language files.
- Dedicated-tenant database routing is recorded as metadata (`tier`, `region`) and not enforced by connection switching.
