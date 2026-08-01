#!/usr/bin/env bash
# configure.sh — konfiguracja Angular new UI (app.config.json)
#
# Użycie (z katalogu /opt/feer-szo):
#   bash docker/configure.sh
#
# Tworzy/aktualizuje:
#   ng-app/public/app.config.json
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
NG_CONFIG="${REPO_DIR}/ng-app/public/app.config.json"

GREEN='\033[0;32m'; CYAN='\033[0;36m'; BOLD='\033[1m'; DIM='\033[2m'; RESET='\033[0m'

ok()  { echo -e "${GREEN}  ✔ $*${RESET}"; }
ask() {
  local prompt="$1" default="${2:-}" var
  read -rp $'\e[36m  '"${prompt}"$' [\e[0m'"${default}"$'\e[36m]: \e[0m' var
  printf '%s' "${var:-$default}"
}

ng_val() {
  local key="$1" default="${2:-}"
  if [[ -f "$NG_CONFIG" ]] && command -v python3 &>/dev/null; then
    python3 -c "
import json
try:
  print(json.load(open('$NG_CONFIG')).get('$key','$default'))
except:
  print('$default')
" 2>/dev/null
  else
    printf '%s' "$default"
  fi
}

echo -e "\n${BOLD}  feerSZO — Konfiguracja Angular new UI${RESET}"
echo -e "  ${DIM}${NG_CONFIG}${RESET}\n"

API_URL=$(ask  "URL API (karty30.php)" "$(ng_val apiUrl  '/api/v1/karty30.php')")
APP_TITLE=$(ask "Tytuł aplikacji"      "$(ng_val appTitle 'feerSZO — Panel')")
ORG_NAME=$(ask  "Nazwa organizacji"    "$(ng_val orgName  'FEER')")
BASE_HREF=$(ask "Base href"            "$(ng_val baseHref '/newUI/')")

mkdir -p "$(dirname "$NG_CONFIG")"
printf '{\n  "apiUrl":   "%s",\n  "appTitle": "%s",\n  "orgName":  "%s",\n  "baseHref": "%s"\n}\n' \
  "$API_URL" "$APP_TITLE" "$ORG_NAME" "$BASE_HREF" > "$NG_CONFIG"

echo ""
ok "Zapisano ${NG_CONFIG}"
echo -e "  ${DIM}Angular dostępny po deployu pod: https://<domena>${BASE_HREF}${RESET}"
