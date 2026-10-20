# Production / staging smoke test

Run after every deploy and after every restore, in **staging first**, then in production with a
dedicated **smoke tenant**. Never use real employees' data for destructive steps: create, change and
delete only the smoke tenant's records.

**Status in the production readiness closure:** this checklist was **not executed against staging or
production**, because neither environment exists yet. The automated suites cover every step on the build
workstation (column "Automated evidence"), but that is not environment evidence.

| # | Step | How (smoke tenant) | Expected | Automated evidence (workstation) | Staging result | Production result |
|---|---|---|---|---|---|---|
| 1 | Application loads | Open `/admin/login` over https | 200, valid certificate, HSTS | Panel render tests | NOT EXECUTED | NOT EXECUTED |
| 2 | Authentication | Sign in as the smoke HR admin (MFA if the tenant requires it) | Dashboard opens | Identity / security policy tests | NOT EXECUTED | NOT EXECUTED |
| 3 | Tenant resolution | Header shows the smoke tenant; a second tenant's record id in the URL returns 404 | Isolation holds | TenantIsolationHardeningTest, Tenancy suites | NOT EXECUTED | NOT EXECUTED |
| 4 | Employee search | Search by name / code in Employees | Results within scope | EmployeeDirectoryTest | NOT EXECUTED | NOT EXECUTED |
| 5 | Employee 360 | Open an employee; the 360 overview renders | Only permitted sections | IntelligenceTest, PlatformInvariants 30 | NOT EXECUTED | NOT EXECUTED |
| 6 | Organisation scope | Sign in as a location-scoped HR user; an out-of-scope employee is invisible | Not listed, 404 on direct URL | AccessScope suites | NOT EXECUTED | NOT EXECUTED |
| 7 | Manager visibility | Sign in as a manager; direct reports visible, peers not | As configured | Performance / manager suites | NOT EXECUTED | NOT EXECUTED |
| 8 | Document upload / download | Upload a PDF on the smoke employee; download it | Stored on the documents disk (S3 in production) under `tenants/{id}/…`; download audited | StorageProductionTest, DocumentsTest | NOT EXECUTED | NOT EXECUTED |
| 9 | Signed URL | Copy the download link; reuse it after 15 minutes, or without a session | 403 | DocumentsTest, attachment tests | NOT EXECUTED | NOT EXECUTED |
| 10 | Service request | Raise a smoke HR request; an agent replies; close it | Lifecycle and SLA recorded | ServiceDesk suites | NOT EXECUTED | NOT EXECUTED |
| 11 | Notification | The request reply notifies the requester (bell); email sent | In-app immediately; email delivery `sent` | OperationsHardeningTest | NOT EXECUTED | NOT EXECUTED |
| 12 | Queue processing | `queue:work` running; `/health/ready` queue `ok`; no new failed jobs | Pending drains | ProductionConfigurationTest (real worker round-trip) | NOT EXECUTED | NOT EXECUTED |
| 13 | Scheduler | `schedule:list` shows 23 entries; heartbeat younger than 3 minutes | `scheduler: ok` | OperationsHardeningTest (inventory) | NOT EXECUTED | NOT EXECUTED |
| 14 | API authentication | `GET /api/v1/employees` without a key; with a smoke key | 401; 200 | ApiPlatformTest, API suites | NOT EXECUTED | NOT EXECUTED |
| 15 | API authorisation | A key without `employees.read` | 403 with the error envelope | API suites | NOT EXECUTED | NOT EXECUTED |
| 16 | Webhook | Smoke endpoint on a public request-bin over https: publish an event; verify the signature with `Webhooks::verify` logic. An internal URL is refused at save | Delivered, signed; private destination refused | OutboundSsrfTest, EnterpriseTest | NOT EXECUTED | NOT EXECUTED |
| 17 | Audit | Change Intelligence shows the smoke changes; `peopleos:audit:verify` passes | Chain valid | Audit suites | NOT EXECUTED | NOT EXECUTED |
| 18 | Health / readiness | `/health/live` 200; `/health/ready` with the token shows every check `ok` (configuration included) | 200 | OperationsHardeningTest, ProductionConfigurationTest | NOT EXECUTED | NOT EXECUTED |
| 19 | Cache | Change a tenant setting; it applies on the next request on another node | Shared cache | ProductionConfigurationTest (per-tenant keys) | NOT EXECUTED | NOT EXECUTED |
| 20 | Storage | `/health/ready` storage probe `ok`; the step 8 object exists in the bucket and is not publicly readable (anonymous GET → 403) | Private | StorageProductionTest | NOT EXECUTED | NOT EXECUTED |

Record the operator, date, environment, release and evidence (screenshots or command output) for each
row in the go-live checklist (`docs/production/go-live-checklist.md`).
