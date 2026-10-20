# Runbook: tenant data export

Audience: platform operators, and customer administrators holding `data.export`. Operator page: **Platform → Offboarding**.
Customer page: **Data export** (`/admin/data-export`).

## Request
- **Customer:** **Request a full export**, with a reason. Only while the organisation is in service.
- **Operator:** **Request export** on the offboarding page, for any tenant, including a closed one.
- One export is generated at a time per tenant. A second request joins the one in progress.
- No export can be requested once a deletion is approved or has started.

## Generation
- Generation runs in the background (queue `default`). Large tenants can take a while.
- The page shows **Generating** with a progress count. If the worker stops, the 15-minute tick resumes it from its
  checkpoint.
- After 5 attempts, or on a storage failure, the export shows **Failed** with the reason. Choose **Retry** (with a
  reason) once the cause is fixed.

## Deliver
1. **Download** gives a link valid for 5 minutes, for you only. It never works for anyone else and never becomes
   permanent.
2. **Show passphrase** displays the 32-character passphrase. Each reveal is audited.
3. Send the file and the passphrase **by different channels**.
4. A customer's own download records delivery automatically.
5. If you handed the package over another way, choose **Record delivered** and add a note (for example the ticket).
6. If the customer declines an export, choose **Waive export** on the case, with their written confirmation as the reason.

## What the customer receives
A ZIP with each entry encrypted using AES-256 (7-Zip, WinZip or `7z x`). It contains:
- `data/{category}/{table}.jsonl` and `files/…`;
- `manifest.json`: counts, and the sha256 of every file;
- `manifest.sig` and `README.txt`.

Credentials and secrets are never included. The package is deleted after 7 days. Its record, checksums and manifest stay.

## Checks
- **Tenant audit trail:** `EXPORT_REQUESTED` → `EXPORT_STARTED` → `EXPORT_COMPLETED` → `EXPORT_DOWNLOADED` /
  `EXPORT_DELIVERED`.
- The manifest's sha256 matches each file (`sha256sum`).
