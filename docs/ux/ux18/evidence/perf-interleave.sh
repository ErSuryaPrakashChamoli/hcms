#!/usr/bin/env bash
# UX.18: before/after under identical conditions. For each scale and each persona:surface, measure the UX.17 code
# (BEFORE_ROOT, a worktree at 69f6386) and the final code (ROOT) alternately: before, final, then final, before. Machine
# drift (swap, other load) therefore hits both sides alike. Output: one JSON line per measurement.
#   BEFORE_ROOT=<worktree> AFTER_ROOT=<repo> OUT=<file.jsonl> perf-interleave.sh
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
CASES=("priya.nair:Home" "priya.nair:My work" "priya.nair:Directory" "amit.verma:Home" "amit.verma:My work" "amit.verma:My team" "amit.verma:People" "amit.verma:Approval Center" "amit.verma:Employee 360 (report)" "amit.verma:Notifications" "neha.kapoor:Home" "neha.kapoor:My work" "neha.kapoor:People directory" "neha.kapoor:Employee register" "neha.kapoor:Employee 360" "meera.iyer:Home" "meera.iyer:My work" "meera.iyer:Workforce pulse" "kavya.menon:Home" "kavya.menon:My work" "kavya.menon:Users" "arjun.bose:Home" "arjun.bose:My work" "arjun.bose:People")
for DB in ${SCALES:-hcm_ux18_s10k_showcase hcm_ux18_s1k_showcase hcm_ux18_s100_showcase}; do
  for c in "${CASES[@]}"; do
    for round in 1 2; do
      if [ $round = 1 ]; then ORDER="before after"; else ORDER="after before"; fi
      for side in $ORDER; do
        root=$AFTER_ROOT; [ $side = before ] && root=$BEFORE_ROOT
        tmp=$(mktemp)
        ONLY="$c" ROOT=$root DB_DATABASE=$DB REPS=${REPS:-5} WARM=${WARM:-2} php "$HERE/perf-measure.php" "$side" "$tmp" 2>/dev/null
        python3 -c "import json,sys; r=json.load(open('$tmp'))['rows'][0]; r.update(side='$side', db='$DB', round=$round); print(json.dumps(r))" >> "$OUT"
        rm -f "$tmp"
      done
    done
  done
done
echo done >> "$OUT.done"
