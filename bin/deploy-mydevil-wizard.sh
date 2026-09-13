#!/usr/bin/env bash
# bin/deploy-mydevil-wizard.sh — Kreator konfiguracji wdrożenia na MyDevil.
#
# Interaktywnie pyta o dane potrzebne bin/deploy-mydevil.sh (SSH, domeny, IP,
# repo) zamiast każenia Ci ręcznie edytować sekcję KONFIGURACJA w tamtym
# skrypcie. Zapisuje odpowiedzi do bin/.deploy-mydevil.conf (gitignored, bo to
# konfiguracja Twojego konkretnego środowiska, nie sekrety aplikacji) i na
# koniec proponuje od razu odpalić deploy (najpierw na sucho).
#
# Użycie:
#   bash bin/deploy-mydevil-wizard.sh
#
# Uruchom ponownie w każdej chwili, żeby zmienić odpowiedzi — istniejący plik
# .deploy-mydevil.conf jest używany jako domyślne wartości pytań.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
CONFIG_FILE="${SCRIPT_DIR}/.deploy-mydevil.conf"
DEPLOY_SCRIPT="${SCRIPT_DIR}/deploy-mydevil.sh"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

command -v ssh >/dev/null || die "Brak polecenia: ssh"

# Wczytaj poprzednie odpowiedzi jako domyślne (jeśli kreator był już uruchamiany)
# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

# Logowanie hasłem (nie kluczem): ta sama pula gniazd ControlMaster co
# deploy-mydevil.sh — hasło raz tutaj, deploy-mydevil.sh (uruchomiony niżej
# albo osobno) już go nie zapyta przez 10 minut.
SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

# ── helper: pytanie z domyślną wartością ────────────────────────────────────
ask() {
    local __var="$1" __prompt="$2" __default="${3:-}"
    local __answer
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

echo -e "${BOLD}Kreator wdrożenia feerSZO na MyDevil${RESET}"
echo "Odpowiedzi trafią do ${CONFIG_FILE} i będą użyte przez deploy-mydevil.sh."
echo

# ── 1. SSH ───────────────────────────────────────────────────────────────────
section "Połączenie SSH"
ask MYDEVIL_SSH "Login SSH do konta MyDevil (user@serwerX.mydevil.net)" "${MYDEVIL_SSH:-}"
while true; do
    info "Sprawdzam połączenie (może zapytać o hasło)…"
    if ssh -o ConnectTimeout=8 "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "echo ok" >/dev/null 2>&1; then
        ok "Połączenie działa"
        break
    fi
    warn "Nie udało się połączyć z ${MYDEVIL_SSH} (login/hasło poprawne?)."
    confirm "Spróbować ponownie z inną wartością?" || die "Przerwano — popraw dostęp SSH i uruchom kreator ponownie."
    ask MYDEVIL_SSH "Login SSH do konta MyDevil (user@serwerX.mydevil.net)" "$MYDEVIL_SSH"
done

# ── 2. Repozytorium na koncie ────────────────────────────────────────────────
section "Kod"
ask REMOTE_REPO_DIR "Katalog na koncie (względem \$HOME), gdzie ma trafić kod" "${REMOTE_REPO_DIR:-feerSZO}"

_local_origin="$(git -C "$REPO_ROOT" remote get-url origin 2>/dev/null || true)"
ask GIT_REMOTE_URL "URL repozytorium git do sklonowania" "${GIT_REMOTE_URL:-${_local_origin:-git@codeberg.org:ziemowitgil/feerSZO.git}}"

# Spróbuj wykryć wersję PHP na koncie, żeby zaproponować composerXX
_detected_composer=""
_php_ver="$(ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "php -r 'echo PHP_MAJOR_VERSION.PHP_MINOR_VERSION;'" 2>/dev/null || true)"
if [[ "$_php_ver" =~ ^[0-9]{2,3}$ ]]; then
    _detected_composer="composer${_php_ver}"
    info "Wykryto PHP ${_php_ver:0:1}.${_php_ver:1} na koncie → proponuję ${_detected_composer}"
fi
ask COMPOSER_BIN "Polecenie composera na koncie" "${COMPOSER_BIN:-${_detected_composer:-composer84}}"

# ── 3. Domeny ────────────────────────────────────────────────────────────────
section "Domeny"
ask PRIMARY_DOMAIN "Domena główna (tu faktycznie stoi kod)" "${PRIMARY_DOMAIN:-szo.feer.org.pl}"

info "Teraz domeny-aliasy (pointer → domena główna), np. crm.feer.org.pl."
info "Zostaw puste i wciśnij Enter, żeby zakończyć dodawanie."
_existing_aliases=("${ALIAS_DOMAINS[@]:-}")
NEW_ALIAS_DOMAINS=()
if [[ ${#_existing_aliases[@]} -gt 0 && -n "${_existing_aliases[0]}" ]]; then
    info "Obecnie skonfigurowane: ${_existing_aliases[*]}"
    confirm "Zachować tę listę bez zmian?" && NEW_ALIAS_DOMAINS=("${_existing_aliases[@]}")
fi
if [[ ${#NEW_ALIAS_DOMAINS[@]} -eq 0 ]]; then
    while true; do
        ask _alias "Kolejna domena-alias (Enter = koniec)" ""
        [[ -z "$_alias" ]] && break
        NEW_ALIAS_DOMAINS+=("$_alias")
    done
fi
ALIAS_DOMAINS=("${NEW_ALIAS_DOMAINS[@]}")
ok "Aliasy: ${ALIAS_DOMAINS[*]:-(brak)}"

# ── 4. SSL / IP konta ────────────────────────────────────────────────────────
section "SSL"
info "IP konta znajdziesz w panelu MyDevil albo komendą 'devil vhost list' przez SSH."
_suggested_ip="$(ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "devil vhost list 2>/dev/null | awk 'NR==2{print \$1}'" 2>/dev/null || true)"
ask MYDEVIL_IP "IP konta (do certyfikatów Let's Encrypt, Enter = pomiń SSL na razie)" "${MYDEVIL_IP:-$_suggested_ip}"

# ── 5. Zapis konfiguracji ────────────────────────────────────────────────────
section "Zapis"
{
    echo "# Wygenerowane przez bin/deploy-mydevil-wizard.sh — $(date '+%Y-%m-%d %H:%M:%S')"
    echo "MYDEVIL_SSH=$(printf '%q' "$MYDEVIL_SSH")"
    echo "REMOTE_REPO_DIR=$(printf '%q' "$REMOTE_REPO_DIR")"
    echo "GIT_REMOTE_URL=$(printf '%q' "$GIT_REMOTE_URL")"
    echo "COMPOSER_BIN=$(printf '%q' "$COMPOSER_BIN")"
    echo "PRIMARY_DOMAIN=$(printf '%q' "$PRIMARY_DOMAIN")"
    echo -n "ALIAS_DOMAINS=("
    for d in "${ALIAS_DOMAINS[@]:-}"; do [[ -n "$d" ]] && printf ' %q' "$d"; done
    echo " )"
    echo "MYDEVIL_IP=$(printf '%q' "$MYDEVIL_IP")"
} > "$CONFIG_FILE"
ok "Zapisano: ${CONFIG_FILE}"

# Upewnij się, że plik konfiguracyjny (dane środowiska, nie sekrety appki, ale
# i tak specyficzne dla Twojego konta) nie trafi do gita.
GITIGNORE="${REPO_ROOT}/.gitignore"
if [[ -f "$GITIGNORE" ]] && ! grep -qxF "bin/.deploy-mydevil.conf" "$GITIGNORE"; then
    echo "bin/.deploy-mydevil.conf" >> "$GITIGNORE"
    ok "Dopisano bin/.deploy-mydevil.conf do .gitignore"
fi

# ── 6. Podsumowanie i odpalenie ──────────────────────────────────────────────
section "Podsumowanie"
cat <<EOF
  SSH:            ${MYDEVIL_SSH}
  Katalog:        ~/${REMOTE_REPO_DIR}
  Repo:           ${GIT_REMOTE_URL}
  Composer:       ${COMPOSER_BIN}
  Domena główna:  ${PRIMARY_DOMAIN}
  Aliasy:         ${ALIAS_DOMAINS[*]:-(brak)}
  IP (SSL):       ${MYDEVIL_IP:-(pominięte — deploy z --skip-ssl)}
EOF

echo
if confirm "Uruchomić teraz deploy-mydevil.sh --dry-run (podgląd, nic nie zmienia)?"; then
    echo
    bash "$DEPLOY_SCRIPT" --dry-run
    echo
    if confirm "Podgląd wygląda dobrze — uruchomić naprawdę (bez --sync-data)?"; then
        _extra=()
        [[ -z "$MYDEVIL_IP" ]] && _extra+=(--skip-ssl)
        bash "$DEPLOY_SCRIPT" "${_extra[@]}"
    else
        info "OK — uruchom ręcznie kiedy będziesz gotów: bash bin/deploy-mydevil.sh"
    fi
else
    info "OK — konfiguracja zapisana. Uruchom kiedy chcesz: bash bin/deploy-mydevil.sh --dry-run"
fi
