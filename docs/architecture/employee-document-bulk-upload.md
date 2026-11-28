# Employee documents bulk upload

Admin → Data import → **Employee documents** (`/admin/data-import/employee-documents`: a tab beside Import and Recent
uploads, an entry in the Data import area bar and All modules, the first band on Data import; also on the Lifecycle desk under
"Reports and uploads", and in the Actions panel and the search as "Upload employee documents", ActionCatalogue key
`upload_employee_documents`). HR uploads many employees' documents at once; each file is matched to a document type by its name.

## How people use it

1. **Upload the zips.** One zip per employee named `employee code_name` (`EMP001_Asha Rao.zip`), up to 50 zips at once,
   12 MB each (the panel's temporary-upload limit). One zip holding a folder or a zip per employee works too, with or
   without one folder around them all. Choices: what to do when the employee already has a document of that type (add a
   new version — default — or keep the one on file), and whether the usual "document uploaded" notifications go out
   (off by default).
2. **Check the matches.** Every file is listed under its employee with the document type its name matched, how it was
   matched, the dates read from the name, and its status. The person changes a type or dates, skips or includes files
   (one by one or ticked together). Nothing reaches an employee yet.
3. **Upload.** Queued (`RunDocumentBulkUpload`); the page follows the progress, then shows what happened to each file.
   The results sheet (CSV) lists every file. The uploader gets an in-app notification that opens the upload.

The kit beside the upload has a sample zip (two employees' zips; it uploads as it is when EMP001 Asha Rao and EMP002
Ravi Kumar exist) and a names guide (every active document type with the file names it is recognised by).

## Pieces

| Piece | What it does |
| --- | --- |
| `Documents\Services\DocumentBulkUploads` | check → review edits (edit, setType, skip, include) → start → run; discard; results CSV, names guide, sample zip |
| `Documents\Services\DocumentNameMatcher` | reads a document type (and dates) from a file name |
| `Documents\Models\DocumentUploadBatch` / `DocumentUploadItem` | one upload / one file in it (tables `document_upload_batches`, `document_upload_items`) |
| `Documents\Jobs\RunDocumentBulkUpload` | tenant-aware job; a retry carries on with the files still ready |
| `Filament\Pages\EmployeeDocumentUpload` | the page (slug under `data-import/`, so it keeps the Data import area bar) |
| `Documents::store(..., supersede:, notify:)` | the one write path, with two switches the bulk upload uses |

## Rules

- **Permission** `document.bulk_upload` (HR Manager; HR Admin through `document.*`), plus `employee.view` for the page.
  Employees are found within the uploader's access scope and the employee policy; anyone else reads as "No employee
  with the code … in your access". Only the uploader changes, starts or discards an upload; `bulk.view_all` may look.
  File rows are `ScopedByEmployee` (a viewer sees only employees in their scope, and files that found no employee);
  counts and the run read the whole upload and check each file against the uploader's access when it is stored.
- **Employee from the zip or folder name** (owner, 2026-11-27: "must match the emp id and name"; "if there is no last
  name pick the first name only … match it with emp code strictly"): named `employee code_First Last name`
  (`ME0001_Vikram_Singh`) or `employee code_First name` (`ME0001_Vikram`). The code is matched exactly: the longest
  beginning of the name, ending before an underscore or a space, that is an employee code (`MK-07_Kiran Shah` finds
  MK-07 even when MK exists; `MK-99_Meera` is never read as MK). The rest must be that employee's name in PeopleOS —
  the first name alone, first and last, first, middle and last, or the preferred name alone or with the last name.
  Case, accents, spaces, underscores, hyphens, dots and the order of the names do not matter; "copy", "documents",
  "kyc" and numbers like "(1)" are ignored. A missing, different or misspelt name, or the last name alone, means none of
  that zip's files is uploaded, with the expected names in the reason (`… name it ME0001_Vikram_Singh or
  ME0001_Vikram`); it cannot be overridden — rename the zip and upload it again. An employee who never joined gets
  nothing.
- **Document type from the file name** (`DocumentNameMatcher`): words without the employee's code and name or any
  date; phrases from the organisation's own active types (name and code — surest; name without its bracketed part —
  least sure) and the usual spellings of the standard types (`ALIASES`, by code or by the profile field a type is
  linked to). The longest phrase wins, a broad one (`WEAK`: "address proof", "degree", "photo") only when nothing more
  specific is there; two types at the same length are not guessed between. The types issued letters are kept under
  (`LETTER_*`, Letters::documentType()) answer only when no other type does: an uploaded "Experience letter.pdf" is the
  previous employer's (Experience / service certificate), not a letter PeopleOS issued. Misspellings are corrected to the nearest
  word the phrases use and the name read again; then the folder the file is in. Only types the employee's company uses
  (configuration applicability) are matched or offered.
- **Dates in a name**: after "exp / expiry / valid / till / upto / until" the expiry date, after "issued / dated / doi"
  the issue date; otherwise a future date is the expiry and a past one the issue date. The expiry is kept only for types
  that have one.
- **What is on file**: the same file (name and size) for that type is skipped as already on file; another file of the
  type becomes the new version (the earlier archived) unless the person chose to keep what is on file. A second file of
  the same type in one upload (an ID's front and back) is added beside the first, not over it, and both are titled
  "Type · file name". The same file twice in one upload is uploaded once.
- **Files**: the document allow-list and size (`peopleos.documents.mimes`, `max_kb`), content sniffing (FileSafety) when
  stored; empty files, other types and zips inside an employee's zip are listed as "cannot upload". Folders, hidden
  files, `__MACOSX`, `Thumbs.db`, `desktop.ini` and Office lock files are ignored. Nothing is ever extracted to a path
  taken from a zip; every read is capped at the allowed size.
- **Every document** is an ordinary employee document: pending verification, audited with reason "Bulk upload #n",
  `uploaded_by` the uploader (who therefore cannot verify it). Without a type chosen, a file is left out and says so.
- **Zip entries** are read by their position in the zip (`entry_index`, `container_index`), never by a name taken from
  it (Windows tools write backslashes; names may repeat).
- **Zips** are kept on the documents disk under `tenants/{id}/document-uploads/{batch}` only until the upload has run or
  is discarded; an upload left unchecked for 7 days is discarded when the next check starts. Tenant deletion's
  stored-files sweep covers the folder; both tables are in `DataInventory` (documents).
- **Limits** `peopleos.documents.bulk_upload`: zips_max 50, zip_max_kb 12288, zip_max_entries 2000, files_max 5000,
  zip_max_uncompressed_kb 512000.

Tests: `tests/Feature/Documents/EmployeeDocumentBulkUploadTest.php`.
