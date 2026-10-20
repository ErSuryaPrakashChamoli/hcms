#!/usr/bin/env bash
# UX.19: a closer look at the surfaces the interleaved run showed a few per cent apart. ROUNDS alternating rounds
# (before/after, then after/before) of REPS measured requests each, at the 10,585-employee scale, with UX.18's
# perf-measure.php. Includes two surfaces UX.19 did not touch as a noise control (manager People, HR Home).
#   BEFORE_ROOT=<worktree at 59118a3> AFTER_ROOT=<repo> OUT=<file.jsonl> [ROUNDS=4] [REPS=9] perf-recheck.sh
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
MEASURE="$HERE/../../ux18/evidence/perf-measure.php"
CASES=("priya.nair:Home" "amit.verma:Home" "kavya.menon:Home" "amit.verma:My team" "amit.verma:People" "neha.kapoor:Home")
for c in "${CASES[@]}"; do
  for round in $(seq 1 "${ROUNDS:-4}"); do
    if [ $((round % 2)) = 1 ]; then ORDER="before after"; else ORDER="after before"; fi
    for side in $ORDER; do
      root=$AFTER_ROOT; [ $side = before ] && root=$BEFORE_ROOT
      tmp=$(mktemp)
      ONLY="$c" ROOT=$root DB_DATABASE=${DB:-hcm_ux19_s10k_showcase} REPS=${REPS:-9} WARM=2 php "$MEASURE" "$side" "$tmp" 2>/dev/null
      python3 -c "import json; r=json.load(open('$tmp'))['rows'][0]; r.update(side='$side', round=$round); print(json.dumps(r))" >> "$OUT"
      rm -f "$tmp"
    done
  done
done
echo done >> "$OUT.done"
