# Markedge PeopleOS documentation

This folder holds two kinds of documentation: the **customer documentation** (for prospective buyers, customers,
administrators, managers and employees) and the **engineering documentation** (for the people who build and operate
PeopleOS).

## Customer documentation

| What | Where | Format |
|---|---|---|
| **Customer Handbook** — the complete, consolidated guide | [handbook/PeopleOS-Customer-Handbook.pdf](handbook/PeopleOS-Customer-Handbook.pdf) | PDF (A4) |
| Customer Handbook for the browser | [handbook/PeopleOS-Customer-Handbook.html](handbook/PeopleOS-Customer-Handbook.html) | HTML (open in a browser; keep the `docs` folder structure so screenshots load) |
| Customer guide, chapter by chapter | [customer-guide/](customer-guide/README.md) | Markdown |
| Genuine application screenshots | [assets/screenshots/](assets/screenshots/) | PNG |
| Workflow diagrams (rendered) | [assets/diagrams/](assets/diagrams/) | SVG (sources are the Mermaid blocks in the chapters) |
| Documentation reports (feature inventory, implementation status, gaps) | [reports/](reports/) | Markdown |

Start here:
- **Evaluating PeopleOS?** [Product overview](customer-guide/01-product-overview.md) and
  [Buyer's guide](customer-guide/02-buyer-guide.md).
- **New user?** [Getting started — 30-minute quick start](customer-guide/03-getting-started.md).
- **Administrator?** [Administrator guide](customer-guide/07-administrator-guide.md).

### Rebuilding the handbook

The HTML and PDF handbook are generated from the Markdown chapters. The build renders every Mermaid flowchart to SVG,
numbers the figures, writes `customer-guide/08-workflow-diagrams.md`, and prints the A4 PDF with Chromium (two passes,
so the contents show page numbers). See [handbook/README.md](handbook/README.md).

## Engineering documentation

| Area | Where |
|---|---|
| Product blueprint (130 sections; scope and phasing) | [peopleos-blueprint.md](peopleos-blueprint.md) |
| Architecture notes, decision records (ADRs) and invariants | [architecture/](architecture/) |
| Phase reports | `PeopleOS-Phase-*.md` |
| SaaS programme (commercial, release gate, owner actions) | [saas/](saas/) — the authoritative release decision is [saas/SaaS-Final-Release-Gate.md](saas/SaaS-Final-Release-Gate.md) |
| UX programme and design system | [ux/](ux/) |
| Statutory compliance notes (India) | [compliance/](compliance/) |
| Operations and production runbooks | [operations/](operations/), [production/](production/) |
| REST API (OpenAPI 3.1) | [api/openapi.json](api/openapi.json) |

> **Superseded:** `PeopleOS-Administrator-Guide.html` / `.pdf` (27 September 2026) predate the redesigned interface
> and the SaaS programme. Use the Customer Handbook instead.
