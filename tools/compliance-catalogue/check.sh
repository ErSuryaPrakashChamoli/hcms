#!/usr/bin/env bash
# C8 reproducibility check. Regenerates the compliance report catalogue and the two research documents in a temporary
# directory and compares them byte-for-byte with the committed files. It never writes to the working tree.
# Exit 0: identical. Exit 1: a difference (a unified diff is printed). Exit 2: the generator itself failed.
# Usage: tools/compliance-catalogue/check.sh [repository root to compare against; default: this repository]
set -uo pipefail
TOOL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${1:-$(cd "$TOOL/../.." && pwd)}"
PY=(python3 -I -B -X utf8)   # isolated, no bytecode written into the tree, UTF-8 regardless of locale
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

generate() {
  "${PY[@]}" "$TOOL/catalogue/gen2.py" "$TMP/in.php" &&
  php -r 'echo json_encode((require $argv[1])["states"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$TMP/in.php" > "$TMP/states.json" &&
  "${PY[@]}" "$TOOL/docs/matrix.py" "$TMP/states.json" "$TMP/state-wise-compliance-matrix.md" > /dev/null &&
  "${PY[@]}" "$TOOL/docs/sources.py" "$TMP/india-compliance-source-register.md"
}
if ! generate; then
  echo "FAILED: the generator did not run to completion (see the error above)." >&2
  exit 2
fi

status=0
compare() { # generated committed label
  if cmp -s "$1" "$2"; then
    echo "identical: $3"
  else
    echo "DIFFERS:   $3 (committed file vs regenerated output)"
    diff -u --label "committed/$3" --label "generated/$3" "$2" "$1" | head -n 80
    status=1
  fi
}
compare "$TMP/in.php" "$ROOT/database/data/compliance/reports/in.php" "database/data/compliance/reports/in.php"
compare "$TMP/state-wise-compliance-matrix.md" "$ROOT/docs/compliance-research/state-wise-compliance-matrix.md" "docs/compliance-research/state-wise-compliance-matrix.md"
compare "$TMP/india-compliance-source-register.md" "$ROOT/docs/compliance-research/india-compliance-source-register.md" "docs/compliance-research/india-compliance-source-register.md"

if [ "$status" -ne 0 ]; then
  echo
  echo "The committed files do not match the generator. Either a generated file was edited by hand (edit the specs, the"
  echo "research notes or manual/ instead), or the specs changed without regenerating: run"
  echo "tools/compliance-catalogue/regenerate.sh and review the diff."
fi
exit "$status"
