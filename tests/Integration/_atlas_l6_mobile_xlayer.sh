#!/usr/bin/env bash
# Lens 6 cross-layer (API ↔ DB): mobile Basic ack + claim must mutate MariaDB.
# Does NOT require an emulator — pairs with device UI proof when a dedicated AVD is held.
#
# Usage (from nextcloud/):
#   RUN_ID=atlas-r2 bash apps/dutycheck/tests/Integration/_atlas_l6_mobile_xlayer.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose)
API="${DC_MOBILE_API:-http://localhost:8081/index.php/apps/dutycheck/api/mobile}"
USER="${DC_MOBILE_USER:-dc.review.employee}"
PASS_LOGIN="${DC_MOBILE_LOGIN_PASS:-DcReviewEmployee2026!}"
RUN_ID="${RUN_ID:-atlas-l6-$(date +%s)}"
ART="${ART_DIR:-/tmp/atlas-dc-r2}"
mkdir -p "$ART"

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

mint_app_password() {
  export OC_PASS="$PASS_LOGIN"
  "${COMPOSE[@]}" exec -T -e OC_PASS nextcloud \
    php occ user:resetpassword --password-from-env "$USER" >/dev/null
  "${COMPOSE[@]}" exec -T -e OC_PASS nextcloud bash -lc \
    "php occ user:auth-tokens:add -n --password-from-env --name=xlayer-${RUN_ID} ${USER} 2>&1" \
    | sed -n '/app password:/{n;p;}' | tr -d '\r '
}

mysql_q() {
  "${COMPOSE[@]}" exec -T mariadb mysql -N -u nextcloud -pnextcloud_password nextcloud \
    -e "$1" 2>/dev/null | tr -d '\r'
}

log "RUN_ID=$RUN_ID user=$USER"
APP_PASS="$(mint_app_password)"
[[ -n "$APP_PASS" ]] || die "failed to mint app password"
printf '%s' "$APP_PASS" >"$ART/mobile-app-pass.txt"
chmod 600 "$ART/mobile-app-pass.txt"

AUTH=(-u "${USER}:${APP_PASS}" -H 'OCS-APIRequest: true' -H 'Accept: application/json')

log "bootstrap"
BOOT=$(curl -sS "${AUTH[@]}" "$API/bootstrap")
echo "$BOOT" | tee "$ART/r2-l6-bootstrap.json" >/dev/null
echo "$BOOT" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("seatAssigned") is True, d; print("seat=ok")'

log "roster → pick unacked assignment"
ROSTER=$(curl -sS "${AUTH[@]}" "$API/my/roster")
echo "$ROSTER" | tee "$ART/r2-l6-roster.json" >/dev/null
AID=$(echo "$ROSTER" | python3 -c '
import json,sys
rows=json.load(sys.stdin)
if isinstance(rows, dict):
  rows=rows.get("assignments") or rows.get("data") or []
for r in rows:
  if not r.get("acknowledged"):
    print(int(r["id"])); break
else:
  sys.exit("no unacked assignment")
')
[[ -n "$AID" ]] || die "no unacked assignment in roster"
BEFORE=$(mysql_q "SELECT IF(acknowledged_at IS NULL,0,1) FROM oc_dc_assignments WHERE id=${AID}")
[[ "$BEFORE" == "0" ]] || die "assignment ${AID} already acknowledged in DB"

log "POST acknowledge assignment=${AID}"
ACK=$(curl -sS -w '\n%{http_code}' -X POST "${AUTH[@]}" \
  "$API/my/assignments/${AID}/acknowledge")
HTTP=$(echo "$ACK" | tail -1)
BODY=$(echo "$ACK" | sed '$d')
echo "$BODY" | tee "$ART/r2-l6-ack.json" >/dev/null
[[ "$HTTP" == "200" || "$HTTP" == "204" ]] || die "ack HTTP=$HTTP body=$BODY"
AFTER=$(mysql_q "SELECT IF(acknowledged_at IS NULL,0,1) FROM oc_dc_assignments WHERE id=${AID}")
[[ "$AFTER" == "1" ]] || die "DB ack not set for assignment ${AID}"
log "ACK OK assignment=${AID} db_ack=1"

log "open-shifts → claim first open"
OPENS=$(curl -sS "${AUTH[@]}" "$API/open-shifts")
echo "$OPENS" | tee "$ART/r2-l6-opens.json" >/dev/null
OID=$(echo "$OPENS" | python3 -c '
import json,sys
rows=json.load(sys.stdin)
if isinstance(rows, dict):
  rows=rows.get("openShifts") or rows.get("items") or rows.get("data") or []
for r in rows:
  if (r.get("status") or "open") == "open":
    print(int(r["id"])); break
else:
  sys.exit("no open shift")
')
[[ -n "$OID" ]] || die "no open shift to claim"
ST_BEFORE=$(mysql_q "SELECT status FROM oc_dc_open_shifts WHERE id=${OID}")
[[ "$ST_BEFORE" == "open" ]] || die "open shift ${OID} status=${ST_BEFORE}"

log "POST claim open=${OID}"
CL=$(curl -sS -w '\n%{http_code}' -X POST "${AUTH[@]}" \
  "$API/open-shifts/${OID}/claim")
HTTP=$(echo "$CL" | tail -1)
BODY=$(echo "$CL" | sed '$d')
echo "$BODY" | tee "$ART/r2-l6-claim.json" >/dev/null
[[ "$HTTP" == "200" || "$HTTP" == "201" ]] || die "claim HTTP=$HTTP body=$BODY"
ST_AFTER=$(mysql_q "SELECT status FROM oc_dc_open_shifts WHERE id=${OID}")
[[ "$ST_AFTER" != "open" ]] || die "claim did not change DB status (still open)"
log "CLAIM OK open=${OID} db_status=${ST_AFTER}"

{
  echo "RUN_ID=$RUN_ID"
  echo "ACK_ASSIGNMENT=$AID"
  echo "CLAIM_OPEN=$OID"
  echo "CLAIM_STATUS=$ST_AFTER"
  echo "PASS"
} | tee "$ART/r2-l6-xlayer-api-db.txt"

log "PASS api↔db cross-layer"
