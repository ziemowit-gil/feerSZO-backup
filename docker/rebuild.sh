#!/usr/bin/env bash
# rebuild.sh — git pull + przebuduj obraz + restart aplikacji
#
# Użycie:
#   bash rebuild.sh              # SQLite (domyślnie)
#   bash rebuild.sh --mysql      # z MySQL
#   bash rebuild.sh --no-pull    # przebuduj bez git pull (np. po ręcznej edycji)
#   bash rebuild.sh --no-cache   # wymusza pełny rebuild bez cache (po zmianie Dockerfile)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${SCRIPT_DIR}/.env.prod"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

# ── Argumenty ─────────────────────────────────────────────────────────────────
USE_MYSQL=0
SKIP_PULL=0
NO_CACHE=0
for arg in "$@"; do
    [[ "$arg" == "--mysql"    ]] && USE_MYSQL=1
    [[ "$arg" == "--no-pull"  ]] && SKIP_PULL=1
    [[ "$arg" == "--no-cache" ]] && NO_CACHE=1
done

# ── Walidacja ─────────────────────────────────────────────────────────────────
[[ -f "$ENV_FILE" ]] || die ".env.prod nie istnieje: ${ENV_FILE}\nSkopiuj docker/.env.prod.example → docker/.env.prod i wypełnij."

# ── Budowa komendy docker compose ─────────────────────────────────────────────
COMPOSE="docker compose \
  -f ${SCRIPT_DIR}/docker-compose.yml \
  -f ${SCRIPT_DIR}/docker-compose.prod.yml \
  --env-file ${ENV_FILE}"

if [[ $USE_MYSQL -eq 1 ]]; then
    COMPOSE="${COMPOSE} -f ${SCRIPT_DIR}/docker-compose.mysql.yml"
    info "Tryb: MySQL"
else
    info "Tryb: SQLite (domyślny)"
fi

# ── 1. Git pull ────────────────────────────────────────────────────────────────
if [[ $SKIP_PULL -eq 0 ]]; then
    section "1. Aktualizacja kodu"
    git -C "${REPO_DIR}" pull --ff-only
    ok "Repo zaktualizowane ($(git -C "${REPO_DIR}" log -1 --format='%h %s'))"
else
    warn "Pomijam git pull (--no-pull)"
fi

# ── 2. Przebuduj obraz app ─────────────────────────────────────────────────────
section "2. Budowanie obrazu"
if [[ $NO_CACHE -eq 1 ]]; then
    info "Tryb: --no-cache (pełny rebuild bez warstw cache)"
    $COMPOSE build --no-cache --pull app
else
    $COMPOSE build --pull app
fi
ok "Obraz zbudowany"

# ── 3. Restart usług ──────────────────────────────────────────────────────────
section "3. Restart"
$COMPOSE up -d --remove-orphans
ok "Stack uruchomiony"

# ── 4. Poczekaj na gotowość i pokaż status ────────────────────────────────────
section "4. Status"
echo ""
sleep 3
$COMPOSE ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"

echo ""
info "Ostatnie logi aplikacji:"
docker logs feer-app --tail=25

echo ""
ok "Rebuild zakończony."
