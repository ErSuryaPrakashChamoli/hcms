"""Writes docs/compliance-research/india-compliance-source-register.md. Usage: sources.py <output.md>
The register's head (manual/source-register-head.md) and its second-pass sections at the end
(manual/source-register-second-pass.md) are maintained by hand; the source tables between them are generated."""
import sys, os
TOOL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # tools/compliance-catalogue
sys.path.insert(0, os.path.join(TOOL, 'catalogue'))
from ptlwf_spec import PT, LWF
import central_spec as C
ABBR = {'OFFICIAL_PRIMARY_SOURCE_VERIFIED': 'Primary', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED': 'Partial', 'SECONDARY_SOURCE_ONLY': 'Secondary',
        'CONFLICTING_SOURCES': 'Conflicting', 'LEGAL_REVIEW_REQUIRED': 'Legal review', 'UNVERIFIED': 'Unverified'}
order = ['AN','AP','AR','AS','BR','CH','CG','DH','DL','GA','GJ','HR','HP','JK','JH','KA','KL','LA','LD','MP','MH','MN','ML','MZ','NL','OD','PY','PB','RJ','SK','TN','TG','TR','UP','UK','WB']
out = []
w = out.append
def rows(d, key):
    for c in order:
        e = d.get(c)
        if not e or not e.get(key):
            continue
        src = '; '.join(f'[{t}]({u})' for t, u in e.get('sources', [])) or '—'
        w(f"| {c} | {ABBR.get(e.get('confidence'), e.get('confidence', '—'))} | {src} | {(e.get('legal_review') or '').replace('|', '/')} |")
w('## 6. Professional tax sources (levying states)')
w('')
w('| State | Label | Sources | Open |')
w('|---|---|---|---|')
rows(PT, 'levied')
w('')
w('States and UTs with no levy on salaried employees:')
for c in order:
    e = PT.get(c)
    if e and not e.get('levied'):
        w(f"- **{c}** ({ABBR.get(e.get('confidence'), '—')}): {e.get('note')}")
w('')
w('Full evidence, with verbatim excerpts: `evidence/2026-10-27b-verify-pt.md` (second pass) and `evidence/2026-10-27-verify-pt.md`.')
w('')
w('## 7. Labour welfare fund sources')
w('')
w('| State | Label | Sources | Open |')
w('|---|---|---|---|')
rows(LWF, 'applies')
w('')
w('States and UTs with no general LWF found:')
for c in order:
    e = LWF.get(c)
    if e and not e.get('applies'):
        w(f"- **{c}**: {e.get('note')}")
w('')
w('Full evidence: `evidence/2026-10-27b-verify-lwf.md` (second pass), `evidence/2026-10-27-verify-lwf.md` and, for Karnataka and Haryana, `evidence/2026-10-27-verify-rule-changes.md`.')
w('')
w('## 8. State rules under the Labour Codes (final rules used for register forms)')
w('')
w('| State | Code | Effective from | Rules | Label in the catalogue | Source |')
w('|---|---|---|---|---|---|')
for name, table, idx in (('Wages', C.WAGES_RULES, None), ('OSH', C.OSH_RULES, None), ('SS', C.SS_RULES, None)):
    for st, d in table.items():
        w(f"| {st} | {name} | {d[0]} | {d[1]} | {ABBR[C._conf(d[2])]} | [{d[2].split('/')[2]}]({d[2]}) |")
w('')
w('The status of every state\'s rules under all four Codes, with sources, is in `evidence/2026-10-27b-verify-states.md` (second pass),')
w('`evidence/2026-10-27-verify-state-code-rules.md`, and')
w('summarised in `state-wise-compliance-matrix.md` §1.')
manual = lambda name: open(os.path.join(TOOL, 'manual', name), encoding='utf-8').read()
open(sys.argv[1], 'w', encoding='utf-8').write(manual('source-register-head.md') + '\n'.join(out) + '\n' + manual('source-register-second-pass.md'))
