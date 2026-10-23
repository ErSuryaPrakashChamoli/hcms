# Letters — templates, variables, Word files and signatories

For: engineers changing how PeopleOS builds letters. Extends the letter factory of Phase 13 (`phase-13-exit-alumni.md`). Built 2026-10-22.

## What a client can do

- **Write a template in PeopleOS**: rich-text editor (headings, alignment, tables, lists) with a **{ } variable picker**. Variables show as chips; `{{ variable }}` typed as text works too.
- **Upload their own Word letter** (.docx): their letterhead, logo, fonts and layout, with `{{ variables }}` typed in Word. Every letter is a filled copy of that file and is issued as a .docx.
- **Salary annexure**: `{{ salary.annexure }}` on a line of its own prints a table of every component, monthly and yearly, with gross salary, employer contributions and CTC. Each component also has its own variables (`{{ salary.basic.monthly }}`, `{{ salary.basic.annual }}`, `{{ salary.basic.name }}`) for a client's own table.
- **Signing authorities** (Letters → Signing authorities): name, designation, company and a signature image. `{{ signatory.signature }}` prints the image; `{{ signatory.name }}` and `{{ signatory.designation }}` print the text.
- **Variables** button beside **New letter template**: every variable with its meaning and an example (search, click to copy), downloadable as a page or a CSV, plus a **sample Word template**.
- **Preview** on each template: the template filled in for one employee, without generating a letter. Approvers can download the **draft** of a letter before it is issued.

## Where things are

| Piece | Purpose |
|---|---|
| `Letters\Support\LetterVariables` | The catalogue: fixed groups (`GROUPS`), plus the tenant's salary components and employee custom fields. The guide, the editor's picker (`mergeTags()`), the template check (`usedIn()`, `extras()`) and the tests read it. |
| `Letters\Services\LetterContext` | Fills every catalogue path for one employee. `snapshot()` is what a `Letter` keeps in `context`. |
| `Letters\Support\HtmlLetter` | Renders a written template. |
| `Letters\Support\DocxLetter` | Checks (`problem`), reads (`text`, `variables`) and fills (`render`) a Word template; builds the `sample()`. |
| `Letters\Support\SalaryAnnexure`, `SignatureImage` | The two block values (a table, an image). They print as plain text where a block cannot go, such as a subject line. |
| `Letters\Models\LetterSignatory` | One default per tenant (saving a default clears the others). `signatureImage()` reads the private file and accepts only PNG and JPEG. |
| `Letters\Services\Letters` | `generate(…, signatory)`, `preview`, `check`, `draft`, `download` / `file` (returns content, name and MIME type), `issue`. |
| Filament | `LetterTemplateResource` (form, Preview, Word file download, `save()`), `LetterSignatoryResource`, `LetterVariablesUi` (guide, CSV, sample), views in `resources/views/filament/letters/`. |

## Schema (`2026_11_05_100001_letter_studio`)

- `letter_signatories` (tenant-owned, audited).
- `letter_templates`: `format` (`html` | `docx`), `file_disk`, `file_path`, `file_name`, `letter_signatory_id`. The migration converts every existing markdown body to HTML (`Str::markdown`), without a version bump.
- `letters`: `format` (`markdown` for letters generated before this change, `html`, `docx`), `file_disk`, `file_path` (the filled .docx), `letter_signatory_id`.
- Files live under `tenants/{id}/letters/{templates|signatures|issued}` on the documents disk. `DataInventory` lists the table, the three file columns and the prefix, so export and deletion cover them.

## Rules

- **Salary comes from the read contract.** `LetterContext` calls `CompensationOutput::forPayroll(employee, day, day)`. The day is the letter date, or the exit date for a leaver. The breakup is `StructureFormulas::preview` with the employee's fixed amounts, parameters and formula attributes, for a full 30-day month. It is an annexure, not a payslip: statutory deductions are left out, and employer PF is the payroll estimate used for balancing to CTC, shown only when the structure balances on `pf_employer`. CTC is always the approved CTC. Amounts print as `number_format(x, 2)`. Words use Indian numbering for INR and `NumberFormatter` spell-out otherwise.
- **Values are escaped.** In a written template the author's HTML is sanitised first (`Str::sanitizeHtml`), then values are inserted with `e()`. Only `SalaryAnnexure` and `SignatureImage` are inserted as HTML, and a block alone in a paragraph replaces that paragraph. In Word files values are XML-escaped.
- **Word run-joining.** Word splits typed text over runs, for spell-check or formatting. `joinRuns` joins a `{{ … }}` only when the tags between its characters are run-level. A variable broken up by a link, field, table, drawing or text box is refused at upload, and the error names it. Every part written is checked to be well-formed XML, so a file Word cannot open is never stored. The table and picture XML declare their own namespaces, so headers and footers can hold them as well as the body. Uploads are refused when they are not a .docx, contain macros (`vbaProject.bin`), or open to more than 30 MB.
- **Sensitive identifiers.** Aadhaar and bank account numbers are offered only masked (last four digits). PAN, UAN, PF and ESIC numbers print in full, because salary and employment certificates carry them.
- **What a letter keeps.** `context` holds only the variables the template used (as printed; dates as `Y-m-d`; the annexure as rows), plus `company` and `letter`. It never holds the signature image or variables the template did not use.
- **Audit.** Generate, approve, reject and issue as before. `preview` records a `VIEW` on the template, once per user, template and employee in ten minutes. `draft` records a `VIEW` on the letter. `download` records a `DOWNLOAD`.
- **Signatures are private.** They are never served by URL: the admin shows the current image inline from the server, and letters embed it as a data URI (HTML) or as a media part (Word).
- **Permissions.** No new keys. `letter.manage` manages templates and signatories; `letter.issue` generates, previews, approves, downloads drafts and issues.

## Not built

- PDF output. There is still no PDF library; a written letter is an HTML page (print to PDF from the browser) and a Word letter is a .docx.
- Per-template letterhead or logo for written templates (Word templates carry their own).
- E-signature or a signing workflow. The signature image is printed and is not a digital signature.
- Repeating sections in Word, such as one row per family member.
