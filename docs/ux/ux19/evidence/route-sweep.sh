#!/usr/bin/env bash
# UX.19: every parameterless admin page as every showcase persona, one process per persona (reach-probe.php ALL=1).
#   DB_DATABASE=<*_showcase database> MYSQL_DEFAULTS=<option file> bash route-sweep.sh <label>
set -euo pipefail
cd "$(dirname "$0")/../../../.."
label=${1:-run}; dir=docs/ux/ux19/evidence; tmp=$(mktemp -d)
for email in $(mysql --defaults-extra-file="${MYSQL_DEFAULTS:?}" -N -e "select email from ${DB_DATABASE}.users where email like '%@demo.local' order by id"); do
  ALL=1 PERSONA="$email" php -d memory_limit=1536M $dir/reach-probe.php "$tmp/$email.json" 2>>"$dir/route-sweep-$label.log"
done
php -r '$rows=[]; foreach (glob($argv[1]."/*.json") as $f) { $rows += json_decode(file_get_contents($f), true)["rows"]; } file_put_contents($argv[2], json_encode(["database" => getenv("DB_DATABASE"), "rows" => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));' "$tmp" "$dir/route-sweep-$label.json"
rm -rf "$tmp"
