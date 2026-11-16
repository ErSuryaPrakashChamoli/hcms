# Public website

The marketing website at `/` (home, product, solutions, platform, pricing, about and contact pages, with mega-menu
navigation) and the PeopleOS copy on the sign-in page. Platform operators control all of it from **Platform → Public
website**. Introduced on 2026-11-16 at the owner's request: a site with the same kind of structure as leading HR software
sites (mega menus, module pages, pricing and a split sign-in page), in the PeopleOS brand rather than a copy of any
other site.

## How it works

- **One document, versioned** (`website_revisions`, platform-owned, no tenant). The whole site is one JSON document:
  `site`, `announcement`, `header` (buttons and `menus[]`), `pages[]` (each with `blocks[]`), `footer`, `sign_in` and
  `seo`. `SiteDefaults` holds the shape and the first draft. `SiteContent` fills keys missing from a stored document
  (so fields added later always have a value) but keeps stored lists as they are.
- **Draft → preview → publish.** Every edit goes to the single draft (`Website::saveArea / saveMenu / savePage …`,
  serialised by a cache lock). **Preview** (`/site-preview/{slug}`, platform operators only) renders the draft,
  including hidden pages. **Publish** makes the draft the live revision and keeps the previous one as history.
  **Restore** copies a historic revision into the draft. **Discard** deletes the draft. Every action is audited on the
  platform chain (module `website`).
- **Not live until the first publish.** Before it, or while *Site and brand → Public website is on* is off, `/`
  redirects to `/admin` as it always did and site pages return 404. Deploying this code changes nothing for visitors.

## Rendering (`App\Http\Controllers\Website\SiteController`, `resources/views/website`)

- Routes (end of `routes/web.php`): `/` (home), `/login` (redirect to `/admin/login`, named `login` so the auth
  middleware sends guests there instead of failing), `/robots.txt`, `/sitemap.xml`, `/site-preview/{slug?}`,
  `POST /enquiry` (throttle 5/min) and `/{slug}`. The page route is a **fallback route**, so every workspace route wins
  over it. `Website::assertSlugAllowed` also refuses page addresses whose first part is a registered route or a
  `public/` path.
- Blocks: `hero`, `logos`, `features`, `spotlight`, `stats`, `steps`, `pricing`, `quote`, `faq`, `text` (Markdown, HTML
  escaped), `contact` and `cta`. Illustrations (`visuals/*`) are HTML/CSS mock-ups, so there are no image files to
  manage. Icons are a curated Heroicons list (`SiteIcons`) and hues are the workspace `--pos-hue-*` family.
- Styles in `resources/css/site.css` (the `ps-` namespace: ink → iris, champagne accent, Instrument Sans/Serif);
  behaviour in `resources/js/site.js` (mega menus with hover intent, click and keyboard; the phone sheet; dismissible
  announcement; reveal on scroll). Both are Vite entries. Pages work without JavaScript.
- Safety: all text is escaped and links go through `SiteLink::href` (site paths, http(s), mailto and tel only).
  Block types, illustrations and icons come from fixed lists. Security invariants 126–128.

## Search engines

Off by default: every page says `noindex` and `robots.txt` disallows everything (invariant 118). With *Search engines →
Allow search engines* on and the site live, published pages send `X-Robots-Tag: index, follow`, robots.txt disallows
every workspace path and allows the rest, and `/sitemap.xml` lists the published pages. The static `public/robots.txt`
was removed so the route can answer.

## Sign-in page

`resources/views/filament/auth/login.blade.php` (the branded split sign-in, built in a parallel session) reads
`Website::signIn()`. This never throws and falls back to the defaults. On the PeopleOS (unbranded) page, the copy comes
from the website: the headline, a highlighted line in gold italic, and the lead. Both the PeopleOS page and an
organisation-branded page can show:
- a notice above the form (information or warning, e.g. maintenance);
- a help link (a site path, http(s) or mailto);
- app store links (http(s) only);
- "Back to the website" when the site is live (unbranded page only).

Styles: theme.css §R. An organisation's own branding stays in Setup → Core HR → Sign-in page.

## Enquiries

The contact block's form posts to `website.enquiry`. It is rate-limited, has a hidden honeypot field, and stores the
visitor's IP only as an HMAC. Submissions land in `website_enquiries`, and operators move them through New →
Contacted → Closed under *Enquiries* (audited). Both tables are platform-owned in the data inventory.

## Mobile verification on the contact form (2026-11-16)

Full name and work e-mail are always required. **Work e-mail only** (on by default) refuses free and throwaway providers
(`PersonalEmailDomains`: Gmail, Yahoo, Outlook, Hotmail, iCloud, Rediffmail, Mailinator, …; the operator can block
more domains). The browser warns when the visitor leaves the field, and the server refuses the enquiry.

The mobile number follows the contact block's **Mobile number** setting:
- `verify` (the default): required, and confirmed with an SMS code;
- `optional`: asked for but not verified;
- `hidden`: not asked.

How verification works (`PhoneVerifications`, `SiteController::sendCode` / `verifyCode`, routes `website.enquiry.code`
and `.verify`):
- The visitor picks a country code (India first) and types the number. Indian numbers must be 10 digits starting 6–9.
- **Send code** texts a 6-digit code that is valid for 10 minutes. The code is kept in the cache only as an HMAC.
- Each code allows 5 tries. A number can get a new code every 30 seconds and 5 codes an hour; an address can request
  10 codes an hour.
- A successful check gives a token, and the enquiry is accepted only with a token verified for the same number. A
  token is used once. `website_enquiries.phone_verified_at` records the verification, and Enquiries shows Verified /
  Not verified.

SMS goes through `App\Support\Sms\SmsGateway`, chosen by `PEOPLEOS_SMS_DRIVER` (keys in `.env.example`):
- `log` (the default): the message is only written to the log, and a local or testing server also shows the code on
  screen. No other environment ever shows it.
- `msg91`: India; needs a DLT-approved Flow template whose OTP variable is `PEOPLEOS_SMS_MSG91_OTP_VAR`.
- `twilio`.

The production config check advises when the driver is still `log`. Tests: `tests/Feature/Website/PhoneVerificationTest.php`.

## Editor (`App\Filament\Pages\PlatformWebsitePage`, slug `platform-website`)

A masthead shows the live status, the unpublished areas and the Publish / Preview / Discard buttons. The areas run down
the left (pages, header and menus, announcement, footer, sign-in page, site and brand, search engines, enquiries,
history); the open area fills the panel. Every edit is a step wizard (`WebsiteForms`): a menu goes Menu → Columns →
Feature card → Footer strip; a page goes Page → Sections (a Builder of the blocks) → Search and sharing. Styles:
theme.css §S.

## Menus, tools and resources (2026-11-16, second pass)

The header's drop-downs follow the structure of leading HR software sites:
- **Product**: 16 modules in three columns plus a Platform column and a feature card.
- **Solutions**: by team and by industry.
- **AI for HR**: the assistants, plain-English search, the payroll auditor and the AI data boundary.
- **Pricing**: a plain link.
- **Resources**: glossary, guides, policy templates and company pages (`SiteResources`).
- **Tools**: eight letter generators and four calculators.

The header's **Log in** button opens `/admin/login`. Two block types run entirely in the visitor's browser
(`resources/js/site.js`), and nothing typed is sent:
- **letter**: the template's `{placeholders}` become fields; a placeholder ending in `_date` becomes a date field.
  Visitors can copy, print or save the letter as PDF.
- **calculator**: gratuity (15 ÷ 26 or 15 ÷ 30, with an operator-set ceiling), CTC to in-hand (PF on a capped basic,
  gratuity at 4.81%), leave encashment and notice period. Each one shows its working and a note on its assumptions.

There are 60 default pages in all.

## Default content and what the owner must supply

The first draft describes only features that exist in PeopleOS (16 module pages, 4 platform pages, 9 solution pages,
pricing, about and contact). It deliberately leaves out:
- customer names, testimonials and certifications;
- prices (plans show "Ask us" until real figures are entered);
- legal pages (privacy policy, terms);
- social profiles, sales e-mail and phone;
- app store links.

Add these in the editor before turning on search-engine indexing.

Tests: `tests/Feature/Website/PublicWebsiteTest.php`.

Testing: the bootstrap refuses to run when the configuration is cached (`tests/TestCase.php`). A cached config makes
Laravel ignore phpunit.xml, so `RefreshDatabase` would wipe the real MySQL database. If the config is cached, run with
`APP_CONFIG_CACHE` (and the routes and events equivalents) pointed at a missing path.

