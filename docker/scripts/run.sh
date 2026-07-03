#!/usr/bin/env bash
# run.sh — wykonaj polecenie w kontenerze FEER (prod, test lub oba)
#
# Użycie:
#   bash docker/run.sh prod <polecenie>
#   bash docker/run.sh test <polecenie>
#   bash docker/run.sh both <polecenie>    ← prod + test naraz
#
# Skróty (zamiast polecenia):
#   unlock [email]      — odblokuj konta po brute-force logowania
#   locks               — pokaż aktualnie zablokowane konta (brute-force)
#   unlock-ika [email]  — odblokuj blokadę IKA/CPC
#   locks-ika           — pokaż aktualnie zablokowane kody IKA
#   shell               — bash wewnątrz kontenera (tylko prod/test)
#   logs                — tail -f logów Apache (tylko prod/test)
#   php <skrypt.php>    — uruchom skrypt PHP
#   sql <zapytanie>     — uruchom zapytanie SQLite na umowy.db
#
# Przykłady:
#   bash docker/run.sh both unlock
#   bash docker/run.sh both unlock-ika jan@feer.org.pl
#   bash docker/run.sh both locks-ika
#   bash docker/run.sh prod shell
#   bash docker/run.sh both php cli/generatorCertyfikatu.php
#   bash docker/run.sh both sql "SELECT id,email,locked_until FROM users WHERE locked_until IS NOT NULL"
#   bash docker/run.sh test php -r "phpinfo();"

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

err()  { echo -e "${RED}  ✖ $*${RESET}" >&2; }
ok()   { echo -e "${GREEN}  ✔ $*${RESET}"; }
info() { echo -e "${CYAN}  ▸ $*${RESET}"; }
warn() { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
sep()  { echo -e "${BOLD}  ── ${1} ─────────────────────────────────────${RESET}"; }

DB_PATH="/var/www/html/umowy.db"

# ── Sprawdź czy kontener działa (miękko — tylko warn, nie exit) ───────────────
is_running() {
    local ctr="$1"
    docker inspect "${ctr}" &>/dev/null \
        && [[ "$(docker inspect "${ctr}" --format '{{.State.Status}}')" == "running" ]]
}

# ── Wykonaj polecenie w jednym kontenerze ─────────────────────────────────────
run_one() {
    local CONTAINER="$1"
    local LABEL="$2"
    local CMD="$3"
    shift 3
    # $@ = reszta argumentów

    if ! is_running "$CONTAINER"; then
        warn "${LABEL} (${CONTAINER}): kontener nie działa — pomijam"
        return
    fi

    case "$CMD" in

      unlock)
        local EMAIL="${1:-}"
        if [[ -n "$EMAIL" ]]; then
            info "${LABEL}: odblokowuję konto ${EMAIL}…"
            docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
                "UPDATE users SET locked_until=NULL WHERE email='${EMAIL}';
                 DELETE FROM login_attempts WHERE identifier='${EMAIL}';"
            ok "${LABEL}: konto ${EMAIL} odblokowane."
        else
            local COUNT
            COUNT=$(docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
                "SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL;")
            info "${LABEL}: odblokowuję wszystkie zablokowane konta (${COUNT})…"
            docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
                "UPDATE users SET locked_until=NULL; DELETE FROM login_attempts;"
            ok "${LABEL}: odblokowano ${COUNT} kont(a), wyczyszczono login_attempts."
        fi
        ;;

      locks)
        info "${LABEL}: zablokowane konta (brute-force)…"
        docker exec "$CONTAINER" sqlite3 -column -header "$DB_PATH" \
            "SELECT id, email, locked_until,
                    CAST((strftime('%s', locked_until) - strftime('%s','now')) / 60 AS INT) || ' min' AS pozostalo
             FROM users
             WHERE locked_until IS NOT NULL AND locked_until > datetime('now')
             ORDER BY locked_until;"
        ;;

      unlock-ika)
        local EMAIL="${1:-}"
        if [[ -n "$EMAIL" ]]; then
            info "${LABEL}: odblokowuję IKA dla ${EMAIL}…"
            docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
                "UPDATE users SET cpc_fails=0, cpc_blocked_until=NULL WHERE email='${EMAIL}';"
            ok "${LABEL}: IKA odblokowane dla ${EMAIL}."
        else
            local COUNT
            COUNT=$(docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
                "SELECT COUNT(*) FROM users WHERE cpc_blocked_until IS NOT NULL AND cpc_blocked_until > datetime('now');")
            info "${LABEL}: odblokowuję wszystkie blokady IKA (${COUNT})…"
            docker exec "$CONTAINER" sqlite3 "$DB_PATH" \
                "UPDATE users SET cpc_fails=0, cpc_blocked_until=NULL WHERE cpc_blocked_until IS NOT NULL;"
            ok "${LABEL}: odblokowano IKA dla ${COUNT} kont(a)."
        fi
        ;;

      locks-ika)
        info "${LABEL}: zablokowane kody IKA…"
        docker exec "$CONTAINER" sqlite3 -column -header "$DB_PATH" \
            "SELECT id, email, cpc_fails AS proby, cpc_blocked_until AS zablokowany_do,
                    CAST((strftime('%s', cpc_blocked_until) - strftime('%s','now')) / 60 AS INT) || ' min' AS pozostalo
             FROM users
             WHERE cpc_blocked_until IS NOT NULL AND cpc_blocked_until > datetime('now')
             ORDER BY cpc_blocked_until;"
        ;;

      shell)
        info "${LABEL}: otwieranie bash w ${CONTAINER}…"
        docker exec -it "$CONTAINER" bash
        ;;

      logs)
        info "${LABEL}: tail -f logów Apache (Ctrl+C aby wyjść)…"
        docker exec "$CONTAINER" tail -f \
            /var/log/apache2/error.log \
            /var/log/apache2/access.log 2>/dev/null \
            || docker logs -f "$CONTAINER"
        ;;

      php)
        info "${LABEL}: php $*"
        docker exec "$CONTAINER" php "$@"
        ;;

      sql)
        local QUERY="${1:-}"
        if [[ -z "$QUERY" ]]; then err "Podaj zapytanie SQL."; return 1; fi
        info "${LABEL}: sqlite3 → ${QUERY}"
        docker exec "$CONTAINER" sqlite3 -column -header "$DB_PATH" "$QUERY"
        ;;

      *)
        info "${LABEL}: wykonuję: ${CMD} $*"
        docker exec "$CONTAINER" "$CMD" "$@"
        ;;
    esac
}

# ── Pomoc ─────────────────────────────────────────────────────────────────────
usage() {
    grep '^#' "$0" | sed 's/^# \{0,1\}//' | head -30
    exit 0
}

[[ "${1:-}" =~ ^(-h|--help|help)$ ]] && usage
[[ $# -lt 2 ]] && usage

ENV="$1"; shift
CMD="${1:-shell}"; shift || true

# ── Uruchom na jednym lub obu środowiskach ────────────────────────────────────
case "$ENV" in
    prod|p)
        run_one "feer-app"       "PROD" "$CMD" "$@"
        ;;
    test|testy|t)
        run_one "feer-testy-app" "TEST" "$CMD" "$@"
        ;;
    both|all|a)
        # shell i logs są interaktywne — nie dają się uruchomić na obu naraz
        if [[ "$CMD" =~ ^(shell|logs)$ ]]; then
            err "'${CMD}' jest interaktywne — użyj prod lub test, nie both."
            exit 1
        fi
        sep "PROD"
        run_one "feer-app"       "PROD" "$CMD" "$@"
        sep "TEST"
        run_one "feer-testy-app" "TEST" "$CMD" "$@"
        ;;
    *)
        err "Nieznane środowisko: '${ENV}'. Użyj: prod | test | both"
        exit 1
        ;;
esac
