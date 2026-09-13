#!/usr/bin/env bash
# bin/pull-db-from-docker.sh — pobierz aktualną bazę SQLite z kontenera Docker
# na serwerze produkcyjnym i zapisz ją LOKALNIE na tym Macu, w folderze projektu.
#
# Robi bezpieczny, atomowy snapshot przez `VACUUM INTO` (PHP/PDO w kontenerze
# feer-app) — bezpieczne przy równoczesnych zapisach na produkcji, w
# przeciwieństwie do zwykłego `cp`/`scp` samego pliku .db w trakcie zapisu.
#
# Zakłada bind mount `../:/var/www/html` (tak jak w docker/docker-compose.yml)
# — dzięki temu snapshot zapisany przez kontener w /var/www/html/ jest od razu
# widoczny na hoście pod REMOTE_APP_DIR, bez potrzeby `docker cp`.
#
# Użycie:
#   bash bin/pull-db-from-docker-wizard.sh            # najprościej: kreator pyta o wszystko
#   bash bin/pull-db-from-docker.sh                   # zapisze jako ./umowy.production.db
#   bash bin/pull-db-from-docker.sh --out=sciezka.db  # zapisz gdzie indziej
#   bash bin/pull-db-from-docker.sh --overwrite       # NADPISZE lokalną (dev) ./umowy.db
#
# Uruchom najpierw bin/pull-db-from-docker-wizard.sh — zapyta o SSH/katalog/
# kontener i zapisze do bin/.pull-db-from-docker.conf, skąd ten skrypt
# odczyta je automatycznie. Ręczna edycja sekcji KONFIGURACJA nadal działa —
# wartości z pliku .conf mają pierwszeństwo.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/.pull-db-from-docker.conf"
# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

# ─── KONFIGURACJA (wartości domyślne — nadpisz przez .pull-db-from-docker.conf) ─
VPS_SSH="${VPS_SSH:-root@twoj-vps.feer.org.pl}"        # SSH do serwera produkcyjnego (Docker)
REMOTE_APP_DIR="${REMOTE_APP_DIR:-/opt/feer-szo}"       # katalog repo na hoście (docker/setup.sh → INSTALL_DIR)
CONTAINER_NAME="${CONTAINER_NAME:-feer-app}"
DB_PATH_IN_CONTAINER="${DB_PATH_IN_CONTAINER:-/var/www/html/umowy.db}"
# ──────────────────────────────────────────────────────────────────────────────

OUT_FILE=""
OVERWRITE=false
for arg in "$@"; do
    case "$arg" in
        --out=*)     OUT_FILE="${arg#--out=}" ;;
        --overwrite) OVERWRITE=true ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Nieznana opcja: $arg" >&2; exit 1 ;;
    esac
done

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; RESET='\033[0m'
info() { echo -e "${CYAN}▸ $*${RESET}"; }
ok()   { echo -e "${GREEN}✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()  { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

[[ "$VPS_SSH" == "root@twoj-vps.feer.org.pl" ]] && \
    die "Uzupełnij VPS_SSH (i REMOTE_APP_DIR, jeśli inny) na górze skryptu przed uruchomieniem."

command -v ssh >/dev/null || die "Brak polecenia: ssh"
command -v scp >/dev/null || die "Brak polecenia: scp"

REPO_ROOT="$(dirname "$SCRIPT_DIR")"

# Logowanie hasłem (nie kluczem): ControlMaster trzyma jedno połączenie przez
# 10 minut, więc hasło do VPS-a podajesz raz, nie przy każdym ssh/scp.
SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

if $OVERWRITE; then
    OUT_FILE="${OUT_FILE:-${REPO_ROOT}/umowy.db}"
    warn "Tryb --overwrite: nadpiszę LOKALNĄ (deweloperską) bazę: ${OUT_FILE}"
else
    OUT_FILE="${OUT_FILE:-${REPO_ROOT}/umowy.production.db}"
fi

info "Łączę się z ${VPS_SSH} (może zapytać o hasło — kolejne kroki go już nie zapytają przez 10 min)…"
ssh -o ConnectTimeout=8 "${SSH_OPTS[@]}" "$VPS_SSH" "echo ok" >/dev/null 2>&1 \
    || die "Nie mogę połączyć się przez SSH z ${VPS_SSH}."

STAMP="$(date +%Y%m%d_%H%M%S)"
SNAP_NAME="umowy_pull_${STAMP}.db"
REMOTE_SNAP_HOST_PATH="${REMOTE_APP_DIR}/${SNAP_NAME}"

info "Tworzę atomowy snapshot bazy (docker exec ${CONTAINER_NAME}, VACUUM INTO)…"
ssh "${SSH_OPTS[@]}" "$VPS_SSH" "docker exec ${CONTAINER_NAME} php -r '
\$p = new PDO(\"sqlite:${DB_PATH_IN_CONTAINER}\");
\$p->exec(\"VACUUM INTO \" . \$p->quote(\"/var/www/html/${SNAP_NAME}\"));
'" || die "Nie udało się utworzyć snapshotu w kontenerze ${CONTAINER_NAME}."

if ! ssh "${SSH_OPTS[@]}" "$VPS_SSH" "test -f '${REMOTE_SNAP_HOST_PATH}'"; then
    die "Snapshot nie pojawił się pod ${REMOTE_SNAP_HOST_PATH} na hoście — sprawdź, czy ${CONTAINER_NAME} faktycznie ma bind mount \`../:/var/www/html\` (REMOTE_APP_DIR musi wskazywać na katalog NADRZĘDNY wobec docker/, nie na docker/ samo w sobie)."
fi
ok "Snapshot: ${REMOTE_SNAP_HOST_PATH}"

info "Pobieram na ten komputer → ${OUT_FILE}"
scp "${SSH_OPTS[@]}" "${VPS_SSH}:${REMOTE_SNAP_HOST_PATH}" "$OUT_FILE"

info "Sprzątam snapshot na serwerze…"
# Plik utworzył proces w kontenerze (inny UID niż login SSH) — usuwamy tym
# samym kontenerem, żeby uniknąć "Permission denied" na hoście. Nieusunięcie
# nie jest krytyczne (dane już bezpiecznie ściągnięte) — tylko ostrzegamy.
ssh "${SSH_OPTS[@]}" "$VPS_SSH" "docker exec ${CONTAINER_NAME} rm -f '/var/www/html/${SNAP_NAME}'" \
    || warn "Nie udało się usunąć snapshotu ze serwera (${REMOTE_SNAP_HOST_PATH}) — usuń ręcznie, dane masz już lokalnie."

ok "Gotowe: ${OUT_FILE} ($(du -h "$OUT_FILE" | cut -f1))"
info "Żeby wysłać to na MyDevil (jako umowy.import.db do zaimportowania w install.php):"
echo "    bash bin/deploy-mydevil.sh --sync-data --db-file=\"${OUT_FILE}\""
