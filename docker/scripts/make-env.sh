#!/usr/bin/env bash
# make-env.sh — interaktywny kreator pliku .env.prod
# Użycie: bash make-env.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.prod"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

ask()  { read -rp "$(echo -e "${CYAN}▸ $1${RESET} [${2}]: ")" _v; echo "${_v:-$2}"; }
askp() { read -rsp "$(echo -e "${CYAN}▸ $1${RESET}: ")" _v; echo; echo "$_v"; }
ok()   { echo -e "${GREEN}✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()  { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

echo
echo -e "${BOLD}━━ FEER SZO — kreator .env.prod ━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo

# Ostrzeżenie jeśli plik już istnieje
if [[ -f "$ENV_FILE" ]]; then
    warn ".env.prod już istnieje: ${ENV_FILE}"
    read -rp "Nadpisać? [t/N]: " _ow
    [[ "${_ow,,}" == "t" ]] || { warn "Anulowano."; exit 0; }
fi

# ── Domena ────────────────────────────────────────────────────────────────────
echo -e "${BOLD}Domena i SSL${RESET}"
DOMAIN=$(ask "Domena aplikacji" "szo.feer.org.pl")
ACME_EMAIL=$(ask "E-mail Let's Encrypt" "admin@feer.org.pl")
DOMAIN_CRM=$(ask "Domena CRM redirect (Enter = pomiń)" "")

# ── Aplikacja ─────────────────────────────────────────────────────────────────
echo
echo -e "${BOLD}Aplikacja${RESET}"
ORG_NAME=$(ask "Nazwa organizacji" "Fundacja Edukacji Empatii Rozwoju FEER")
APP_KEY=$(php -r "echo bin2hex(random_bytes(32));" 2>/dev/null \
          || openssl rand -hex 32)
ok "APP_KEY wygenerowany automatycznie"

# ── Baza danych ───────────────────────────────────────────────────────────────
echo
echo -e "${BOLD}Baza danych${RESET}"
echo -e "  1) SQLite (domyślne)"
echo -e "  2) MySQL"
read -rp "Wybór [1]: " _db_choice
_db_choice="${_db_choice:-1}"

DB_BLOCK="DB_TYPE=sqlite"
if [[ "$_db_choice" == "2" ]]; then
    DB_HOST=$(ask "DB_HOST" "mysql")
    DB_NAME=$(ask "DB_NAME" "feer")
    DB_USER=$(ask "DB_USER" "feer")
    DB_PASS=$(askp "DB_PASS")
    MYSQL_ROOT_PASS=$(askp "MYSQL_ROOT_PASSWORD")
    DB_BLOCK="DB_TYPE=mysql
DB_HOST=${DB_HOST}
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASS}"
fi

# ── Microsoft 365 (opcjonalne) ────────────────────────────────────────────────
echo
echo -e "${BOLD}Microsoft 365 (Enter = pomiń)${RESET}"
MS_TENANT=$(ask "MS_TENANT_ID" "")
MS_CLIENT=$(ask "MS_CLIENT_ID" "")
MS_SECRET=""
MS_ENABLED=0
if [[ -n "$MS_TENANT" && -n "$MS_CLIENT" ]]; then
    MS_SECRET=$(askp "MS_CLIENT_SECRET")
    MS_ENABLED=1
fi

# ── SMTP (opcjonalne) ─────────────────────────────────────────────────────────
echo
echo -e "${BOLD}SMTP — opcjonalne (Enter = pomiń)${RESET}"
SMTP_HOST=$(ask "SMTP_HOST" "")
SMTP_BLOCK="# SMTP nie skonfigurowany"
if [[ -n "$SMTP_HOST" ]]; then
    SMTP_PORT=$(ask "SMTP_PORT" "587")
    SMTP_FROM=$(ask "SMTP_FROM" "no-reply@${DOMAIN}")
    SMTP_USER=$(ask "SMTP_USER" "")
    SMTP_PASS=$(askp "SMTP_PASS")
    SMTP_TLS=$(ask "SMTP_TLS (off/tls/starttls)" "starttls")
    SMTP_BLOCK="SMTP_HOST=${SMTP_HOST}
SMTP_PORT=${SMTP_PORT}
SMTP_FROM=${SMTP_FROM}
SMTP_USER=${SMTP_USER}
SMTP_PASS=${SMTP_PASS}
SMTP_TLS=${SMTP_TLS}"
fi

# ── ownCloud (opcjonalne — magazyn plików lekcji TI) ──────────────────────────
echo
echo -e "${BOLD}ownCloud — magazyn plików lekcji TI (Enter = pomiń)${RESET}"
OC_DOMAIN=$(ask "OWNCLOUD_DOMAIN" "")
OC_BLOCK="# ownCloud nie skonfigurowany — uruchom docker/setup-owncloud.sh później, gdy będzie potrzebny"
if [[ -n "$OC_DOMAIN" ]]; then
    OC_ADMIN_USER=$(ask "OWNCLOUD_ADMIN_USERNAME" "admin")
    OC_ADMIN_PASS=$(askp "OWNCLOUD_ADMIN_PASSWORD (Enter = wygeneruj losowe)")
    if [[ -z "$OC_ADMIN_PASS" ]]; then
        OC_ADMIN_PASS="$(openssl rand -hex 16)"
        ok "OWNCLOUD_ADMIN_PASSWORD wygenerowane automatycznie"
    fi
    OC_BLOCK="OWNCLOUD_DOMAIN=${OC_DOMAIN}
OWNCLOUD_ADMIN_USERNAME=${OC_ADMIN_USER}
OWNCLOUD_ADMIN_PASSWORD=${OC_ADMIN_PASS}"
fi

# ── Zapisz plik ───────────────────────────────────────────────────────────────
CRM_LINE=""
[[ -n "$DOMAIN_CRM" ]] && CRM_LINE="DOMAIN_CRM=${DOMAIN_CRM}"

cat > "$ENV_FILE" <<EOF
# FEER SZO — produkcja — wygenerowano $(date '+%Y-%m-%d %H:%M')

# ── Domena i SSL ──────────────────────────────────────────────────────────────
DOMAIN=${DOMAIN}
ACME_EMAIL=${ACME_EMAIL}
${CRM_LINE}

# ── Aplikacja ─────────────────────────────────────────────────────────────────
APP_ENV=production
APP_URL=https://${DOMAIN}
APP_KEY=${APP_KEY}
ORG_NAME=${ORG_NAME}

# ── Baza danych ───────────────────────────────────────────────────────────────
${DB_BLOCK}

# ── SMTP ──────────────────────────────────────────────────────────────────────
${SMTP_BLOCK}

# ── Microsoft 365 ─────────────────────────────────────────────────────────────
MS_ENABLED=${MS_ENABLED}
MS_TENANT_ID=${MS_TENANT}
MS_CLIENT_ID=${MS_CLIENT}
MS_CLIENT_SECRET=${MS_SECRET}
MS_REDIRECT_URI=https://${DOMAIN}/auth/microsoft.php

# ── ownCloud (magazyn plików lekcji TI, docker-compose.owncloud.yml) ─────────
${OC_BLOCK}
EOF

chmod 600 "$ENV_FILE"

echo
ok ".env.prod zapisany: ${ENV_FILE}"
echo
echo -e "  ${BOLD}Co dalej:${RESET}"
echo -e "  ${CYAN}docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.prod up -d --build${RESET}"
if [[ -n "$OC_DOMAIN" ]]; then
    echo -e "  Żeby uruchomić ownCloud: ${CYAN}bash setup-owncloud.sh${RESET} (dokłada kontener + konto integracyjne)"
fi
