# 10. Frequently asked questions

## About the product

**What is PeopleOS?**
An online HR system that keeps one record per employee and runs the HR processes around it — from hiring to exit.
See [Chapter 1](01-product-overview.md).

**Do we need to install anything?**
No. PeopleOS runs in a web browser on computers, tablets and phones. There is no native mobile app; the web
application adapts to small screens.

**Which browsers are supported?**
The team tested Chromium-based browsers (for example Google Chrome, Microsoft Edge) and Firefox on desktop, and the
WebKit engine at phone width. Apple Safari and Android Chrome have not been tested yet. Formal browser support:
**to be confirmed with the SaaS provider.**

**Is PeopleOS available in other languages?**
Not today. The interface is in English (UK spelling).

**Is PeopleOS live and generally available?**
Not yet. The provider's release checks — hosting, independent security review, backup and recovery tests and
statutory rule verification — are still open. Ask Markedge for the go-live date. **To be confirmed with the SaaS
provider.**

**How much does it cost? Is there a free trial?**
Prices, plans and trials are agreed with Markedge. **To be confirmed with the SaaS provider.**

**Can we buy it online?**
No. A self-service sign-up feature exists but is switched off; organisations are set up by Markedge.

## Data and security

**Can other companies see our data?**
No. Each organisation has its own private space. Records from other organisations are never shown — even a link from
elsewhere leads to "We could not find that".

**Where is our data stored? How often is it backed up?**
**To be confirmed with the SaaS provider.**

**Can we get all our data out?**
Yes. A Tenant Super Admin or Account Owner can request a full export in **Admin › Platform › All in Platform › Data
export**. The package is encrypted, stays available for a limited time, and each download link works for a few
minutes and only for the person who requested it.

**What happens to our data if we leave?**
Data retention and deletion after the contract: **to be confirmed with the SaaS provider.** (Data deletion is
switched off in the platform today.)

**Does PeopleOS support multi-factor sign-in and single sign-on?**
Yes: authenticator-app MFA with recovery codes, which your administrator can make compulsory, and single sign-on via
OpenID Connect (presets for Microsoft Entra, Google and Okta). See the Administration chapter.

**Can we restrict access to our office network?**
Yes. The security policy has an IP allow-list; people outside allowed networks see "Your network is not allowed to
access this tenant."

**Who can see salaries and personal data?**
Only roles that include those permissions — for example Payroll Admin for sensitive data. Each role is described in
[Chapter 6](06-role-specific-guides.md). Organisation scopes further limit HR and managers to their companies or teams.

**Can Markedge staff see our data?**
Markedge operators work in a separate console. Entering your organisation requires a written reason, is limited to
60 minutes and is recorded in your own audit trail.

**Is there an audit trail?**
Yes. **Insights › Audit › Change history** shows who changed what, when, the values before and after and the reason.
The trail is tamper-evident.

## Using PeopleOS

**I forgot my password. What do I do?**
Click **Forgot password?** on the sign-in page. The link in the e-mail is valid for 60 minutes. See
[9.2](09-troubleshooting.md#92-i-forgot-my-password).

**Why can't I see a menu item my colleague sees?**
Menus depend on roles, organisation scope, feature switches and whether your account is linked to an employee record.
See [9.5](09-troubleshooting.md#95-a-page-or-menu-item-is-not-visible).

**Can I use PeopleOS on my phone?**
Yes, in the browser. On a phone, a bar at the bottom gives quick access to the most important areas for your role.

**Can I change my own bank details or address?**
You request the change through **My HR**; it is applied after the approval your organisation requires. This protects
you from fraud.

**Why do I have to acknowledge policies?**
Your organisation asks you to confirm you have read the current version. Acknowledgements are recorded per version.

**Are surveys really anonymous?**
Anonymous surveys are designed so that answers cannot be linked to the person: participation and answers are stored
separately, results appear only after the survey closes, and breakdowns for small groups are hidden. Confidential
surveys are different — HR can see who answered under strict controls. The survey tells you which type it is.

**Does the AI make decisions about me?**
No. "Ask PeopleOS" explains and summarises using records the person asking may see, shows its sources, and states that
no employment, pay or compensation decision is made by it. By default no external AI service is used.

## Administration

**How do we add users?**
Invite them in **Admin › Setup › Users**. They receive an e-mail with a one-time link valid for 72 hours and set their
own password; administrators never set passwords. Users can also be created automatically through single sign-on or
SCIM if you set these up.

**Can we create our own roles?**
Yes, in **Admin › Setup › Roles** (editing permissions needs a Tenant Super Admin). Nobody can give a role more powerful
than their own.

**Can we add our own fields and forms?**
Yes — custom fields and forms are configured by your administrator, without code.

**Can configuration changes be reviewed before they apply?**
Partly. With the "configuration approval" feature switched on, edits made in the main form of certain configuration
records (medium- and high-risk ones) wait in the Change Centre for approval and can be rolled back. Settings, feature
flags, creating and deleting records, and edits made in tabs apply immediately, and nothing yet stops a person from
approving their own change. See chapter 5.9 for details.

**Can we connect our biometric devices / identity provider / other systems?**
Yes, through devices and punch imports, single sign-on, SCIM, the REST API with scoped API keys, signed webhooks and the
integration hub. Each needs technical set-up.

## Payroll and compliance

**Can we run payroll in PeopleOS?**
Yes, with an important limitation: statutory rules (India) are provided by the platform but none has completed
verification yet. With enforcement on (the default), payroll that relies on an unverified rule cannot be finalised.
Ask Markedge for the verification status, and run PeopleOS in parallel with your current payroll first.

**Which countries does payroll support?**
Statutory payroll rules exist for India (and one UAE rule). Payroll is blocked in a country for which PeopleOS holds no
statutory rules.

**Is gratuity calculated?**
No, not today. The final settlement has no gratuity line; calculate it separately.

**Can PeopleOS produce statutory returns?**
It prepares EPF (ECR), ESI, Professional Tax and LWF returns, TDS statements (Form 138) and TDS certificates
(Form 130) from finalised payroll. Exporting a return is refused until the rules, registrations, establishments and file
layouts it uses are verified — none is verified yet in the shipped rule set. PeopleOS never files anything with an
authority: you file on the authority's portal and record the reference in PeopleOS.

## Support

**How do we contact support?**
Support channels and hours: **to be confirmed with the SaaS provider.** Inside your organisation, employees should ask
HR through **My HR**, and HR should contact your PeopleOS administrator first.

**What should we tell support?**
See the checklist in [9.21](09-troubleshooting.md#921-before-contacting-support). Never share passwords, codes or API
keys.
