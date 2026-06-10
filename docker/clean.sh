#!/usr/bin/env bash
# clean.sh — zatrzymaj, usuń kontenery/obrazy FEER i opcjonalnie wyczyść system Docker
#
# Użycie:
#   bash clean.sh              # usuwa kontenery + obrazy feer-szo-app
#   bash clean.sh --full       # j.w. + docker system prune (wszystkie nieużywane zasoby)
#   bash clean.sh --full --yes # bez pytania o potwierdzenie
#   bash clean.sh --purge      # j.w. + usuwa katalog /opt/feer-szo (pełny reset)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

FULL=0; AUTO_YES=0; PURGE=0
for arg in "$@"; do
    [[ "$arg" == "--full"   ]] && FULL=1
    [[ "$arg" == "--yes"    ]] && AUTO_YES=1
    [[ "$arg" == "-y"       ]] && AUTO_YES=1
    [[ "$arg" == "--purge"  ]] && { PURGE=1; FULL=1; AUTO_YES=1; }
done

# ── 1. Zatrzymaj i usuń kontenery FEER ───────────────────────────────────────
section "1. Kontenery FEER"

CONTAINERS=(feer-app feer-traefik feer-redis feer-mailpit feer-rabbitmq feer-crm-redirect feer-test-83 feer-test-85 feer-test-redis)

for c in "${CONTAINERS[@]}"; do
    if docker inspect "$c" &>/dev/null 2>&1; then
        docker rm -f "$c" && ok "Usunięto kontener: $c" || warn "Nie udało się usunąć: $c"
    fi
done

# Zatrzymaj wszystkie compose stacki w katalogu docker/
for compose_file in \
    "${SCRIPT_DIR}/docker-compose.yml" \
    "${SCRIPT_DIR}/docker-compose.test.yml"; do
    [[ -f "$compose_file" ]] || continue
    fname="$(basename "$compose_file")"
    if docker compose -f "$compose_file" ps -q 2>/dev/null | grep -q .; then
        info "Zatrzymuję stack: ${fname}"
        docker compose -f "$compose_file" down --remove-orphans 2>/dev/null || true
        ok "Stack ${fname} zatrzymany"
    fi
done

ok "Kontenery posprzątane"

# ── 2. Usuń obrazy feer-szo-app ───────────────────────────────────────────────
section "2. Obrazy feer-szo-app"

IMAGE_TAGS=(
    feer-szo-app:latest
    feer-szo-app:php83
    feer-szo-app:php85
)

for tag in "${IMAGE_TAGS[@]}"; do
    if docker image inspect "$tag" &>/dev/null 2>&1; then
        docker rmi -f "$tag" && ok "Usunięto obraz: $tag" || warn "Nie udało się usunąć: $tag"
    fi
done

# Usuń też nieoznaczone (dangling) warstwy po naszych buildach
DANGLING=$(docker images -f "dangling=true" -q 2>/dev/null || true)
if [[ -n "$DANGLING" ]]; then
    echo "$DANGLING" | xargs docker rmi -f 2>/dev/null || true
    ok "Usunięto nieoznaczone warstwy (dangling layers)"
fi

ok "Obrazy posprzątane"

# ── 3. Opcjonalnie: pełne czyszczenie systemu Docker ─────────────────────────
if [[ $FULL -eq 1 ]]; then
    section "3. Docker system prune"
    warn "Usunie WSZYSTKIE nieużywane obrazy, sieci i build cache (nie tylko FEER)."

    if [[ $AUTO_YES -eq 0 ]]; then
        read -rp "Kontynuować? [t/N]: " _ans
        [[ "${_ans,,}" == "t" ]] || { warn "Pominięto system prune."; FULL=0; }
    fi

    if [[ $FULL -eq 1 ]]; then
        docker system prune -af --volumes 2>/dev/null && ok "Docker system prune zakończony"
    fi
fi

# ── 4. Podsumowanie ───────────────────────────────────────────────────────────
echo
docker system df 2>/dev/null || true
echo
# ── 5. Opcjonalnie: usuń katalog instalacji ───────────────────────────────────
INSTALL_DIR="/opt/feer-szo"
if [[ $PURGE -eq 1 && -d "$INSTALL_DIR" ]]; then
    section "5. Usuwanie plików instalacji"
    warn "Usuwam katalog: ${INSTALL_DIR}"
    rm -rf "$INSTALL_DIR"
    ok "Katalog ${INSTALL_DIR} usunięty"
fi

ok "Czyszczenie zakończone."
if [[ $PURGE -eq 0 ]]; then
    echo -e "  Uruchom teraz: ${CYAN}bash rebuild.sh --no-cache${RESET}"
else
    echo -e "  Reinstalacja:  ${CYAN}curl -fsSL https://codeberg.org/ziemowitgil/feerSZO/raw/branch/main/docker/setup.sh | sudo bash${RESET}"
fi
