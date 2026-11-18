# Makes PeopleOS-Organisation-Administrator-Complete-Training.pdf from the built course without loading the whole deck
# into LibreOffice at once (that needs more memory than a normal workstation has):
#   1. split the slide list into chunks; each chunk is a copy of the deck listing only its slides (qa/subset.py logic)
#   2. LibreOffice renders each chunk to PDF, one at a time
#   3. the chunk PDFs are joined; LibreOffice's own link annotations are removed (a chunk cannot point outside itself)
#   4. every internal link of the deck (tools/.work/pdf-links.json, from build/pdf-links.js) is added back as a PDF link
#      to the right page, with the same clickable box as on the slide
# Run with the venv that has python-pptx and pypdf:  python build/pdf.py [chunk size]
import json, os, shutil, subprocess, sys, threading

sys.setrecursionlimit(200000)
threading.stack_size(512 * 1024 * 1024)

HERE = os.path.dirname(os.path.abspath(__file__))
TOOLS = os.path.dirname(HERE)
PKG = os.path.dirname(TOOLS)
DECK = os.path.join(PKG, 'PeopleOS-Organisation-Administrator-Complete-Training.pptx')
OUT = os.path.join(PKG, 'PeopleOS-Organisation-Administrator-Complete-Training.pdf')
WORK = os.path.join(TOOLS, '.work', 'pdf-chunks')
CHUNK = int(sys.argv[1]) if len(sys.argv) > 1 else 120


def subset(keep, out):
    from pptx import Presentation
    p = Presentation(DECK)
    ids = p.slides._sldIdLst
    for i, sid in reversed(list(enumerate(list(ids), 1))):
        if i not in keep:
            p.part.drop_rel(sid.rId)
            ids.remove(sid)
    p.save(out)


def main():
    from pypdf import PdfReader, PdfWriter
    from pypdf.generic import ArrayObject, DictionaryObject, FloatObject, NameObject, NumberObject

    links = json.load(open(os.path.join(TOOLS, '.work', 'pdf-links.json')))
    n = links['slides']
    os.makedirs(WORK, exist_ok=True)
    parts = []
    for k, start in enumerate(range(1, n + 1, CHUNK)):
        keep = set(range(start, min(n, start + CHUNK - 1) + 1))
        pdf = os.path.join(WORK, f'chunk-{k:02d}.pdf')
        parts.append((pdf, len(keep)))
        if os.path.exists(pdf) and len(PdfReader(pdf).pages) == len(keep):
            print(f'chunk {k}: slides {min(keep)}-{max(keep)} already rendered', flush=True)
            continue
        src = os.path.join(WORK, f'chunk-{k:02d}.pptx')
        subset(keep, src)
        subprocess.run(['/snap/bin/libreoffice', f'-env:UserInstallation=file://{os.path.join(TOOLS, ".work", "lo-pdf")}', '--headless',
                        '--convert-to', 'pdf', '--outdir', WORK, src], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=3600)
        got = len(PdfReader(pdf).pages)
        if got != len(keep):
            raise SystemExit(f'chunk {k}: {got} pages for {len(keep)} slides')
        os.remove(src)
        print(f'chunk {k}: slides {min(keep)}-{max(keep)} rendered', flush=True)

    w = PdfWriter()
    for pdf, _ in parts:
        for page in PdfReader(pdf).pages:
            if '/Annots' in page:
                del page['/Annots']
            w.add_page(page)
    if len(w.pages) != n:
        raise SystemExit(f'{len(w.pages)} pages for {n} slides')
    pw, ph = float(w.pages[0].mediabox.width), float(w.pages[0].mediabox.height)
    sx, sy = pw / 13.333, ph / 7.5
    added = 0
    for l in links['links']:
        page = w.pages[l['page'] - 1]
        x0, x1 = l['x'] * sx, (l['x'] + l['w']) * sx
        y1, y0 = ph - l['y'] * sy, ph - (l['y'] + l['h']) * sy
        annot = DictionaryObject({
            NameObject('/Type'): NameObject('/Annot'), NameObject('/Subtype'): NameObject('/Link'),
            NameObject('/Rect'): ArrayObject([FloatObject(x0), FloatObject(y0), FloatObject(x1), FloatObject(y1)]),
            NameObject('/Border'): ArrayObject([NumberObject(0), NumberObject(0), NumberObject(0)]),
            NameObject('/Dest'): ArrayObject([w.pages[l['to'] - 1].indirect_reference, NameObject('/Fit')]),
        })
        ref = w._add_object(annot)
        if '/Annots' not in page:
            page[NameObject('/Annots')] = ArrayObject()
        page['/Annots'].append(ref)
        added += 1
    w.add_metadata({'/Title': 'PeopleOS Organisation Administrator Training', '/Subject': 'Training for the organisation administrator of a company using PeopleOS'})
    w.compress_identical_objects(remove_duplicates=True, remove_unreferenced=True)
    with open(OUT, 'wb') as f:
        w.write(f)
    print(f'{OUT}: {len(w.pages)} pages, {added} links, {os.path.getsize(OUT) / 1e6:.1f} MB', flush=True)


t = threading.Thread(target=main)
t.start()
t.join()
