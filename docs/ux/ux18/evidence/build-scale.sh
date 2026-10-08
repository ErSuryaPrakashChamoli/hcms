#!/bin/bash
# UX.18 scale databases (disposable, fictional): 100, 1,000 and 10,000 (+ edge cases) employees.
set -e
# MYSQL_DEFAULTS: a MySQL option file with credentials (never committed). Run from the application root.
MY=${MYSQL_DEFAULTS:?set MYSQL_DEFAULTS to a MySQL option file}
for spec in "hcm_ux18_s100_showcase:100:0" "hcm_ux18_s1k_showcase:1000:0" "hcm_ux18_s10k_showcase:10000:1"; do
  IFS=: read DB N EDGE <<< "$spec"
  start=$(date +%s)
  mysql --defaults-extra-file=$MY -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  DB_DATABASE=$DB php artisan migrate --force -q
  DB_DATABASE=$DB php artisan db:seed --class=UxShowcaseSeeder --force -q
  DB_DATABASE=$DB UX_SCALE_EMPLOYEES=$N php artisan db:seed --class=UxScaleShowcaseSeeder --force -q
  if [ "$EDGE" = "1" ]; then DB_DATABASE=$DB UX_SCALE_EDGE=1 php artisan db:seed --class=UxScaleShowcaseSeeder --force -q; fi
  echo "$DB done in $(( $(date +%s) - start ))s: $(mysql --defaults-extra-file=$MY -N -e "select count(*) from $DB.employees")"
done
