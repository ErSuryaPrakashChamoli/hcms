# Make a small QA copy of the built course holding only the given slides (others are removed from the package).
#   python qa/subset.py out.pptx 1,2,3,...
import sys, json, random, threading
sys.setrecursionlimit(200000)
threading.stack_size(512 * 1024 * 1024)
from pptx import Presentation
deck = sys.argv[1]; out = sys.argv[2]; keep = sorted({int(x) for x in sys.argv[3].split(',')})
def main():
  p = Presentation(deck)
  ids = p.slides._sldIdLst
  for i, sid in reversed(list(enumerate(list(ids), 1))):
    if i not in keep:
        p.part.drop_rel(sid.rId); ids.remove(sid)
  p.save(out); print('kept', len(keep), 'slides ->', out)
t = threading.Thread(target=main); t.start(); t.join()
