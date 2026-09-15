#!/usr/bin/env bash
# bin/copy-config-local-to-mydevil.sh — przekopiuj config.local.php ze starego
# VPS-a (Docker) na konto MyDevil, żeby nie wpisywać sekretów (MS_*, LDAP_*,
# EXAM_ENGINE_*, dss_url...) ręcznie od nowa w instalatorze.
#
# Ściąga plik przez ten komputer jako pośrednik (tymczasowo, do katalogu
# scratch), wysyła na MyDevil, po czym usuwa lokalną tymczasową kopię.
# Sekrety NIE są nigdzie wypisywane na ekran.
#
# Czyta VPS_SSH/REMOTE_APP_DIR z bin/.pull-db-from-docker.conf oraz
# MYDEVIL_SSH/REMOTE_REPO_DIR z bin/.deploy-mydevil.conf — oba pliki powinny
# już istnieć z wcześniejszych kreatorów.
#
# Użycie:
#   bash bin/copy-config-local-to-mydevil.sh              # kopiuj
#   bash bin/copy-config-local-to-mydevil.sh --dry-run     # tylko pokaż co by zrobił
#   bash bin/copy-config-local-to-mydevil.sh --force        # nadpisz, jeśli na MyDevil już istnieje
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PULL_CONF="${SCRIPT_DIR}/.pull-db-from-docker.conf"
DEPLOY_CONF="${SCRIPT_DIR}/.deploy-mydevil.conf"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; RESET='\033[0m'
info() { echo -e "${CYAN}▸ $*${RESET}"; }
ok()   { echo -e "${GREEN}✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()  { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

[[ -f "$PULL_CONF" ]]   || die "Brak ${PULL_CONF} — uruchom najpierw bin/pull-db-from-docker-wizard.sh"
[[ -f "$DEPLOY_CONF" ]] || die "Brak ${DEPLOY_CONF} — uruchom najpierw bin/deploy-mydevil-wizard.sh"
# shellcheck source=/dev/null
source "$PULL_CONF"
# shellcheck source=/dev/null
source "$DEPLOY_CONF"

[[ -n "${VPS_SSH:-}" ]]        || die "VPS_SSH nie ustawione w ${PULL_CONF}"
[[ -n "${REMOTE_APP_DIR:-}" ]] || die "REMOTE_APP_DIR nie ustawione w ${PULL_CONF}"
[[ -n "${MYDEVIL_SSH:-}" ]]        || die "MYDEVIL_SSH nie ustawione w ${DEPLOY_CONF}"
[[ -n "${REMOTE_REPO_DIR:-}" ]]    || die "REMOTE_REPO_DIR nie ustawione w ${DEPLOY_CONF}"

DRY=false; FORCE=false
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=true ;;
        --force)   FORCE=true ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Nieznana opcja: $arg" ;;
    esac
done

SCRATCH_DIR="$(mktemp -d)"
trap 'rm -rf "$SCRATCH_DIR"' EXIT
LOCAL_TMP="${SCRATCH_DIR}/config.local.php"

SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

REMOTE_SRC="${VPS_SSH}:${REMOTE_APP_DIR}/config.local.php"
REMOTE_DST="${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/config.local.php"

if $DRY; then
    warn "[dry] scp ${REMOTE_SRC} → (lokalny plik tymczasowy, nie zapisany do repo)"
    warn "[dry] scp (lokalny plik tymczasowy) → ${REMOTE_DST}"
    exit 0
fi

if ! $FORCE; then
    if ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "test -f ${REMOTE_REPO_DIR}/config.local.php"; then
        die "Na MyDevil już istnieje ${REMOTE_REPO_DIR}/config.local.php — użyj --force, żeby nadpisać."
    fi
fi

info "Ściągam config.local.php ze starego VPS-a (${VPS_SSH}) do pliku tymczasowego…"
scp "${SSH_OPTS[@]}" "$REMOTE_SRC" "$LOCAL_TMP" || die "Nie udało się pobrać config.local.php ze starego VPS-a."

info "Wysyłam na MyDevil (${MYDEVIL_SSH})…"
scp "${SSH_OPTS[@]}" "$LOCAL_TMP" "$REMOTE_DST" || die "Nie udało się wysłać config.local.php na MyDevil."

ok "Przekopiowano config.local.php ze starego VPS-a na MyDevil (bez zmian w treści)."
warn "Sekrety wskazujące na usługi zostające na starym VPS (exam-engine, DSS, LDAP, ownCloud) muszą być pod PUBLICZNYMI adresami — jeśli w oryginale były to adresy wewnętrzne Dockera (np. nazwa kontenera zamiast domeny), popraw je ręcznie w ${REMOTE_REPO_DIR}/config.local.php na koncie MyDevil."
