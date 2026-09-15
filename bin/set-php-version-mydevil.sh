#!/usr/bin/env bash
# bin/set-php-version-mydevil.sh — ustaw wersję PHP dla domeny na MyDevil.
#
# Domyślna wersja PHP na koncie MyDevil to 8.3, ale composer.lock tego
# projektu (przez lokalny "platform.php": "8.5.6" w composer.json) dobrał
# pakiety (symfony/http-client, symfony/options-resolver, doctrine/instantiator)
# wymagające PHP >=8.4.1 — stąd fatal error "Composer detected issues in your
# platform" po wdrożeniu. To ustawia wersję PHP dla żądań WWW domeny na 8.4
# (max dostępna na MyDevil), zgodnie z:
# https://pomoc.mydevil.net/PHP/ (sekcja "Wersja PHP").
#
# Działa przez plik ~/domains/DOMENA/.htaccess (POZIOM KATALOGU DOMENY, czyli
# RODZIC public_html — nie ten .htaccess z repo, który leży wewnątrz
# public_html/feerSZO). Dopisuje/podmienia tylko linię "AddType
# application/x-httpd-phpXX .php", nie rusza reszty pliku.
#
# NIE zmienia wersji PHP CLI (używanej np. przez cron/composer) — do tego
# na koncie trzeba osobno: ln -s /usr/local/bin/php84 ~/bin/php (patrz
# dokumentacja MyDevil, sekcja "Zmiana wersji PHP dla CLI").
#
# Użycie:
#   bash bin/set-php-version-mydevil.sh              # ustaw 8.4 dla PRIMARY_DOMAIN z .deploy-mydevil.conf
#   bash bin/set-php-version-mydevil.sh --version=83  # inna wersja (np. rollback)
#   bash bin/set-php-version-mydevil.sh --domain=crm.feer.org.pl --version=84
#   bash bin/set-php-version-mydevil.sh --dry-run
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/.deploy-mydevil.conf"
# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

VERSION="84"
DOMAIN="${PRIMARY_DOMAIN:-}"
DRY=false
for arg in "$@"; do
    case "$arg" in
        --version=*) VERSION="${arg#--version=}" ;;
        --domain=*)  DOMAIN="${arg#--domain=}" ;;
        --dry-run)   DRY=true ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Nieznana opcja: $arg" ;;
    esac
done

[[ -n "${MYDEVIL_SSH:-}" ]] || die "Brak MYDEVIL_SSH — uzupełnij ${CONFIG_FILE} albo uruchom bin/deploy-mydevil-wizard.sh"
[[ -n "$DOMAIN" ]] || die "Brak domeny — podaj --domain=... albo ustaw PRIMARY_DOMAIN w ${CONFIG_FILE}"
[[ "$VERSION" =~ ^(56|70|71|72|73|74|80|81|82|83|84)$ ]] || die "Nieznana wersja PHP: ${VERSION} (dostępne wg pomoc.mydevil.net/PHP/: 56 70 71 72 73 74 80 81 82 83 84)"

SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

MARKER="# feerszo-managed-by-set-php-version-mydevil.sh"
HTACCESS_PATH="domains/${DOMAIN}/.htaccess"
DIRECTIVE="AddType application/x-httpd-php${VERSION} .php"

info "Domena: ${DOMAIN} → PHP ${VERSION:0:1}.${VERSION:1} (plik: ~/${HTACCESS_PATH})"

# Usuń poprzednią linię AddType application/x-httpd-phpXX .php (dowolna wersja)
# dodaną przez ten skrypt, dopisz nową — reszta pliku (jeśli cokolwiek innego
# w nim jest) zostaje nietknięta.
REMOTE_CMD="mkdir -p domains/${DOMAIN} && \
touch ${HTACCESS_PATH} && \
grep -vE '^AddType application/x-httpd-php[0-9]+ \\.php\$|^${MARKER}\$' ${HTACCESS_PATH} > ${HTACCESS_PATH}.tmp || true; \
printf '%s\n%s\n' '${MARKER}' '${DIRECTIVE}' >> ${HTACCESS_PATH}.tmp && \
mv ${HTACCESS_PATH}.tmp ${HTACCESS_PATH} && \
cat ${HTACCESS_PATH}"

if $DRY; then
    warn "[dry] ssh ${MYDEVIL_SSH} \"${REMOTE_CMD}\""
else
    info "Łączę (może zapytać o hasło)…"
    ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "$REMOTE_CMD"
    ok "Zapisano. Odśwież stronę — powinna zniknąć: 'Composer detected issues in your platform'."
    warn "To zmienia tylko PHP dla ruchu WWW. Jeśli composer/cron też mają błędy platformy, na koncie: ln -s /usr/local/bin/php${VERSION} ~/bin/php (patrz nagłówek skryptu)."
fi
