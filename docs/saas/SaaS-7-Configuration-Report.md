# SaaS.7 — Commercial configuration and current statutory values: report

**Pass:** SaaS.7 configuration (two owner prompts of 8 October 2026: "Commercial configuration flexibility / no hardcoded pricing or compliance" and "Populate current statutory values, while keeping everything configurable").
**Branch:** `feature/oct_1_phase_1`. Not pushed, not merged, not deployed. SaaS.8 not started.
**Status: SaaS.7 BLOCKED** — the configuration pass itself is complete and validated; real invoicing still waits for business, legal and tax input (§17, §18).

The principle the pass implements: **prices are data; tax and compliance rules are versioned data; calculation engines are code; historical transactions are immutable; customer deals need no code.** Current statutory values for India, the UK, the EU member states, the UAE and the researched US states are pre-loaded as a dataset with their official sources; they are not hardcoded, they are verified by a second operator before use, and they change by new versions. Loading them is not a claim of permanent legal compliance.

## 1. Starting commit

`7f63abc` (docs: SaaS.7 completion report, ADR-0049..0057, invariants 59-65 and browser evidence).

## 2. Ending commit

Code: `3be7cac` (feat: SaaS.7 commercial configuration — customer deals, versioned policy, statutory dataset, multi-leg tax). Documentation: the commit that adds this report, immediately after it.

## 3. Files changed

Code commit `3be7cac`: 68 files, +6,524 / −396 (Pint also replaced fully qualified class names with imports on some unchanged lines of touched files). Docs commit: the decision register, the security invariants, the SaaS.7 developer's map, the Commercial Decisions (§15), this report and the browser evidence.

**New**

- app/Domain/Billing: `ConfigurationKey.php`, `ConfigurationVersion.php`, `NegotiatedPrice.php`, `NegotiatedPriceVersion.php`, `CommercialConfiguration.php`, `NegotiatedPrices.php`, `StatutoryDataset.php`
- app/Domain/Tax: `TaxRuleState.php`, `EuVatDestination.php`, `UaeVatDestination.php`, `UkVatDestination.php`, `UsSalesTaxDestination.php`, `UsStates.php`, `TaxLeg.php`
- app/Filament: `PlatformCommercialPoliciesPage.php`
- database: `peopleos-statutory-2026.10.json`, `2026_10_27_100001_commercial_configuration_tables.php`
- resources: `platform-commercial-policies.blade.php`
- tests: `CommercialPricingTest.php`, `StatutoryDatasetTest.php`, `CommercialConfigurationConcurrencyTest.php`

**Changed**

- app/Domain/Audit: `AuditAction.php`
- app/Domain/Billing: `ApprovalAction.php`, `BillingPeriod.php`, `InvoiceLine.php`, `SubscriptionBillingTerm.php`, `SupplierProfile.php`, `BillingCatalog.php`, `BillingDirectory.php`, `BillingPeriods.php`, `BillingProfiles.php`, `BillingTerms.php`, `InvoicePresentation.php`, `InvoiceSeries.php`, `Invoices.php`, `PriceNotices.php`, `SupplierProfiles.php`, `InvoiceLineInput.php`
- app/Domain/Payments: `ApprovalDesk.php`, `TdsSettlement.php`
- app/Domain/Tax: `TaxRuleStatus.php`, `TaxTreatment.php`, `TaxUnavailableException.php`, `IndiaGstDeterminer.php`, `TaxRule.php`, `JurisdictionCatalogue.php`, `TaxEngine.php`, `TaxRegistry.php`, `TaxRules.php`, `TaxDetermination.php`, `TaxParty.php`, `TaxQuote.php`
- app/Filament: `PlatformBillingAccountsPage.php`, `PlatformBillingCatalogPage.php`, `PlatformInvoicesPage.php`, `PlatformTaxSetupPage.php`
- config: `peopleos.php`
- resources: `invoice-document.blade.php`, `platform-billing-accounts.blade.php`, `platform-billing-catalog.blade.php`, `platform-tax-setup.blade.php`
- tests: `ArchitectureTest.php`, `BillingPagesTest.php`, `BillingProfileTest.php`, `BillingTestHelpers.php`, `InvoiceTest.php`, `SettlementTest.php`, `TaxEngineTest.php`

## 4. Migrations

One additive migration, `2026_10_27_100001_commercial_configuration_tables`. It writes no row.

| Table | Change |
|---|---|
| `tax_rules` | `effective_to`, `rule_code`, `conditions`, `statutory_notes`, `amount_basis` (exclusive), `source`, `source_reference`, `source_url`, `source_date`, `origin` (operator / dataset), `dataset_version`, `dataset_key`, `dataset_status`, `rejected_by`, `rejected_at`; unique `(dataset_version, dataset_key)` |
| `billing_supplier_profiles` | `registrations` (dated registrations and undertakings: `IN_LUT`, `GB_VAT`, `EU_OSS_NON_UNION`, `AE_TRN`, `US_STATE:US-XX`) |
| `negotiated_prices` (new, tenant-owned) | subscription, plan version, market, currency, interval, basis (PEPM or flat), contract start / end / reference, notes, reason; unique per subscription, plan version, market, interval and contract start |
| `negotiated_price_versions` (new, tenant-owned) | version, status (draft / published / retired), currency, unit amount, minimum quantity, discount %, the standard version it was based on, effective from, maker, publisher, retirer |
| `subscription_billing_terms` | `negotiated_price_version_id`; `plan_price_version_id` and `plan_price_id` nullable (exactly one source, model guard) |
| `billing_periods` | `negotiated_price_version_id`, `price_source`, `discount_percent`, `net_unit_amount_minor`; `plan_price_version_id` and `billing_term_id` nullable (a NO_PRICE_CONFIGURED exception has neither) |
| `invoice_lines` | `negotiated_price_version_id` |
| `configuration_versions` (new, platform) | domain (company_policy / statutory), key, scope (country), value, effective from / to, version, status (pending / approved / rejected / withdrawn), reason, source fields, origin, dataset version, maker, approval, checker |

On a throwaway MySQL 8.4 database it migrated, rolled back (the 3 new tables and 22 new columns removed) and re-applied; the schema after up → down → up is identical to the first up. The three columns made nullable stay nullable on rollback (narrowing them back could fail on rows written meanwhile; documented in the migration). The database was dropped. On the 8090 showcase it added 3 empty tables and 1 migration row; every other table count was identical.

## 5. Pricing architecture

PLAN VERSION → MARKET (one currency) → INTERVAL → PRICE → PRICE VERSION (unit amount, minimum quantity, status, effective from; on sale until the next version starts) → billing terms pin a version.

- **Nothing is shipped.** No market, price or amount exists until an operator creates it (B-4 is the owner's). Every published plan version × market × interval without a price version on sale shows **NO PRICE CONFIGURED** on the catalogue's price matrix (or SCHEDULED when only a future version exists); billing terms cannot be pinned to it and the billing run never bills it (it is never 0, never a fallback, never another market's price, never converted).
- **Versions** are draft → published from a date (maker-checker, B-13) → retired. A published version never changes (model guard); a change is a new version. The catalogue shows each version's state: DRAFT, PENDING APPROVAL, SCHEDULED, CURRENT, SUPERSEDED, RETIRED, with the dates it is on sale (the end is derived from the next version).
- **Markets and currencies are independent.** Each market sells in its own currency; changing one market's price never touches another (`CommercialPricingTest`: India 100.00 → 120.00 leaves the USD price at 12.00). No exchange-rate code exists in billing (architecture test).
- **Pinning (B-15).** Existing subscribers keep their pinned version; an increase reaches them only through a re-pin backed by a written notice of the configured period (30 days, now a policy value); new subscribers get the version on sale.
- **Basis.** PEPM (per employee per month, with an optional minimum) and flat (a fixed monthly amount). Flat is now billed: one unit a month, prorated like any month; × 12 in advance for annual terms, with no true-up.

## 6. Tenant-specific commercial architecture

A **negotiated price** is a customer's deal for its subscription: one plan version, market (and so currency), interval, PEPM or fixed, within a contract window, with the contract reference and notes. Its amounts are versions (unit, minimum, optional discount %, optionally the standard version it was negotiated from), drafted by one operator and published from a date by another (`negotiated_price_publication`), never changed once published.

**Precedence: CUSTOMER AGREED TERMS > PLAN PRICE VERSION > NO PRICE.**
- `BillingTerms::priceFor()` answers which price would apply on a day and why: the deal in force, else the standard version on sale in the tenant's billing market, else NO_PRICE_CONFIGURED with the reason.
- While a deal is in force for a plan version, market and interval, the standard price cannot be pinned for them ("Agreed terms take precedence"); the accounts page does not even offer it.
- Billing terms pin exactly one source; terms pinned to a deal end with its contract. After the contract, billable days without terms are reported as NO_PRICE_CONFIGURED, never billed at the catalogue price silently.
- A deal is in its market's currency and is never based on another currency's price; terms only pin a price of the tenant's billing market.
- A discount is applied once to the unit (net unit, with the configured rounding) and frozen on the period with the list unit; invoices show "agreed price".
- Contract windows of one subscription, plan version, market and interval never overlap (subscription row lock); a renewal is a new window.

The two examples of the prompt, as data only (`CommercialPricingTest`):

| Customer | Configuration | Billed |
|---|---|---|
| Standard | Catalogue PEPM 100.00 | 3 employees → 300.00 |
| Client A | Deal: PEPM 80.00, minimum 250, from contract start | 3 employees → max(3, 250) × 80.00 = 20,000.00, source "negotiated" |
| Client B | Deal: fixed 50,000.00 a month, annual, contract 1 May 2027 – 30 April 2028 | 600,000.00 in advance on 1 May 2027; no true-up; after the contract, May 2028 is a NO_PRICE_CONFIGURED exception |

The standard catalogue and every other customer are untouched; nothing needed code.

## 7. Compliance and tax configuration architecture

JURISDICTION → REGIME → RULE (scope: country, state or member state; category) → VERSION (effective from / to, outcomes) → OUTCOME (treatment, components with rates, taxable share, conditions, required supplier registration, invoice wording, failure code) → STATUS (draft → review → verified by another operator, or rejected; retired) → derived STATE.

- **Legs.** The supplier's regime (India GST, Markedge's Indian entity) is always determined; an export of services adds the **customer country's** leg: UK VAT, EU VAT per member state, UAE VAT, US sales tax per state. Each leg has its own verified rule, treatment, rounding and wording; the invoice stores tax lines per leg and every leg in its snapshot.
- **No single rate anywhere.** India is four outcomes (intra-state CGST + SGST, intra-UT CGST + UTGST, inter-state IGST, export of services); the EU is 27 member-state rules; the US is one rule per state (none national); the UK and UAE distinguish business customers (reverse charge) from consumers / unregistered recipients.
- **Conditions are data, evaluation is code.** Nine named conditions (customer type, registration, tax id type, recipient outside the supplier's country, invoice currency, supplier registration valid on the tax point, reporting currency, local rates required, customer special status). An unknown condition refuses.
- **Reason codes** on every refusal: TAX_CONFIGURATION_MISSING, TAX_RULE_UNVERIFIED, PLACE_OF_SUPPLY_UNRESOLVED, CUSTOMER_TAX_STATUS_UNRESOLVED, SUPPLIER_TAX_STATUS_UNRESOLVED, REGISTRATION_REQUIRED, EXPORT_CONDITIONS_NOT_SATISFIED, TAX_CLASSIFICATION_PENDING (and REPORTING_VALUE_REQUIRED at issue).
- **States.** CURRENT, SCHEDULED, SUPERSEDED, EXPIRED, PENDING VERIFICATION, DRAFT, REJECTED, RETIRED, shown on every rule. The rule in force is the latest started verified version; an expired one leaves nothing (never an older version). Missing configuration is never 0 %.
- **Statute, policy and contract are separate.** Statute: `tax_rules` and statutory parameters (`configuration_versions`, domain `statutory`, scoped by country, e.g. India's 16-character invoice number, CGST Rules r.46(b)). Markedge policy: `configuration_versions`, domain `company_policy` (payment terms 15 days, B2B only, 30-day notice, tax-exclusive prices, half-up proration rounding, TDS in India/INR), shipped defaults in `config/peopleos.php`. Customer contract: negotiated prices and billing terms.
- **Policy is configuration.** A change is proposed from today or later and executed only when another operator approves it (approval desk, `configuration_change`); an approval after the proposed start date is refused (no retroactive change); readers use `CommercialConfiguration::required()`, which refuses ([CONFIGURATION_MISSING]) when a version has expired with no successor.
- **Algorithms stay in code** (determination, place of supply, proration, the billing run, rounding mechanics); parameters moved out: `PEOPLEOS_BILLING_B2B_ONLY`, `PEOPLEOS_BILLING_PAYMENT_TERMS_DAYS`, `PriceNotices::NOTICE_DAYS` (30) and the half-up proration constant are now policy versions; `invoice_number_max_length` left the jurisdiction catalogue for a statutory parameter; `TdsSettlement`'s hardcoded India/INR test is the `settlement.tds_jurisdictions` policy.

## 8. Default statutory values added

Dataset `database/data/statutory/peopleos-statutory-2026.10.json` (researched 8 October 2026 against official sources only): **36 tax rules and 1 statutory parameter**; 35 rules and the parameter are marked `source_verified`, 1 rule `pending_verification`. They are loaded pending verification by one operator and become usable only when a second operator verifies the dataset (`StatutoryDataset`). Rates are the law's; nothing here is a business decision.

| Rule code | Jurisdiction | Effective | Outcomes | Source | Status |
|---|---|---|---|---|---|
| IN-GST-9983-18 | IN | 2025-09-22 → | `intra_state`: CGST 9 % + SGST 9 % (standard)<br>`intra_union_territory`: CGST 9 % + UTGST 9 % (standard)<br>`inter_state`: IGST 18 % (standard)<br>`export_of_services`: no tax (zero_rated; if customer_outside_supplier_country=True, invoice_currency_not=['INR'], supplier_registration=IN_LUT, customer_special_status_not=['supplier_establishment'], reporting_currency=INR; wording “SUPPLY MEANT FOR EXPORT/SUPPLY TO SEZ UNIT OR SEZ DEVELOPER …”) | [CBIC / GST Council](https://taxinformation.cbic.gov.in/api/cbic-notification-msts/download/1010453/ENG) — Notification No. 11/2017-Central Tax (Rate) S.No. 21(ii), as amended by No. 15/2025-Centra… | source_verified |
| GB-VAT-DEST-20 | GB | 2011-01-04 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['GB_VAT'])<br>`b2c_digital_services`: VAT 20 % (standard; supplier must hold GB_VAT) | [HMRC / GOV.UK](https://www.gov.uk/vat-rates) — VAT rates (standard rate 20 % from 4 Jan 2011); VATA 1994 ss.7A, 8 (as reproduced in HMRC … | source_verified |
| AE-VAT-DEST-5 | AE | 2026-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['AE_TRN'])<br>`unregistered_recipient`: VAT 5 % (standard; supplier must hold AE_TRN) | [Federal Tax Authority (UAE)](https://tax.gov.ae/Datafolder/Files/Legislation/2025/Federal-Decree-Law-No-8-of-2017-and-amendments.pdf) — Federal Decree-Law No. 8 of 2017 on VAT (consolidated to FDL 16/2024) Arts. 3, 13, 30, 31,… | source_verified |
| EU-BE-VAT-DEST | BE | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 21 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-BG-VAT-DEST | BG | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 20 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-CZ-VAT-DEST | CZ | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 21 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-DK-VAT-DEST | DK | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 25 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-DE-VAT-DEST | DE | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 19 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-EE-VAT-DEST | EE | 2025-07-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 24 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://emta.ee/en/business-client/taxes-and-payment/value-added-tax/vat-rates-and-supply-exempt-tax/standard-vat-rate) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-IE-VAT-DEST | IE | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 23 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-GR-VAT-DEST | GR | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 24 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08 (TEDB snapshot dated 2025-01-01: stale); Directiv… | pending_verification |
| EU-ES-VAT-DEST | ES | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 21 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-FR-VAT-DEST | FR | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 20 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-HR-VAT-DEST | HR | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 25 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-IT-VAT-DEST | IT | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 22 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-CY-VAT-DEST | CY | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 19 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-LV-VAT-DEST | LV | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 21 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-LT-VAT-DEST | LT | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 21 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-LU-VAT-DEST | LU | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 17 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-HU-VAT-DEST | HU | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 27 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-MT-VAT-DEST | MT | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 18 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-NL-VAT-DEST | NL | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 21 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-AT-VAT-DEST | AT | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 20 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-PL-VAT-DEST | PL | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 23 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-PT-VAT-DEST | PT | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 23 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-RO-VAT-DEST | RO | 2025-08-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 21 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://static.anaf.ro/static/10/Anaf/AsistentaContribuabili_r/Cotele_de_TVA_09.2025.pdf) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-SI-VAT-DEST | SI | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 22 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-SK-VAT-DEST | SK | 2025-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 23 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-pridanej-hodnoty/sadzby-dane) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-FI-VAT-DEST | FI | 2024-09-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 25.5 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://www.vero.fi/en/businesses-and-corporations/taxes-and-charges/vat/rates-of-vat/new-vat-rate-from-1-september-2024--instructions-for-vat-reporting/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| EU-SE-VAT-DEST | SE | 2024-01-01 → | `b2b_reverse_charge`: no tax (reverse_charge; if customer_types=['business'], customer_tax_id_types=['EU_VAT_ID']; wording “Reverse charge”)<br>`b2c_electronic_services`: VAT 25 % (standard; supplier must hold EU_OSS_NON_UNION) | [European Commission — Taxes in Europe Da](https://ec.europa.eu/taxation_customs/tedb/) — TEDB standard rate, retrieved 2026-10-08; Directive 2006/112/EC (consolidated 14.04.2025) … | source_verified |
| US-CA-SAAS | US-CA | 2026-10-08 to 2026-12-31 | `saas`: no tax (not_taxable) | [California Department of Tax and Fee Adm](https://cdtfa.ca.gov/lawguides/vol1/sutr/1502.html) — Sales and Use Tax Regulation 1502(f)(1)(D), (c)(7) | source_verified |
| US-CA-SAAS-2027 | US-CA | 2027-01-01 → | `saas`: STATE 7.25 % (standard; supplier must hold US_STATE:US-CA; if local_rates_required=True) | [California Department of Tax and Fee Adm](https://cdtfa.ca.gov/formspubs/l1036.pdf) — SB 122 (Stats. 2026, ch. 23); CDTFA Special Notice L-1036 (September 2026); CDTFA sales an… | source_verified |
| US-TX-SAAS | US-TX | 2026-10-08 → | `saas`: STATE 6.25 % (standard; on 80 % of the price; supplier must hold US_STATE:US-TX; if local_rates_required=True) | [Texas Comptroller of Public Accounts](https://comptroller.texas.gov/taxes/publications/96-259.php) — Tax Code ss.151.0035, 151.051(b), 151.351; 34 TAC Rules 3.286, 3.330, 3.334; Publication 9… | source_verified |
| US-NY-SAAS | US-NY | 2026-10-08 → | `saas`: STATE 4 % (standard; supplier must hold US_STATE:US-NY; if local_rates_required=True) | [New York State Department of Taxation an](https://www.tax.ny.gov/pubs_and_bulls/tg_bulletins/st/computer_software.htm) — Tax Bulletin TB-ST-128 (updated 31 March 2026); Tax Law ss.1101(b)(6), 1101(b)(14), 1115(a… | source_verified |
| US-WA-SAAS | US-WA | 2026-10-08 → | `saas`: STATE 6.5 % (standard; supplier must hold US_STATE:US-WA; if local_rates_required=True) | [Washington State Department of Revenue](https://dor.wa.gov/taxes-rates/retail-sales-tax) — RCW 82.04.050(6)(b)(i), 82.08.0208(4); WAC 458-20-15503; ESSB 5814 (2025 c 422) | source_verified |
| US-PA-SAAS | US-PA | 2026-10-08 → | `saas`: STATE 6 % (standard; supplier must hold US_STATE:US-PA; if local_rates_required=True) | [Pennsylvania Department of Revenue](https://www.pa.gov/agencies/revenue/resources/tax-types-and-information/sales-use-and-hotel-occupancy-tax/canned-computer-software-digital-goods) — 72 P.S. ss.7201(k)(1), 7201(m)(2) (Act 84 of 2016); Act 13 of 2019 | source_verified |
| parameter `invoice.number_max_length` | IN | 2017-07-01 → | 16 | [CBIC](https://taxinformation.cbic.gov.in/content/html/tax_repository/gst/rules/cgst_rules/active/chapter6/rule46_v1.00.html) — CGST Rules, 2017, rule 46(b) | source_verified |

Notes per jurisdiction:
- **India.** 18 % on IT and software services (heading 9983 and the candidate SACs) under Notification 11/2017-CT(R) as amended by 15/2025-CT(R) (17.09.2025, effective 22.09.2025); no later amendment found through 30.09.2026. Place of supply: IGST Act s.12(2) (domestic), s.13(2) and s.13(12) (recipient's location abroad). Export of services: IGST Act s.2(6) conditions, zero-rated (s.16(1)(a)) without payment under an LUT (s.16(3), Rule 96A), with the Rule 46 export wording; the INR value at the GAAP rate for the date of supply (CGST Rules r.34(2)). Classification (SAC) is **not** shipped: it is a judgement among 998314 / 998315 / 997331 / 998431 / 998439 with no CBIC circular, so India invoices are refused (TAX_CLASSIFICATION_PENDING) until a rule version with the SAC is verified.
- **United Kingdom.** 20 % standard rate since 4 January 2011; B2B: place of supply where the customer belongs (VATA 1994 s.7A), customer accounts by reverse charge (s.8); B2C digital services: UK VAT, the non-UK supplier must register (no threshold). Evidence: the customer's VAT number (Notice 741A 2.4); without it the customer is treated as a consumer.
- **European Union.** Directive 2006/112/EC (consolidated 14.04.2025): B2B Art. 44 + reverse charge Art. 196 with "Reverse charge" on the invoice (Art. 226(11a)), evidence of a validated VAT id (Reg. 282/2011 Art. 18); B2C e-services Art. 58 at the member state's rate, non-Union OSS (Art. 358a–369), no EUR 10,000 threshold for a non-EU supplier. 27 member-state standard rates from TEDB (retrieved 8 October 2026), with national sources where a rate changed recently (Estonia 24 % from 1 July 2025, Romania 21 % from 1 August 2025, Slovakia 23 % from 1 January 2025, Finland 25.5 % from 1 September 2024).
- **United Arab Emirates.** 5 % (Federal Decree-Law 8/2017 Art. 3) since 1 January 2018; electronic services (Executive Regulation Art. 23(2)) supplied from abroad: a VAT-registered recipient accounts by reverse charge (Art. 48; self-invoicing removed by FDL 16/2025 from 1 January 2026); otherwise 5 % due from a UAE-registered supplier (non-resident registration, Art. 13(2), no threshold). The rule version starts 1 January 2026 (the law as amended then).
- **United States.** No national rate: one rule per state, for SaaS only. California: not taxable today (Regulation 1502(f)(1)(D)) and **taxable from 1 January 2027** at the 7.25 % statewide rate (SB 122, CDTFA L-1036) as a second, scheduled version; Texas 6.25 % on 80 % of the price (data processing, 20 % exempt); New York 4 %; Washington 6.5 %; Pennsylvania 6 %. Each needs the supplier's state permit (`US_STATE:US-XX`) and local rates, which are not configured: a US invoice is refused rather than under-taxed (TAX_CONFIGURATION_MISSING, "local … rates"). Economic-nexus thresholds and purchaser use tax are recorded as notes, not evaluated.
- **Parameter.** India: an invoice serial number has at most 16 characters (CGST Rules r.46(b)), from 1 July 2017; series longer than that are refused.

## 9. Values left pending

| What | Why | Effect |
|---|---|---|
| Greece's standard rate (EU-GR-VAT-DEST, 24 %) | TEDB's snapshot for Greece is dated 1 January 2025 (stale); not confirmed against a current Greek source | Shipped `pending_verification`: stays PENDING VERIFICATION after the dataset is verified; Greek consumers are refused (TAX_RULE_UNVERIFIED) until an operator verifies or replaces it |
| 46 US jurisdictions (45 states and DC) | Not researched in this pass | No rule: PENDING VERIFICATION on the US coverage table; invoices to them refused (TAX_CONFIGURATION_MISSING), never 0 % |
| US local (county, city, district) rates in the five researched states | Thousands of rates; need a rate source decision | Refused (`local_rates_required`) |
| India SAC classification | Adviser's judgement (B-8) | India invoices refused (TAX_CLASSIFICATION_PENDING) until a verified version carries it |
| Markedge's LUT, OSS, UK VAT, UAE TRN, US state permits | Registrations are facts of Markedge (B-7, B-9) | Exports refused without a valid LUT; consumer supplies abroad refused (REGISTRATION_REQUIRED) |
| Income-tax TDS rates (India) | Not verified (official site unreachable) | No rate configured; TDS stays a declared amount (B-11) |
| UAE TRN format | Only the FTA form's 15-character limit found | Stored, not validated |

## 10. Admin screens

| Screen | What it shows and does |
|---|---|
| **Billing catalogue** | Price matrix today (every published plan version × market × interval: CURRENT / SCHEDULED / NO PRICE CONFIGURED, with the next version); prices with every version's state and on-sale dates; markets; currency catalogue. Actions: market, price, draft amount, request publication, retire |
| **Billing accounts** (per tenant) | Negotiated prices (deal, contract window and reference, notes, versions with state); billing terms with the source of today's price ("agreed price" / "standard price") or NO PRICE CONFIGURED; periods with their price source. Actions: new negotiated price, draft deal amount (unit, minimum, discount), request deal publication, retire deal amount, set billing terms (agreed versions first; a standard price covered by a deal is not offered), notices, billing run |
| **Tax & invoicing** | Jurisdiction support (supplier / destination side built); statutory dataset (shipped, loaded, verified, pending, with why); tax rules by jurisdiction with state, effective dates, rule code, outcomes (treatment, components, conditions, registration, wording), classification, official source link and verification; US state coverage (51 rows) and EU member-state coverage (27 rows); statutory parameters; selling entities with their registrations. Actions: load statutory dataset (maker), verify statutory dataset (checker), draft rule (incl. state, expiry, source, outcomes JSON), new rule version (pre-filled from a rule), submit, verify, reject, retire, supplier entity (with registrations), number series |
| **Commercial policies** (new) | Markedge policy and statutory parameters: value in force, where it comes from (approved version / shipped default / not configured), the decision behind it, every version with state; change history from the platform audit chain (who, what, before → after, why). Action: propose change (from a date, with source for statutory values) |
| **Approvals** | Now also negotiated price publications and configuration changes (payload, before, after, maker; approve executes, reject / withdraw closes) |
| **Invoices** | Issue form takes the reporting rate, its source and date for foreign-currency India invoices; the document shows each tax leg, the statutory wording and the INR reporting value |

No screen edits a row in place: every change goes through a domain service with an operator, a reason and an audit event.

## 11. Maker-checker behaviour

| Change | Maker | Checker | Refused |
|---|---|---|---|
| Statutory dataset | Loads it (rules pending verification) | Verifies it with a reference (source-verified values only) | The loader verifying it (service; nothing half-verified) |
| Tax rule version | Drafts and submits | Verifies (or rejects) with a reference | The author or submitter verifying or rejecting |
| Standard price publication | Requests | Approves on Approvals (executes) | Self-approval (service and model) |
| Negotiated price publication | Requests | Approves on Approvals (executes) | Self-approval |
| Policy or statutory parameter | Proposes from a date | Approves on Approvals (executes) | Self-approval; approval after the start date |

Every decision is audited on the platform chain (and the tenant chain for a deal) with maker, checker, reason, value before and after, effective date, source and a correlation id.

## 12. Effective dating

- Every version has an effective-from date (today or later for operator changes; the law's date for dataset rules, which describe the law in force) and may have an effective-to date.
- The value on a day is the latest started version in force; a scheduled version activates by itself on its date (California 2027 is CURRENT from 1 January 2027 without any action); an expired version leaves nothing (EXPIRED), never the older one.
- Historical questions are answered with the version of that day (a quote for 31 December 2026 still uses California's version 1).

## 13. Historical immutability

Issued invoices keep their prices, the price source (standard or negotiated version), quantities, the tax rule version of every leg, the statutory wording, the currency and the reporting value in their rows and frozen snapshot; billing periods freeze the price source, list unit, discount and net unit; published price, deal and rule versions and approved configuration versions never change (model guards). Proven by `CommercialPricingTest` (a later price version leaves an issued invoice identical; the pinned subscriber keeps v1) and `StatutoryDatasetTest` (a new rule version supersedes from its date; the old one answers earlier dates and refuses edits and deletion).

## 14. Security and tenant isolation

- Negotiated prices and their versions are tenant-owned and fail-closed: another tenant sees none; without a tenant nothing is visible; pinning terms with another tenant's deal fails ("belongs to another subscription") because it is not even found in the subscription's tenant (`CommercialPricingTest` critical 13–14). Configuration versions are platform records (platform invariant 22, architecture allow-list).
- Every new operation runs `OperatorChange` (platform operator + reason); every tenant user is refused the new Commercial policies page as all billing pages (`BillingPagesTest`).
- Source URLs must be `https://` and render with `rel="noopener noreferrer"`; the dataset is read only from the shipped directory by a `YYYY.MM` version; outcome JSON from the form is validated by the same normaliser as the dataset (known outcome keys, conditions, components, rates).
- No float, no exchange-rate code, no HTTP outside the Razorpay adapter in billing, tax or payments (architecture tests).
- Invariants 66–72 added (`docs/architecture/security-invariants.md`).
- The leaked demo credential noted since SaaS.2, re-checked as booleans only: the platform operator account seeded with it exists in `hcm` and `hcm_ux_showcase` and the old credential still works there (the demo tenant administrator account does not exist in those databases). Rotation is the owner's action; no value was printed or written.

## 15. Test totals

| | Total | Passed | Failed | Skipped | Assertions |
|---|---|---|---|---|---|
| Full suite (`php artisan test --parallel --processes=6`, SQLite) | 1,392 | 1,288 | 0 | 104 | 16,579 |
| MySQL suite (`tests/MySql`, MySQL 8.4) | 104 | 104 | 0 | 0 | 568 |

- The 104 skipped tests in the full suite are exactly the MySQL suite (opt-in, run separately).
- New in this pass: `CommercialPricingTest` (7), `StatutoryDatasetTest` (7), one admin-screen test in `BillingPagesTest`, and 4 MySQL races (12–15).
- Adapted: `TaxEngineTest` (reason codes; destination regimes now "pending tax review" instead of "not supported"; classification no longer required at draft; expiry and unknown-condition drafts refused), `SettlementTest` (US status), `BillingProfileTest` and `InvoiceTest` (the B2B rule is a policy: `policyDefault()`), `BillingPagesTest` (billing-terms options are keyed by source) and the architecture allow-lists (configuration versions are platform-level; deals and configuration audited by their services; the deal service reads subscriptions; the new page uses billing).
- The run before the final one (taken while the MySQL suite ran in parallel) had one failure outside billing: `Ux15WorkTest` expected a Faker-generated name containing an apostrophe ("O'Kon") and compared it with HTML-escaped output. It passed 3 of 3 on its own and in the final full run; it is data-dependent, not caused by this pass.

The 20 cases of the first prompt and the cases of the second:

| # | Case | Where |
|---|---|---|
| 1 | Future price change leaves historical invoices unchanged | `CommercialPricingTest` "never re-prices history…" |
| 2 | Existing subscribers stay pinned | same test (applicable on 15 June is still v1) |
| 3 | New subscribers get the current price | same test (`priceFor` returns v2; pinning v1 refused) |
| 4 | Tenant negotiated price overrides the catalogue | "bills Client A at its agreed PEPM…" |
| 5 | Markets have different prices | "keeps markets and currencies independent…" |
| 6 | Currencies are independent | same test |
| 7 | No INR → foreign conversion | same test (USD tenant: NO_PRICE_CONFIGURED; INR price and INR-based deal refused) |
| 8 | Tax change applies only on/after its effective date | `StatutoryDatasetTest` India v2 from 9 October; California 2027 |
| 9 | Historical invoices keep their rule version | `StatutoryDatasetTest` (8 October answered by v1); `CommercialPricingTest` (issued invoice's rule id unchanged) |
| 10 | Unverified tax rule cannot issue | `StatutoryDatasetTest` (TAX_RULE_UNVERIFIED before verification; Greece) |
| 11 | Approved tax rule can be used | `StatutoryDatasetTest` (IGST 18, CGST 9 + SGST 9, export invoice issued) |
| 12 | Maker cannot approve own tax change | `StatutoryDatasetTest` (loader cannot verify the dataset; submitter cannot reject) |
| 13 | Tenant terms are isolated | "bills Client A…" (Beta billed at the catalogue; Alpha's deal not pinnable for Beta) |
| 14 | One tenant cannot see another's negotiated price | same test (0 rows in Beta's context and without a tenant) |
| 15 | Published price versions are immutable | "applies an agreed discount once…" (deal and standard versions) |
| 16 | Published tax rules are immutable | `StatutoryDatasetTest` India test |
| 17 | Future-dated configuration works | policy test (net 30 from May), California 2027, deal v2 from June |
| 18 | Expired configuration works | policy test (July version expired → NOT CONFIGURED, required() refuses, no fallback) |
| 19 | Configuration changes are audited | policy test (proposed / approved events, actor, before → after); dataset and rule events |
| 20 | SaaS.1–7 behaviour intact | full suite (§15), MySQL suite (§16) |
| — | Current rules load | `StatutoryDatasetTest` "ships the current statutory values as data…" |
| — | US jurisdiction-specific | "keeps US sales tax state by state…" |
| — | EU member-state aware | "applies EU member-state, UK and UAE destination rules…" (DE 19, FR 20, HU 27, FI 25.5, GR pending) |
| — | India export conditions evaluated | "zero-rates an export of services only when its statutory conditions hold…" (no LUT, expired LUT, INR, distinct person, reporting value) |
| — | UAE electronic services / place of supply | EU/UK/UAE test (reverse charge with TRN; 5 % only with a UAE registration) |
| — | Admin screens | `BillingPagesTest` "loads and verifies the statutory dataset, agrees a customer price and changes a policy from the pages…" |

## 16. MySQL, concurrency, mutation, browser, accessibility, visual, performance

| Check | Result |
|---|---|
| **MySQL suite** (MySQL 8.4, disposable concurrency database) | **104 of 104 passed** (568 assertions) in the final run. An earlier run in this pass had one failure, race 15, which found defect 2 below; the Phase 14 scale test passed at every size |
| **Concurrency** | 4 new races (12–15), on top of the 11 billing races: two proposals of one policy at once → distinct version numbers, both accepted; two checkers approve one configuration change → applied once, one audit event; two deals with overlapping windows at once → one refused; two drafts of one deal at once → one refused; the dataset loaded twice and verified by two checkers at once → 36 rules, 35 verified once each, one activation event, the second verifier refused ("nothing waits for verification"). Invariants after each race: no approved-but-unexecuted request, both audit chains valid. **Two defects found by the races:** (1) race 12: two proposals of the same key deadlocked on MySQL's gap lock (the version number is read under `FOR UPDATE` on an empty range) and one operator got a raw SQL error; proposals and tax-rule drafts now retry a deadlocked top-level transaction, which numbers the loser next. (2) race 15 in the full MySQL run: values the dataset marks pending (Greece) stay in review after a verification, so a second, concurrent verifier still found "work", verified nothing and recorded a second activation event; a verification now needs at least one source-verified value or parameter pending, and a sequential feature test covers it. Races 12–15 then passed in 3 of 3 isolated runs and in the final full MySQL run |
| **Mutation** | **40 of 40 killed**, on a private copy of the tree. 37 against the feature tests: precedence (pin and lookup), another market's price, discount, deal minimum, mutable deal version, overlapping windows, two drafts, terms outliving the contract, unpriced days unreported, fixed annual trued up, flat billed per employee, deal from another currency, deal in the past, shipped default after expiry, future policy applied today, retroactive approval, hardcoded payment terms, rejected proposal left pending, expired rule falling back, currency / LUT / registration-validity / supplier-registration / local-rates conditions ignored, taxable share ignored, SAC not required, destination leg skipped, pending dataset values verified, dataset loaded verified, dataset verified again, unknown condition accepted, reporting value not required, unchecked source URL scheme, deals not tenant-scoped, another subscription's deal pinned, terms with two sources. Two survived the first run (local rates, and another subscription's deal of the same tenant: no test told them apart) and were killed after the tests were strengthened. 3 on MySQL: no deadlock retry (race 12), dataset activation without locking the pending rows (race 15: two activation events), deal windows checked without the subscription lock (race 14: two overlapping deals). The first KM2 result was not valid (the unmutated code also produced two events, defect 2 above); it was re-run after the fix, against a baseline that passes |
| **Migration** | §4: up → down → up identical on a throwaway MySQL database |
| **Browser** ([evidence](evidence/SaaS-7-configuration-browser-validation.json)) | **26 of 26 checks** on a disposable `hcm_saas7g_ui_showcase` (showcase seeder + fictional deals and prices; the statutory dataset loaded by one operator and verified by another) on 8094, Chromium, two operators with TOTP: Commercial policies (value in force and its source, pending change with its decision, statutory parameter with its rule, the three kinds kept apart, change history with who and before → after); Tax & invoicing (dataset 36 / 36 / 35 / 1, India rule current with its rates and export conditions and the SAC pending, rule states incl. scheduled California 2027, official sources as safe https links, US state and EU member-state coverage, the selling entity's dated LUT, load / new version / reject actions); the price matrix with NO PRICE CONFIGURED; Client A's deal (contract, minimum 250, 5 % discount, current), today's price from the agreed price and the period billed at it; Gamma (USD market, no USD price) NO PRICE CONFIGURED and its fixed annual deal pending approval; an India draft refused with TAX_CLASSIFICATION_PENDING; the maker cannot approve their own requests; the checker approves the deal (then SCHEDULED) and the policy change (then SCHEDULED, today still 15 days); no horizontal scroll on a phone; the tenant administrator gets 403 on Commercial policies and every billing page with no platform link. Database dropped afterwards |
| **Accessibility** | axe-core (WCAG 2 A/AA, 2.1 A/AA): **0 violations in 11 states** (commercial policies light and dark, propose-change modal, tax & invoicing light and dark, new-rule-version modal, billing catalogue, billing account with a deal and with no price, phone commercial policies, phone billing account). No console errors |
| **Visual** (8092, frozen-clock showcase rebuilt with the new migration) | **120 passed**, 198 skipped by design, no baseline changed |
| **Browser suite** (Chromium and WebKit) | **74 passed**, 6 skipped by design |
| **Showcase (8090)** | Backed up, migrated additively (3 new empty tables, 1 migration row; every other table count identical). All 6 personas sign in (in two batches, to stay under the login rate limit) and their everyday pages answer 200; the 10 platform commercial pages, incl. the new Commercial policies, answer 403 with no platform link. As its existing operator, inside a rolled-back transaction: Commercial policies, Tax & invoicing, the price matrix and a billing account render; the statutory dataset loads (36 rules, 1 parameter) and is verified by a temporary second operator (35 rules and the parameter; Greece pending); the billing run calculates nothing. Every table count identical afterwards. 8090 kept running |
| **Performance** | Billing run, one monthly period: **38 queries at 200, 1,000 and 3,000 employees, the same at a standard and at an agreed price** (time linear, ≈0.2 ms per employee). Configuration pages at 2, 10 and 30 records (deals, policy versions, tenants): Commercial policies 23, Tax & invoicing with the dataset loaded 32, Billing catalogue 26, billing account with deals 35, flat. **Found and fixed in this pass:** the billing account page made ~21 queries per negotiated price (98 → 266 → 686) and Tax & invoicing ~2.7 per rule (99): deals are now loaded once per request with their versions, states derived from the loaded rows (`TaxRules::states()`, checked against `state()` for every rule on four days), and the price matrix is three queries whatever the catalogue size |
| **Security** | §14 |


## 17. Remaining blockers

| Blocker | Owner | What it unblocks |
|---|---|---|
| B-4 prices per market | Business owner | Any real price (the structure, matrix and deals are ready; enter them on the Billing catalogue) |
| B-7 entity details and registrations (incl. the LUT) | Legal / finance | The supplier profile with its registrations; any issued invoice |
| B-8 India SAC, LUT, INR rate-source policy | GST adviser | India invoices (a rule version with the SAC) and exports (LUT on the supplier) |
| B-9 foreign registrations (OSS, UK VAT, UAE TRN, US permits) and US local rates | Tax advisers | Consumer supplies abroad and US invoices |
| Greece rate; the 46 unresearched US jurisdictions | Operator with a current official source | Those destinations |
| B-16 retention | Legal | SaaS.9 |

## 18. Status

**SaaS.7 — BLOCKED.**

The configuration pass is **complete**: prices, customer deals, Markedge policy and statutory values are versioned, effective-dated data, changed by two operators without a deployment; historical transactions never change; nothing is billed at a guessed, zero, borrowed or converted price, and missing tax configuration is never 0 %. The current statutory values of India, the United Kingdom, the 27 EU member states (Greece pending), the United Arab Emirates and five US states ship as a dataset with their official sources, loaded by one operator and verified by another.

PeopleOS contains the current verified statutory configuration and can be updated. It does not claim permanent legal compliance: the values are kept current by new versions, and Markedge's tax advisers remain responsible for its tax treatment.

What still blocks issuing a real invoice is input, now entered as data rather than code: prices (B-4), the selling entity and its registrations including the LUT (B-7), India's SAC classification (B-8), registrations abroad and US local rates (B-9). SaaS.8 was not started; nothing was pushed, merged or deployed.

## Process notes

- Statutory facts come only from official sources (CBIC and GST Council, HMRC / GOV.UK and legislation.gov.uk via HMRC manuals, EUR-Lex and the Commission's TEDB plus national tax authorities, the UAE FTA, the state revenue departments); each rule carries its authority, legal reference, URL, source date and how it was checked. Where a source was stale or an interpretation was needed (Greece; PeopleOS as an electronic service in the UAE; UAE unregistered businesses; SAC), the dataset says so and the value stays pending or is noted for the adviser.
- Interpretations made in this pass (to confirm with advisers; each is data, changeable without code): UK and EU reverse charge require the customer's VAT number (otherwise treated as a consumer); a UAE business without a TRN is treated as an unregistered recipient; US taxable states require the state permit and local rates before any invoice.
- Every amount, GSTIN, registration number and deal in tests is fictional; tests complete India's rule with a fictional SAC (`000000`) only to reach a full quote.
- Pint was run on the files this pass touched (`--dirty`); in some existing files it also replaced fully qualified class names with imports on unchanged lines (cosmetic).
