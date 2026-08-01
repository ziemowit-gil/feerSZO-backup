#!/usr/bin/env bash
# configure.sh — interaktywna konfiguracja feerSZO
#
# Użycie (z katalogu /opt/feer-szo):
#   bash docker/configure.sh
#
# Tworzy/aktualizuje:
#   docker/.env.prod          — zmienne środowiskowe serwera
#   ng-app/public/app.config.json — konfiguracja Angular (runtime)
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${SCRIPT_DIR}/.env.prod"
NG_CONFIG="${REPO_DIR}/ng-app/public/app.config.json"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; DIM='\033[2m'; RESET='\033[0m'

info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
section() { echo -e "\n${BOLD}━━ $* ${DIM}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

# Zapytaj z domyślną wartością
ask() {
  local prompt="$1" default="${2:-}"
  local var
  if [[ -n "$default" ]]; then
    read -rp $'\e[36m  '"${prompt}"$' [\e[0m'"${default}"$'\e[36m]: \e[0m' var
  else
    read -rp $'\e[36m  '"${prompt}"$': \e[0m' var
  fi
  printf '%s' "${var:-$default}"
}

# Zapytaj o hasło (ukryte)
ask_secret() {
  local prompt="$1" current="${2:-}" var
  if [[ -n "$current" ]]; then
    read -rsp $'\e[36m  '"${prompt}"$' [Enter=zachowaj]: \e[0m' var
    echo
    printf '%s' "${var:-$current}"
  else
    read -rsp $'\e[36m  '"${prompt}"$': \e[0m' var
    echo
    printf '%s' "$var"
  fi
}

# Wczytaj wartość z .env.prod
env_val() {
  local key="$1" default="${2:-}"
  if [[ -f "$ENV_FILE" ]]; then
    local v
    v=$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true)
    printf '%s' "${v:-$default}"
  else
    printf '%s' "$default"
  fi
}

# Wczytaj wartość z app.config.json
ng_val() {
  local key="$1" default="${2:-}"
  if [[ -f "$NG_CONFIG" ]] && command -v python3 &>/dev/null; then
    python3 -c "
import json,sys
try:
  d=json.load(open('$NG_CONFIG'))
  print(d.get('$key','$default'))
except:
  print('$default')
" 2>/dev/null || printf '%s' "$default"
  else
    printf '%s' "$default"
  fi
}

# Generuj losowy sekret
gen_secret() {
  python3 -c "import secrets; print(secrets.token_hex(32))" 2>/dev/null \
    || openssl rand -hex 32 2>/dev/null \
    || head -c 32 /dev/urandom | xxd -p
}

# ════════════════════════════════════════════════════════════════════
echo -e "\n${BOLD}  feerSZO — Konfiguracja środowiska${RESET}"
echo -e "  ${DIM}Plik env:    ${ENV_FILE}${RESET}"
echo -e "  ${DIM}Ng config:   ${NG_CONFIG}${RESET}"
[[ -f "$ENV_FILE" ]] && warn "Istniejący .env.prod zostanie zaktualizowany." \
                     || info "Zostanie utworzony nowy .env.prod."

# ════════════════════════════════════════════════════════════════════
section "1 / 5  Domena i SSL"

DOMAIN=$(ask   "Domena (np. szo.feer.org.pl)" "$(env_val DOMAIN 'szo.feer.org.pl')")
ACME_EMAIL=$(ask "E-mail dla Let's Encrypt"   "$(env_val ACME_EMAIL '')")

# ════════════════════════════════════════════════════════════════════
section "2 / 5  Klucze tajne"

cur_app=$(env_val APP_SECRET '')
[[ -z "$cur_app" ]] && cur_app=$(gen_secret) && info "Wygenerowano nowy APP_SECRET."
APP_SECRET=$(ask "APP_SECRET" "$cur_app")

cur_jwt=$(env_val JWT_SECRET '')
[[ -z "$cur_jwt" ]] && cur_jwt=$(gen_secret) && info "Wygenerowano nowy JWT_SECRET."
JWT_SECRET=$(ask "JWT_SECRET" "$cur_jwt")

# ════════════════════════════════════════════════════════════════════
section "3 / 5  Microsoft 365"

MS_TENANT=$(ask    "Tenant ID (GUID)"      "$(env_val MS_TENANT '')")
MS_CLIENT=$(ask    "Client ID"             "$(env_val MS_CLIENT '')")
MS_SECRET=$(ask_secret "Client Secret"     "$(env_val MS_SECRET '')")

# ════════════════════════════════════════════════════════════════════
section "4 / 5  SMTP i SMS"

SMTP_HOST=$(ask    "SMTP host"      "$(env_val SMTP_HOST 'smtp.office365.com')")
SMTP_PORT=$(ask    "SMTP port"      "$(env_val SMTP_PORT '587')")
SMTP_USER=$(ask    "SMTP login"     "$(env_val SMTP_USER '')")
SMTP_PASS=$(ask_secret "SMTP hasło" "$(env_val SMTP_PASS '')")

TWILIO_SID=$(ask   "Twilio SID   (puste = SMS off)" "$(env_val TWILIO_SID '')")
TWILIO_TOKEN=$(ask "Twilio Token"                    "$(env_val TWILIO_TOKEN '')")
TWILIO_FROM=$(ask  "Twilio FROM"                     "$(env_val TWILIO_FROM '')")

# ════════════════════════════════════════════════════════════════════
section "5 / 5  Angular (app.config.json)"

NG_API=$(ask   "URL API (karty30.php)" "$(ng_val apiUrl '/api/v1/karty30.php')")
NG_TITLE=$(ask "Tytuł aplikacji"       "$(ng_val appTitle 'feerSZO — Panel')")
NG_ORG=$(ask   "Nazwa organizacji"     "$(ng_val orgName 'FEER')")
NG_BASE=$(ask  "Base href"             "$(ng_val baseHref '/newUI/')")

# ════════════════════════════════════════════════════════════════════
section "Zapis"

# Backup .env.prod
if [[ -f "$ENV_FILE" ]]; then
  bak="${ENV_FILE}.$(date +%Y%m%d_%H%M%S).bak"
  cp "$ENV_FILE" "$bak"
  info "Backup: ${bak}"
fi

cat > "$ENV_FILE" <<ENVEOF
# feerSZO .env.prod — configure.sh $(date '+%Y-%m-%d %H:%M')

DOMAIN=${DOMAIN}
ACME_EMAIL=${ACME_EMAIL}

APP_SECRET=${APP_SECRET}
JWT_SECRET=${JWT_SECRET}

MS_TENANT=${MS_TENANT}
MS_CLIENT=${MS_CLIENT}
MS_SECRET=${MS_SECRET}

SMTP_HOST=${SMTP_HOST}
SMTP_PORT=${SMTP_PORT}
SMTP_USER=${SMTP_USER}
SMTP_PASS=${SMTP_PASS}

TWILIO_SID=${TWILIO_SID}
TWILIO_TOKEN=${TWILIO_TOKEN}
TWILIO_FROM=${TWILIO_FROM}
ENVEOF

chmod 600 "$ENV_FILE"
ok ".env.prod zapisany (prawa: 600)."

# Zapis app.config.json
mkdir -p "$(dirname "$NG_CONFIG")"
printf '{\n  "apiUrl":   "%s",\n  "appTitle": "%s",\n  "orgName":  "%s",\n  "baseHref": "%s"\n}\n' \
  "$NG_API" "$NG_TITLE" "$NG_ORG" "$NG_BASE" > "$NG_CONFIG"
ok "app.config.json zapisany."

echo ""
info "Następne kroki:"
echo "     bash docker/apply-prod.sh --ng --build --pull -y"
echo ""
info "Angular będzie dostępny pod:"
echo "     https://${DOMAIN}${NG_BASE}"
echo ""
ok "Konfiguracja zakończona."
