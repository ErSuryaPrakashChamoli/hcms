"""Writes docs/compliance-research/state-wise-compliance-matrix.md from the catalogue, the PT/LWF specs and the
state Labour Code rules matrix (research/verify-state-code-rules.md).
Usage: matrix.py <states.json: the catalogue's `states` section> <output.md>
Everything above the first table is maintained by hand in manual/state-matrix-head.md; the tables are generated."""
import json, re, sys, os
TOOL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # tools/compliance-catalogue
sys.path.insert(0, os.path.join(TOOL, 'catalogue'))
from ptlwf_spec import PT, LWF
states = json.load(open(sys.argv[1], encoding='utf-8'))
ABBR = {'OFFICIAL_PRIMARY_SOURCE_VERIFIED': 'Primary', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED': 'Partial', 'SECONDARY_SOURCE_ONLY': 'Secondary',
        'CONFLICTING_SOURCES': 'Conflicting', 'LEGAL_REVIEW_REQUIRED': 'Legal review', 'UNVERIFIED': 'Unverified', None: '—', '': '—'}
CODE_ABBR = {'OPV': 'Primary', 'OSPV': 'Partial', 'SSO': 'Secondary', 'CS': 'Conflicting', 'LRR': 'Legal review', 'UNV': 'Unverified'}

# Code rules matrix rows: | # | XX Name | CoW | IR | SS | OSH |
codes = {}
text = open(os.path.join(TOOL, 'research', 'verify-state-code-rules.md'), encoding='utf-8').read()
for line in text.split('\n'):
    m = re.match(r'^\| \d+ \| ([A-Z]{2}) [^|]+\|(.+)\|$', line)
    if m:
        cells = [c.strip() for c in m.group(2).split('|')]
        if len(cells) == 4:
            codes[m.group(1)] = cells

def code_cell(cell):
    if 'same pattern' in cell or 'Text dated' in cell:
        return 'Unresolved: texts headed "DRAFT RULES" gazetted 2026 (Legal review)'
    if 'commencement never' in cell or 'not commenced' in cell:
        return 'Final 2023 rules never commenced; 2026 rules laid, not seen in the gazette (Legal review)'
    final = 'FINAL' in cell
    lab = re.search(r'· (OPV|OSPV|SSO|CS|LRR|UNV)', cell) or re.search(r'\b(OPV|OSPV|SSO|CS|LRR|UNV)\b', cell)
    label = CODE_ABBR.get(lab.group(1)) if lab else '—'
    if final:
        d = re.search(r'FINAL[^0-9]*?(\d{2}-\d{2}-\d{4})', cell.replace('**', ''))
        return f"Final {d.group(1) if d else ''} ({label})".replace('  ', ' ')
    if 'LRR' in cell and 'DRAFT' not in cell.split('·')[0] and 'Text dated' in cell:
        return 'Unresolved (Legal review)'
    if 'NONE' in cell:
        return f'None found ({label})'
    if 'DRAFT' in cell or 'Draft' in cell:
        return f'Draft ({label})'
    return f'{cell[:40]} ({label})'

order = ['AN','AP','AR','AS','BR','CH','CG','DH','DL','GA','GJ','HR','HP','JK','JH','KA','KL','LA','LD','MP','MH','MN','ML','MZ','NL','OD','PY','PB','RJ','SK','TN','TG','TR','UP','UK','WB']
out = []
w = out.append
out.extend(open(os.path.join(TOOL, 'manual', 'state-matrix-head.md'), encoding='utf-8').read().split('\n')[:-1])
w('| State / UT | Code on Wages | Industrial Relations | Social Security | OSH |')
w('|---|---|---|---|---|')
for c in order:
    row = codes.get(c)
    name = states[c]['name']
    if not row:
        w(f'| {c} {name} | — | — | — | — |'); continue
    w(f'| {c} {name} | ' + ' | '.join(code_cell(x) for x in row) + ' |')
w('')
w('PeopleOS uses a state\'s own register forms from its final rules\' effective date where they are prescribed (catalogue variants):')
w('- Wages registers: AP, BR, LA (central numbering); GJ, AR, SK, LD (Form IV employee, Form I wages); RJ (IV / I / V); UP (II / I); UK (VI / I).')
w('- OSH leave register and unified annual return: AP, GJ, RJ, AR, UP, LA, LD, JK, MZ.')
w('- SS register of women employees: AP, AR, BR, GJ, LA, UP.')
w('')
w('Before those dates, and in the other states, the register uses a PeopleOS layout with the particulars of the central form, under legal review.')
w('')
w('## 2. Professional tax, labour welfare fund and shops and establishments')
w('')
w('| State / UT | Professional tax | PT label | PT in payroll | Labour welfare fund | LWF label | LWF in payroll | Shops and establishments | S&E label |')
w('|---|---|---|---|---|---|---|---|---|')
PT_RULES = {'MH','KA','TG','AP','WB','TN','GJ','MP','KL'}
LWF_RULES = {'MH','KA','TG','DL','TN','GJ','HR'}
PT_PAY = {'MH': 'Yes (February ₹300 now on the top band only)', 'KA': 'Yes — February 2024 and 2025 overstated (W8)', 'TG': 'Yes', 'AP': 'Yes', 'GJ': 'Yes',
          'WB': 'Yes — 2014 slabs; v2 from Oct 2026 proposed', 'KL': 'Yes — 2016 slabs; v2 from Oct 2024 proposed', 'TN': 'Yes — superseded table, monthly instead of half-yearly (W9)', 'MP': 'Yes — third band ₹1 high (W10)'}
LWF_PAY = {'MH': 'Yes — dated from 2015 with the 2024 amounts (W1)', 'KA': 'Yes — v1 ₹20 / ₹40; v2 proposed (W5)', 'TG': 'Yes', 'DL': 'Yes',
           'TN': 'Yes — dated from 2015; deduction month conflicts (W2)', 'GJ': 'Yes', 'HR': 'Yes — flat ₹31 / ₹62; v2–v5 proposed (W4)'}
for c in order:
    s = states[c]
    pt = PT.get(c, {}); lw = LWF.get(c, {})
    reps = s.get('reports') or {}
    se = [v for k, v in reps.items() if k.startswith('se_')]
    se_conf = sorted({ABBR.get(v.get('confidence'), v.get('confidence')) for v in se}) or ['—']
    if pt.get('levied'):
        ptxt = f"{pt.get('frequency_text','')}; {pt.get('due_text','')}".replace('|', '/')
        if pt.get('effective_to'):
            ptxt = 'Repealed from 1 Apr 2026 (to 31 Mar 2026: ' + ptxt + ')'
    else:
        ptxt = 'Not levied: ' + (pt.get('note') or '').replace('|', '/')
    ptl = ABBR.get(pt.get('confidence'), pt.get('confidence') or '—')
    if lw.get('applies'):
        ltxt = f"{lw.get('employee')} / {lw.get('employer')}; {lw.get('frequency_text')}; {lw.get('due_text')}".replace('|', '/')
    else:
        ltxt = 'None: ' + (lw.get('note') or '').replace('|', '/')
    ltl = ABBR.get(lw.get('confidence'), lw.get('confidence') or '—')
    shop = s.get('shops', {})
    stxt = (shop.get('act') or '—') + ('; ' + str(len(se)) + ' register(s)/return(s) generated' if se else '; checklist only' if shop.get('act') and shop.get('act') != '—' else '')
    w(f"| {c} {s['name']} | {ptxt} | {ptl} | {PT_PAY.get(c, 'No')} | {ltxt} | {ltl} | {LWF_PAY.get(c, 'No')} | {stxt.replace('|','/')} | {', '.join(se_conf)} |")
w('')
w('"PT in payroll" and "LWF in payroll" mean a rule version exists, so payroll deducts and the statement has figures. Where it')
w('does not, the statement is generated empty and says why. Versions found out of date are listed in `statutory-rule-change-register.md`.')
w('')
w('## 3. Gaps by state (what PeopleOS cannot yet do)')
w('')
w('| Gap | States / UTs |')
w('|---|---|')
w('| PT levied but no rule version in payroll | ' + ', '.join(c for c in order if PT.get(c, {}).get('levied') and c not in PT_RULES and not PT[c].get('effective_to')) + ' |')
w('| LWF due but no rule version in payroll | ' + ', '.join(c for c in order if LWF.get(c, {}).get('applies') and c not in LWF_RULES) + ' |')
w('| PT structure not modelled (half-yearly or annual deduction, local-body rates) | TN, KL, PY (half-yearly, local body); BR, JH, MN, MZ, TR, ML (annual) |')
w('| LWF exclusions and employer minimums not modelled | All LWF states (supervisory wage ceilings differ: MH/GJ ₹3,500, DL ₹2,500, GA/TG/AP ₹1,600, WB ₹10,000, TN ₹15,000); MP employer minimum; PB/CH monthly deduction with half-yearly remittance |')
w('| Shops-and-establishments forms on secondary sources only | ' + ', '.join(c for c in order if any(v.get('confidence') == 'SECONDARY_SOURCE_ONLY' for k, v in (states[c].get('reports') or {}).items() if k.startswith('se_'))) + ' |')
w('| No S&E register generated (checklist only, or no S&E law) | ' + ', '.join(c for c in order if not any(k.startswith('se_') for k in (states[c].get('reports') or {}))) + ' |')
w('| Code rules status unresolved | ML (all four Codes); MZ (IR, SS); UP IR and JK IR / OSH gazette dates |')
w('| Local-body PT not checked where the state levies none | DL, GA and others (municipal levies) |')
w('')
open(sys.argv[2], 'w', encoding='utf-8').write('\n'.join(out) + '\n')
print('written', len(out), 'lines')
