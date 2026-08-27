#!/usr/bin/env bash
# rebuild.sh — git pull + przebuduj obraz + restart aplikacji
#
# Użycie:
#   bash rebuild.sh              # SQLite (domyślnie)
#   bash rebuild.sh --mysql      # z MySQL
#   bash rebuild.sh --ng         # dołącz ng-ui (szo.feer.org.pl/newUI/) — buduje też ng-ui
#   bash rebuild.sh --kursant    # dołącz kursant-ui (ti.feer.org.pl/newUI/) — buduje też kursant-ui
#   bash rebuild.sh --no-pull    # przebuduj bez sync z origin (np. po ręcznej edycji)
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
USE_NG=0
USE_KURSANT=0
SKIP_PULL=0
NO_CACHE=0
for arg in "$@"; do
    [[ "$arg" == "--mysql"    ]] && USE_MYSQL=1
    [[ "$arg" == "--ng"       ]] && USE_NG=1
    [[ "$arg" == "--kursant"  ]] && USE_KURSANT=1
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
[[ $USE_NG      -eq 1 ]] && { COMPOSE="${COMPOSE} -f ${SCRIPT_DIR}/docker-compose.ng.yml"; info "Angular UI: ng-ui (szo/newUI/)"; }
[[ $USE_KURSANT -eq 1 ]] && { COMPOSE="${COMPOSE} -f ${SCRIPT_DIR}/docker-compose.kursant.yml"; info "Kursant UI: kursant-ui (ti/newUI/)"; }

# ── 1. Synchronizacja kodu z origin ─────────────────────────────────────────────
# Zawsze ustawiamy lokalne repo dokładnie na stanie origin (hard reset).
# Wszelkie lokalne zmiany na serwerze są nadpisywane — produkcja = origin.
if [[ $SKIP_PULL -eq 0 ]]; then
    section "1. Aktualizacja kodu (sync z origin)"
    BRANCH="$(git -C "${REPO_DIR}" rev-parse --abbrev-ref HEAD)"
    [[ "$BRANCH" == "HEAD" ]] && BRANCH="main"
    info "Gałąź: ${BRANCH} → origin/${BRANCH}"
    git -C "${REPO_DIR}" fetch origin --prune
    git -C "${REPO_DIR}" reset --hard "origin/${BRANCH}"
    ok "Repo ustawione na origin/${BRANCH} ($(git -C "${REPO_DIR}" log -1 --format='%h %s'))"
else
    warn "Pomijam sync z origin (--no-pull)"
fi

# ── 2. Przebuduj obrazy ────────────────────────────────────────────────────────
# exam-engine buduje się razem z app: kod Javy silnika Equi Exams jest kompilowany
# wewnątrz obrazu (etap `javac`), więc bez rebuildu zmiany w exam-engine/src
# nie trafiłyby na serwer. Gdy katalog się nie zmienił, cache warstw sprawia,
# że ten krok kosztuje ułamek sekundy.
section "2. Budowanie obrazów"
BUILD_SERVICES="app exam-engine"
[[ $USE_NG      -eq 1 ]] && BUILD_SERVICES="${BUILD_SERVICES} ng-ui"
[[ $USE_KURSANT -eq 1 ]] && BUILD_SERVICES="${BUILD_SERVICES} kursant-ui"
if [[ $NO_CACHE -eq 1 ]]; then
    info "Tryb: --no-cache (pełny rebuild bez warstw cache)"
    $COMPOSE build --no-cache --pull ${BUILD_SERVICES}
else
    $COMPOSE build --pull ${BUILD_SERVICES}
fi
ok "Obraz(y) zbudowane: ${BUILD_SERVICES}"

# ── 3. Restart usług ──────────────────────────────────────────────────────────
section "3. Restart"
$COMPOSE up -d --remove-orphans
ok "Stack uruchomiony"

# ── 4. Poczekaj na gotowość i pokaż status ────────────────────────────────────
section "4. Status"
echo ""
sleep 3
$COMPOSE ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"

# ── 5. Certyfikat ─────────────────────────────────────────────────────────────
section "5. Certyfikat"
days=$(docker exec feer-app php -r "
    \$f = '/var/www/html/certs/app.crt';
    if (!file_exists(\$f)) { echo -999; exit; }
    \$p = openssl_x509_parse(file_get_contents(\$f));
    if (!\$p) { echo -999; exit; }
    echo (int)ceil((\$p['validTo_time_t'] - time()) / 86400);
" 2>/dev/null) || days=-999

if [[ "${days}" -le 7 ]]; then
    if [[ "${days}" -eq -999 ]]; then
        warn "Brak certyfikatu — generuję..."
    elif [[ "${days}" -le 0 ]]; then
        warn "Certyfikat wygasł — regeneruję..."
    else
        warn "Certyfikat wygasa za ${days} dni — odnawiam..."
    fi
    docker exec feer-app php /var/www/html/cli/generatorCertyfikatu.php \
        2>&1 | grep -E "\[OK\]|\[INFO\]|\[BLAD\]"
    ok "Certyfikat odnowiony"
else
    ok "Certyfikat OK (${days} dni)"
fi

echo ""
info "Ostatnie logi aplikacji:"
docker logs feer-app --tail=15

echo ""
ok "Rebuild zakończony."
