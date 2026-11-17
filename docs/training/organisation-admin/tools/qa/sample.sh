#!/usr/bin/env bash
# Render a representative sample of the built course (or the pages given) and tile them into contact sheets.
#   tools/qa/sample.sh [page,page,...]
set -e
T="$(cd "$(dirname "$0")/.." && pwd)"
R="$T/.work/qa-render"
rm -rf "$R" && mkdir -p "$R"
PAGES="${1:-$(python3 - "$T" <<'PY'
import json,sys,random
m=json.load(open(sys.argv[1]+'/nav-map/OA.json'))
plan=m['plan']; pick=set([1,2,3])
random.seed(7)
by={}
for p in plan: by.setdefault(p['kind'],[]).append(p['n'])
for k,ns in by.items(): pick.update(random.sample(ns,min(len(ns),{'content':40,'divider':4,'contents':4,'summary':3,'checklist':3,'toc':2}.get(k,3))))
print(','.join(str(n) for n in sorted(pick)))
PY
)}"
echo "pages: $PAGES"
cp "$T/../PeopleOS-Organisation-Administrator-Complete-Training.pptx" "$R/deck.pptx"
cd "$R" && /snap/bin/libreoffice -env:UserInstallation=file://$T/.work/lo-qa --headless --convert-to "pdf:impress_pdf_Export:{\"PageRange\":{\"type\":\"string\",\"value\":\"$PAGES\"}}" deck.pptx > lo.log 2>&1
pdftoppm -jpeg -r 50 deck.pdf s
"$T/.work/venv/bin/python" - "$R" <<'PY'
import glob,sys
from PIL import Image
fs=sorted(glob.glob(sys.argv[1]+'/s-*.jpg')); W,H=Image.open(fs[0]).size; c=4; per=16
for k in range(0,len(fs),per):
    sh=Image.new('RGB',(W*c,H*((min(per,len(fs)-k)+c-1)//c)),'white')
    for i,f in enumerate(fs[k:k+per]): sh.paste(Image.open(f),((i%c)*W,(i//c)*H))
    sh.save(f"{sys.argv[1]}/sheet-{k//per+1:02d}.jpg",quality=80)
print(len(fs),'pages rendered')
PY
