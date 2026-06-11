#!/usr/bin/env bash
# run.sh — wykonaj polecenie w kontenerze FEER (prod lub test)
#
# Użycie:
#   bash docker/run.sh prod <polecenie>
#   bash docker/run.sh test <polecenie>
#
# Skróty (zamiast polecenia):
#   unlock [email]      — odblokuj konta po brute-force (jedno lub wszystkie)
#   locks               — pokaż aktualnie zablokowane konta
#   shell               — bash wewnątrz kontenera
#   logs                — tail -f logów Apache
#   php <skrypt.php>    — uruchom skrypt PHP
#   sql <zapytanie>     — uruchom zapytanie SQLite na umowy.db
#
# Przykłady:
#   bash docker/run.sh prod unlock
#   bash docker/run.sh prod unlock jan@feer.org.pl
#   bash docker/run.sh prod locks
#   bash docker/run.sh prod shell
#   bash docker/run.sh prod php cli/generatorCertyfikatu.php
#   bash docker/run.sh prod sql "SELECT id, email, locked_until FROM users WHERE locked_until IS NOT NULL"
#   bash docker/run.sh test php -r "phpinfo();"

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

err()  { echo -e "${RED}  ✖ $*${RESET}" >&2; }
ok()   { echo -e "${GREEN}  ✔ $*${RESET}"; }
info() { echo -e "${CYAN}  ▸ $*${RESET}"; }
warn() { echo -e "${YELLOW}  ⚠ $*${RESET}"; }

DB_PATH="/var/www/html/umowy.db"

# ── Mapowanie środowiska → nazwa kontenera ────────────────────────────────────
resolve_container() {
    case "${1:-}" in
        prod|p)        echo "feer-app"       ;;
        test|testy|t)  echo "feer-testy-app" ;;
        *)
            err "Nieznane środowisko: '${1}'. Użyj: prod | test"
            exit 1
            ;;
    esac
}

# ── Sprawdź czy kontener działa ───────────────────────────────────────────────
require_running() {
    local ctr="$1"
    if ! docker inspect "${ctr}" &>/dev/null; then
        err "Kontener '${ctr}' nie istnieje."
        exit 1
    fi
    if [[ "$(docker inspect "${ctr}" --format '{{.State.Status}}')" != "running" ]]; then
        err "Kontener '${ctr}' nie działa (status: $(docker inspect "${ctr}" --format '{{.State.Status}}'))."
        exit 1
    fi
}

# ── Pomoc ─────────────────────────────────────────────────────────────────────
usage() {
    grep '^#' "$0" | sed 's/^# \{0,1\}//' | head -20
    exit 0
}

[[ "${1:-}" =~ ^(-h|--help|help)$ ]] && usage
[[ $# -lt 2 ]] && { usage; }

ENV="$1"; shift
CONTAINER=$(resolve_container "$ENV")
LABEL="${ENV^^}"
CMD="${1:-shell}"; shift || true

require_running "$CONTAINER"

# ── Obsługa skrótów ───────────────────────────────────────────────────────────
case "$CMD" in

  # odblokuj konto(a) po brute-force
  unlock)
    EMAIL="${1:-}"
    if [[ -n "$EMAIL" ]]; then
        info "${LABEL}: odblokowuję konto ${EMAIL}…"
        docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
            "UPDATE users SET locked_until=NULL WHERE email='${EMAIL}';
             DELETE FROM login_attempts WHERE identifier='${EMAIL}';"
        ok "Konto ${EMAIL} odblokowane."
    else
        info "${LABEL}: odblokowuję WSZYSTKIE zablokowane konta…"
        COUNT=$(docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
            "SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL;")
        docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
            "UPDATE users SET locked_until=NULL;
             DELETE FROM login_attempts;"
        ok "Odblokowano ${COUNT} kont(a), wyczyszczono login_attempts."
    fi
    ;;

  # lista aktualnie zablokowanych kont
  locks)
    info "${LABEL}: zablokowane konta (brute-force)…"
    docker exec "$CONTAINER" sqlite3 -column -header "$DB_PATH" \
        "SELECT id, email, locked_until,
                CAST((strftime('%s', locked_until) - strftime('%s','now')) / 60 AS INT) || ' min' AS pozostalo
         FROM users
         WHERE locked_until IS NOT NULL AND locked_until > datetime('now')
         ORDER BY locked_until;"
    ;;

  # bash wewnątrz kontenera
  shell)
    info "${LABEL}: otwieranie bash w ${CONTAINER}…"
    docker exec -it "$CONTAINER" bash
    ;;

  # tail logów Apache
  logs)
    info "${LABEL}: tail -f logów Apache (Ctrl+C aby wyjść)…"
    docker exec "$CONTAINER" tail -f \
        /var/log/apache2/error.log \
        /var/log/apache2/access.log 2>/dev/null \
        || docker logs -f "$CONTAINER"
    ;;

  # uruchom skrypt PHP
  php)
    info "${LABEL}: php $*"
    docker exec "$CONTAINER" php "$@"
    ;;

  # zapytanie SQLite
  sql)
    QUERY="${1:-}"
    if [[ -z "$QUERY" ]]; then
        err "Podaj zapytanie SQL jako argument."
        exit 1
    fi
    info "${LABEL}: sqlite3 → ${QUERY}"
    docker exec "$CONTAINER" sqlite3 -column -header "$DB_PATH" "$QUERY"
    ;;

  # dowolne polecenie pass-through
  *)
    info "${LABEL}: wykonuję: ${CMD} $*"
    docker exec "$CONTAINER" "$CMD" "$@"
    ;;

esac
