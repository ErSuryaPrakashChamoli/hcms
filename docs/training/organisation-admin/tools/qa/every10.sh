#!/usr/bin/env bash
# Render every tenth slide of the built course in two batches (small subset copies keep LibreOffice's memory low).
set -e
T="$(cd "$(dirname "$0")/.." && pwd)"; V="$T/../../../presentations/peopleos/tools/.work/venv/bin/python"; R="$T/.work/qa10"
mkdir -p "$R"
for part in 0 1; do
  [ -f "$R/q$part.pdf" ] && { echo "batch $part already rendered"; continue; }
  P=$(python3 -c "print(','.join(str(n) for n in range(5+$part*830, min(1651, 5+($part+1)*830), 10)))")
  "$V" "$T/qa/subset.py" "$T/../PeopleOS-Organisation-Administrator-Complete-Training.pptx" "$R/q$part.pptx" "$P" 2>&1 | grep -v Warning
  (cd "$R" && /snap/bin/libreoffice -env:UserInstallation=file://$T/.work/lo-qa --headless --convert-to pdf "q$part.pptx" >/dev/null 2>&1 && rm -f "q$part.pptx")
  echo "batch $part rendered: $(pdfinfo "$R/q$part.pdf" | awk '/Pages/{print $2}') pages"
done
cd "$R" && rm -f p*.jpg && pdftoppm -jpeg -r 45 q0.pdf p0 && pdftoppm -jpeg -r 45 q1.pdf p1
"$T/.work/venv/bin/python" - "$R" <<'PY'
import glob,sys
from PIL import Image
fs=sorted(glob.glob(sys.argv[1]+'/p*.jpg')); W,H=Image.open(fs[0]).size; c=4; per=20
for k in range(0,len(fs),per):
    n=min(per,len(fs)-k); sh=Image.new('RGB',(W*c,H*((n+c-1)//c)),'white')
    for i,f in enumerate(fs[k:k+per]): sh.paste(Image.open(f),((i%c)*W,(i//c)*H))
    sh.save(f"{sys.argv[1]}/sheet-{k//per+1:02d}.jpg",quality=80)
print(len(fs),'slides on',(len(fs)+per-1)//per,'sheets')
PY
