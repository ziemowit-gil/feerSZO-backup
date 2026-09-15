#!/usr/bin/env bash
# bin/sync-ezd-files-mydevil.sh — przenieś TYLKO pliki EZD (uploads/ezd/,
# organizowane per sprawa_id — patrz includes/ezd.php EZD_UPLOAD_SUBDIR)
# ze starego VPS-a na MyDevil, bez ruszania reszty uploads/certs/bazy.
#
# Powód osobnego skryptu: bin/deploy-mydevil.sh --sync-data synchronizuje
# CAŁE ./uploads (+certs+baza) z TEGO komputera — a to wymaga, żeby lokalny
# checkout miał już aktualne pliki. Dla samego EZD (świeższe niż ostatni pełny
# sync) prościej i szybciej przeciągnąć tylko ten jeden podkatalog bezpośrednio
# ze starego VPS-a.
#
# Ściąga do lokalnego katalogu tymczasowego (scratch), potem wysyła na
# MyDevil, po czym czyści lokalną kopię pośrednią.
#
# Czyta VPS_SSH/REMOTE_APP_DIR z bin/.pull-db-from-docker.conf (stary VPS) i
# MYDEVIL_SSH/REMOTE_REPO_DIR z bin/.deploy-mydevil.conf (MyDevil).
#
# Użycie:
#   bash bin/sync-ezd-files-mydevil.sh              # sync
#   bash bin/sync-ezd-files-mydevil.sh --dry-run     # podgląd (rsync --dry-run)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PULL_CONF="${SCRIPT_DIR}/.pull-db-from-docker.conf"
DEPLOY_CONF="${SCRIPT_DIR}/.deploy-mydevil.conf"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

[[ -f "$PULL_CONF" ]]   || die "Brak ${PULL_CONF} — uruchom najpierw bin/pull-db-from-docker-wizard.sh"
[[ -f "$DEPLOY_CONF" ]] || die "Brak ${DEPLOY_CONF} — uruchom najpierw bin/deploy-mydevil-wizard.sh"
# shellcheck source=/dev/null
source "$PULL_CONF"
# shellcheck source=/dev/null
source "$DEPLOY_CONF"
[[ -n "${VPS_SSH:-}" ]]         || die "VPS_SSH nie ustawione w ${PULL_CONF}"
[[ -n "${REMOTE_APP_DIR:-}" ]]  || die "REMOTE_APP_DIR nie ustawione w ${PULL_CONF}"
[[ -n "${MYDEVIL_SSH:-}" ]]     || die "MYDEVIL_SSH nie ustawione w ${DEPLOY_CONF}"
[[ -n "${REMOTE_REPO_DIR:-}" ]] || die "REMOTE_REPO_DIR nie ustawione w ${DEPLOY_CONF}"

DRY=""
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY="--dry-run" ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Nieznana opcja: $arg" ;;
    esac
done

SCRATCH_DIR="$(mktemp -d)"
trap 'rm -rf "$SCRATCH_DIR"' EXIT

SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

section "Ściągam uploads/ezd/ ze starego VPS-a (${VPS_SSH})"
mkdir -p "${SCRATCH_DIR}/ezd"
rsync -avz $DRY -e "ssh ${SSH_OPTS[*]}" \
    "${VPS_SSH}:${REMOTE_APP_DIR}/uploads/ezd/" "${SCRATCH_DIR}/ezd/" \
    || die "Nie udało się ściągnąć uploads/ezd/ ze starego VPS-a."
ok "Ściągnięto do tymczasowego katalogu."

section "Wysyłam na MyDevil (${MYDEVIL_SSH})"
ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "mkdir -p ${REMOTE_REPO_DIR}/uploads/ezd"
rsync -avz $DRY -e "ssh ${SSH_OPTS[*]}" \
    "${SCRATCH_DIR}/ezd/" "${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/uploads/ezd/" \
    || die "Nie udało się wysłać uploads/ezd/ na MyDevil."

if [[ -n "$DRY" ]]; then
    ok "Podgląd zakończony (--dry-run) — nic nie zostało nadpisane."
else
    ok "Gotowe — pliki EZD zsynchronizowane."
fi
