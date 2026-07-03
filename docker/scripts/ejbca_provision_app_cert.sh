#!/usr/bin/env bash
# ejbca_provision_app_cert.sh — jednorazowy bootstrap certyfikatu aplikacji dla EJBCA
#
# Uruchom z katalogu docker/ na serwerze (obraz feer-app musi już mieć
# rozszerzenie PHP soap — jeśli nie, najpierw: bash rebuild.sh --no-cache):
#   cd /opt/feer-szo/docker && bash scripts/ejbca_provision_app_cert.sh <ścieżka-do-p12-admina> <hasło>
#
# Co robi:
#   1. Kopiuje TYMCZASOWO podany .p12 administratora EJBCA (np. superadmin.p12)
#      do drzewa repo, żeby był widoczny wewnątrz kontenera feer-app
#      (bind mount ../:/var/www/html)
#   2. Uruchamia cli/ejbca_provision_app_cert.php wewnątrz kontenera —
#      tworzy dedykowany end entity dla aplikacji przez SOAP, wystawia mu
#      certyfikat, dodaje do roli administracyjnej, zapisuje certs/ejbca_client.pem
#      i certs/ejbca_ca.pem, włącza integrację (ejbca_enabled=1)
#   3. Usuwa tymczasową kopię .p12 administratora z drzewa repo i z kontenera
#
# Bezpieczne do ponownego uruchomienia — nadpisuje istniejący certyfikat aplikacji.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
APP_CONTAINER="feer-app"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info() { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()   { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
die()  { echo -e "${RED}  ✖ $*${RESET}" >&2; exit 1; }

ADMIN_P12="${1:-}"
ADMIN_PASS="${2:-}"
[[ -n "$ADMIN_P12" && -n "$ADMIN_PASS" ]] || die "Użycie: bash scripts/ejbca_provision_app_cert.sh <ścieżka-do-p12-admina> <hasło>"
[[ -f "$ADMIN_P12" ]] || die "Nie znaleziono pliku: ${ADMIN_P12}"

docker inspect "${APP_CONTAINER}" &>/dev/null \
    || die "Kontener ${APP_CONTAINER} nie działa."
[[ "$(docker inspect "${APP_CONTAINER}" --format '{{.State.Status}}')" == "running" ]] \
    || die "Kontener ${APP_CONTAINER} nie działa."

info "Sprawdzam rozszerzenie PHP soap w kontenerze…"
if ! docker exec "${APP_CONTAINER}" php -m 2>/dev/null | grep -qi '^soap$'; then
    die "Rozszerzenie PHP soap nie jest zainstalowane w obrazie. Uruchom najpierw: bash rebuild.sh --no-cache"
fi
ok "Rozszerzenie soap dostępne."

TMP_NAME="ejbca_bootstrap_admin_$$.p12"
TMP_HOST_PATH="${REPO_DIR}/${TMP_NAME}"
TMP_CONTAINER_PATH="/var/www/html/${TMP_NAME}"

cleanup() { rm -f "${TMP_HOST_PATH}"; }
trap cleanup EXIT

info "Kopiuję tymczasowo certyfikat administratora do drzewa repo…"
cp "${ADMIN_P12}" "${TMP_HOST_PATH}"
chmod 600 "${TMP_HOST_PATH}"

info "Uruchamiam bootstrap (docker exec ${APP_CONTAINER} php cli/ejbca_provision_app_cert.php)…"
if docker exec "${APP_CONTAINER}" php cli/ejbca_provision_app_cert.php \
    --admin-p12="${TMP_CONTAINER_PATH}" --admin-pass="${ADMIN_PASS}"; then
    BOOTSTRAP_OK=1
else
    BOOTSTRAP_OK=0
fi

cleanup
trap - EXIT

[[ "$BOOTSTRAP_OK" -eq 1 ]] || die "Bootstrap nie powiódł się — patrz komunikaty wyżej."

echo ""
ok "Gotowe. Testuj: panel admina → Certyfikaty X.509 (admin/x509_login.php) → źródło 'Wewnętrzny CA (EJBCA)'."
