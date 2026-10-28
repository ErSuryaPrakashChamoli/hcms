# Compliance catalogue generator

This folder builds three files:
- `database/data/compliance/reports/in.php`, the compliance report catalogue read by `ReportCatalogue`;
- the two research documents it is documented by:
  - `docs/compliance-research/state-wise-compliance-matrix.md`;
  - `docs/compliance-research/india-compliance-source-register.md`.

The generator lives in the repository under owner decision C8 (2026-10-27), and CI checks that it reproduces the
committed files byte-for-byte.

**Never edit the three output files by hand.** Edit the specs, the research notes or `manual/`, then regenerate. CI
fails on any difference.

## Commands

```sh
tools/compliance-catalogue/check.sh        # regenerate in a temporary directory and compare; writes nothing here
tools/compliance-catalogue/regenerate.sh   # write the regenerated files into the repository (then review git diff)
```

`check.sh` exit codes:

| Code | Meaning |
|---|---|
| 0 | All three files identical |
| 1 | A difference; the unified diff is printed |
| 2 | The generator failed, for example a missing research note |

It takes an optional repository root to compare against.

**Requirements.** Python 3 (standard library only; output verified with 3.14.4) and the PHP CLI. Python runs as
`python3 -I -B -X utf8`:
- `-I`: isolated from environment variables and user site packages;
- `-B`: no bytecode written into this folder;
- `-X utf8`: UTF-8 whatever the locale.

## What is here

| Path | Kind | What it is |
|---|---|---|
| `catalogue/gen2.py` | generator | Writes the catalogue from the specs |
| `catalogue/central_spec.py` | input | Central obligations and the state Code rules tables |
| `catalogue/ptlwf_spec.py` | input | Professional tax and labour welfare fund, per state |
| `catalogue/se_spec.py`, `catalogue/se2_spec.py` | input | Shops and establishments registers and returns, per state |
| `catalogue/thresholds_spec.py` | input | Dated applicability thresholds |
| `catalogue/sources_spec.py` | input | Official source URLs taken from the research notes. It stops if a note is missing |
| `research/se-top15.md`, `research/se-rest.md` | research input | Shops and establishments research notes (the source URLs come from these) |
| `research/verify-state-code-rules.md` | research input | The state Labour Code rules matrix read by `docs/matrix.py` |
| `docs/matrix.py` | generator | Writes the state matrix. It takes the catalogue's `states` section as JSON, which the scripts dump from the generated catalogue, so there is no second copy of the data |
| `docs/sources.py` | generator | Writes the source register |
| `manual/state-matrix-head.md` | **maintained by hand** | Everything in the state matrix above its first table |
| `manual/source-register-head.md` | **maintained by hand** | The source register's title and sections 0–5 |
| `manual/source-register-second-pass.md` | **maintained by hand** | The source register's end: the second-pass notes on the Code rules, section 9 (shops and establishments) and section 10 (secondary sources) |

The scripts write only to the output path they are given. They read nothing over the network and hold no
credentials or personal data.

## What it cannot do

- **The catalogue holds no rates, ceilings or rule status** (`CatalogueIntegrityTest` asserts it). Running the
  generator therefore cannot activate a statutory rule or change a rule's verification status. Rules live in
  `database/data/compliance/in.php`, and only `RuleVerifications::verify()` activates one.
- **Confidence labels are data in the specs.** The generator copies them and never upgrades one.
  `CatalogueIntegrityTest` and `CatalogueVariantsTest` check that a label is backed by the sources it cites.

## History

- Written on 2026-10-27 in a temporary session folder.
- Archived to `/home/administrator/Documents/hrms/hcms-compliance-research-archive-20261027/generator/`, outside the
  repository, together with the raw research downloads (not committed).
- Moved here under C8. Changes from the archive:
  - the research notes moved to `research/`;
  - `states.json` is no longer stored; it is derived from the catalogue at run time;
  - the two patch files that held hand-written sections became the plain Markdown files in `manual/`;
  - `sources_spec.py` now fails when a note is missing instead of silently dropping its sources.
- The output did not change: `check.sh` reported all three files identical on 2026-10-27.
