#!/usr/bin/env bash
# Intentional regeneration (C8): writes the catalogue and the two research documents into the repository from the
# specs, the research notes and manual/. Review the diff before committing; the catalogue holds no rule data, so this
# can never activate a statutory rule or change a rule's verification status.
# Usage: tools/compliance-catalogue/regenerate.sh
set -euo pipefail
TOOL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$TOOL/../.." && pwd)"
PY=(python3 -I -B -X utf8)
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Generate everything first, so a failure leaves the repository untouched.
"${PY[@]}" "$TOOL/catalogue/gen2.py" "$TMP/in.php"
php -r 'echo json_encode((require $argv[1])["states"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$TMP/in.php" > "$TMP/states.json"
"${PY[@]}" "$TOOL/docs/matrix.py" "$TMP/states.json" "$TMP/state-wise-compliance-matrix.md" > /dev/null
"${PY[@]}" "$TOOL/docs/sources.py" "$TMP/india-compliance-source-register.md"

cp "$TMP/in.php" "$ROOT/database/data/compliance/reports/in.php"
cp "$TMP/state-wise-compliance-matrix.md" "$ROOT/docs/compliance-research/state-wise-compliance-matrix.md"
cp "$TMP/india-compliance-source-register.md" "$ROOT/docs/compliance-research/india-compliance-source-register.md"
echo "Regenerated. Review with: git diff -- database/data/compliance/reports/in.php docs/compliance-research/"
