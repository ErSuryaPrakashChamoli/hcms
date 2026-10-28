# S&E source URLs per state, extracted from the research sections (first three official-looking URLs).
import re, os
HERE = os.path.dirname(os.path.abspath(__file__))
RESEARCH = os.path.join(HERE, '..', 'research')  # tools/compliance-catalogue/research
OFFICIAL = re.compile(r'(gov\.in|nic\.in|prsindia\.org|egazette|assembly|neva\.gov\.in|thc\.nic\.in)', re.I)
def _sections(path):
    text = open(path).read()
    parts = re.split(r'^### ([A-Z]{2}(?:/[A-Z]{2})?) — ', text, flags=re.M)
    out = {}
    for i in range(1, len(parts), 2):
        code = parts[i].split('/')[0]
        out['DH' if code == 'DN' else code] = parts[i + 1]
    return out
SE = {}
for f in ('se-top15.md', 'se-rest.md'):
    p = os.path.join(RESEARCH, f)
    if not os.path.exists(p):  # fail closed: a missing note would silently drop sources from the catalogue
        raise SystemExit(f'missing research input {p}')
    for code, body in _sections(p).items():
        urls = []
        for u in re.findall(r'https?://[^\s)\]>,;]+', body):
            u = u.rstrip('.')
            if OFFICIAL.search(u) and u not in urls:
                urls.append(u)
        SE[code] = urls[:3]
