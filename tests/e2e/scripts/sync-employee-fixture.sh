#!/usr/bin/env bash
# Sync Nextcloud e2e_employee password from tests/e2e/.env (gitignored).
# Prevents Lens-3/6 employee a11y flakes after host/Docker password-policy resets.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
# tests/e2e → tests → dutycheck → apps → nextcloud
COMPOSE_DIR="$(cd "$ROOT/../../../.." && pwd)"
if [[ ! -f "$COMPOSE_DIR/docker-compose.yml" ]]; then
	# Fallback: workspace layout nextcloud-dev/nextcloud
	COMPOSE_DIR="$(cd "$ROOT/../../../../../nextcloud" && pwd)"
fi
if [[ ! -f "$COMPOSE_DIR/docker-compose.yml" ]]; then
	echo "sync-employee-fixture: docker-compose.yml not found (tried $COMPOSE_DIR)" >&2
	exit 1
fi
if [[ ! -f "$ENV_FILE" ]]; then
	echo "sync-employee-fixture: missing $ENV_FILE (copy from .env.example)" >&2
	exit 1
fi
EMP_USER="$(grep -E '^NC_EMPLOYEE_USER=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
EMP_PASS="$(grep -E '^NC_EMPLOYEE_PASS=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
if [[ -z "${EMP_USER:-}" || -z "${EMP_PASS:-}" ]]; then
	echo "sync-employee-fixture: NC_EMPLOYEE_USER/PASS required in .env" >&2
	exit 1
fi
cd "$COMPOSE_DIR"
docker compose exec -u www-data -e OC_PASS="$EMP_PASS" nextcloud \
	php occ user:resetpassword --password-from-env "$EMP_USER"
echo "sync-employee-fixture: OK user=$EMP_USER"
