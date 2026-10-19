# PeopleOS Customer Handbook — build

| File | What it is |
|---|---|
| `PeopleOS-Customer-Handbook.pdf` | The consolidated handbook (A4): cover, contents with page numbers, all chapters, screenshots and flowcharts, PDF bookmarks. Sized for sharing (screenshots as JPEG at 1280 px); `--tagged` also builds an accessibility-tagged copy |
| `PeopleOS-Customer-Handbook.html` | The same handbook for a browser. It loads screenshots from `../assets/screenshots/`, so keep the `docs` folder structure when you share it |
| `tools/` | The build (`build.mjs`, `handbook.css`) and the screenshot capture scripts (`screenshots/`) |

The Markdown chapters in `../customer-guide/` are the source. Never edit the HTML or PDF by hand.

## Rebuild the handbook

Requirements: Node.js 22, Python 3 with Pillow (used to downscale screenshots for the PDF), the application's own
Playwright Chromium (`npx playwright install chromium` at the repository root, once), and internet access for the Google
Fonts the handbook uses (without it the fallback fonts are used).

```bash
cd docs/handbook/tools
npm ci            # marked, mermaid and pdfjs-dist, pinned in tools/package.json (separate from the application)
node build.mjs    # or: --no-pdf (HTML only), --tagged (also an accessibility-tagged PDF, about 40 % larger)
```

The build:

1. reads the chapters in order (1–12, with the module chapters as 5.1–5.9);
2. renders every Mermaid block to SVG (`../assets/diagrams/`) and writes the generated
   `../customer-guide/08-workflow-diagrams.md` (all flowcharts with their sources);
3. converts Markdown to HTML (figures and captions, numbered per chapter; status badges; callouts; internal links
   between chapters become in-document links);
4. writes `PeopleOS-Customer-Handbook.html`;
5. prints the PDF with Chromium twice: the first pass finds the page of every heading, the second writes the page
   numbers into the contents.

It prints the number of diagrams (and any Mermaid errors), chapters, images used and missing images. Scratch output
goes to `tools/.work/` (gitignored), including `artifact/`, a variant with its images beside it for publishing as a
web page.

## Re-capture screenshots

Screenshots are genuine captures from a **disposable** demonstration database with fictional people only. Never point
the scripts at a real tenant.

```bash
# 1. a disposable database (the seeder refuses any database whose name does not end in _showcase, and production)
DB_DATABASE=hcm_docs_showcase php artisan migrate --force
DB_DATABASE=hcm_docs_showcase php artisan db:seed --class=UxShowcaseSeeder --force

# 2. serve it on its own port, with the array cache (so the sign-in limiter does not throttle the personas)
DB_DATABASE=hcm_docs_showcase CACHE_STORE=array SESSION_COOKIE=peopleos_docs_session \
  php artisan serve --host=127.0.0.1 --port=8097

# 3. run the capture script of the area you changed (screenshots are written to docs/assets/screenshots)
node docs/handbook/tools/screenshots/time.mjs
```

- `screenshots/lib.mjs` signs the showcase personas in (the seeder's fixture password, valid on `*_showcase`
  databases only), captures at 1440 × 900 with 1.5× pixel density, and draws numbered callouts on real elements.
- The Tenant Super Admin persona (`admin@demo.local`) has no password after seeding; set one on the disposable database
  only if you need the Data export screen.
- Scripts that create example records (`org/e.mjs`, `org/f.mjs`, `ppl-*`) were run once; they are kept for reference.
- After a re-capture, look at every image, then rebuild the handbook.

## Where things are

| What | Where |
|---|---|
| Chapters | `docs/customer-guide/` |
| Screenshots | `docs/assets/screenshots/` (`qs-`, `ov-` quick start and overview; `ws-`, `ppl-`, `time-`, `pay-`, `grow-`, `org-`, `svc-`, `ins-`, `adm-` per module chapter) |
| Diagrams | `docs/assets/diagrams/` (generated) |
| Reports | `docs/reports/` (feature inventory, implementation status, documentation gaps) |
