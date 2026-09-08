#!/usr/bin/env bash
# Atlas L1 — concurrent publish of same open period.
set -euo pipefail
ART="${ART:-/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/artifacts}"
COMPOSE=(docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml)
N="${1:-8}"
mkdir -p "$ART"

SEED_JSON=$("${COMPOSE[@]}" exec -T nextcloud \
  php /var/www/html/custom_apps/dutycheck/tests/Integration/_atlas_l1_seed.php)
PERIOD=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->periodId;' <<<"$SEED_JSON")
echo "$SEED_JSON" | tee "$ART/lens1-publish-seed.json"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

for i in $(seq 1 "$N"); do
  (
    "${COMPOSE[@]}" exec -T nextcloud \
      php /var/www/html/custom_apps/dutycheck/tests/Integration/_atlas_l1_publish_once.php \
      "$PERIOD" >"$TMP/out-$i.json" 2>"$TMP/err-$i.txt" || true
  ) &
done
wait

cat "$TMP"/out-*.json > "$ART/lens1-publish-race-outputs.txt"
echo "=== worker outputs ==="
cat "$ART/lens1-publish-race-outputs.txt"

OK_COUNT=$(grep -c '"ok":true' "$ART/lens1-publish-race-outputs.txt" || true)
CONFLICT=$(grep -c 'PERIOD_STATUS_CONFLICT' "$ART/lens1-publish-race-outputs.txt" || true)
INVALID=$(grep -c 'INVALID_PERIOD_TRANSITION' "$ART/lens1-publish-race-outputs.txt" || true)

STATUS=$("${COMPOSE[@]}" exec -T mariadb \
  mysql -N -unextcloud -pnextcloud_password nextcloud \
  -e "SELECT status FROM oc_dc_periods WHERE id=${PERIOD};")
PUB_COUNT=$("${COMPOSE[@]}" exec -T mariadb \
  mysql -N -unextcloud -pnextcloud_password nextcloud \
  -e "SELECT COUNT(*) FROM oc_dc_periods WHERE id=${PERIOD} AND status='published';")

SUMMARY="workers=$N ok=$OK_COUNT status_conflict=$CONFLICT invalid_transition=$INVALID db_status=$STATUS db_published_rows=$PUB_COUNT"
echo "$SUMMARY" | tee "$ART/lens1-publish-race-summary.txt"

if [[ "$PUB_COUNT" != "1" || "$STATUS" != "published" ]]; then
  echo "FAIL: expected one published row, got count=$PUB_COUNT status=$STATUS" >&2
  exit 1
fi
if [[ "$OK_COUNT" -lt 1 ]]; then
  echo "FAIL: expected at least one success" >&2
  exit 1
fi
# Losers may be PERIOD_STATUS_CONFLICT (CAS) or INVALID_PERIOD_TRANSITION (re-read after flip)
if [[ $((CONFLICT + INVALID)) -lt 1 && "$N" -gt 1 ]]; then
  echo "FAIL: expected concurrent losers" >&2
  exit 1
fi
echo "PASS: concurrent publish collapsed to single published status"
