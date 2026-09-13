#!/usr/bin/env bash
# bin/migrate-to-mydevil.sh — CAŁY pipeline migracji w jednym poleceniu:
#   1. bin/pull-db-from-docker.sh   — ściągnij aktualną bazę ze starego VPS-a
#   2. bin/deploy-mydevil.sh        — kod + composer + domeny + SSL + cron
#   3. bin/deploy-mydevil.sh --sync-data --db-file=<świeżo ściągnięta baza>
#
# Jeśli konfiguracje (bin/.pull-db-from-docker.conf, bin/.deploy-mydevil.conf)
# jeszcze nie istnieją, ten skrypt najpierw odpala odpowiednie kreatory.
#
# NIE automatyzuje (i tak wymaga Ciebie): dokończenia importu w przeglądarce
# (https://TWOJA_DOMENA/install.php), uzupełnienia config.local.php sekretami
# na koncie MyDevil, przełączenia DNS. Te trzy kroki są celowo ręczne —
# patrz podsumowanie na końcu.
#
# Użycie:
#   bash bin/migrate-to-mydevil.sh                 # pełny pipeline
#   bash bin/migrate-to-mydevil.sh --dry-run        # podgląd kroku deployu (bez zmian)
#   bash bin/migrate-to-mydevil.sh --skip-pull       # użyj już ściągniętej umowy.production.db
#   bash bin/migrate-to-mydevil.sh --skip-ssl        # bez wydawania certów SSL
#   bash bin/migrate-to-mydevil.sh --yes             # bez pytań potwierdzających
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}▓▓▓ $* ▓▓▓━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

DRY=false; SKIP_PULL=false; SKIP_SSL=false; ASSUME_YES=false
for arg in "$@"; do
    case "$arg" in
        --dry-run)   DRY=true ;;
        --skip-pull) SKIP_PULL=true ;;
        --skip-ssl)  SKIP_SSL=true ;;
        --yes)       ASSUME_YES=true ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Nieznana opcja: $arg" >&2; exit 1 ;;
    esac
done

PULL_CONF="${SCRIPT_DIR}/.pull-db-from-docker.conf"
DEPLOY_CONF="${SCRIPT_DIR}/.deploy-mydevil.conf"
PROD_DB="${REPO_ROOT}/umowy.production.db"

echo -e "${BOLD}feerSZO → MyDevil — pełen pipeline migracji${RESET}"

# ── Krok 0: kreatory, jeśli brakuje konfiguracji ────────────────────────────
if ! $SKIP_PULL && [[ ! -f "$PULL_CONF" ]]; then
    section "Brak konfiguracji pobierania bazy — uruchamiam kreator"
    bash "${SCRIPT_DIR}/pull-db-from-docker-wizard.sh" || die "Kreator pull-db-from-docker przerwany."
    # Kreator sam pyta, czy od razu pobrać — jeśli tak zrobił, dalszy "Krok 1"
    # poniżej i tak bezpiecznie pobierze świeżą kopię jeszcze raz.
fi

if [[ ! -f "$DEPLOY_CONF" ]]; then
    section "Brak konfiguracji wdrożenia MyDevil — uruchamiam kreator"
    info "Kreator zapyta też o dry-run/pełny deploy — na potrzeby tego pipeline'u odpowiedz przecząco (N), zrobi to reszta tego skryptu."
    bash "${SCRIPT_DIR}/deploy-mydevil-wizard.sh" || die "Kreator deploy-mydevil przerwany."
fi

# ── Krok 1: pobierz świeżą bazę ze starego VPS-a ────────────────────────────
if ! $SKIP_PULL; then
    section "Krok 1/3 — pobieranie bazy z Dockera (stary VPS)"
    bash "${SCRIPT_DIR}/pull-db-from-docker.sh" --out="$PROD_DB"
else
    section "Krok 1/3 — pominięty (--skip-pull)"
    [[ -f "$PROD_DB" ]] || die "Brak ${PROD_DB} — usuń --skip-pull albo najpierw uruchom bin/pull-db-from-docker.sh --out=umowy.production.db"
    info "Używam istniejącego: ${PROD_DB}"
fi

# ── Krok 2: kod + composer + domeny + SSL + cron ────────────────────────────
section "Krok 2/3 — wdrożenie kodu na MyDevil"
_deploy_args=()
$DRY      && _deploy_args+=(--dry-run)
$SKIP_SSL && _deploy_args+=(--skip-ssl)
$ASSUME_YES && _deploy_args+=(--yes)
bash "${SCRIPT_DIR}/deploy-mydevil.sh" "${_deploy_args[@]}"

# ── Krok 3: wyślij dane (bazę jako umowy.import.db + uploads + certy) ───────
section "Krok 3/3 — przesyłanie danych"
_sync_args=(--sync-data --db-file="$PROD_DB")
$DRY        && _sync_args+=(--dry-run)
$SKIP_SSL   && _sync_args+=(--skip-ssl)
$ASSUME_YES && _sync_args+=(--yes)
bash "${SCRIPT_DIR}/deploy-mydevil.sh" "${_sync_args[@]}"

# ── Podsumowanie ─────────────────────────────────────────────────────────────
section "Gotowe — zostały 3 ręczne kroki"
# shellcheck source=/dev/null
[[ -f "$DEPLOY_CONF" ]] && source "$DEPLOY_CONF"
cat <<EOF
  1. Wejdź na https://${PRIMARY_DOMAIN:-TWOJA_DOMENA}/install.php i dokończ
     import (krok "Baza danych" → "Plik już na serwerze").
  2. Uzupełnij config.local.php NA KONCIE MyDevil sekretami (MS_*,
     EXAM_ENGINE_URL/TOKEN, dss_url, LDAP_*) wskazującymi na usługi
     zostające na starym VPS.
  3. Dopiero po weryfikacji pod tymczasowym testem — przełącz DNS na IP MyDevil.
EOF
ok "Pipeline zakończony."
