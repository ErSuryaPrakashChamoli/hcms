# PeopleOS visual regression references

Experience Transformation §52. These are reference screenshots of the redesigned experience. Use them to spot unintended visual change when the theme, shell or components are touched.

## How they were made

- **Data:** a disposable `hcm_ux_showcase` database built by `UxShowcaseSeeder`. Every person in it is fictional, and the seeder refuses to run on any database whose name does not end in `_showcase`.
- **Browser:** headless Chromium (Playwright 1.62.1).
- **Viewports:** 1440 × 900 (desktop) and 390 × 844 (phone).
- **Motion:** `prefers-reduced-motion: reduce`, so captures are deterministic.
- **Script:** `capture.mjs` in this folder. Its header has the full command sequence, including how to seed the showcase database and start the server.

To compare after a change, regenerate into a scratch folder and diff the images (for example with `pixelmatch` or an image-diff tool in review). Treat any change to tokens, the shell or a component as needing a fresh, reviewed set.

## Index

| # | Screen | Persona (fictional) | Theme | Viewport |
|---|---|---|---|---|
| 01 | Home | Employee (Priya) | light | desktop |
| 02 | Home | Manager (Amit) | light | desktop |
| 03 | Home | Manager | dark | desktop |
| 04 | Home | HR (Neha) | light | desktop |
| 05 | Home | Executive (Meera) | light | desktop |
| 06 | Approval Center | Manager | light | desktop |
| 07 | My work | Manager | light | desktop |
| 08 | Command center, "leave" | HR | light | desktop |
| 09 | Employee 360 | HR | light | desktop |
| 10 | Employee 360 | HR | dark | desktop |
| 11 | People directory | HR | light | desktop |
| 12 | Organisation map | HR | light | desktop |
| 13 | Workforce Command Center | Executive | light | desktop |
| 14 | Notification center | Manager | light | desktop |
| 15 | Home | Employee | light | phone |
| 16 | Approval Center | Manager | light | phone |
| 17 | People directory | HR | dark | phone |
| 18 | Person drawer | HR | light | desktop |
| 19 | Assistant panel (Summarise on the 360) | HR | light | desktop |
| 20 | Admin Centre (all modules) | HR admin (Kavya) | light | desktop |

## Accessibility audit alongside the references

The same screens were audited with axe-core 4 (WCAG 2.0 / 2.1 / 2.2, A and AA), in light and dark, for the employee, manager and HR personas. The open command center, person drawer, assistant panel and shortcut sheet were audited too. The final run reports no violations. See the transformation report, §23.
