#!/usr/bin/env bash
# bin/pull-db-from-docker-wizard.sh — Kreator konfiguracji pobierania bazy
# z Dockera na starym VPS-ie.
#
# Pyta o SSH/katalog/kontener zamiast każenia Ci ręcznie edytować sekcję
# KONFIGURACJA w bin/pull-db-from-docker.sh. Zapisuje do
# bin/.pull-db-from-docker.conf (gitignored) i proponuje od razu pobrać bazę.
#
# Użycie:
#   bash bin/pull-db-from-docker-wizard.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
CONFIG_FILE="${SCRIPT_DIR}/.pull-db-from-docker.conf"
PULL_SCRIPT="${SCRIPT_DIR}/pull-db-from-docker.sh"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

command -v ssh >/dev/null || die "Brak polecenia: ssh"

# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

# Logowanie hasłem (nie kluczem): ta sama pula gniazd ControlMaster co
# pull-db-from-docker.sh — hasło raz tutaj, tamten skrypt (uruchomiony niżej
# albo osobno) już go nie zapyta przez 10 minut.
SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

ask() {
    local __var="$1" __prompt="$2" __default="${3:-}" __answer
    if [[ -n "$__default" ]]; then
        read -r -p "$(echo -e "${BOLD}${__prompt}${RESET} [${__default}]: ")" __answer
        __answer="${__answer:-$__default}"
    else
        read -r -p "$(echo -e "${BOLD}${__prompt}${RESET}: ")" __answer
    fi
    printf -v "$__var" '%s' "$__answer"
}

confirm() {
    local __prompt="$1" __answer
    read -r -p "$(echo -e "${BOLD}${__prompt}${RESET} [t/N]: ")" __answer
    [[ "$__answer" =~ ^[tT]$ ]]
}

echo -e "${BOLD}Kreator: pobranie bazy z Dockera (stary VPS) na ten Mac${RESET}"
echo "Odpowiedzi trafią do ${CONFIG_FILE} i będą użyte przez pull-db-from-docker.sh."
echo

# ── 1. SSH ───────────────────────────────────────────────────────────────────
section "Połączenie SSH do serwera z Dockerem"
ask VPS_SSH "Login SSH do serwera produkcyjnego (user@host)" "${VPS_SSH:-}"
while true; do
    info "Sprawdzam połączenie…"
    if ssh -o ConnectTimeout=8 "${SSH_OPTS[@]}" "$VPS_SSH" "echo ok" >/dev/null 2>&1; then
        ok "Połączenie działa"
        break
    fi
    warn "Nie udało się połączyć z ${VPS_SSH}."
    confirm "Spróbować ponownie z inną wartością?" || die "Przerwano — popraw dostęp SSH i uruchom kreator ponownie."
    ask VPS_SSH "Login SSH do serwera produkcyjnego (user@host)" "$VPS_SSH"
done

# ── 2. Katalog aplikacji i kontener ──────────────────────────────────────────
section "Docker"
ask REMOTE_APP_DIR "Katalog repo NA HOŚCIE (nadrzędny wobec docker/, gdzie leży docker-compose.yml)" "${REMOTE_APP_DIR:-/opt/feer-szo}"

# Wykryj kontenery app na hoście, żeby podpowiedzieć nazwę zamiast zgadywać
_containers="$(ssh "${SSH_OPTS[@]}" "$VPS_SSH" "docker ps --format '{{.Names}}'" 2>/dev/null || true)"
if [[ -n "$_containers" ]]; then
    info "Kontenery działające na serwerze:"
    echo "$_containers" | sed 's/^/    /'
fi
ask CONTAINER_NAME "Nazwa kontenera aplikacji" "${CONTAINER_NAME:-feer-app}"

if ! ssh "${SSH_OPTS[@]}" "$VPS_SSH" "docker ps --format '{{.Names}}' | grep -qx '${CONTAINER_NAME}'"; then
    warn "Kontener '${CONTAINER_NAME}' nie jest aktualnie uruchomiony na serwerze (docker ps go nie widzi)."
    confirm "Kontynuować mimo to (np. jeszcze go nie odpaliłeś)?" || die "Przerwano."
fi

ask DB_PATH_IN_CONTAINER "Ścieżka do umowy.db WEWNĄTRZ kontenera" "${DB_PATH_IN_CONTAINER:-/var/www/html/umowy.db}"

# Sanity-check: czy plik istnieje na hoście pod REMOTE_APP_DIR + względna ścieżka
# (bind mount ../:/var/www/html → REMOTE_APP_DIR/umowy.db)
_rel_path="${DB_PATH_IN_CONTAINER#/var/www/html/}"
if ssh "${SSH_OPTS[@]}" "$VPS_SSH" "test -f '${REMOTE_APP_DIR}/${_rel_path}'" 2>/dev/null; then
    ok "Znaleziono ${REMOTE_APP_DIR}/${_rel_path} na hoście — bind mount wygląda poprawnie."
else
    warn "Nie widzę ${REMOTE_APP_DIR}/${_rel_path} na hoście — sprawdź REMOTE_APP_DIR (musi być katalogiem NADRZĘDNYM wobec docker/, nie samym docker/) albo czy kontener faktycznie ma bind mount \`../:/var/www/html\`."
    confirm "Kontynuować mimo to?" || die "Przerwano."
fi

# ── 3. Zapis konfiguracji ────────────────────────────────────────────────────
section "Zapis"
{
    echo "# Wygenerowane przez bin/pull-db-from-docker-wizard.sh — $(date '+%Y-%m-%d %H:%M:%S')"
    echo "VPS_SSH=$(printf '%q' "$VPS_SSH")"
    echo "REMOTE_APP_DIR=$(printf '%q' "$REMOTE_APP_DIR")"
    echo "CONTAINER_NAME=$(printf '%q' "$CONTAINER_NAME")"
    echo "DB_PATH_IN_CONTAINER=$(printf '%q' "$DB_PATH_IN_CONTAINER")"
} > "$CONFIG_FILE"
ok "Zapisano: ${CONFIG_FILE}"

GITIGNORE="${REPO_ROOT}/.gitignore"
if [[ -f "$GITIGNORE" ]] && ! grep -qxF "bin/.pull-db-from-docker.conf" "$GITIGNORE"; then
    echo "bin/.pull-db-from-docker.conf" >> "$GITIGNORE"
    ok "Dopisano bin/.pull-db-from-docker.conf do .gitignore"
fi

# ── 4. Odpalenie ─────────────────────────────────────────────────────────────
section "Podsumowanie"
cat <<EOF
  SSH:                ${VPS_SSH}
  Katalog na hoście:  ${REMOTE_APP_DIR}
  Kontener:           ${CONTAINER_NAME}
  Baza w kontenerze:  ${DB_PATH_IN_CONTAINER}
EOF

echo
if confirm "Pobrać teraz bazę (zapisze jako umowy.production.db)?"; then
    bash "$PULL_SCRIPT"
else
    info "OK — uruchom kiedy chcesz: bash bin/pull-db-from-docker.sh"
fi
