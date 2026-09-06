#!/usr/bin/env bash
# apply-prod.sh — bezpieczne zastosowanie zmian konfiguracji na PRODUKCJI
#
# W odróżnieniu od rebuild.sh NIE robi `git reset --hard` ani przebudowy obrazu.
# Jedynie przeładowuje stack (`docker compose up -d`), aby:
#   - Traefik podchwycił nowe etykiety (routery/aliasy *.ngosystem.pl),
#   - zadziałał zmieniony php.prod.ini (montowany jako wolumen),
#   - zostały usunięte nieaktualne kontenery (np. *-redirect) — flaga --prune.
#
# Kod aplikacji bierze się z bieżącego drzewa roboczego (bez ruszania gita).
# Jeśli chcesz najpierw pobrać zmiany, zrób to świadomie: `git pull --ff-only`
# albo uruchom z flagą --pull (bezpieczny fast-forward, BEZ hard reset).
#
# Użycie:
#   bash apply-prod.sh                 # przeładuj stack (SQLite)
#   bash apply-prod.sh --pull          # git pull --ff-only + restart app (OPcache)
#   bash apply-prod.sh --prune         # przeładuj + usuń osierocone kontenery
#   bash apply-prod.sh --mysql         # wariant z MySQL
#   bash apply-prod.sh --ng            # dołącz serwis Angular (ng-ui, szo.feer.org.pl/newUI/)
#   bash apply-prod.sh --kursant       # dołącz panel kursanta (kursant-ui, ti.feer.org.pl/newUI/)
#   bash apply-prod.sh --subdomains    # dołącz subdomeny modułów (*.szo.feer.org.pl) + wildcard SSL
#   bash apply-prod.sh --build         # dodatkowo przebuduj obraz (jak rebuild)
#   bash apply-prod.sh --dry-run       # tylko pokaż polecenie, nic nie uruchamiaj
#   bash apply-prod.sh -y              # bez pytania o potwierdzenie

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# docker-compose.yml może być obok skryptu (uruchomiony przez symlink
# docker/*.sh) albo jeden poziom wyżej (docker/scripts/*.sh) —
# sprawdzamy, gdzie faktycznie jest ([[project_docker_scripts_reorg]]).
if [[ -f "${SCRIPT_DIR}/docker-compose.yml" ]]; then
    REPO_DIR="$SCRIPT_DIR"
else
    REPO_DIR="$(dirname "$SCRIPT_DIR")"
fi
ENV_FILE="${REPO_DIR}/.env.prod"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info() { echo -e "${CYAN}▸ $*${RESET}"; }
ok()   { echo -e "${GREEN}✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()  { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

usage() { grep '^#' "$0" | sed 's/^# \{0,1\}//' | sed '1d'; exit 0; }

# ── Argumenty ─────────────────────────────────────────────────────────────────
USE_MYSQL=0; DO_PULL=0; DO_BUILD=0; DO_PRUNE=0; DRY_RUN=0; ASSUME_YES=0; USE_NG=0; USE_KURSANT=0; USE_SUBDOMAINS=0
for arg in "$@"; do
    case "$arg" in
        -h|--help)   usage ;;
        --mysql)     USE_MYSQL=1 ;;
        --ng)        USE_NG=1 ;;
        --kursant)   USE_KURSANT=1 ;;
        --subdomains) USE_SUBDOMAINS=1 ;;
        --pull)      DO_PULL=1 ;;
        --build)     DO_BUILD=1 ;;
        --prune)     DO_PRUNE=1 ;;
        --dry-run)   DRY_RUN=1 ;;
        -y|--yes)    ASSUME_YES=1 ;;
        *)           die "Nieznana flaga: $arg (użyj --help)" ;;
    esac
done

[[ -f "$ENV_FILE" ]] || die ".env.prod nie istnieje: ${ENV_FILE}"
command -v docker >/dev/null || die "Brak 'docker'."

# ── Budowa polecenia docker compose ───────────────────────────────────────────
COMPOSE="docker compose \
  -f ${REPO_DIR}/docker-compose.yml \
  -f ${REPO_DIR}/docker-compose.prod.yml \
  --env-file ${ENV_FILE}"
[[ $USE_MYSQL   -eq 1 ]] && COMPOSE="${COMPOSE} -f ${REPO_DIR}/docker-compose.mysql.yml"
[[ $USE_NG      -eq 1 ]] && COMPOSE="${COMPOSE} -f ${REPO_DIR}/docker-compose.ng.yml"
[[ $USE_KURSANT -eq 1 ]] && COMPOSE="${COMPOSE} -f ${REPO_DIR}/docker-compose.kursant.yml"
[[ $USE_SUBDOMAINS -eq 1 ]] && COMPOSE="${COMPOSE} \
  -f ${REPO_DIR}/docker-compose.szo-subdomains.yml \
  -f ${REPO_DIR}/docker-compose.wildcard-ssl.yml"

UP_ARGS="up -d"
[[ $DO_BUILD -eq 1 ]] && UP_ARGS="${UP_ARGS} --build"
[[ $DO_PRUNE -eq 1 ]] && UP_ARGS="${UP_ARGS} --remove-orphans"

# ── Plan ──────────────────────────────────────────────────────────────────────
echo -e "${BOLD}━━ apply-prod ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
info "Tryb DB:      $([[ $USE_MYSQL -eq 1 ]] && echo MySQL || echo SQLite)"
info "Angular UI:   $([[ $USE_NG -eq 1 ]] && echo 'tak (ng-ui → szo/newUI/)' || echo nie)"
info "Kursant UI:   $([[ $USE_KURSANT -eq 1 ]] && echo 'tak (kursant-ui → ti/newUI/)' || echo nie)"
info "git pull:     $([[ $DO_PULL -eq 1 ]] && echo 'tak (--ff-only + restart app → OPcache)' || echo 'nie (bieżące drzewo)')"
info "Rebuild:      $([[ $DO_BUILD -eq 1 ]] && echo tak || echo 'nie (tylko recreate)')"
info "Remove-orphans: $([[ $DO_PRUNE -eq 1 ]] && echo tak || echo nie)"
info "Polecenie:    ${COMPOSE} ${UP_ARGS}"
echo ""
warn "Operacja odtworzy zmienione kontenery (krótka przerwa w działaniu app)."

if [[ $DRY_RUN -eq 1 ]]; then
    warn "--dry-run: nic nie uruchamiam."
    exit 0
fi

if [[ $ASSUME_YES -ne 1 ]]; then
    read -rp "$(echo -e "${CYAN}Kontynuować? [t/N]: ${RESET}")" _c
    [[ "$(printf '%s' "$_c" | tr '[:upper:]' '[:lower:]')" == t* ]] || { warn "Anulowano."; exit 0; }
fi

# ── 1. (opcjonalnie) bezpieczny git pull ──────────────────────────────────────
if [[ $DO_PULL -eq 1 ]]; then
    info "git pull --ff-only…"
    git -C "$REPO_DIR" pull --ff-only || die "git pull --ff-only nie powiódł się (rozbieżne zmiany lokalne?). Rozwiąż ręcznie."
    ok "Kod zaktualizowany ($(git -C "$REPO_DIR" log -1 --format='%h %s'))"
fi

# ── 2. Walidacja konfiguracji compose (nie ruszamy nic, gdy błąd) ─────────────
info "Walidacja docker compose config…"
$COMPOSE config >/dev/null || die "Błąd w konfiguracji compose — przerywam, stack nietknięty."
ok "Konfiguracja poprawna."

# ── 3. Recreate ────────────────────────────────────────────────────────────────
info "Przeładowanie stacku…"
$COMPOSE $UP_ARGS
ok "Zastosowano."

# Po git pull OPcache (validate_timestamps=0) nie widzi nowych plików —
# wymuszamy restart kontenera app żeby wyczyścić cache bytecode.
if [[ $DO_PULL -eq 1 ]]; then
    info "Restart kontenera app (czyszczenie OPcache po git pull)…"
    $COMPOSE restart app
    ok "OPcache wyczyszczony."
fi

# ── 4. Status ───────────────────────────────────────────────────────────────
echo ""
$COMPOSE ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null || $COMPOSE ps

echo ""
info "Logi Traefika (ostatnie 15):"
docker logs feer-traefik --tail=15 2>/dev/null || warn "Brak kontenera feer-traefik?"

echo ""
ok "Gotowe. Aliasy *.ngosystem.pl wymagają działających rekordów DNS (bash cloudflare-dns.sh --init)."
