#!/usr/bin/env bash
# update.sh — git pull kodu + graceful reload Apache (bez rebuildu obrazu)
#
# Używaj gdy zmieniły się tylko pliki PHP/HTML/JS/CSS.
# Dla zmian w Dockerfile lub php.prod.ini użyj rebuild.sh.
#
# Użycie:
#   bash docker/update.sh           # prod + testy (jeśli działa)
#   bash docker/update.sh --no-testy  # tylko prod

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD_DIR="$(dirname "$SCRIPT_DIR")"
TESTY_DIR="/opt/feer-testy"
PROD_CONTAINER="feer-app"
TEST_CONTAINER="feer-testy-app"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

SKIP_TESTY=0
[[ "${1:-}" == "--no-testy" ]] && SKIP_TESTY=1

# ── Funkcja: aktualizuj jedno repo ────────────────────────────────────────────
update_repo() {
    local dir="$1"
    local label="$2"

    if [[ ! -d "${dir}/.git" ]]; then
        warn "Brak repo git w ${dir} — pomijam ${label}"
        return 1
    fi

    local before after
    before=$(git -C "${dir}" rev-parse HEAD)
    git -C "${dir}" pull --ff-only origin main 2>&1 | sed 's/^/    /'
    after=$(git -C "${dir}" rev-parse HEAD)

    if [[ "$before" == "$after" ]]; then
        ok "${label}: już aktualny (${after:0:7})"
        return 0
    fi

    ok "${label}: zaktualizowany ${before:0:7} → ${after:0:7}"
    git -C "${dir}" log --oneline "${before}..${after}" | sed 's/^/    /'
    return 0
}

# ── Funkcja: odśwież Apache + OPcache w kontenerze ────────────────────────────
reload_container() {
    local container="$1"
    local label="$2"

    if ! docker inspect "${container}" &>/dev/null; then
        warn "${label}: kontener nie istnieje — pomijam"
        return
    fi
    if [[ "$(docker inspect "${container}" --format '{{.State.Status}}')" != "running" ]]; then
        warn "${label}: kontener nie działa — pomijam"
        return
    fi

    # Graceful Apache reload (bez utraty połączeń)
    docker exec "${container}" apache2ctl graceful 2>/dev/null \
        || docker exec "${container}" kill -USR1 1 2>/dev/null \
        || true

    # Touch plików PHP → OPcache zwaliduje je przy następnym żądaniu
    docker exec "${container}" find /var/www/html \
        -name "*.php" -newer /var/www/html/config.php \
        -exec touch {} \; 2>/dev/null || true

    ok "${label}: Apache przeładowany, OPcache odświeżony"
}

# ══════════════════════════════════════════════════════════════════════════════

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║   FEER SZO — aktualizacja kodu                          ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

# ── 1. Produkcja ──────────────────────────────────────────────────────────────
section "1. Produkcja — git pull"
update_repo "${PROD_DIR}" "prod"

section "2. Produkcja — reload"
reload_container "${PROD_CONTAINER}" "feer-app"

# ── 2. Środowisko testowe (opcjonalne) ────────────────────────────────────────
if [[ $SKIP_TESTY -eq 1 ]]; then
    warn "Środowisko testowe pominięte (--no-testy)"
elif ! docker inspect "${TEST_CONTAINER}" &>/dev/null || \
     [[ "$(docker inspect "${TEST_CONTAINER}" --format '{{.State.Status}}' 2>/dev/null)" != "running" ]]; then
    info "Środowisko testowe nie działa — pomijam"
else
    section "3. Środowisko testowe — git pull"
    if [[ -d "${TESTY_DIR}/.git" ]]; then
        update_repo "${TESTY_DIR}" "testy"
    else
        warn "${TESTY_DIR} nie istnieje — pomiń lub uruchom setup-testy.sh"
    fi

    section "4. Środowisko testowe — reload"
    reload_container "${TEST_CONTAINER}" "feer-testy-app"
fi

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
ok "Aktualizacja zakończona — $(date '+%H:%M:%S')"
echo ""
