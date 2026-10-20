# Commercial configuration runbook

How Markedge operators enter and change prices, customer deals, policy and statutory values in PeopleOS. **None of these needs a developer or a deployment.** Every change is made on a platform page by one operator (the maker), takes effect only when **a second operator** (the checker) approves or verifies it, applies **from an effective date** (today or later), and never changes an invoice already issued.

Pages (platform operators only; tenant users get 403): **Billing catalogue**, **Billing accounts**, **Tax & invoicing**, **Commercial policies**, **Approvals**, **Invoices**, **Payments**.

```
CONFIGURATION (maker) → SECOND-OPERATOR APPROVAL (checker) → EFFECTIVE DATE → ACTIVE → TRANSACTION → IMMUTABLE RECORD
```

General rules:
- Give every change a reason (5 to 500 characters); it is kept in the audit trail with who, when, the value before and after.
- The maker never approves their own request: another operator opens **Approvals** and chooses **Approve and carry out** (or **Reject**). The maker may **Withdraw my request**.
- A request approved after its proposed start date is refused: propose it again from today or later.
- Missing values are never assumed. Until a value is configured, PeopleOS shows **NO PRICE CONFIGURED**, **NOT CONFIGURED** or **PENDING VERIFICATION**, and refuses to bill or to issue rather than use zero, another market's value or a converted one.

## 1. Selling entity, registrations and the LUT (decision B-7)

Where: **Tax & invoicing → Propose selling entity version**.

1. Maker enters the entity code (e.g. the code the market uses), legal name, address, country and state, the tax registration type and number (for India the GSTIN, validated by format, check character and state), and the effective date.
2. In **Registrations and undertakings**, one per line: `TYPE reference valid-from [valid-to]`. Types the tax rules use:
   - `IN_LUT` the Letter of Undertaking (ARN or reference, financial-year validity) — needed for zero-rated exports of services;
   - `GB_VAT` UK VAT registration; `EU_OSS_NON_UNION` the EU non-Union OSS registration; `AE_TRN` UAE TRN;
   - `US_STATE:US-XX` a US state sales-tax permit (e.g. `US_STATE:US-TX`).
3. Checker approves the **Selling entity change** on **Approvals**. Until then the version shows **PENDING APPROVAL** and is not used for invoices or numbering.
4. A renewed LUT (new financial year) or any new registration is a **new version** of the entity with the full registration list.

Then: **Tax & invoicing → New number series** for the entity (prefix, first and last day, sequence digits). India: the number is at most 16 characters (statutory parameter `invoice.number_max_length` IN); a longer prefix and padding are refused.

## 2. Standard prices (decision B-4)

Where: **Billing catalogue**.

1. **New market** (once per market): code, currency (permanent), countries it is meant for, selling entity code, display locale.
2. **New price**: a published plan version × market × interval (month or year) × basis:
   - *per employee per month* (PEPM: the amount is per employee per month whatever the interval; optional minimum quantity);
   - *fixed per billing interval* (the amount per month for monthly terms, per year for annual terms).
3. **Draft amount**: the amount in the market's currency (major units, exact to its decimals) and the minimum quantity.
4. **Request publication** from a date; checker approves **Price publication**.
5. Check the **Price matrix today**: every plan version × market × interval shows CURRENT, SCHEDULED or NO PRICE CONFIGURED.

A later price is a new version from a date. Existing subscribers keep their pinned version; an increase reaches them only after a written notice of the configured period (**Billing accounts → Record price notice**, then re-pin on the date).

## 3. Customer deals (negotiated prices)

Where: **Billing accounts** (choose the tenant).

1. **New negotiated price**: subscription, plan version, market (the deal is in that market's currency, never converted), interval, basis (PEPM or fixed per billing interval), contract start, optional contract end, contract reference, notes.
2. **Draft deal amount**: the agreed unit amount, the minimum quantity (PEPM only) and an optional discount % (applied once to the unit).
   - Example A: PEPM ₹80, minimum 250 → amount `80.00`, minimum `250`.
   - Example B: fixed annual commitment ₹600,000 → interval *year*, basis *fixed*, amount `600000.00`.
3. **Request deal publication** from a date within the contract; checker approves **Negotiated price publication**.
4. **Set billing terms** for the subscription: the agreed version is offered first; while a deal is in force the standard price for the same plan version, market and interval is not offered. Terms pinned to a deal end with the contract; pin the next terms explicitly (renewed deal or standard price), otherwise those days are reported as NO_PRICE_CONFIGURED and not billed.

A renegotiation is a new version of the deal; a renewal after the contract ends is a new deal with a new contract window.

## 4. Markedge policy

Where: **Commercial policies → Propose change**; checker approves **Policy or statutory parameter change**.

| Setting | Value format | Shipped default (decision) |
|---|---|---|
| Payment terms (days after issue) | whole number 0–365 | 15 (B-11) |
| Sell to businesses only (B2B) | true / false | true (B-5) |
| Written notice before a price increase (days) | whole number 0–365 | 30 (B-15) |
| Prices include tax | true / false | false (B-5; tax-inclusive invoicing is not enabled, so true refuses issue) |
| Rounding of a prorated line | half_up / half_even | half_up (B-3) |
| Where customers may deduct income-tax withholding (TDS) | `COUNTRY:CURRENCY`, comma-separated (e.g. `IN:INR`); empty = nowhere | IN:INR (B-11) |

A version may have an end date; after it, the setting is NOT CONFIGURED (billing refuses with `[CONFIGURATION_MISSING]`) until another version starts — it never falls back to an older value.

## 5. India GST (decision B-8)

The shipped dataset holds the current India rule (18 %: CGST 9 + SGST 9 or CGST 9 + UTGST 9 intra-state, IGST 18 inter-state; export of services zero-rated only under its conditions). It is **without the SAC**, so every India invoice is refused with `TAX_CLASSIFICATION_PENDING` until the adviser's SAC is recorded:

1. **Tax & invoicing → New rule version**: choose the current India rule (`IN-GST-9983-18`); the outcomes are pre-filled. Enter the effective date, the classification `sac=<the adviser's code>`, the rule code, and the source (the adviser's opinion reference).
2. **Submit for review**; another operator **Verify rule** with the review reference.
3. From its date, India invoices carry the SAC; earlier dates keep the earlier version.

Exports also need the entity's `IN_LUT` (section 1), an invoice not in INR, and, at issue, the INR reporting value: on **Invoices → Issue invoice**, enter the rate, its source and date (CGST Rules r.34(2)); it is frozen on the invoice.

## 6. Foreign customers (decision B-9)

Shipped destination rules: UK VAT 20 %, the 27 EU member-state rates (Greece pending), UAE VAT 5 %, five US states. What is still needed is data:

- **Registrations abroad**: record `GB_VAT`, `EU_OSS_NON_UNION`, `AE_TRN` or `US_STATE:US-XX` on the selling entity (section 1). Until then consumer supplies there are refused with `REGISTRATION_REQUIRED`; business customers with a valid VAT number / TRN are reverse charge.
- **Greece**: **Tax & invoicing → Verify rule** (if the 24 % rate is confirmed from a current Greek source) or **Reject rule** and draft a new one.
- **Other US states**: **Draft tax rule** → regime US sales tax, the state, outcome `saas` (components e.g. `STATE <rate>`, treatment, taxable share, required registration `US_STATE:US-XX`, condition `local_rates_required` where the state has local taxes), effective date, source; submit and verify.
- **US local rates** (county, city, district), from the rate source Markedge chooses:
  1. **Draft tax rule** → US sales tax, the state, **Local tax jurisdiction** = the jurisdiction code from the rate source (e.g. `TX-...`), outcome `saas` with components `CITY`, `COUNTY`, `DISTRICT` and the same taxable share as the state; submit and verify.
  2. On the customer's **Record billing profile**, enter the same **Local tax jurisdiction** code.
  3. Where a state rule requires local rates, a customer without a recorded local jurisdiction is refused (`PLACE_OF_SUPPLY_UNRESOLVED`) and a jurisdiction without a verified local rule is refused (`TAX_CONFIGURATION_MISSING`); the state rate is never used alone.

## 7. Statutory updates

- **One rule changes** (a new rate, a new treatment): **New rule version** from the law's effective date (pre-filled from the current one), with the source; submit; another operator verifies. Invoices before the date keep the earlier version.
- **A refreshed dataset** (a new `database/data/statutory/peopleos-statutory-YYYY.MM.json` shipped with a release): **Load statutory dataset** (maker), then **Verify statutory dataset** (checker, with a verification reference). Values the dataset marks pending stay pending until verified one by one.
- **A statutory parameter** (e.g. the invoice-number length): **Commercial policies → Propose change**, with the authority and legal reference (required).

## 8. Checking what applies

- **Commercial policies**: each setting's value today, where it comes from (approved version or shipped default) and its history; the change history of prices, deals, tax rules, entities, policies and the dataset.
- **Tax & invoicing**: rule states (CURRENT, SCHEDULED, SUPERSEDED, EXPIRED, PENDING VERIFICATION, REJECTED, RETIRED), sources, US state and EU member-state coverage, local rules, entity versions.
- **Billing accounts**: today's price per subscription and where it comes from (agreed price / standard price / NO PRICE CONFIGURED).
- **Invoices**: a draft says why it cannot be issued today (with the reason code); an issued invoice shows its tax legs, statutory wording, reporting value, and keeps in its snapshot the policy versions it used.
- **Payments**: chargeback (dispute) events are not processed yet (B-12, with the provider's activation); they are flagged "chargeback not processed: handle manually" and logged.
