#!/usr/bin/env bash
# REQ-TST-02 — DutyCheck must not write to at_* tables, import OCA\ArbeitszeitCheck,
# use SELECT *, or misuse the employee absences route for planner outbound links.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0

echo "== DutyCheck ↔ ArbeitszeitCheck integration discipline =="

if command -v rg >/dev/null 2>&1; then
	search() { rg -n -- "$@"; }
elif command -v grep >/dev/null 2>&1; then
	# Portable fallback when ripgrep is unavailable (e.g. slim CI / container images).
	search() {
		local pattern=$1
		shift
		grep -RInE --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules -- "$pattern" "$@"
	}
else
	echo "FAIL: neither rg nor grep available — cannot enforce REQ-TST-02"
	exit 1
fi

hits_mutating="$(search '\b(INSERT|UPDATE|DELETE)\b[^;]*\bat_[a-z_]+' "$ROOT/lib" "$ROOT/appinfo" || true)"
if [[ -n "$hits_mutating" ]]; then
	echo "FAIL: mutating SQL against at_* tables"
	echo "$hits_mutating"
	FAIL=1
else
	echo "OK: no at_* mutating SQL in lib/appinfo"
fi

hits_star="$(search 'SELECT[[:space:]]+\*[[:space:]]+FROM[[:space:]]+at_' "$ROOT/lib" "$ROOT/appinfo" "$ROOT/js" "$ROOT/templates" || true)"
if [[ -n "$hits_star" ]]; then
	echo "FAIL: SELECT * FROM at_*"
	echo "$hits_star"
	FAIL=1
else
	echo "OK: no SELECT * FROM at_*"
fi

hits_ns="$(search 'use OCA\\ArbeitszeitCheck\\' "$ROOT/lib" "$ROOT/appinfo" || true)"
if [[ -n "$hits_ns" ]]; then
	echo "FAIL: OCA\\ArbeitszeitCheck namespace reference in runtime code"
	echo "$hits_ns"
	FAIL=1
else
	echo "OK: no OCA\\ArbeitszeitCheck runtime imports"
fi

hits_route="$(search 'arbeitszeitcheck\.page\.absences' "$ROOT/lib" "$ROOT/js" "$ROOT/templates" "$ROOT/appinfo" \
	| grep -v 'ArbeitszeitCheckIntegrationService\.php' \
	| grep -v 'ROUTE_EMPLOYEE' \
	| grep -v 'peerEmployeeOutboundUrl' \
	| grep -v 'MobileGate' \
	|| true)"
if [[ -n "$hits_route" ]]; then
	echo "FAIL: arbeitszeitcheck.page.absences used outside employee outbound builders"
	echo "$hits_route"
	FAIL=1
else
	echo "OK: employee absences route confined to employee outbound"
fi

if [[ ! -f "$ROOT/lib/Integration/ArbeitszeitCheckAbsenceReader.php" ]]; then
	echo "FAIL: missing AbsenceReader"
	FAIL=1
else
	echo "OK: AbsenceReader present"
fi

# REQ-TST-02 allowlist: peer at_* tables only inside the reader.
hits_peer_at="$(search '\bFROM[[:space:]]+[`\"]?at_' "$ROOT/lib" \
	| grep -v 'ArbeitszeitCheckAbsenceReader\.php' \
	|| true)"
if [[ -n "$hits_peer_at" ]]; then
	echo "FAIL: at_* table access outside AbsenceReader"
	echo "$hits_peer_at"
	FAIL=1
else
	echo "OK: at_* peer tables confined to AbsenceReader"
fi

if [[ "$FAIL" -ne 0 ]]; then
	echo "Discipline check FAILED"
	exit 1
fi
echo "Discipline check PASSED"
exit 0
