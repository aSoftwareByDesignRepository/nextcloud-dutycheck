#!/usr/bin/env bash
# Atlas L1 — concurrent claim of same open shift; DB must show exactly one pending claim.
set -euo pipefail
ART="${ART:-/home/alex/Development/nextcloud-dev/documentation/dutycheck/qa-report/artifacts}"
COMPOSE=(docker compose -f /home/alex/Development/nextcloud-dev/nextcloud/docker-compose.yml)
N="${1:-8}"
mkdir -p "$ART"

SEED_JSON=$("${COMPOSE[@]}" exec -T nextcloud \
  php /var/www/html/custom_apps/dutycheck/tests/Integration/_atlas_l1_openshift_seed.php)
echo "$SEED_JSON" | tee "$ART/lens1-openshift-seed.json"
OSID=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->openShiftId;' <<<"$SEED_JSON")
UID=$(php -r '$j=json_decode(file_get_contents("php://stdin")); echo $j->employeeUserId;' <<<"$SEED_JSON")

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

for i in $(seq 1 "$N"); do
  (
    "${COMPOSE[@]}" exec -T nextcloud \
      php /var/www/html/custom_apps/dutycheck/tests/Integration/_atlas_l1_claim_once.php \
      "$OSID" "$UID" >"$TMP/out-$i.json" 2>"$TMP/err-$i.txt" || true
  ) &
done
wait

cat "$TMP"/out-*.json > "$ART/lens1-claim-race-outputs.txt"
echo "=== worker outputs ==="
cat "$ART/lens1-claim-race-outputs.txt"

OK_COUNT=$(grep -c '"ok":true' "$ART/lens1-claim-race-outputs.txt" || true)
NOT_OPEN=$(grep -c 'OPEN_SHIFT_NOT_OPEN' "$ART/lens1-claim-race-outputs.txt" || true)

STATUS=$("${COMPOSE[@]}" exec -T mariadb \
  mysql -N -unextcloud -pnextcloud_password nextcloud \
  -e "SELECT status FROM oc_dc_open_shifts WHERE id=${OSID};")
PENDING=$("${COMPOSE[@]}" exec -T mariadb \
  mysql -N -unextcloud -pnextcloud_password nextcloud \
  -e "SELECT COUNT(*) FROM oc_dc_open_shifts WHERE id=${OSID} AND status='pending';")

SUMMARY="workers=$N ok=$OK_COUNT not_open=$NOT_OPEN db_status=$STATUS db_pending_rows=$PENDING"
echo "$SUMMARY" | tee "$ART/lens1-claim-race-summary.txt"

if [[ "$PENDING" != "1" || "$STATUS" != "pending" ]]; then
  echo "FAIL: expected one pending open-shift, got status=$STATUS pending=$PENDING" >&2
  exit 1
fi
if [[ "$OK_COUNT" -lt 1 ]]; then
  echo "FAIL: expected at least one success" >&2
  exit 1
fi
echo "PASS: concurrent claims collapsed to single pending row"
