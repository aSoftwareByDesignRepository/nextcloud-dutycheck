#!/usr/bin/env bash
# Atlas L1 — fire N concurrent createAssignment for the same slot; assert DB count == 1.
set -euo pipefail
ART="${ART:-/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/artifacts}"
COMPOSE=(docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml)
N="${1:-8}"
mkdir -p "$ART"

SEED_JSON=$("${COMPOSE[@]}" exec -T nextcloud \
  php /var/www/html/custom_apps/dutycheck/tests/Integration/_atlas_l1_seed.php)
echo "$SEED_JSON" | tee "$ART/lens1-seed.json"

PERIOD=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->periodId;' <<<"$SEED_JSON")
EMP=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->employeeId;' <<<"$SEED_JSON")
LOC=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->locationId;' <<<"$SEED_JSON")
DATE=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->dutyDate;' <<<"$SEED_JSON")
START=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->start;' <<<"$SEED_JSON")
END=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->end;' <<<"$SEED_JSON")
BREAK=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->{"break"} ?? 30;' <<<"$SEED_JSON")

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

for i in $(seq 1 "$N"); do
  (
    "${COMPOSE[@]}" exec -T nextcloud \
      php /var/www/html/custom_apps/dutycheck/tests/Integration/_atlas_l1_create_once.php \
      "$PERIOD" "$EMP" "$LOC" "$DATE" "$START" "$END" "$BREAK" >"$TMP/out-$i.json" 2>"$TMP/err-$i.txt" || true
  ) &
done
wait

cat "$TMP"/out-*.json > "$ART/lens1-assignment-race-outputs.txt"
echo "=== worker outputs ==="
cat "$ART/lens1-assignment-race-outputs.txt"

OK_COUNT=$(grep -c '"ok":true' "$ART/lens1-assignment-race-outputs.txt" || true)
FAIL_COUNT=$(grep -c '"ok":false' "$ART/lens1-assignment-race-outputs.txt" || true)
DUP_COUNT=$(grep -c 'ASSIGNMENT_DUPLICATE_SLOT' "$ART/lens1-assignment-race-outputs.txt" || true)
OVERLAP_COUNT=$(grep -c 'ASSIGNMENT_OVERLAP' "$ART/lens1-assignment-race-outputs.txt" || true)

DB_COUNT=$("${COMPOSE[@]}" exec -T mariadb \
  mysql -N -unextcloud -pnextcloud_password nextcloud \
  -e "SELECT COUNT(*) FROM oc_dc_assignments WHERE period_id=${PERIOD} AND employee_id=${EMP} AND duty_date='${DATE}' AND status='active';")

SUMMARY="workers=$N ok=$OK_COUNT fail=$FAIL_COUNT duplicate_slot=$DUP_COUNT overlap=$OVERLAP_COUNT db_active_rows=$DB_COUNT"
echo "$SUMMARY" | tee "$ART/lens1-assignment-race-summary.txt"

if [[ "$DB_COUNT" != "1" ]]; then
  echo "FAIL: expected exactly 1 active assignment, got $DB_COUNT" >&2
  exit 1
fi
if [[ "$OK_COUNT" -lt 1 ]]; then
  echo "FAIL: expected at least one success" >&2
  exit 1
fi
echo "PASS: concurrent creates collapsed to 1 DB row"
