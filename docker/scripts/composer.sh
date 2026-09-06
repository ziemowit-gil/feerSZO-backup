#!/usr/bin/env bash
# composer.sh — uruchamia Composera w kontenerze aplikacji (PHP 8.4).
#
# Rozwiązuje błąd:
#   "ext-dom is missing" / "ext-bcmath is missing"
# który pojawia się przy uruchamianiu composera na HOŚCIE (PHP 8.3 CLI bez tych
# rozszerzeń). Kontener feer-app działa na PHP 8.4 i ma komplet rozszerzeń
# (bcmath, dom, xml, ...), a katalog kodu jest bind-mountowany, więc vendor/
# zapisuje się z powrotem na host.
#
# Użycie:
#   bash docker/composer.sh                       # composer install --no-dev --optimize-autoloader
#   bash docker/composer.sh update                # dowolna komenda composera
#   bash docker/composer.sh require foo/bar:^1.0
#   bash docker/composer.sh --testy install       # operuj na kontenerze testowym
#
# Jeśli kontener nie działa, skrypt spróbuje uruchomić composera przez
# `docker compose run --rm`, a w ostateczności na hoście z --ignore-platform-req.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# docker-compose.yml może być obok skryptu (uruchomiony przez symlink
# docker/*.sh) albo jeden poziom wyżej (docker/scripts/*.sh) —
# sprawdzamy, gdzie faktycznie jest ([[project_docker_scripts_reorg]]).
if [[ -f "${SCRIPT_DIR}/docker-compose.yml" ]]; then
    PROD_DIR="$SCRIPT_DIR"
else
    PROD_DIR="$(dirname "$SCRIPT_DIR")"
fi
PROD_CONTAINER="feer-app"
TEST_CONTAINER="feer-testy-app"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
err()     { echo -e "${RED}  ✘ $*${RESET}"; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

# ── Wybór środowiska ──────────────────────────────────────────────────────────
CONTAINER="$PROD_CONTAINER"
if [[ "${1:-}" == "--testy" ]]; then
    CONTAINER="$TEST_CONTAINER"
    shift
fi

# ── Argumenty composera (domyślnie: install) ──────────────────────────────────
if [[ $# -eq 0 ]]; then
    COMPOSER_ARGS=(install --no-dev --optimize-autoloader)
else
    COMPOSER_ARGS=("$@")
fi

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║   FEER SZO — Composer w kontenerze                       ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"
info "Kontener: ${CONTAINER}"
info "Komenda:  composer ${COMPOSER_ARGS[*]}"

container_running() {
    docker inspect "$1" &>/dev/null && \
    [[ "$(docker inspect "$1" --format '{{.State.Status}}' 2>/dev/null)" == "running" ]]
}

# ── Ścieżka 1: kontener działa → docker exec ──────────────────────────────────
if container_running "$CONTAINER"; then
    section "Uruchamiam composera w działającym kontenerze"
    docker exec -w /var/www/html "$CONTAINER" composer "${COMPOSER_ARGS[@]}"

    # vendor/ powstaje jako root — odśwież prawa odczytu dla Apache (www-data)
    docker exec "$CONTAINER" sh -c \
        'find /var/www/html/vendor -type d -exec chmod o+rx {} + 2>/dev/null; \
         find /var/www/html/vendor -type f -exec chmod o+r  {} + 2>/dev/null' || true
    ok "Gotowe. vendor/ zaktualizowany i czytelny dla Apache."

    section "Reload OPcache"
    docker exec "$CONTAINER" apache2ctl graceful 2>/dev/null || true
    ok "Apache przeładowany."
    echo ""
    exit 0
fi

# ── Ścieżka 2: kontener nie działa → docker compose run --rm ──────────────────
warn "Kontener ${CONTAINER} nie działa — próbuję 'docker compose run --rm'."

COMPOSE_FILES=(-f docker-compose.yml)
[[ -f "${PROD_DIR}/docker-compose.prod.yml" ]] && COMPOSE_FILES+=(-f docker-compose.prod.yml)
ENV_ARG=()
[[ -f "${PROD_DIR}/.env.prod" ]] && ENV_ARG=(--env-file .env.prod)
[[ "$CONTAINER" == "$TEST_CONTAINER" && -f "${PROD_DIR}/docker-compose.testy-srv.yml" ]] && \
    COMPOSE_FILES=(-f docker-compose.yml -f docker-compose.testy-srv.yml)

if docker compose version &>/dev/null; then
    section "docker compose run --rm app"
    ( cd "$PROD_DIR" && \
      docker compose "${COMPOSE_FILES[@]}" "${ENV_ARG[@]}" \
        run --rm -w /var/www/html app composer "${COMPOSER_ARGS[@]}" )
    ok "Gotowe (przez docker compose run)."
    echo ""
    exit 0
fi

# ── Ścieżka 3 (ostateczność): composer na hoście z pominięciem rozszerzeń ─────
warn "Docker niedostępny — fallback na composera hosta z --ignore-platform-req."
warn "To bezpieczne TYLKO dlatego, że docelowo kod działa w kontenerze PHP 8.4,"
warn "który ma ext-dom i ext-bcmath. Nie uruchamiaj tak aplikacji na hoście."

if ! command -v composer &>/dev/null; then
    err "Brak composera na hoście i brak Dockera. Przerywam."
    exit 1
fi

( cd "$PROD_DIR" && \
  composer "${COMPOSER_ARGS[@]}" \
    --ignore-platform-req=ext-dom \
    --ignore-platform-req=ext-bcmath )
ok "Gotowe (host, z pominięciem ext-dom/ext-bcmath)."
echo ""
