#!/usr/bin/env bash
# reset-passwd.sh — resetuje hasło użytkownika w kontenerze FEER SZO
#
# Użycie:
#   bash docker/reset-passwd.sh                          # lista kont (prod)
#   bash docker/reset-passwd.sh serwis@local             # losowe hasło (prod)
#   bash docker/reset-passwd.sh serwis@local "NoweHaslo1!" # podane hasło (prod)
#   bash docker/reset-passwd.sh --testy serwis@local     # środowisko testowe

set -euo pipefail

PROD_CONTAINER="feer-app"
TEST_CONTAINER="feer-testy-app"

# ── Wybór kontenera ────────────────────────────────────────────────────────────
if [[ "${1:-}" == "--testy" ]]; then
    CONTAINER="${TEST_CONTAINER}"
    shift
else
    CONTAINER="${PROD_CONTAINER}"
fi

# ── Sprawdź że kontener działa ─────────────────────────────────────────────────
if ! docker inspect "${CONTAINER}" &>/dev/null; then
    echo "✖ Kontener '${CONTAINER}' nie istnieje." >&2
    echo "  Prod: feer-app  |  Test: --testy" >&2
    exit 1
fi
if [[ "$(docker inspect "${CONTAINER}" --format '{{.State.Status}}')" != "running" ]]; then
    echo "✖ Kontener '${CONTAINER}' nie działa. Sprawdź: docker ps" >&2
    exit 1
fi

# ── Przekaż do cli/passwd.php ──────────────────────────────────────────────────
docker exec -it "${CONTAINER}" php /var/www/html/cli/passwd.php "$@"
